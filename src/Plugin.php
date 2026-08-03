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
use Composer\EventDispatcher\Event as BaseEvent;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\Installer\InstallerEvent;
use Composer\Installer\InstallerEvents;
use Composer\Installer\PackageEvent;
use Composer\Installer\PackageEvents;
use Composer\IO\IOInterface;
use Composer\Plugin\Capable;
use Composer\Plugin\CommandEvent;
use Composer\Plugin\PluginInterface;
use Composer\Plugin\PluginEvents;
use Composer\Plugin\PostFileDownloadEvent;
use Composer\Plugin\PreCommandRunEvent;
use Composer\Plugin\PreFileDownloadEvent;
use Composer\Plugin\PrePoolCreateEvent;
use Composer\Script\Event;
use Composer\Script\ScriptEvents;

/**
 * MyAdmin Composer Plugin — the single `extra.class` entry point for this package.
 *
 * This class is deliberately wired into *every* extension point Composer 2.10 offers
 * (PluginInterface, Capable, EventSubscriberInterface), so that switching a behaviour on
 * is a matter of filling in a method body rather than rediscovering the plumbing. Most
 * handlers below are intentional no-ops: they exist to document the hook, pin the correct
 * signature, and give future work a place to land.
 *
 * ---------------------------------------------------------------------------------
 * READ THIS BEFORE CHANGING ANYTHING — the package has two independent halves
 * ---------------------------------------------------------------------------------
 *
 * 1. The COMPOSER-TIME half — this class's activate()/getCapabilities()/event handlers,
 *    plus Installer, CommandProvider and Command\*.
 *    It runs only when `detain/myadmin-plugin-installer` is listed as `true` under
 *    `config.allow-plugins` in the consuming project. In MyAdmin it is currently set to
 *    `false`, so Composer silently skips activation: activate() is never called, no custom
 *    installer is registered, no event handler below fires, and the `composer myadmin:*`
 *    commands do not appear. The skip message is emitted at IOInterface::DEBUG, i.e.
 *    invisible without -vvv.
 *
 * 2. The RUNTIME half — src/modules.php and src/function_requirements.php.
 *    These are `autoload.files` entries, so they load with the ordinary Composer autoloader
 *    on every PHP request REGARDLESS of `config.allow-plugins`. They are the sole
 *    definition site of the global get_module_db(), get_module_settings(), register_module(),
 *    get_module_name(), get_valid_module(), has_module_db(), get_module_stuff(),
 *    get_service_define() and function_requirements() helpers that MyAdmin depends on.
 *    MyAdmin\Plugins\Loader is likewise loaded by plain PSR-4 and drives every route
 *    registration in include/config/router.php.
 *
 * Consequence: `allow-plugins: false` does NOT make this package inert, and removing the
 * package would fatal the application. Static analysers and dead-code sweeps will get this
 * wrong; do not let them.
 *
 * A third path also bypasses `allow-plugins` entirely: Composer resolves `scripts`
 * callables through the PROJECT autoloader, not the plugin allowlist. MyAdmin's
 * composer.json wires `post-install-cmd`/`post-update-cmd` -> `@postSetup` ->
 * `@setPermissions` -> `MyAdmin\Plugins\Plugin::setPermissions`, so the permission helpers
 * at the bottom of this class execute on every install and update even though the plugin
 * itself is disallowed. Only `--no-scripts` or editing that `scripts` block stops them.
 *
 * @see https://getcomposer.org/doc/articles/plugins.md
 * @see https://getcomposer.org/doc/articles/scripts.md
 * @see \Composer\Plugin\PluginInterface
 * @see \Composer\Plugin\Capable
 * @see \Composer\EventDispatcher\EventSubscriberInterface
 */
class Plugin implements PluginInterface, EventSubscriberInterface, Capable
{
    /**
     * The active Composer instance, captured in activate().
     *
     * Handlers for PluginEvents::INIT and PluginEvents::PRE_POOL_CREATE receive event
     * objects with no getComposer(), so this property is the only route to Composer from
     * those callbacks.
     *
     * @var \Composer\Composer|null
     */
    protected $composer;

    /**
     * The active Composer IO channel, captured in activate().
     *
     * Always prefer this over echo/print: it honours -q/-v/-vv/-vvv, --no-ansi and
     * non-interactive mode. Progress goes to write(); problems go to writeError() so they
     * land on stderr and do not corrupt piped output.
     *
     * @var \Composer\IO\IOInterface|null
     */
    protected $io;

    /**
     * The custom installer registered in activate(), retained so deactivate() can
     * unregister the exact instance that was added.
     *
     * @var \MyAdmin\Plugins\Installer|null
     */
    protected $installer;

    /**
     * Wires this plugin into the Composer runtime.
     *
     * WHEN: called by Composer\Plugin\PluginManager::addPlugin() immediately after this
     * class is instantiated. In a normal boot that happens inside Factory::createComposer(),
     * BEFORE PluginEvents::INIT is dispatched. If the plugin is installed during the current
     * run it is instead called from PluginInstaller::install() at the moment the package
     * lands on disk.
     *
     * WHY: the only place with a guaranteed-valid $composer where custom installers,
     * downloaders and repository classes may be registered.
     *
     * NOT CALLED AT ALL while the package is blocked by config.allow-plugins.
     *
     * NOTE ON ORDERING: InstallationManager::addInstaller() PREPENDS. Composer's default
     * LibraryInstaller is registered with type=null and its supports() accepts every type,
     * so whatever this installer claims in supports() it takes precedence over the default
     * for those types. Widen Installer::supports() with care.
     *
     * @param \Composer\Composer       $composer the fully constructed Composer instance
     * @param \Composer\IO\IOInterface $io       the console IO channel
     * @return void
     */
    public function activate(Composer $composer, IOInterface $io)
    {
        $this->composer = $composer;
        $this->io = $io;
        $this->installer = new Installer($io, $composer);
        $composer->getInstallationManager()->addInstaller($this->installer);
    }

    /**
     * Unhooks everything activate() registered.
     *
     * WHEN: on plugin uninstall, and on plugin UPDATE — Composer deactivates the old
     * instance then activates the new one. This must stay symmetric with activate() or a
     * stale installer lingers in the InstallationManager across an upgrade.
     *
     * Event subscribers do NOT need removing here: PluginManager::removePlugin() calls
     * EventDispatcher::removeListener($plugin) for us.
     *
     * @param \Composer\Composer       $composer
     * @param \Composer\IO\IOInterface $io
     * @return void
     */
    public function deactivate(Composer $composer, IOInterface $io)
    {
        if ($this->installer !== null) {
            $composer->getInstallationManager()->removeInstaller($this->installer);
            $this->installer = null;
        }
    }

