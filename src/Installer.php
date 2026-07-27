<?php
/**
 * Plugins Management
 * @author Joe Huss <detain@interserver.net>
 * @copyright 2025
 * @package MyAdmin
 * @category Plugins
 */

namespace MyAdmin\Plugins;

use Composer\Composer;
use Composer\Installer\BinaryInstaller;
use Composer\Installer\LibraryInstaller;
use Composer\IO\IOInterface;
use Composer\Package\PackageInterface;
use Composer\Repository\InstalledRepositoryInterface;
use Composer\Util\Filesystem;

/**
 * MyAdmin custom Composer installer.
 *
 * Registered from Plugin::activate(). Claims the MyAdmin package types and decides where
 * each lands on disk.
 *
 * ---------------------------------------------------------------------------------
 * WHAT THIS CLASS IS FOR, AND WHY IT CURRENTLY DOES ALMOST NOTHING
 * ---------------------------------------------------------------------------------
 * Composer's default LibraryInstaller is registered with type=null, and its supports()
 * accepts EVERY package type. So every MyAdmin package already installs correctly into
 * vendor/ with no custom installer at all. This class only earns its place if we want a
 * package type to land somewhere OTHER than vendor/.
 *
 * As of this writing no MyAdmin package needs that:
 *   - all 73 MyAdmin packages declare `"type": "myadmin-plugin"` and belong in vendor/
 *   - nothing declares myadmin-template, myadmin-module or myadmin-menu
 *   - vendor packages read their own .tpl files in place via __DIR__, and ship no web
 *     assets, so there is nothing to copy out of vendor/ in the first place
 *
 * A previous revision tried to route `myadmin-template` packages to include/templates/.
 * That branch was unreachable — it tested $this->type, which is the installer's own
 * constructor type ('library' by default, and Plugin::activate() never passes one), not the
 * type of the package being installed. It has been removed rather than repaired, because
 * copying vendor templates into the tree would only duplicate files the app already reads
 * from vendor/. If that decision is ever revisited, branch on
 * $package->getType(), never on $this->type.
 *
 * ---------------------------------------------------------------------------------
 * COMPOSER 2 ASYNC CONTRACT — the thing custom installers most often get wrong
 * ---------------------------------------------------------------------------------
 * In Composer 2, download(), prepare(), install(), update(), uninstall() and cleanup() may
 * each return a React PromiseInterface, and the InstallationManager awaits them. Operations
 * are BATCHED across packages, not run to completion one package at a time: every download()
 * runs first, then every prepare(), then the install/update/uninstall, then cleanup() for
 * every package regardless of success.
 *
 * Two rules follow:
 *   1. An override MUST return the parent's promise. Swallowing it makes Composer proceed
 *      before the work has finished, which corrupts installs in ways that look random.
 *   2. Risky work and any user interaction belong in prepare(), not install().
 *
 * A prior revision of this class reimplemented install()/update()/uninstall() as Composer 1
 * era copies: they returned nothing, and installCode() called
 * $this->downloadManager->download(), which in Composer 2 only fetches into cache — the
 * separate ->install() call that actually extracts the archive was never made. Because
 * InstallationManager::addInstaller() PREPENDS, re-enabling the plugin with that code would
 * have handed all 73 myadmin-plugin packages to a broken installer. Every method below now
 * delegates to LibraryInstaller and returns its promise.
 *
 * SIGNATURE NOTE: LibraryInstaller declares prepare($type, ...) and cleanup($type, ...)
 * WITHOUT a string type hint, even though InstallerInterface declares them WITH one. A
 * subclass of LibraryInstaller must therefore omit the hint or PHP raises a
 * signature-compatibility fatal.
 *
 * @see \Composer\Installer\InstallerInterface
 * @see \Composer\Installer\LibraryInstaller
 * @see https://getcomposer.org/doc/articles/custom-installers.md
 */
