<?php
/**
 * Plugins Management
 * @author Joe Huss <detain@interserver.net>
 * @copyright 2017
 * @package MyAdmin
 * @category Plugins
 */

namespace MyAdmin\PluginInstaller;

use Composer\Plugin\Capability\CommandProvider as CommandProviderCapability;
use MyAdmin\PluginInstaller\Command\Command;
use MyAdmin\PluginInstaller\Command\Parse;
use MyAdmin\PluginInstaller\Command\CreateUser;
use MyAdmin\PluginInstaller\Command\UpdatePlugins;

/**
 * Class CommandProvider
 *
 * @package MyAdmin\PluginInstaller
 */
class CommandProvider implements CommandProviderCapability {
	/**
	 * @return array
	 */
	public function getCommands() {
		return [
			new Command,
			new Parse,
			new CreateUser,
			new UpdatePlugins
		];
	}
}
