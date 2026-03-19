<?php

namespace Tests\MyAdmin\Plugins\Command;

use MyAdmin\Plugins\Command\Command;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Test suite for the Command class.
 *
 * Tests class structure and command configuration.
 *
 * @covers \MyAdmin\Plugins\Command\Command
 */
class CommandTest extends TestCase
{
    /**
     * Test that Command extends BaseCommand.
     */
    public function testExtendsBaseCommand(): void
    {
        $ref = new ReflectionClass(Command::class);
        $this->assertSame('Composer\Command\BaseCommand', $ref->getParentClass()->getName());
    }

    /**
     * Test that the command name is 'myadmin'.
     */
    public function testCommandNameIsMyadmin(): void
    {
        $command = new Command();
        $this->assertSame('myadmin', $command->getName());
    }

    /**
     * Test that the command has a description set.
     */
    public function testCommandHasDescription(): void
    {
        $command = new Command();
        $this->assertNotEmpty($command->getDescription());
    }

    /**
     * Test that the command has help text set.
     */
    public function testCommandHasHelp(): void
    {
        $command = new Command();
        $this->assertNotEmpty($command->getHelp());
    }

    /**
     * Test that configure method exists and is protected.
     */
    public function testConfigureIsProtected(): void
    {
        $ref = new ReflectionClass(Command::class);
        $method = $ref->getMethod('configure');
        $this->assertTrue($method->isProtected());
    }

    /**
     * Test that execute method exists and is protected.
     */
    public function testExecuteIsProtected(): void
    {
        $ref = new ReflectionClass(Command::class);
        $method = $ref->getMethod('execute');
        $this->assertTrue($method->isProtected());
    }

    /**
     * Test that initialize method exists and is protected.
     */
    public function testInitializeIsProtected(): void
    {
        $ref = new ReflectionClass(Command::class);
        $method = $ref->getMethod('initialize');
        $this->assertTrue($method->isProtected());
    }

    /**
     * Test that interact method exists and is protected.
     */
    public function testInteractIsProtected(): void
    {
        $ref = new ReflectionClass(Command::class);
        $method = $ref->getMethod('interact');
        $this->assertTrue($method->isProtected());
    }
}
