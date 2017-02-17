<?php
/**
 * MyAdmin MyInstaller Plugin
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

namespace MyAdmin\Plugins;

use Composer\Package\PackageInterface;
use Composer\Installer\LibraryInstaller;

class MyInstaller extends LibraryInstaller {
	/**
	 * Returns the installation path of a package
	 *
	 * @param  PackageInterface $package
	 * @return string		   path
	 */
	public function getInstallPath(PackageInterface $package) {
		$strip = 'myadmin/template-';
		$cut = strlen($strip) - 1;
		$prefix = substr($package->getPrettyName(), 0, $cut);
		if ($strip !== $prefix)
			throw new \InvalidArgumentException("Unable to install template, myadmin templates should always start their package name with '{$strip}'");
		return 'public_html/templates/'.substr($package->getPrettyName(), $cut);
	}

	/**
	 * Decides if the installer supports the given type
	 *
	 * @param  string $packageType
	 * @return bool
	 */
	public function supports($packageType) {
		return 'myadmin-template' === $packageType;
	}

	/**
	 * Checks that provided package is installed.
	 *
	 * @param InstalledRepositoryInterface $repo	repository in which to check
	 * @param PackageInterface			 $package package instance
	 *
	 * @return bool
	 */
	public function isInstalled(InstalledRepositoryInterface $repo, PackageInterface $package) {
		parent::isInstalled($repo, $package);
	}

	/**
	 * Installs specific package.
	 *
	 * @param InstalledRepositoryInterface $repo	repository in which to check
	 * @param PackageInterface			 $package package instance
	 */
	public function install(InstalledRepositoryInterface $repo, PackageInterface $package) {
		parent::install($repo, $package);
	}

	/**
	 * Updates specific package.
	 *
	 * @param InstalledRepositoryInterface $repo	repository in which to check
	 * @param PackageInterface			 $initial already installed package version
	 * @param PackageInterface			 $target  updated version
	 *
	 * @throws InvalidArgumentException if $initial package is not installed
	 */
	public function update(InstalledRepositoryInterface $repo, PackageInterface $initial, PackageInterface $target) {
		parent::update($repo, $initial, $target);
	}

	/**
	 * Uninstalls specific package.
	 *
	 * @param InstalledRepositoryInterface $repo	repository in which to check
	 * @param PackageInterface			 $package package instance
	 */
	public function uninstall(InstalledRepositoryInterface $repo, PackageInterface $package) {
		parent::uninstall($repo, $package);
	}

}
