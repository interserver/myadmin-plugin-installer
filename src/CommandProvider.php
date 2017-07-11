<?php

namespace MyAdmin\PluginInstaller;

use Composer\Plugin\Capability\CommandProvider as CommandProviderCapability;
use MyAdmin\PluginInstaller\Command\Command;
use MyAdmin\PluginInstaller\Command\CreateUser;

class CommandProvider implements CommandProviderCapability {
	public function getCommands() {
		return [
			new Command,
			new CreateUser
		];
	}
}