    /**
     * Final cleanup before the package is removed from disk.
     *
     * WHEN: always after deactivate(), from PluginManager::uninstallPlugin().
     *
     * WHY: the place to delete state that should not outlive the package — plugin-owned
     * caches, generated config, scratch directories.
     *
     * Deliberately empty. MyAdmin owns include/config/plugins.json, include/config/hooks.json
     * and the logs/ caches; those must survive a plugin reinstall.
     *
     * @param \Composer\Composer       $composer
     * @param \Composer\IO\IOInterface $io
     * @return void
     */
    public function uninstall(Composer $composer, IOInterface $io)
    {
    }

    /**
     * Declares which Composer "capabilities" this plugin implements.
     *
     * Returns a map of capability-interface FQCN => our implementing class FQCN. Composer
     * instantiates the named class with exactly ONE constructor argument: an array carrying
     * 'composer', 'io' and 'plugin' keys.
     *
     * CommandProvider is the ONLY capability interface that exists in Composer 2.10.
     * Composer's own Capable docblock shows a `Validator` example — that capability has no
     * implementation and never existed. Naming an unresolvable capability class throws
     * UnexpectedValueException, so do not speculatively add entries here.
     *
     * @return string[] map of capability interface name => implementing class name
     */
    public function getCapabilities()
    {
        return [
            'Composer\Plugin\Capability\CommandProvider' => 'MyAdmin\Plugins\CommandProvider'
        ];
    }

    /**
     * Declares every Composer event this plugin listens to.
     *
     * Accepted value shapes:
     *   'event-name' => 'methodName'                 // priority 0
     *   'event-name' => ['methodName', 10]           // explicit priority
     *   'event-name' => [['a', 5], ['b']]            // several handlers
     *
     * PRIORITY: higher integers run FIRST (Composer krsort()s the buckets). Handlers coming
     * from the root package's `scripts` block are appended to the priority-0 bucket AFTER
     * plugin listeners, so a priority-0 subscriber here runs before a composer.json script
     * on the same event. Use a negative priority to run after scripts instead.
     *
     * FIRST-INSTALL CAVEAT: on a fresh `composer install` into an empty vendor/, this
     * package does not exist yet when the run begins, so it misses INIT, COMMAND,
     * PRE_COMMAND_RUN, PRE_POOL_CREATE, PRE_OPERATIONS_EXEC and PRE_INSTALL_CMD on that
     * first run. It goes live mid-run and still receives the later PRE_/POST_PACKAGE_*,
     * POST_INSTALL_CMD and *_AUTOLOAD_DUMP events. Installing the plugin globally, or
     * setting extra.plugin-modifies-downloads, are the standard workarounds.
     *
     * CI WARNING: MyAdmin's GitHub Actions run `composer install` WITHOUT --no-scripts, so
     * anything implemented on the install/update/autoload-dump events below executes on
     * every CI leg. Every handler must be a no-op-or-warn when its preconditions are absent.
     *
     * Every handler is registered but intentionally inert, so the whole surface is visible
     * and correctly typed in one place.
     *
     * @return array<string, string|array{0: string, 1?: int}|array<array{0: string, 1?: int}>>
     */
    public static function getSubscribedEvents()
    {
        return [
            // -- Plugin events. Reachable ONLY from a plugin, never from composer.json scripts.
            PluginEvents::INIT => 'onInit',
            PluginEvents::COMMAND => 'onCommand',
            PluginEvents::PRE_COMMAND_RUN => 'onPreCommandRun',
            PluginEvents::PRE_POOL_CREATE => 'onPrePoolCreate',
            PluginEvents::PRE_FILE_DOWNLOAD => 'onPreFileDownload',
            PluginEvents::POST_FILE_DOWNLOAD => 'onPostFileDownload',

            // -- Installer events.
            InstallerEvents::PRE_OPERATIONS_EXEC => 'onPreOperationsExec',

            // -- Per-package events.
            PackageEvents::PRE_PACKAGE_INSTALL => 'onPrePackageInstall',
            PackageEvents::POST_PACKAGE_INSTALL => 'onPostPackageInstall',
            PackageEvents::PRE_PACKAGE_UPDATE => 'onPrePackageUpdate',
            PackageEvents::POST_PACKAGE_UPDATE => 'onPostPackageUpdate',
            PackageEvents::PRE_PACKAGE_UNINSTALL => 'onPrePackageUninstall',
            PackageEvents::POST_PACKAGE_UNINSTALL => 'onPostPackageUninstall',

            // -- Command lifecycle / script events.
            ScriptEvents::PRE_INSTALL_CMD => 'onPreInstallCmd',
            ScriptEvents::POST_INSTALL_CMD => 'onPostInstallCmd',
            ScriptEvents::PRE_UPDATE_CMD => 'onPreUpdateCmd',
            ScriptEvents::POST_UPDATE_CMD => 'onPostUpdateCmd',
            ScriptEvents::PRE_STATUS_CMD => 'onPreStatusCmd',
            ScriptEvents::POST_STATUS_CMD => 'onPostStatusCmd',
            ScriptEvents::PRE_AUTOLOAD_DUMP => 'onPreAutoloadDump',
            ScriptEvents::POST_AUTOLOAD_DUMP => 'onPostAutoloadDump',
            ScriptEvents::POST_ROOT_PACKAGE_INSTALL => 'onPostRootPackageInstall',
            ScriptEvents::POST_CREATE_PROJECT_CMD => 'onPostCreateProjectCmd',
            ScriptEvents::PRE_ARCHIVE_CMD => 'onPreArchiveCmd',
            ScriptEvents::POST_ARCHIVE_CMD => 'onPostArchiveCmd',
        ];
    }

    /*
     * =================================================================================
     *  Plugin events — Composer\Plugin\PluginEvents
     * =================================================================================
     */

    /**
     * PluginEvents::INIT ('init') — a Composer instance has finished initialising.
     *
     * FIRES: from Factory::createComposer(), after every installed plugin has been activated
     * and all managers are populated, but before packages are purged.
     *
     * INPUT: a plain Composer\EventDispatcher\Event. It has NO getComposer() and NO getIO() —
     * use $this->composer / $this->io captured in activate(). Available: getName(),
     * getArguments(), getFlags(), isPropagationStopped(), stopPropagation().
     *
     * RETURNS: void. Returning false marks the listener's return code as 1.
     *
     * USE FOR: late wiring that must observe a fully built Composer — inspecting which
     * sibling plugins loaded, or adjusting repositories after all plugins have run.
     *
     * @param \Composer\EventDispatcher\Event $event
     * @return void
     */
    public function onInit(BaseEvent $event)
    {
    }

