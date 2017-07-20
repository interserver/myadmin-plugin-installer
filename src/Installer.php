<?php
/**
 * Plugins Management
 * Last Changed: $LastChangedDate: 2017-04-27 04:45:04 -0400 (Thu, 27 Apr 2017) $
 * @author detain
 * @copyright 2017
 * @package MyAdmin
 * @category Plugins
 */

/**
 * MyAdmin Installer Plugin
 *
 * The InstallerInterface class defines the following methods (please see the source for the exact signature):
 *   supports(), here you test whether the passed type matches the name that you declared for this installer (see the example).
 *   isInstalled(), determines whether a supported package is installed or not.
 *   install(), here you can determine the actions that need to be executed upon installation.
 *   update(), here you define the behavior that is required when Composer is invoked with the update argument.
 *   uninstall(), here you can determine the actions that need to be executed when the package needs to be removed.
 *   getInstallPath(), this method should return the location where the package is to be installed, relative from the location of composer.json.
 *
 * Implements https://github.com/composer/composer/blob/master/src/Composer/Installer/InstallerInterface.php
 *
 */

namespace MyAdmin\PluginInstaller;

use Composer\Composer;
use Composer\Installer\BinaryInstaller;
use Composer\Installer\LibraryInstaller;
use Composer\IO\IOInterface;
use Composer\Package\PackageInterface;
use Composer\Util\Filesystem;
use Composer\Util\Silencer;
use Composer\Repository\InstalledRepositoryInterface;

/**
 * Class Installer
 *
 * @package MyAdmin\PluginInstaller
 */
class Installer extends LibraryInstaller {
	protected $composer;
	protected $vendorDir;
	protected $templateDir;
	protected $binDir;
	protected $downloadManager;
	protected $io;
	protected $type;
	protected $filesystem;
	protected $binCompat;
	protected $binaryInstaller;

	/**
	 * Initializes library installer.
	 *
	 * @param IOInterface     $io
	 * @param Composer        $composer
	 * @param string          $type
	 * @param Filesystem      $filesystem
	 * @param BinaryInstaller $binaryInstaller
	 */
	public function __construct(IOInterface $io, Composer $composer, $type = 'library', Filesystem $filesystem = NULL, BinaryInstaller $binaryInstaller = NULL) {
		$this->composer = $composer;
		$this->downloadManager = $composer->getDownloadManager();
		$this->io = $io;
		$this->type = $type;
		$this->filesystem = $filesystem ?: new Filesystem();
		$this->vendorDir = rtrim($composer->getConfig()->get('vendor-dir'), '/');
		$this->templateDir = $this->vendorDir.'/../public_html/templates';
		$this->binaryInstaller = $binaryInstaller ?: new BinaryInstaller($this->io, rtrim($composer->getConfig()->get('bin-dir'), '/'), $composer->getConfig()->get('bin-compat'), $this->filesystem);
	}

	/**
	 * {@inheritDoc}
	 */
	public function supports($packageType) {
		return in_array($packageType, [
			'myadmin-template',
			'myadmin-module',
			'myadmin-plugin',
			'myadmin-menu'
		]);
		//return $packageType === $this->type || NULL === $this->type;
	}


	/**
	 * Checks that provided package is installed.
	 *
	 * @param InstalledRepositoryInterface $repo    repository in which to check
	 * @param PackageInterface             $package package instance
	 *
	 * @return bool
	 */
	public function isInstalled(InstalledRepositoryInterface $repo, PackageInterface $package) {
		return parent::isInstalled($repo, $package);
		//return $repo->hasPackage($package) && is_readable($this->getInstallPath($package));
	}

