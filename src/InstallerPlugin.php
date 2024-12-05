<?php
/**
 * Plugins Management
 * @author Joe Huss <detain@interserver.net>
 * @copyright 2025
 * @package MyAdmin
 * @category Plugins
 */

/**
 * MyAdmin Installer Plugin
 * Implements https://github.com/composer/composer/blob/master/src/Composer/Plugin/PluginInterface.php
 */

namespace MyAdmin\Plugins;

use Composer\Composer;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;

/**
 * MyAdmin Installer Plugin
 */
class InstallerPlugin implements PluginInterface
{
    /**
     * Apply plugin modifications to Composer
     *
     * @param \Composer\Composer	$composer
     * @param \Composer\IO\IOInterface $io
     */
    public function activate(Composer $composer, IOInterface $io)
    {
        $installer = new Installer($io, $composer);
        $composer->getInstallationManager()->addInstaller($installer);
    }
}
