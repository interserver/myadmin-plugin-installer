<?php

namespace Tests\MyAdmin\Plugins\Command;

use MyAdmin\Plugins\Command\UpdatePlugins;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Test suite for the UpdatePlugins command class.
 *
 * Tests class structure and command configuration.
 *
 * @covers \MyAdmin\Plugins\Command\UpdatePlugins
 */
class UpdatePluginsTest extends TestCase
{
    /**
     * Test that UpdatePlugins extends BaseCommand.
     */
    public function testExtendsBaseCommand(): void
    {
        $ref = new ReflectionClass(UpdatePlugins::class);
        $this->assertSame('Composer\Command\BaseCommand', $ref->getParentClass()->getName());
    }

    /**
     * Test that the command name is 'myadmin:update-plugins'.
     */
    public function testCommandNameIsMyadminUpdatePlugins(): void
    {
        $command = new UpdatePlugins();
        $this->assertSame('myadmin:update-plugins', $command->getName());
    }

    /**
     * Test that the command has a description set.
     */
    public function testCommandHasDescription(): void
    {
        $command = new UpdatePlugins();
        $this->assertNotEmpty($command->getDescription());
    }

    /**
     * Test that the command description mentions plugins.
     */
    public function testCommandDescriptionMentionsPlugins(): void
    {
        $command = new UpdatePlugins();
        $this->assertStringContainsString('Plugins', $command->getDescription());
    }

    /**
     * Test that the command has help text set.
     */
    public function testCommandHasHelp(): void
    {
        $command = new UpdatePlugins();
        $this->assertNotEmpty($command->getHelp());
    }

    /**
     * Test that execute method exists and is protected.
     */
    public function testExecuteIsProtected(): void
    {
        $ref = new ReflectionClass(UpdatePlugins::class);
        $method = $ref->getMethod('execute');
        $this->assertTrue($method->isProtected());
    }
}
