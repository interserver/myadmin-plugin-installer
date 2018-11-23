<?php
/**
 * Plugins Management
 * @author Joe Huss <detain@interserver.net>
 * @copyright 2019
 * @package MyAdmin
 * @category Plugins
 */

namespace MyAdmin\Plugins;

use Composer\Plugin\Capability\CommandProvider as CommandProviderCapability;
use MyAdmin\Plugins\Command\Command;
use MyAdmin\Plugins\Command\Parse;
use MyAdmin\Plugins\Command\CreateUser;
use MyAdmin\Plugins\Command\UpdatePlugins;
use MyAdmin\Plugins\Command\SetPermissions;

/**
 * Class CommandProvider
 *
 * @package MyAdmin\Plugins
 */
class CommandProvider implements CommandProviderCapability
{
	/**
	 * @return array
	 */
	public function getCommands()
	{
		return [
			new Command,
			new Parse,
			new CreateUser,
			new UpdatePlugins,
			new SetPermissions
		];
	}
}
