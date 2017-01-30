<?php
/**
 * Template Installer Plugin
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

namespace detain\myAdmin\Composer;

use Composer\Package\PackageInterface;
use Composer\Installer\LibraryInstaller;

class TemplateInstaller extends LibraryInstaller
{
    /**
     * {@inheritDoc}
     */
    public function getInstallPath(PackageInterface $package)
    {
	$strip = 'myadmin/template-';
	$cut = strlen($strip) - 1;
        $prefix = substr($package->getPrettyName(), 0, $cut);
        if ($strip !== $prefix)
            throw new \InvalidArgumentException("Unable to install template, myadmin templates should always start their package name with '{$strip}'");
        return 'public_html/templates/'.substr($package->getPrettyName(), $cut);
    }

    /**
     * {@inheritDoc}
     */
    public function supports($packageType)
    {
        return 'myadmin-template' === $packageType;
    }
}