    /**
     * PluginEvents::COMMAND ('command') — a Composer command is starting.
     *
     * FIRES: from inside a specific command's execute(), NOT globally. Only these emit it:
     * install, update, require, remove, reinstall, search, status, show, licenses, validate,
     * archive, diagnose, dump-autoload, depends, prohibits. Notably run-script, config, exec,
     * audit, init, bump, fund, outdated, create-project and self-update do NOT.
     *
     * INPUT: CommandEvent — getCommandName(): string, getInput(): InputInterface,
     * getOutput(): OutputInterface, plus getArguments() and getFlags().
     *
     * RETURNS: void.
     *
     * USE FOR: observing or decorating one particular command. To MUTATE CLI input use
     * onPreCommandRun() instead — by this point parsing and initialize() have already run.
     *
     * @param \Composer\Plugin\CommandEvent $event
     * @return void
     */
    public function onCommand(CommandEvent $event)
    {
    }

    /**
     * PluginEvents::PRE_COMMAND_RUN ('pre-command-run') — before any command executes.
     *
     * FIRES: from BaseCommand::initialize(), for EVERY Composer command, including outside a
     * project directory (it falls back to the global Composer instance).
     *
     * INPUT: PreCommandRunEvent — getCommand(): string (the command NAME, e.g. 'update'),
     * getInput(): InputInterface.
     *
     * RETURNS: void.
     *
     * USE FOR: the only sanctioned way to rewrite CLI input before a command runs, e.g.
     * $event->getInput()->setOption('prefer-dist', true) to enforce a house default.
     *
     * @param \Composer\Plugin\PreCommandRunEvent $event
     * @return void
     */
    public function onPreCommandRun(PreCommandRunEvent $event)
    {
    }

    /**
     * PluginEvents::PRE_POOL_CREATE ('pre-pool-create') — before dependency resolution.
     *
     * FIRES: from PoolBuilder, immediately before the package pool enters the solver. This is
     * the Composer 2 replacement for Composer 1's removed PRE_DEPENDENCIES_SOLVING and
     * POST_DEPENDENCIES_SOLVING events, which DO NOT EXIST in 2.x — do not reference them.
     *
     * INPUT: PrePoolCreateEvent.
     *   Read-only context: getRepositories(), getRequest(), getAcceptableStabilities(),
     *                      getStabilityFlags(), getRootAliases(), getRootReferences().
     *   Mutable:           getPackages()/setPackages(),
     *                      getUnacceptableFixedPackages()/setUnacceptableFixedPackages().
     * Only those two setters are read back by Composer; the other getters are context only.
     *
     * RETURNS: void — mutate through the setters.
     *
     * USE FOR: filtering candidate versions out of the solver, e.g. blocking a known-bad
     * release of a detain/myadmin-* package fleet-wide without editing 75 constraints.
     *
     * @param \Composer\Plugin\PrePoolCreateEvent $event
     * @return void
     */
    public function onPrePoolCreate(PrePoolCreateEvent $event)
    {
    }

    /**
     * PluginEvents::PRE_FILE_DOWNLOAD ('pre-file-download') — before a file is fetched.
     *
     * FIRES: in two flavours, distinguished by getType():
     *   'package'  — a dist archive is about to download; getContext() is the PackageInterface.
     *   'metadata' — repository metadata (packages.json etc.); getContext() is
     *                array{repository: RepositoryInterface}.
     *
     * INPUT: PreFileDownloadEvent — getHttpDownloader(), getProcessedUrl(),
     * setProcessedUrl(string), getCustomCacheKey(), setCustomCacheKey(?string), getType(),
     * getContext(), getTransportOptions(), setTransportOptions(array).
     *
     * ASYMMETRY WORTH MEMORISING: setTransportOptions() is honoured for 'metadata' ONLY;
     * setCustomCacheKey() is honoured for 'package' ONLY. For package transport options, set
     * them on the package object itself via $package->setTransportOptions().
     *
     * RETURNS: void — mutate through the setters.
     *
     * USE FOR: rewriting URLs to an internal mirror, injecting auth headers for a private
     * repository, or registering a custom protocol handler.
     *
     * A plugin that rewrites download URLs should also declare
     * `extra.plugin-modifies-downloads: true` so Composer installs and activates it in its
     * own batch before any other package is downloaded — otherwise on a first install it
     * misses every URL.
     *
     * @param \Composer\Plugin\PreFileDownloadEvent $event
     * @return void
     */
    public function onPreFileDownload(PreFileDownloadEvent $event)
    {
    }

    /**
     * PluginEvents::POST_FILE_DOWNLOAD ('post-file-download') — after a file is fetched.
     *
     * FIRES: for packages, only after Composer's own SHA-1 check passes — and NOT AT ALL on a
     * cache hit. For metadata, once the response is received.
     *
     * INPUT: PostFileDownloadEvent — getFileName(): ?string (null for metadata),
     * getChecksum(): ?string, getUrl(): string, getType(): string, getContext().
     * Context is the PackageInterface for 'package', or
     * array{response: Response, repository: RepositoryInterface} for 'metadata'.
     *
     * DO NOT CALL getPackage(): deprecated since Composer 2.1, it fires E_USER_DEPRECATED on
     * every single call. Use getContext().
     *
     * RETURNS: void.
     *
     * USE FOR: supplementary integrity checks — GPG signature verification, AV scanning — or
     * download telemetry.
     *
     * @param \Composer\Plugin\PostFileDownloadEvent $event
     * @return void
     */
    public function onPostFileDownload(PostFileDownloadEvent $event)
    {
    }

    /*
     * =================================================================================
     *  Installer events — Composer\Installer\InstallerEvents
     * =================================================================================
     */

    /**
     * InstallerEvents::PRE_OPERATIONS_EXEC ('pre-operations-exec') — the full plan is known
     * and is about to be executed.
     *
     * FIRES: once per run, immediately before any install/update/remove operation executes.
     *
     * INPUT: InstallerEvent — getComposer(), getIO(), isDevMode(), isExecutingOperations()
     * (false under --dry-run) and getTransaction(): ?Transaction.
     * Transaction::getOperations() returns the complete ordered OperationInterface[].
     * getTransaction() IS NULLABLE — always guard it.
     *
     * RETURNS: void. Throwing aborts the run before anything is written to disk.
     *
     * USE FOR: previewing or vetoing the whole change set — refusing to proceed on a
     * downgrade, or logging the operation plan for a deploy audit trail.
     *
     * CAVEAT: on a fresh install this plugin is not yet loaded when this fires. Hooking it
     * reliably requires installing the plugin globally.
     *
     * @param \Composer\Installer\InstallerEvent $event
     * @return void
     */
    public function onPreOperationsExec(InstallerEvent $event)
    {
    }

