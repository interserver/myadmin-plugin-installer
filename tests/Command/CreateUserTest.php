<?php
// tests/AppBundle/Command/CreateUserCommandTest.php
namespace Tests\MyAdmin\Plugins\Command;

use MyAdmin\Plugins\Command\CreateUser;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Class CreateUserTest
 *
 * @package Tests\MyAdmin\Plugins\Command
 */
class CreateUserTest extends KernelTestCase {
	public function testExecute() {
		self::bootKernel();
		$application = new Application(self::$kernel);
		$application->add(new CreateUserCommand());
		$command = $application->find('app:create-user');
		$commandTester = new CommandTester($command);
		$commandTester->execute([
			'command'  => $command->getName(), // pass arguments to the helper
			'username' => 'Wouter', // prefix the key with two dashes when passing options, e.g: '--some-option' => 'option_value',
		]);
		$output = $commandTester->getDisplay(); // the output of the command in the console
		$this->assertContains('Username: Wouter', $output);
		// ...
	}
}