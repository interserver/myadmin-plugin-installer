<?php

namespace myAdmin\Composer;

use Composer\Package\PackageInterface;
use Composer\Installer\LibraryInstaller;

class TemplateInstaller extends LibraryInstaller
{
    /**
     * {@inheritDoc}
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