    /*
     * =================================================================================
     *  Package events — Composer\Installer\PackageEvents
     *
     *  All six deliver a PackageEvent exposing getComposer(), getIO(), isDevMode(),
     *  getLocalRepo(), getOperations() (every operation in the run) and getOperation() (the
     *  one currently executing).
     *
     *  Narrow getOperation() by type before use:
     *      InstallOperation   -> getPackage()
     *      UpdateOperation    -> getInitialPackage(), getTargetPackage()
     *      UninstallOperation -> getPackage()
     *  getOperationType() returns 'install'|'update'|'uninstall'|'markAliasInstalled'|
     *  'markAliasUninstalled'. Composer 1's getJobType() and SolverOperation::getReason() are
     *  gone — do not reference them.
     *
     *  ORDERING TRAP: the POST_* events are DEFERRED and fire after the whole promise batch
     *  resolves. They are NOT emitted immediately after their own package finishes, so do not
     *  assume "post-package-install for X" means only X has changed.
     *
     *  All six are suppressed by --no-scripts.
     * =================================================================================
     */

    /**
     * PackageEvents::PRE_PACKAGE_INSTALL — before a single package is installed.
     *
     * RETURNS: void. Throwing aborts the run.
     *
     * USE FOR: last-moment veto or preparation for one incoming package.
     *
     * @param \Composer\Installer\PackageEvent $event
     * @return void
     */
    public function onPrePackageInstall(PackageEvent $event)
    {
    }

    /**
     * PackageEvents::POST_PACKAGE_INSTALL — after a single package is installed.
     *
     * RETURNS: void.
     *
     * USE FOR: reacting to a newly arrived detain/myadmin-* package — registering its module,
     * invalidating the plugin/hook cache, or applying a patch to its files.
     *
     * @param \Composer\Installer\PackageEvent $event
     * @return void
     */
    public function onPostPackageInstall(PackageEvent $event)
    {
    }

    /**
     * PackageEvents::PRE_PACKAGE_UPDATE — before a single package is updated.
     *
     * getOperation() is an UpdateOperation: getInitialPackage() is the version on disk now,
     * getTargetPackage() the one about to replace it.
     *
     * RETURNS: void.
     *
     * USE FOR: backing up package-local state, or warning on a major-version jump. Relevant
     * here because MyAdmin installs `detain/*` with preferred-install=source and
     * discard-changes=stash, so an update silently stashes uncommitted vendor work.
     *
     * @param \Composer\Installer\PackageEvent $event
     * @return void
     */
    public function onPrePackageUpdate(PackageEvent $event)
    {
    }

    /**
     * PackageEvents::POST_PACKAGE_UPDATE — after a single package is updated.
     *
     * RETURNS: void.
     *
     * USE FOR: re-applying patches or per-package setup that the new files just overwrote.
     *
     * @param \Composer\Installer\PackageEvent $event
     * @return void
     */
    public function onPostPackageUpdate(PackageEvent $event)
    {
    }

    /**
     * PackageEvents::PRE_PACKAGE_UNINSTALL — before a single package is removed.
     *
     * RETURNS: void. Throwing aborts the run.
     *
     * USE FOR: capturing anything from the package directory that must outlive it, or
     * refusing removal of a module that still has live services attached.
     *
     * @param \Composer\Installer\PackageEvent $event
     * @return void
     */
    public function onPrePackageUninstall(PackageEvent $event)
    {
    }

    /**
     * PackageEvents::POST_PACKAGE_UNINSTALL — after a single package is removed.
     *
     * RETURNS: void.
     *
     * USE FOR: de-registering the module from include/config/hooks.json and
     * include/config/plugins.json so a removed package stops being advertised. Today those
     * files are only rebuilt when an admin loads the Plugins page, and plugins.json is never
     * pruned — which is why it currently holds dozens of entries for packages that no longer
     * exist.
     *
     * @param \Composer\Installer\PackageEvent $event
     * @return void
     */
    public function onPostPackageUninstall(PackageEvent $event)
    {
    }

    /*
     * =================================================================================
     *  Script events — Composer\Script\ScriptEvents
     *
     *  All twelve deliver a Composer\Script\Event exposing getComposer(), getIO(),
     *  isDevMode(), getArguments(), getFlags(), getName() and getOriginatingEvent().
     *
     *  Unlike the plugin events above, these are ALSO declarable in a root composer.json
     *  "scripts" block — but only the ROOT package's scripts ever run, never a dependency's.
     *  That is precisely why a plugin is the right home for them: a plugin subscribes from
     *  inside vendor/, which a dependency's own `scripts` block cannot do.
     *
     *  Suppressed by --no-scripts and by the COMPOSER_SKIP_SCRIPTS env var.
     * =================================================================================
     */

    /**
     * ScriptEvents::PRE_INSTALL_CMD — before `composer install` with a lock file present.
     *
     * RETURNS: void. Throwing aborts before anything is installed.
     *
     * IMPLEMENTED: reports uncommitted work in source-installed vendor packages. WARNS only
     * — install runs on deploy targets and in CI, where aborting would do more harm than the
     * silent stash it guards against. `composer update` aborts instead; see onPreUpdateCmd().
     *
     * @param \Composer\Script\Event $event
     * @return void
     */
    public function onPreInstallCmd(Event $event)
    {
        self::reportDirtyVendor($event, false);
    }

    /**
     * ScriptEvents::POST_INSTALL_CMD — after `composer install` completes.
     *
     * RETURNS: void.
     *
     * IMPLEMENTED: busts the MCP tool cache and ensures the public_html/lib symlink exists.
     * See runPostSetup().
     *
     * Note setPermissions() below is ALREADY invoked on this event via the root
     * composer.json `scripts` block, not through this handler.
     *
     * @param \Composer\Script\Event $event
     * @return void
     */
    public function onPostInstallCmd(Event $event)
    {
        self::runPostSetup($event);
    }

    /**
     * ScriptEvents::PRE_UPDATE_CMD — before `composer update`, or before `composer install`
     * when NO lock file is present.
     *
     * RETURNS: void. Throwing aborts.
     *
     * IMPLEMENTED: ABORTS when any source-installed vendor package has uncommitted changes.
     * MyAdmin sets discard-changes=stash, so proceeding would silently stash that work with
     * no prompt and no summary. Override deliberately with:
     *
     *     MYADMIN_ALLOW_DIRTY_VENDOR=1 composer update
     *
     * @param \Composer\Script\Event $event
     * @return void
     * @throws \RuntimeException when vendor working copies are dirty and not overridden
     */
    public function onPreUpdateCmd(Event $event)
    {
        self::reportDirtyVendor($event, true);
    }

    /**
     * ScriptEvents::POST_UPDATE_CMD — after `composer update` completes.
     *
     * RETURNS: void.
     *
     * IMPLEMENTED: same chores as onPostInstallCmd(). The plugin-registry rebuild happens on
     * POST_AUTOLOAD_DUMP, which fires during update too and also on a bare
     * `composer dump-autoload`, giving a manual re-trigger without a full install.
     *
     * @param \Composer\Script\Event $event
     * @return void
     */
    public function onPostUpdateCmd(Event $event)
    {
        self::runPostSetup($event);
    }

