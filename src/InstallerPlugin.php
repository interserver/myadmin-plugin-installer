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
 * Implements https://github.com/composer/composer/blob/master/src/Composer/Plugin/PluginInterface.php
 */

namespace MyAdmin\PluginInstaller;

use Composer\Composer;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;

/**
 * MyAdmin Installer Plugin
 */
class InstallerPlugin implements PluginInterface {
	/**
	 * Apply plugin modifications to Composer
	 *
	 * @param \Composer\Composer	$composer
	 * @param \Composer\IO\IOInterface $io
	 */
	public function activate(Composer $composer, IOInterface $io) {
		$installer = new Installer($io, $composer);
		$composer->getInstallationManager()->addInstaller($installer);
	}
}
