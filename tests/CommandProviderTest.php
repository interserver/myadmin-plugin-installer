<?php

namespace Tests\MyAdmin\Plugins;

use MyAdmin\Plugins\CommandProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Test suite for the CommandProvider class.
 *
 * Tests interface implementation and command registration.
 *
 * @covers \MyAdmin\Plugins\CommandProvider
 */
class CommandProviderTest extends TestCase
{
    /**
     * Test that CommandProvider implements Composer CommandProvider capability.
     */
    public function testImplementsCommandProviderCapability(): void
    {
        $ref = new ReflectionClass(CommandProvider::class);
        $this->assertTrue(
            $ref->implementsInterface(\Composer\Plugin\Capability\CommandProvider::class)
        );
    }

    /**
     * Test that getCommands returns an array.
     */
    public function testGetCommandsReturnsArray(): void
    {
        $provider = new CommandProvider();
        $commands = $provider->getCommands();
        $this->assertIsArray($commands);
    }

    /**
     * Test that getCommands returns exactly 5 commands.
     */
    public function testGetCommandsReturnsFiveCommands(): void
    {
        $provider = new CommandProvider();
        $commands = $provider->getCommands();
        $this->assertCount(5, $commands);
    }

    /**
     * Test that getCommands returns instances of BaseCommand.
     */
    public function testGetCommandsReturnsBaseCommandInstances(): void
    {
        $provider = new CommandProvider();
        $commands = $provider->getCommands();

        foreach ($commands as $command) {
            $this->assertInstanceOf(\Composer\Command\BaseCommand::class, $command);
        }
    }

    /**
     * Test that the Command command is registered.
     */
    public function testContainsCommandInstance(): void
    {
        $provider = new CommandProvider();
        $commands = $provider->getCommands();
        $this->assertInstanceOf(\MyAdmin\Plugins\Command\Command::class, $commands[0]);
    }

    /**
     * Test that the Parse command is registered.
     */
    public function testContainsParseInstance(): void
    {
        $provider = new CommandProvider();
        $commands = $provider->getCommands();
        $this->assertInstanceOf(\MyAdmin\Plugins\Command\Parse::class, $commands[1]);
    }

    /**
     * Test that the CreateUser command is registered.
     */
    public function testContainsCreateUserInstance(): void
    {
        $provider = new CommandProvider();
        $commands = $provider->getCommands();
        $this->assertInstanceOf(\MyAdmin\Plugins\Command\CreateUser::class, $commands[2]);
    }

    /**
     * Test that the UpdatePlugins command is registered.
     */
    public function testContainsUpdatePluginsInstance(): void
    {
        $provider = new CommandProvider();
        $commands = $provider->getCommands();
        $this->assertInstanceOf(\MyAdmin\Plugins\Command\UpdatePlugins::class, $commands[3]);
    }

    /**
     * Test that the SetPermissions command is registered.
     */
    public function testContainsSetPermissionsInstance(): void
    {
        $provider = new CommandProvider();
        $commands = $provider->getCommands();
        $this->assertInstanceOf(\MyAdmin\Plugins\Command\SetPermissions::class, $commands[4]);
    }

    /**
     * Test that getCommands method is public.
     */
    public function testGetCommandsIsPublic(): void
    {
        $ref = new ReflectionClass(CommandProvider::class);
        $method = $ref->getMethod('getCommands');
        $this->assertTrue($method->isPublic());
    }
}
