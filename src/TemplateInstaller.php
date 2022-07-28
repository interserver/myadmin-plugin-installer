<?php
/**
 * Plugins Management
 * @author Joe Huss <detain@interserver.net>
 * @copyright 2019
 * @package MyAdmin
 * @category Plugins
 */

namespace MyAdmin\Plugins;

use Composer\Package\PackageInterface;
use Composer\Installer\LibraryInstaller;

/**
 * Class TemplateInstaller
 *
 * @package MyAdmin\Plugins
 */
class TemplateInstaller extends LibraryInstaller
{
    /**
     * {@inheritDoc}
     * @throws \InvalidArgumentException
     */
    public function getInstallPath(PackageInterface $package)
    {
        $prefix = mb_substr($package->getPrettyName(), 0, 23);
        if ('myadmin/template-' !== $prefix) {
            throw new \InvalidArgumentException(
                'Unable to install template, myadmin templates '
                .'should always start their package name with '
                .'"myadmin/template-"'
            );
        }
        return 'data/templates/'.mb_substr($package->getPrettyName(), 23);
    }

    /**
     * {@inheritDoc}
     */
    public function supports($packageType)
    {
        return 'myadmin-template' === $packageType;
    }
}
