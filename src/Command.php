<?php

namespace MyAdmin\PluginInstaller;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Composer\Command\BaseCommand;

class Command extends BaseCommand
{
	protected function configure()
	{
		$this->setName('i-win');
	}

	protected function execute(InputInterface $input, OutputInterface $output)
	{
		$output->writeln('Executing');
		$output->writeln('Congradulations! You won at life!');
	}
}