class Installer extends LibraryInstaller
{
    /**
     * Package types this installer claims.
     *
     * Kept as a constant so supports() and the docs cannot drift apart.
     *
     * @var string[]
     */
    public const MYADMIN_PACKAGE_TYPES = [
        'myadmin-template',
        'myadmin-module',
        'myadmin-plugin',
        'myadmin-menu',
    ];

    /**
     * Initializes the installer.
     *
     * Delegates entirely to LibraryInstaller, which sets up $vendorDir, $downloadManager,
     * $filesystem and $binaryInstaller. The previous revision reimplemented this body by
     * hand and drifted from upstream.
     *
     * @param \Composer\IO\IOInterface            $io
     * @param \Composer\Composer                  $composer
     * @param string                              $type            installer's own type label;
     *                                                             NOT the package's type — do
     *                                                             not branch install paths on it
     * @param \Composer\Util\Filesystem|null      $filesystem
     * @param \Composer\Installer\BinaryInstaller|null $binaryInstaller
     */
    public function __construct(IOInterface $io, Composer $composer, $type = 'library', Filesystem $filesystem = null, BinaryInstaller $binaryInstaller = null)
    {
        parent::__construct($io, $composer, $type, $filesystem, $binaryInstaller);
    }

    /**
     * Whether this installer handles a given package type.
     *
     * INPUT:   $packageType — the `type` field from the package's composer.json.
     * RETURNS: bool — true to claim the package.
     *
     * Called by InstallationManager when resolving which installer handles a package. First
     * match wins, and addInstaller() prepends, so this installer is consulted BEFORE
     * Composer's catch-all LibraryInstaller. Widening this list therefore takes packages away
     * from Composer's default handling — only claim a type you genuinely install differently.
     *
     * @param string $packageType
     * @return bool
     */
    public function supports($packageType)
    {
        return in_array($packageType, self::MYADMIN_PACKAGE_TYPES, true);
    }

    /**
     * Whether a supported package is currently installed.
     *
     * INPUT:   $repo — the installed-packages repository to check against.
     *          $package — the package in question.
     * RETURNS: bool
     *
     * The parent checks repository membership AND that the install path is readable,
     * including junction/symlink handling on Windows. Delegated deliberately: an override
     * that only tests $repo->hasPackage() silently mishandles a half-removed vendor dir.
     *
     * @param \Composer\Repository\InstalledRepositoryInterface $repo
     * @param \Composer\Package\PackageInterface                $package
     * @return bool
     */
    public function isInstalled(InstalledRepositoryInterface $repo, PackageInterface $package)
    {
        return parent::isInstalled($repo, $package);
    }

    /**
     * Phase 1 of 4 — fetch the package into Composer's cache/temp area.
     *
     * INPUT:   $package     — the package to fetch.
     *          $prevPackage — the version currently installed, when this is an update.
     * RETURNS: PromiseInterface|null — MUST be returned to the caller.
     *
     * Runs for EVERY package in the operation set before any prepare() or install() runs.
     * Nothing is placed in its final location here.
     *
     * USE FOR: nothing today. A future mirror/proxy scheme would more naturally hook
     * PluginEvents::PRE_FILE_DOWNLOAD than override this.
     *
     * @param \Composer\Package\PackageInterface      $package
     * @param \Composer\Package\PackageInterface|null $prevPackage
     * @return \React\Promise\PromiseInterface|null
     */
    public function download(PackageInterface $package, PackageInterface $prevPackage = null)
    {
        return parent::download($package, $prevPackage);
    }

    /**
     * Phase 2 of 4 — verify it is safe to proceed, before anything is written.
     *
     * INPUT:   $type        — 'install', 'update' or 'uninstall'. NOTE: untyped, to match
     *                         LibraryInstaller rather than InstallerInterface.
     *          $package     — the package about to be acted on.
     *          $prevPackage — the currently installed version, on update.
     * RETURNS: PromiseInterface|null — MUST be returned.
     *
     * This is the correct place for checks that should abort the run and for anything that
     * needs to ask the user something, because it happens before any package has been
     * modified. Throwing here aborts cleanly; throwing from install() leaves a partial state.
     *
     * USE FOR: a good future home for the "vendor/detain/* working copy is dirty" guard —
     * MyAdmin sets preferred-install=source and discard-changes=stash, so an update silently
     * stashes uncommitted vendor work.
     *
     * @param string                                  $type
     * @param \Composer\Package\PackageInterface      $package
     * @param \Composer\Package\PackageInterface|null $prevPackage
     * @return \React\Promise\PromiseInterface|null
     */
    public function prepare($type, PackageInterface $package, PackageInterface $prevPackage = null)
    {
        return parent::prepare($type, $package, $prevPackage);
    }