	/**
	 * {@inheritDoc}
	 */
	public function install(InstalledRepositoryInterface $repo, PackageInterface $package) {
		$this->initializeVendorDir();
		$downloadPath = $this->getInstallPath($package);
		// remove the binaries if it appears the package files are missing
		if (!is_readable($downloadPath) && $repo->hasPackage($package))
			$this->binaryInstaller->removeBinaries($package);
		$this->installCode($package);
		$this->binaryInstaller->installBinaries($package, $this->getInstallPath($package));
		if (!$repo->hasPackage($package))
			$repo->addPackage(clone $package);
	}
	/**
	 * {@inheritDoc}
	 */
	public function update(InstalledRepositoryInterface $repo, PackageInterface $initial, PackageInterface $target) {
		if (!$repo->hasPackage($initial))
			throw new \InvalidArgumentException('Package is not installed: '.$initial);
		$this->initializeVendorDir();
		$this->binaryInstaller->removeBinaries($initial);
		$this->updateCode($initial, $target);
		$this->binaryInstaller->installBinaries($target, $this->getInstallPath($target));
		$repo->removePackage($initial);
		if (!$repo->hasPackage($target))
			$repo->addPackage(clone $target);
	}
	/**
	 * {@inheritDoc}
	 */
	public function uninstall(InstalledRepositoryInterface $repo, PackageInterface $package) {
		if (!$repo->hasPackage($package))
			throw new \InvalidArgumentException('Package is not installed: '.$package);
		$this->removeCode($package);
		$this->binaryInstaller->removeBinaries($package);
		$repo->removePackage($package);
		$downloadPath = $this->getPackageBasePath($package);
		if (mb_strpos($package->getName(), '/')) {
			$packageVendorDir = dirname($downloadPath);
			if (is_dir($packageVendorDir) && $this->filesystem->isDirEmpty($packageVendorDir))
				Silencer::call('rmdir', $packageVendorDir);
		}
	}

	/**
	 * {@inheritDoc}
	 */
	public function getInstallPath(PackageInterface $package) {
		if ($this->type == 'myadmin-template') {
			$this->initializeTemplateDir();
			$basePath = ($this->templateDir ? $this->templateDir.'/' : '') . $package->getPrettyName();
		} else {
			$this->initializeVendorDir();
			$basePath = ($this->vendorDir ? $this->vendorDir.'/' : '') . $package->getPrettyName();
		}
		$targetDir = $package->getTargetDir();
		return $basePath . ($targetDir ? '/'.$targetDir : '');
	}

	/**
	 * Make sure binaries are installed for a given package.
	 *
	 * @param PackageInterface $package Package instance
	 */
	public function ensureBinariesPresence(PackageInterface $package) {
		$this->binaryInstaller->installBinaries($package, $this->getInstallPath($package), FALSE);
	}

	/**
	 * Returns the base path of the package without target-dir path
	 *
	 * It is used for BC as getInstallPath tends to be overridden by
	 * installer plugins but not getPackageBasePath
	 *
	 * @param  PackageInterface $package
	 * @return string
	 */
	protected function getPackageBasePath(PackageInterface $package) {
		$installPath = $this->getInstallPath($package);
		$targetDir = $package->getTargetDir();
		if ($targetDir)
			return preg_replace('{/*'.str_replace('/', '/+', preg_quote($targetDir)).'/?$}', '', $installPath);
		return $installPath;
	}

	/**
	 * @param \Composer\Package\PackageInterface $package
	 */
	protected function installCode(PackageInterface $package) {
		$downloadPath = $this->getInstallPath($package);
		$this->downloadManager->download($package, $downloadPath);
	}

	/**
	 * @param \Composer\Package\PackageInterface $initial
	 * @param \Composer\Package\PackageInterface $target
	 */
	protected function updateCode(PackageInterface $initial, PackageInterface $target) {
		$initialDownloadPath = $this->getInstallPath($initial);
		$targetDownloadPath = $this->getInstallPath($target);
		if ($targetDownloadPath !== $initialDownloadPath) {
			// if the target and initial dirs intersect, we force a remove + install
			// to avoid the rename wiping the target dir as part of the initial dir cleanup
			if (mb_substr($initialDownloadPath, 0, mb_strlen($targetDownloadPath)) === $targetDownloadPath
				|| mb_substr($targetDownloadPath, 0, mb_strlen($initialDownloadPath)) === $initialDownloadPath
			) {
				$this->removeCode($initial);
				$this->installCode($target);
				return;
			}
			$this->filesystem->rename($initialDownloadPath, $targetDownloadPath);
		}
		$this->downloadManager->update($initial, $target, $targetDownloadPath);
	}

	/**
	 * @param \Composer\Package\PackageInterface $package
	 */
	protected function removeCode(PackageInterface $package) {
		$downloadPath = $this->getPackageBasePath($package);
		$this->downloadManager->remove($package, $downloadPath);
	}

	protected function initializeVendorDir() {
		$this->filesystem->ensureDirectoryExists($this->vendorDir);
		$this->vendorDir = realpath($this->vendorDir);
	}

	protected function initializeTemplateDir() {
		$this->filesystem->ensureDirectoryExists($this->templateDir);
		$this->templateDir = realpath($this->templateDir);
	}
}
