<?php

namespace Tests\MyAdmin\Plugins\Command;

use MyAdmin\Plugins\Command\CreateUser;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Test suite for the CreateUser command class.
 *
 * Tests class structure, command configuration, and argument definitions.
 *
 * @covers \MyAdmin\Plugins\Command\CreateUser
 */
class CreateUserTest extends TestCase
{
    /**
     * Test that CreateUser extends BaseCommand.
     */
    public function testExtendsBaseCommand(): void
    {
        $ref = new ReflectionClass(CreateUser::class);
        $this->assertSame('Composer\Command\BaseCommand', $ref->getParentClass()->getName());
    }

    /**
     * Test that the command name is 'myadmin:create-user'.
     */
    public function testCommandNameIsMyadminCreateUser(): void
    {
        $command = new CreateUser();
        $this->assertSame('myadmin:create-user', $command->getName());
    }

    /**
     * Test that the command has a description set.
     */
    public function testCommandHasDescription(): void
    {
        $command = new CreateUser();
        $this->assertNotEmpty($command->getDescription());
    }

    /**
     * Test that the command has help text set.
     */
    public function testCommandHasHelp(): void
    {
        $command = new CreateUser();
        $this->assertNotEmpty($command->getHelp());
    }

    /**
     * Test that the command defines a 'username' argument.
     */
    public function testCommandHasUsernameArgument(): void
    {
        $command = new CreateUser();
        $definition = $command->getDefinition();
        $this->assertTrue($definition->hasArgument('username'));
    }

    /**
     * Test that the 'username' argument is required.
     */
    public function testUsernameArgumentIsRequired(): void
    {
        $command = new CreateUser();
        $argument = $command->getDefinition()->getArgument('username');
        $this->assertTrue($argument->isRequired());
    }

    /**
     * Test that the 'username' argument has a description.
     */
    public function testUsernameArgumentHasDescription(): void
    {
        $command = new CreateUser();
        $argument = $command->getDefinition()->getArgument('username');
        $this->assertNotEmpty($argument->getDescription());
    }
}