    /**
     * Phase 3 of 4 — place the package at its final location and register it.
     *
     * INPUT:   $repo    — the installed-packages repository to add the package to.
     *          $package — the package to install.
     * RETURNS: PromiseInterface|null — MUST be returned.
     *
     * The parent extracts the code, installs any binaries, and adds the package to $repo,
     * chaining all of it onto the download promise. Do not reimplement: the ordering between
     * extraction and binary installation is promise-dependent and easy to get wrong.
     *
     * USE FOR: post-install work on a MyAdmin package. Prefer PackageEvents::POST_PACKAGE_INSTALL
     * for that unless the work genuinely must be inside the install promise chain.
     *
     * @param \Composer\Repository\InstalledRepositoryInterface $repo
     * @param \Composer\Package\PackageInterface                $package
     * @return \React\Promise\PromiseInterface|null
     */
    public function install(InstalledRepositoryInterface $repo, PackageInterface $package)
    {
        return parent::install($repo, $package);
    }

    /**
     * Phase 3 of 4, update variant — replace an installed package with a new version.
     *
     * INPUT:   $repo    — the installed-packages repository.
     *          $initial — the version currently on disk.
     *          $target  — the version replacing it.
     * RETURNS: PromiseInterface|null — MUST be returned.
     * THROWS:  \InvalidArgumentException when $initial is not installed.
     *
     * The parent handles the case where the initial and target install paths overlap by
     * forcing a remove-then-install instead of a rename, which would otherwise wipe the
     * target directory during cleanup of the initial one.
     *
     * @param \Composer\Repository\InstalledRepositoryInterface $repo
     * @param \Composer\Package\PackageInterface                $initial
     * @param \Composer\Package\PackageInterface                $target
     * @return \React\Promise\PromiseInterface|null
     */
    public function update(InstalledRepositoryInterface $repo, PackageInterface $initial, PackageInterface $target)
    {
        return parent::update($repo, $initial, $target);
    }

    /**
     * Phase 3 of 4, removal variant — remove a package and deregister it.
     *
     * INPUT:   $repo    — the installed-packages repository.
     *          $package — the package to remove.
     * RETURNS: PromiseInterface|null — MUST be returned.
     * THROWS:  \InvalidArgumentException when the package is not installed.
     *
     * The parent removes the code and binaries, drops the package from $repo, and prunes the
     * vendor directory if it is left empty.
     *
     * USE FOR: a future hook to strip the package from include/config/hooks.json and
     * include/config/plugins.json. PackageEvents::POST_PACKAGE_UNINSTALL is the better place.
     *
     * @param \Composer\Repository\InstalledRepositoryInterface $repo
     * @param \Composer\Package\PackageInterface                $package
     * @return \React\Promise\PromiseInterface|null
     */
    public function uninstall(InstalledRepositoryInterface $repo, PackageInterface $package)
    {
        return parent::uninstall($repo, $package);
    }

    /**
     * Phase 4 of 4 — always runs, success or failure.
     *
     * INPUT:   $type        — 'install', 'update' or 'uninstall'. Untyped, matching
     *                         LibraryInstaller.
     *          $package     — the package acted on.
     *          $prevPackage — the previously installed version, on update.
     * RETURNS: PromiseInterface|null — MUST be returned.
     *
     * Runs for EVERY package after the operation batch completes, including when the
     * operation failed or was rolled back. The parent removes temp download artefacts.
     *
     * USE FOR: releasing anything prepare() acquired — locks, temp dirs, backups. Must be
     * safe to run when the corresponding install never happened.
     *
     * @param string                                  $type
     * @param \Composer\Package\PackageInterface      $package
     * @param \Composer\Package\PackageInterface|null $prevPackage
     * @return \React\Promise\PromiseInterface|null
     */
    public function cleanup($type, PackageInterface $package, PackageInterface $prevPackage = null)
    {
        return parent::cleanup($type, $package, $prevPackage);
    }

