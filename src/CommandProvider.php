<?php

namespace MyAdmin\PluginInstaller;

use Composer\Plugin\Capability\CommandProvider as CommandProviderCapability;
use MyAdmin\PluginInstaller\Command;

class CommandProvider implements CommandProviderCapability
{
	public function getCommands()
	{
		return array(new Command);
	}
}
