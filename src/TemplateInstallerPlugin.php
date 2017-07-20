<?php
/**
 * Plugins Management
 * Last Changed: $LastChangedDate: 2017-04-27 04:45:04 -0400 (Thu, 27 Apr 2017) $
 * @author detain
 * @copyright 2017
 * @package MyAdmin
 * @category Plugins
 */

namespace MyAdmin\PluginInstaller;

use Composer\Composer;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;

/**
 * Class TemplateInstallerPlugin
 *
 * @package MyAdmin\PluginInstaller
 */
class TemplateInstallerPlugin implements PluginInterface {
	/**
	 * @param \Composer\Composer       $composer
	 * @param \Composer\IO\IOInterface $io
	 */
	public function activate(Composer $composer, IOInterface $io) {
        $installer = new TemplateInstaller($io, $composer);
        $composer->getInstallationManager()->addInstaller($installer);
    }
}