    /**
     * ScriptEvents::PRE_STATUS_CMD — before `composer status`.
     *
     * @param \Composer\Script\Event $event
     * @return void
     */
    public function onPreStatusCmd(Event $event)
    {
    }

    /**
     * ScriptEvents::POST_STATUS_CMD — after `composer status`.
     *
     * RETURNS: void.
     *
     * USE FOR: appending MyAdmin-specific health output to `composer status`, e.g. flagging
     * packages present in vendor/ but missing from include/config/hooks.json, or entries in
     * plugins.json with no corresponding package on disk.
     *
     * @param \Composer\Script\Event $event
     * @return void
     */
    public function onPostStatusCmd(Event $event)
    {
    }

    /**
     * ScriptEvents::PRE_AUTOLOAD_DUMP — before the autoloader is written.
     *
     * FIRES: during install and update, and on a bare `composer dump-autoload`.
     * FLAGS: $event->getFlags()['optimize'] is a bool indicating a classmap-optimised dump.
     *
     * RETURNS: void.
     *
     * USE FOR: generating PHP files that must be picked up by the classmap being built right
     * now — this is the only point early enough for that.
     *
     * @param \Composer\Script\Event $event
     * @return void
     */
    public function onPreAutoloadDump(Event $event)
    {
    }

    /**
     * ScriptEvents::POST_AUTOLOAD_DUMP — after the autoloader is written.
     *
     * FLAGS: $event->getFlags()['optimize'] as above.
     *
     * RETURNS: void.
     *
     * USE FOR: work that needs the finished autoloader. The single highest-value hook for
     * MyAdmin: scan vendor/*''/*''/src/Plugin.php for getHooks() implementations and rebuild
     * include/config/hooks.json and include/config/plugins.json from scratch — replacing the
     * current situation where those files are only refreshed when an admin happens to open
     * the Plugins page. Because it also fires on a bare `composer dump-autoload`, it doubles
     * as a cheap manual trigger.
     *
     * IMPLEMENTED: rebuilds both files from the packages actually present in vendor/, which
     * prunes entries for removed or renamed packages. Writes are validated and atomic —
     * include/tf.php json_decode()s hooks.json with no null check, so a truncated write is an
     * instant site-wide fatal. Warns instead of throwing if anything goes wrong, because this
     * event also fires in CI.
     *
     * @param \Composer\Script\Event $event
     * @return void
     */
    public function onPostAutoloadDump(Event $event)
    {
        self::rebuildPluginRegistry($event);
    }

    /**
     * ScriptEvents::POST_ROOT_PACKAGE_INSTALL — after the root package is installed during
     * `composer create-project`, before its dependencies are resolved.
     *
     * RETURNS: void.
     *
     * USE FOR: seeding a brand-new MyAdmin checkout — writing a starter
     * include/config/config.inc.php before anything else runs. Never reached on an ordinary
     * install or update.
     *
     * @param \Composer\Script\Event $event
     * @return void
     */
    public function onPostRootPackageInstall(Event $event)
    {
    }

    /**
     * ScriptEvents::POST_CREATE_PROJECT_CMD — at the very end of `composer create-project`,
     * after POST_INSTALL_CMD.
     *
     * RETURNS: void.
     *
     * USE FOR: first-run setup of a fresh deployment — interactive DB credential prompts via
     * $event->getIO()->ask(), initial admin user creation, directory permissions.
     *
     * @param \Composer\Script\Event $event
     * @return void
     */
    public function onPostCreateProjectCmd(Event $event)
    {
    }

    /**
     * ScriptEvents::PRE_ARCHIVE_CMD — before `composer archive`.
     *
     * RETURNS: void.
     *
     * USE FOR: excluding local-only artefacts from a release tarball.
     *
     * @param \Composer\Script\Event $event
     * @return void
     */
    public function onPreArchiveCmd(Event $event)
    {
    }

    /**
     * ScriptEvents::POST_ARCHIVE_CMD — after `composer archive`.
     *
     * RETURNS: void.
     *
     * USE FOR: checksumming, signing or publishing the produced archive.
     *
     * @param \Composer\Script\Event $event
     * @return void
     */
    public function onPostArchiveCmd(Event $event)
    {
    }

    /*
     * =================================================================================
     *  Implementation helpers for the handlers above
     *
     *  All of these are static, take a Script\Event, and are written to be no-op-or-warn.
     *  They run in CI, where MyAdmin's workflows invoke `composer install` WITHOUT
     *  --no-scripts across four PHP-version legs plus the integration job. Anything that
     *  throws here fails those builds.
     *
     *  The one deliberate exception is reportDirtyVendor($event, true), which is *supposed*
     *  to abort — but only on update, and only when a vendor working copy is dirty, which
     *  cannot happen on a fresh CI runner.
     * =================================================================================
     */

    /**
     * The project root — the directory containing the root composer.json.
     *
     * INPUT:   $event
     * RETURNS: string|null — absolute path, or null if it cannot be determined.
     *
     * Derived from the configured vendor-dir rather than __DIR__, so it stays correct when
     * vendor-dir has been relocated.
     *
     * @param \Composer\Script\Event $event
     * @return string|null
     */
    protected static function getProjectRoot(Event $event)
    {
        $vendorDir = $event->getComposer()->getConfig()->get('vendor-dir');
        if (!is_string($vendorDir) || $vendorDir === '') {
            return null;
        }
        $root = dirname(rtrim($vendorDir, '/'));
        return is_dir($root) ? $root : null;
    }

    /**
     * Reports — and optionally refuses to proceed on — uncommitted work in vendor packages.
     *
     * INPUT:   $event
     *          $abort — true to throw when dirty (update), false to warn only (install).
     * RETURNS: void
     * THROWS:  \RuntimeException when $abort is true, packages are dirty, and
     *          MYADMIN_ALLOW_DIRTY_VENDOR is not set.
     *
     * @param \Composer\Script\Event $event
     * @param bool                   $abort
     * @return void
     */
    protected static function reportDirtyVendor(Event $event, $abort)
    {
        $root = self::getProjectRoot($event);
        if ($root === null) {
            return;
        }
        $guard = new VendorGuard($root.'/vendor');
        $dirty = $guard->findDirty();
        if ($dirty === []) {
            return;
        }
        $io = $event->getIO();
        $io->writeError(sprintf('<warning>%d vendor package(s) have uncommitted changes:</warning>', count($dirty)));
        foreach (VendorGuard::formatReport($dirty) as $line) {
            $io->writeError($line);
        }
        if (!$abort || VendorGuard::isOverridden()) {
            $io->writeError('<comment>Continuing anyway. Composer is configured with discard-changes=stash, so these may be stashed silently.</comment>');
            return;
        }
        throw new \RuntimeException(
            'Refusing to update with uncommitted changes in '.count($dirty).' vendor package(s). '
            .'Composer is configured with discard-changes=stash and would stash them silently. '
            .'Commit or stash them yourself, or re-run with '.VendorGuard::OVERRIDE_ENV.'=1 to override.'
        );
    }

