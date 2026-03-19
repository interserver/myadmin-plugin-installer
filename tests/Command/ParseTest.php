<?php

namespace Tests\MyAdmin\Plugins\Command;

use MyAdmin\Plugins\Command\Parse;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Test suite for the Parse command class.
 *
 * Tests class structure and command configuration.
 *
 * @covers \MyAdmin\Plugins\Command\Parse
 */
class ParseTest extends TestCase
{
    /**
     * Test that Parse extends BaseCommand.
     */
    public function testExtendsBaseCommand(): void
    {
        $ref = new ReflectionClass(Parse::class);
        $this->assertSame('Composer\Command\BaseCommand', $ref->getParentClass()->getName());
    }

    /**
     * Test that the command name is 'myadmin:parse'.
     */
    public function testCommandNameIsMyadminParse(): void
    {
        $command = new Parse();
        $this->assertSame('myadmin:parse', $command->getName());
    }

    /**
     * Test that the command has a description set.
     */
    public function testCommandHasDescription(): void
    {
        $command = new Parse();
        $this->assertNotEmpty($command->getDescription());
    }

    /**
     * Test that the command description mentions PHP DocBlocks.
     */
    public function testCommandDescriptionMentionsDocBlocks(): void
    {
        $command = new Parse();
        $this->assertStringContainsString('DocBlock', $command->getDescription());
    }

    /**
     * Test that the command has help text set.
     */
    public function testCommandHasHelp(): void
    {
        $command = new Parse();
        $this->assertNotEmpty($command->getHelp());
    }
}