    /**
     * Where a package is installed, relative to the directory holding composer.json.
     *
     * INPUT:   $package — the package being placed.
     * RETURNS: string — the install path. MUST NOT end in a slash.
     *
     * This is THE method a custom installer exists to override; everything else here is
     * delegation. It currently defers to LibraryInstaller, i.e. vendor/<vendor>/<name>,
     * because no MyAdmin package needs to live anywhere else.
     *
     * If a package type ever does need a different location, branch on the PACKAGE's type:
     *
     *     if ($package->getType() === 'myadmin-template') {
     *         return 'include/templates/'.$package->getPrettyName();
     *     }
     *
     * and NOT on $this->type, which is the installer's own constructor label and is always
     * 'library' here. Also override getPackageBasePath() to match: install() and update() use
     * getInstallPath(), but uninstall() uses getPackageBasePath(), so overriding only the
     * former leaves packages that install to a custom path uninstalling from the wrong one.
     *
     * @param \Composer\Package\PackageInterface $package
     * @return string
     */
    public function getInstallPath(PackageInterface $package)
    {
        return parent::getInstallPath($package);
    }

    /**
     * Ensures a package's declared binaries are linked into the bin-dir.
     *
     * INPUT:   $package
     * RETURNS: void
     *
     * Part of BinaryPresenceInterface, which LibraryInstaller implements. Called when
     * Composer needs binaries present without performing a full reinstall.
     *
     * @param \Composer\Package\PackageInterface $package
     * @return void
     */
    public function ensureBinariesPresence(PackageInterface $package)
    {
        parent::ensureBinariesPresence($package);
    }

    /**
     * The package's base path, with any target-dir suffix stripped.
     *
     * RETURNS: string
     *
     * Exists because installer plugins habitually override getInstallPath() and forget this
     * one. uninstall() removes from getPackageBasePath(), so the two must stay consistent —
     * see the note on getInstallPath().
     *
     * @param \Composer\Package\PackageInterface $package
     * @return string
     */
    protected function getPackageBasePath(PackageInterface $package)
    {
        return parent::getPackageBasePath($package);
    }

    /**
     * Downloads and extracts a package's code into its install path.
     *
     * RETURNS: PromiseInterface|null — MUST be returned.
     *
     * Internal helper used by install(). The previous revision reimplemented this as
     * $this->downloadManager->download(...), which in Composer 2 only populates the cache;
     * DownloadManager::download() and DownloadManager::install() are distinct operations and
     * both are required. The parent does both, in the right order, chained correctly.
     *
     * @param \Composer\Package\PackageInterface $package
     * @return \React\Promise\PromiseInterface|null
     */
    protected function installCode(PackageInterface $package)
    {
        return parent::installCode($package);
    }

    /**
     * Moves/updates a package's code from one version to another.
     *
     * RETURNS: PromiseInterface|null — MUST be returned.
     *
     * @param \Composer\Package\PackageInterface $initial
     * @param \Composer\Package\PackageInterface $target
     * @return \React\Promise\PromiseInterface|null
     */
    protected function updateCode(PackageInterface $initial, PackageInterface $target)
    {
        return parent::updateCode($initial, $target);
    }

    /**
     * Removes a package's code from disk.
     *
     * RETURNS: PromiseInterface|null — MUST be returned.
     *
     * @param \Composer\Package\PackageInterface $package
     * @return \React\Promise\PromiseInterface|null
     */
    protected function removeCode(PackageInterface $package)
    {
        return parent::removeCode($package);
    }
}