    /**
     * Rebuilds include/config/hooks.json and include/config/plugins.json from vendor/.
     *
     * INPUT:   $event
     * RETURNS: void — never throws; problems are reported through the IO channel.
     *
     * Reports what changed rather than writing silently: a prune that drops dozens of stale
     * entries is something an operator needs to see.
     *
     * @param \Composer\Script\Event $event
     * @return void
     */
    protected static function rebuildPluginRegistry(Event $event)
    {
        $io = $event->getIO();
        $root = self::getProjectRoot($event);
        if ($root === null || !is_dir($root.'/include/config')) {
            // Not a MyAdmin checkout (or a partial one) — nothing to do.
            return;
        }
        try {
            $scanner = PluginScanner::forProjectRoot($root);
            $result = $scanner->rebuild();
        } catch (\Throwable $e) {
            $io->writeError(sprintf('<warning>Plugin registry rebuild failed: %s</warning>', $e->getMessage()));
            return;
        }
        if ($result['scanned'] === 0) {
            $io->writeError('<warning>Plugin scan found no plugins; leaving hooks.json and plugins.json untouched.</warning>');
            return;
        }
        foreach (['hooks', 'plugins'] as $file) {
            $r = $result[$file];
            if ($r['added'] === [] && $r['removed'] === []) {
                continue;
            }
            $io->write(sprintf(
                '<info>%s.json:</info> %d added, %d removed (%d plugins total)',
                $file,
                count($r['added']),
                count($r['removed']),
                $result['scanned']
            ));
            foreach ($r['added'] as $name) {
                $io->write('  + '.$name);
            }
            foreach ($r['removed'] as $name) {
                $io->write('  - '.$name);
            }
            if ($r['written'] !== true) {
                $io->writeError(sprintf('<warning>Could not write %s.json; it was left unchanged.</warning>', $file));
            }
        }
        if ($result['skipped'] !== [] && $io->isVerbose() === true) {
            foreach ($result['skipped'] as $package => $reason) {
                $io->write(sprintf('  <comment>skipped %s: %s</comment>', $package, $reason));
            }
        }
    }

    /**
     * Post-install/update chores that are otherwise manual steps in the docs.
     *
     * INPUT:   $event
     * RETURNS: void — never throws.
     *
     * Currently: bust the MCP tool cache, and make sure public_html/lib points at
     * node_modules.
     *
     * @param \Composer\Script\Event $event
     * @return void
     */
    protected static function runPostSetup(Event $event)
    {
        $root = self::getProjectRoot($event);
        if ($root === null) {
            return;
        }
        self::clearMcpCache($event, $root);
        self::ensureAssetSymlink($event, $root);
    }

    /**
     * Deletes generated MCP tool-cache files.
     *
     * INPUT:   $event, $root — project root.
     * RETURNS: int — number of files removed.
     *
     * Replaces the `rm -f logs/mcp_cache/mcp_tools_*.php` step documented in CLAUDE.md and
     * docs/agents.md. Safe: the cache is keyed on a hash of the spec and is rebuilt on the
     * next request by whichever MCP entry point needs it.
     *
     * @param \Composer\Script\Event $event
     * @param string                 $root
     * @return int
     */
    protected static function clearMcpCache(Event $event, $root)
    {
        $files = glob($root.'/logs/mcp_cache/mcp_tools_*.php');
        if ($files === false || $files === []) {
            return 0;
        }
        $removed = 0;
        foreach ($files as $file) {
            if (@unlink($file)) {
                $removed++;
            }
        }
        if ($removed > 0) {
            $event->getIO()->write(sprintf('<info>Cleared %d MCP tool cache file(s).</info>', $removed));
        }
        return $removed;
    }

    /**
     * Ensures public_html/lib points at node_modules.
     *
     * INPUT:   $event, $root — project root.
     * RETURNS: bool — true if the link exists or was created, false otherwise.
     *
     * public_html/lib is gitignored and normally created by package.json's `postinstall`
     * hook, so a fresh clone followed by `composer install` but no `yarn install` leaves
     * every JS and CSS asset 404ing.
     *
     * Only ever CREATES a missing link — never replaces or deletes an existing path, so it
     * cannot clobber a real directory someone put there on purpose.
     *
     * @param \Composer\Script\Event $event
     * @param string                 $root
     * @return bool
     */
    protected static function ensureAssetSymlink(Event $event, $root)
    {
        $link = $root.'/public_html/lib';
        $target = $root.'/node_modules';
        if (file_exists($link) || is_link($link)) {
            return true;
        }
        if (!is_dir($target)) {
            $event->getIO()->writeError('<warning>public_html/lib is missing and node_modules does not exist; run "yarn install" or frontend assets will 404.</warning>');
            return false;
        }
        if (!is_dir(dirname($link))) {
            return false;
        }
        if (@symlink('../node_modules', $link)) {
            $event->getIO()->write('<info>Created public_html/lib -> ../node_modules symlink.</info>');
            return true;
        }
        $event->getIO()->writeError('<warning>Could not create the public_html/lib symlink; run "yarn install".</warning>');
        return false;
    }

    /*
     * =================================================================================
     *  Permission helpers
     *
     *  These are static so a consuming project can reference them straight from its
     *  composer.json `scripts` block without instantiating the plugin — which is exactly how
     *  MyAdmin uses them:
     *
     *      "scripts": {
     *          "post-install-cmd": ["@postSetup"],
     *          "post-update-cmd":  ["@postSetup"],
     *          "postSetup":        ["@setPermissions", "@getLocaleData"],
     *          "setPermissions":   ["MyAdmin\\Plugins\\Plugin::setPermissions"]
     *      }
     *
     *  Because Composer resolves script callables through the PROJECT autoloader rather than
     *  the plugin allowlist, this path runs even while the package is blocked by
     *  config.allow-plugins. It is the only part of this class that currently executes in
     *  MyAdmin.
     *
     *  The paths come from the ROOT package's composer.json:
     *
     *      "extra": {
     *          "writable-dirs":  ["logs", "logs/smarty_cache", ...],
     *          "writable-files": ["include/config/hooks.json", ...]
     *      }
     * =================================================================================
     */

