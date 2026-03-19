<?php

namespace Tests\MyAdmin\Plugins\Command;

use MyAdmin\Plugins\Command\SetPermissions;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Test suite for the SetPermissions command class.
 *
 * Tests class structure and command configuration.
 *
 * @covers \MyAdmin\Plugins\Command\SetPermissions
 */
class SetPermissionsTest extends TestCase
{
    /**
     * Test that SetPermissions extends BaseCommand.
     */
    public function testExtendsBaseCommand(): void
    {
        $ref = new ReflectionClass(SetPermissions::class);
        $this->assertSame('Composer\Command\BaseCommand', $ref->getParentClass()->getName());
    }

    /**
     * Test that the command name is 'myadmin:set-permissions'.
     */
    public function testCommandNameIsMyadminSetPermissions(): void
    {
        $command = new SetPermissions();
        $this->assertSame('myadmin:set-permissions', $command->getName());
    }

    /**
     * Test that the command has a description set.
     */
    public function testCommandHasDescription(): void
    {
        $command = new SetPermissions();
        $this->assertNotEmpty($command->getDescription());
    }

    /**
     * Test that the command description mentions permissions.
     */
    public function testCommandDescriptionMentionsPermissions(): void
    {
        $command = new SetPermissions();
        $this->assertStringContainsString('Permissions', $command->getDescription());
    }

    /**
     * Test that the command has help text set.
     */
    public function testCommandHasHelp(): void
    {
        $command = new SetPermissions();
        $this->assertNotEmpty($command->getHelp());
    }

    /**
     * Test that execute method exists and is protected.
     */
    public function testExecuteIsProtected(): void
    {
        $ref = new ReflectionClass(SetPermissions::class);
        $method = $ref->getMethod('execute');
        $this->assertTrue($method->isProtected());
    }
}
