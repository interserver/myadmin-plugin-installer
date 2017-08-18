<?php
/**
 * Plugins Management
 * @author Joe Huss <detain@interserver.net>
 * @copyright 2017
 * @package MyAdmin
 * @category Plugins
 */

namespace MyAdmin\Plugins;

use Composer\Plugin\Capability\CommandProvider as CommandProviderCapability;
use MyAdmin\Plugins\Command\Command;
use MyAdmin\Plugins\Command\Parse;
use MyAdmin\Plugins\Command\CreateUser;
use MyAdmin\Plugins\Command\UpdatePlugins;

/**
 * Class CommandProvider
 *
 * @package MyAdmin\Plugins
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