    /**
     * Creates and permissions every path listed in the root package's `extra.writable-dirs`
     * and `extra.writable-files`.
     *
     * INPUT:   $event — the Script\Event; the root package supplies the path lists.
     * RETURNS: void. Never throws — a path that cannot be permissioned is reported and the
     *          run continues.
     *
     * RESILIENCE: each path is handled independently. A previous revision let the first
     * failure escape the loop, so one missing writable-file silently skipped every remaining
     * path in the list.
     *
     * @param \Composer\Script\Event $event
     * @return void
     */
    public static function setPermissions(Event $event)
    {
        if ('WIN' === strtoupper(substr(PHP_OS, 0, 3))) {
            $event->getIO()->write('<info>No permissions setup is required on Windows.</info>');
            return;
        }
        $dirs = self::getWritableDirs($event);
        $files = self::getWritableFiles($event);
        if ($dirs === [] && $files === []) {
            if ($event->getIO()->isVerbose() === true) {
                $event->getIO()->write('<info>No extra.writable-dirs or extra.writable-files declared; nothing to do.</info>');
            }
            return;
        }
        $event->getIO()->write('Setting up permissions.');
        self::setPermissionsChmod($event);
    }

    /**
     * Reads the root package's `extra.writable-dirs`.
     *
     * INPUT:   $event
     * RETURNS: array<int, string> — directory paths, or an empty array when the key is
     *          absent or malformed.
     *
     * Returns [] rather than throwing so a project that declares neither key gets a clean
     * no-op. This matters because MyAdmin's CI runs `composer install` WITHOUT --no-scripts.
     *
     * @param \Composer\Script\Event $event
     * @return array<int, string>
     */
    public static function getWritableDirs(Event $event)
    {
        return self::getExtraPathList($event, 'writable-dirs');
    }

    /**
     * Reads the root package's `extra.writable-files`.
     *
     * INPUT:   $event
     * RETURNS: array<int, string> — file paths, or an empty array when absent/malformed.
     *
     * @param \Composer\Script\Event $event
     * @return array<int, string>
     */
    public static function getWritableFiles(Event $event)
    {
        return self::getExtraPathList($event, 'writable-files');
    }

    /**
     * Reads a list-of-strings key out of the root package's `extra` block.
     *
     * INPUT:   $event, $key — the `extra` key to read.
     * RETURNS: array<int, string> — re-indexed, with non-string and empty entries dropped.
     *
     * @param \Composer\Script\Event $event
     * @param string                 $key
     * @return array<int, string>
     */
    protected static function getExtraPathList(Event $event, $key)
    {
        $extra = $event->getComposer()->getPackage()->getExtra();
        if (!isset($extra[$key]) || !is_array($extra[$key])) {
            return [];
        }
        return array_values(array_filter($extra[$key], function ($path) {
            return is_string($path) && $path !== '';
        }));
    }

    /**
     * Permissions every declared path using setfacl.
     *
     * INPUT:   $event
     * RETURNS: void — failures are reported per path, never thrown.
     *
     * Not currently reached: setPermissions() calls the chmod variant directly. Kept because
     * setfacl is the correct tool where it is available — it grants the webserver user access
     * without making paths world-writable.
     *
     * @param \Composer\Script\Event $event
     * @return void
     */
    public static function setPermissionsSetfacl(Event $event)
    {
        $http_user = self::getHttpdUser($event);
        foreach (self::getWritableDirs($event) as $path) {
            self::guardPath($event, $path, function () use ($event, $http_user, $path) {
                self::SetfaclPermissionsSetter($event, $http_user, $path);
            });
        }
        foreach (self::getWritableFiles($event) as $path) {
            self::guardPath($event, $path, function () use ($event, $http_user, $path) {
                self::ChmodPermissionsSetter($event, $http_user, $path, 'file');
            });
        }
    }

    /**
     * Permissions every declared path using chmod/chown.
     *
     * INPUT:   $event
     * RETURNS: void — failures are reported per path, never thrown.
     *
     * @param \Composer\Script\Event $event
     * @return void
     */
    public static function setPermissionsChmod(Event $event)
    {
        $http_user = self::getHttpdUser($event);
        if ($http_user === null && $event->getIO()->isVerbose() === true) {
            $event->getIO()->write('<comment>Could not determine the webserver user; skipping chown and setting mode only.</comment>');
        }
        foreach (self::getWritableDirs($event) as $path) {
            self::guardPath($event, $path, function () use ($event, $http_user, $path) {
                self::ChmodPermissionsSetter($event, $http_user, $path, 'dir');
            });
        }
        foreach (self::getWritableFiles($event) as $path) {
            self::guardPath($event, $path, function () use ($event, $http_user, $path) {
                self::ChmodPermissionsSetter($event, $http_user, $path, 'file');
            });
        }
    }

    /**
     * Runs one path's permission work, converting any failure into a warning.
     *
     * INPUT:   $event, $path — the path being processed, used in the warning text.
     *          $work — callable performing the work.
     * RETURNS: bool — true on success, false when the work threw.
     *
     * WHY: the permission list is a set of independent paths. One unwritable entry must not
     * prevent the rest from being processed, which is exactly what used to happen.
     *
     * @param \Composer\Script\Event $event
     * @param string                 $path
     * @param callable               $work
     * @return bool
     */
    protected static function guardPath(Event $event, $path, callable $work)
    {
        try {
            $work();
            return true;
        } catch (\Exception $e) {
            $event->getIO()->writeError(sprintf('<warning>Could not set permissions on %s: %s</warning>', $path, $e->getMessage()));
            return false;
        }
    }

    /**
     * Returns the user the webserver runs as, or null when it cannot be determined.
     *
     * INPUT:   $event
     * RETURNS: string|null — the webserver username, or null if no webserver is running or
     *          `ps` is unavailable.
     *
     * Matches on the process's own command name rather than the full `ps aux` line. The
     * previous regex was end-anchored against `ps aux` output, so it required the line to
     * END with "apache"/"nginx"/etc. Real lines end with the command tail
     * ("/usr/sbin/apache2 -k start"), so it matched nothing and fell off the end of the
     * function returning null implicitly — which then got interpolated into shell commands.
     *
     * `ps` is a Unix concept and there is no webserver user to find on Windows, so returning
     * null there is correct. The redirect target still comes from
     * {@see VendorGuard::nullDevice()} rather than a hardcoded `/dev/null`, because cmd.exe
     * fails the redirection itself and prints "The system cannot find the path specified." to
     * the console on every call — noise in an otherwise clean Windows CI leg.
     *
     * @param \Composer\Script\Event $event
     * @return string|null
     */
    public static function getHttpdUser(Event $event)
    {
        try {
            $ps = self::runProcess($event, 'ps axo user:32,comm 2>'.VendorGuard::nullDevice());
        } catch (\Exception $e) {
            return null;
        }
        $needles = ['apache', 'apache2', 'httpd', 'httpd.worker', '_www', 'www-data', 'nginx', 'lshttpd', 'litespeed', 'php-fpm'];
        foreach (explode(PHP_EOL, $ps) as $line) {
            $parts = preg_split('/\s+/', trim($line), 2);
            if (count($parts) < 2) {
                continue;
            }
            list($user, $comm) = $parts;
            $comm = trim($comm);
            if ($user === 'root' || $user === 'USER' || $user === '') {
                continue;
            }
            foreach ($needles as $needle) {
                if ($comm === $needle || strpos($comm, $needle) === 0) {
                    return $user;
                }
            }
        }
        return null;
    }

