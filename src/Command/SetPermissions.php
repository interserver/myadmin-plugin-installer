<?php
/**
 * Plugins Management
 * @author Joe Huss <detain@interserver.net>
 * @copyright 2019
 * @package MyAdmin
 * @category Plugins
 */

namespace MyAdmin\Plugins\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Composer\Command\BaseCommand;

/**
 * Class SetPermissions
 *
 * @package MyAdmin\Plugins\Command
 */
class SetPermissions extends BaseCommand
{
	protected function configure()
	{
		$this
			->setName('myadmin:set-permissions') // the name of the command (the part after "bin/console")
			->setDescription('Creates and Sets Writable Permissions on Required Dirs') // the short description shown while running "php bin/console list"
			->setHelp('Creates and Sets Writable Permissions on Required Directories specified in the writable-dirs composer extra options section.'); // the full command description shown when running the command with the "--help" option
	}

	/** (optional)
	 * This method is executed before the interact() and the execute() methods.
	 * Its main purpose is to initialize variables used in the rest of the command methods.
	 *
	 * @param \Symfony\Component\Console\Input\InputInterface   $input
	 * @param \Symfony\Component\Console\Output\OutputInterface $output
	 */
	protected function initialize(InputInterface $input, OutputInterface $output)
	{
	}

	/** (optional)
	 * This method is executed after initialize() and before execute().
	 * Its purpose is to check if some of the options/arguments are missing and interactively
	 * ask the user for those values. This is the last place where you can ask for missing
	 * options/arguments. After this command, missing options/arguments will result in an error.
	 *
	 * @param \Symfony\Component\Console\Input\InputInterface   $input
	 * @param \Symfony\Component\Console\Output\OutputInterface $output
	 */
	protected function interact(InputInterface $input, OutputInterface $output)
	{
	}


	/** (required)
	 * This method is executed after interact() and initialize().
	 * It contains the logic you want the command to execute.
	 *
	 * @param InputInterface $input
	 * @param OutputInterface $output
	 */
	protected function execute(InputInterface $input, OutputInterface $output)
	{
		\MyAdmin\Plugins\Plugin::setPermissions();
	}
}
