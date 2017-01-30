<?php
/**
 * Template Installer Plugin
 * Implements https://github.com/composer/composer/blob/master/src/Composer/Plugin/PluginInterface.php
 */

namespace detain\myAdmin\Composer;

use Composer\Composer;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;

class TemplateInstallerPlugin implements PluginInterface
{
    public function activate(Composer $composer, IOInterface $io)
    {
        $installer = new TemplateInstaller($io, $composer);
        $composer->getInstallationManager()->addInstaller($installer);
    }
}