    /**
     * Returns the username the current process is running as.
     *
     * RETURNS: string|null
     *
     * $_SERVER['USER'] is set for an interactive shell but UNSET under `env -i`, cron,
     * systemd and CI. The previous code interpolated it unconditionally, producing
     * `chown :www-data <path>` in those contexts. posix_geteuid() is authoritative where the
     * posix extension is available; get_current_user() is the portable fallback.
     *
     * @return string|null
     */
    protected static function getRunAsUser()
    {
        if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
            $info = posix_getpwuid(posix_geteuid());
            if (is_array($info) && isset($info['name']) && $info['name'] !== '') {
                return $info['name'];
            }
        }
        if (isset($_SERVER['USER']) && $_SERVER['USER'] !== '') {
            return $_SERVER['USER'];
        }
        $user = get_current_user();
        return $user !== '' ? $user : null;
    }

    /**
     * Grants the webserver user and the running user access to $path using setfacl.
     *
     * INPUT:   $event, $http_user (may be null), $path
     * RETURNS: void
     * THROWS:  \Exception when setfacl exits non-zero.
     *
     * @param \Composer\Script\Event $event
     * @param string|null            $http_user
     * @param string                 $path
     * @return void
     */
    public static function SetfaclPermissionsSetter(Event $event, $http_user, $path)
    {
        self::EnsureDirExists($event, $path);
        $spec = [];
        if ($http_user !== null && $http_user !== '') {
            $spec[] = '-m u:'.escapeshellarg($http_user).':rwX';
        }
        $runAs = self::getRunAsUser();
        if ($runAs !== null) {
            $spec[] = '-m u:'.escapeshellarg($runAs).':rwX';
        }
        if ($spec === []) {
            return;
        }
        self::runProcess($event, 'setfacl '.implode(' ', $spec).' '.escapeshellarg($path));
        self::runProcess($event, 'setfacl -d '.implode(' ', $spec).' '.escapeshellarg($path));
    }

    /**
     * Sets mode and ownership on $path using chmod/chown.
     *
     * INPUT:   $event
     *          $http_user — webserver username, or null when undetectable. When null the
     *                       chown is SKIPPED rather than emitted with an empty group.
     *          $path      — the path to act on; created first if missing.
     *          $type      — 'dir' (default) or 'file'.
     * RETURNS: void
     * THROWS:  \Exception when a command exits non-zero. Callers wrap this per path.
     *
     * Directories get 0777 so the webserver can create files in them. Files get 0666: they
     * are data, and the previous blanket `chmod 777` left include/config/hooks.json and
     * plugins.json marked executable.
     *
     * @param \Composer\Script\Event $event
     * @param string|null            $http_user
     * @param string                 $path
     * @param string                 $type
     * @return void
     */
    public static function ChmodPermissionsSetter(Event $event, $http_user, $path, $type = 'dir')
    {
        if ($type == 'dir') {
            self::EnsureDirExists($event, $path);
            $mode = '777';
        } else {
            self::EnsureFileExists($event, $path);
            $mode = '666';
        }
        self::runProcess($event, 'chmod '.$mode.' '.escapeshellarg($path));
        $runAs = self::getRunAsUser();
        if ($runAs !== null && $http_user !== null && $http_user !== '') {
            self::runProcess($event, 'chown '.escapeshellarg($runAs.':'.$http_user).' '.escapeshellarg($path));
        }
    }

    /**
     * checks if the given directory exists and if not tries to create it.
     *
     * @param Event $event
     * @param string $path the directory
     * @throws \Exception
     */
    public static function EnsureDirExists(Event $event, $path)
    {
        if (!is_dir($path)) {
            mkdir($path, 0777, true);
            if (!is_dir($path)) {
                throw new \Exception('Path Not Found: '.$path);
            }
            if ($event->getIO()->isVerbose() === true) {
                $event->getIO()->write(sprintf('Created Directory <info>%s</info>', $path));
            }
        }
    }

    /**
     * Creates $path as an empty file if it does not already exist.
     *
     * INPUT:   $event, $path — the file path.
     * RETURNS: void
     * THROWS:  \Exception when the file is absent and cannot be created.
     *
     * The guard is on the FILE, not on its parent directory. The previous version tested
     * `if (!is_dir(dirname($path)))`, so whenever the parent directory already existed — the
     * normal case — the entire body including touch() was skipped and the file was never
     * created. The following `chmod` then failed on a missing path, which under the old
     * control flow aborted every remaining path in the permission run.
     *
     * @param \Composer\Script\Event $event
     * @param string                 $path
     * @return void
     */
    public static function EnsureFileExists(Event $event, $path)
    {
        if (file_exists($path)) {
            return;
        }
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
            if (!is_dir($dir)) {
                throw new \Exception('Could not create parent directory: '.$dir);
            }
        }
        touch($path);
        if (!file_exists($path)) {
            throw new \Exception('File Not Found: '.$path);
        }
        if ($event->getIO()->isVerbose() === true) {
            $event->getIO()->write(sprintf('Created File <info>%s</info>', $path));
        }
    }

    /**
     * Runs a shell command, returning its stdout and failing on a non-zero exit.
     *
     * INPUT:   $event, $commandline — the command to run. Callers are responsible for
     *          escaping any interpolated values (see escapeshellarg use above).
     * RETURNS: string — stdout, newline-joined.
     * THROWS:  \Exception when the command exits non-zero. The message now names the command
     *          and includes its output; previously it said only "Returned Error Code 1",
     *          which made the permission step effectively undebuggable.
     *
     * @param \Composer\Script\Event $event
     * @param string                 $commandline
     * @return string
     */
    public static function runProcess(Event $event, $commandline)
    {
        if ($event->getIO()->isVerbose() === true) {
            $event->getIO()->write(sprintf('Running <info>%s</info>', $commandline));
        }
        $output = [];
        $return = 0;
        exec($commandline, $output, $return);
        if ($return != 0) {
            throw new \Exception(sprintf('Command "%s" exited %d: %s', $commandline, $return, trim(implode(' ', $output))));
        }
        return implode(PHP_EOL, $output);
    }
}
