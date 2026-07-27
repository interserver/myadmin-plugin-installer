<?php

namespace Tests\MyAdmin\Plugins;

use Composer\Command\BaseCommand;
use Composer\Plugin\Capability\Capability;
use Composer\Plugin\Capability\CommandProvider as CommandProviderCapability;
use MyAdmin\Plugins\Command\Command;
use MyAdmin\Plugins\Command\SetPermissions;
use MyAdmin\Plugins\Command\UpdatePlugins;
use MyAdmin\Plugins\CommandProvider;
use PHPUnit\Framework\TestCase;

/**
 * Test suite for the CommandProvider capability.
 *
 * @covers \MyAdmin\Plugins\CommandProvider
 */
class CommandProviderTest extends TestCase
{
    public function testImplementsTheCommandProviderCapability(): void
    {
        $provider = new CommandProvider();
        $this->assertInstanceOf(CommandProviderCapability::class, $provider);
        $this->assertInstanceOf(Capability::class, $provider);
    }

    /**
     * Composer constructs a capability with exactly one argument: an array carrying
     * 'composer', 'io' and 'plugin'. A constructor that cannot accept that shape fails at
     * runtime rather than at load time, so pin it here.
     */
    public function testAcceptsComposerCapabilityConstructorPayload(): void
    {
        $provider = new CommandProvider(['composer' => null, 'io' => null, 'plugin' => null]);
        $this->assertCount(3, $provider->getCommands());
    }

    public function testIsConstructibleWithNoArguments(): void
    {
        $provider = new CommandProvider();
        $this->assertCount(3, $provider->getCommands());
    }

    /**
     * Composer throws UnexpectedValueException on any element that is not a BaseCommand.
     */
    public function testEveryCommandIsABaseCommand(): void
    {
        foreach ((new CommandProvider())->getCommands() as $command) {
            $this->assertInstanceOf(BaseCommand::class, $command);
        }
    }

    public function testProvidesTheThreeSupportedCommands(): void
    {
        $classes = array_map('get_class', (new CommandProvider())->getCommands());
        $this->assertContains(Command::class, $classes);
        $this->assertContains(UpdatePlugins::class, $classes);
        $this->assertContains(SetPermissions::class, $classes);
    }

    /**
     * Parse required an undeclared dependency and CreateUser was demo scaffolding; both were
     * removed rather than repaired.
     */
    public function testRemovedCommandsAreGone(): void
    {
        $this->assertFalse(class_exists('MyAdmin\Plugins\Command\Parse'));
        $this->assertFalse(class_exists('MyAdmin\Plugins\Command\CreateUser'));
    }

    public function testCommandNamesAreNamespacedAndUnique(): void
    {
        $names = [];
        foreach ((new CommandProvider())->getCommands() as $command) {
            $name = $command->getName();
            $this->assertNotEmpty($name);
            $this->assertTrue(
                $name === 'myadmin' || strpos($name, 'myadmin:') === 0,
                $name.' must be namespaced under myadmin'
            );
            $names[] = $name;
        }
        $this->assertSame($names, array_unique($names));
    }

    /**
     * A command with no description renders blank in `composer list`.
     */
    public function testEveryCommandHasADescription(): void
    {
        foreach ((new CommandProvider())->getCommands() as $command) {
            $this->assertNotEmpty($command->getDescription(), $command->getName().' needs a description');
        }
    }
}
