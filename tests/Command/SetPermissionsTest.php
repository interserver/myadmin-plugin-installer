<?php

namespace Tests\MyAdmin\Plugins\Command;

use Composer\Command\BaseCommand;
use MyAdmin\Plugins\Command\SetPermissions;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Tests\MyAdmin\Plugins\Support\SourceInspection;

/**
 * Test suite for the SetPermissions command.
 *
 * @covers \MyAdmin\Plugins\Command\SetPermissions
 */
class SetPermissionsTest extends TestCase
{
    use SourceInspection;

    /** @var SetPermissions */
    private $command;

    protected function setUp(): void
    {
        $this->command = new SetPermissions();
    }

    public function testIsAComposerCommand(): void
    {
        $this->assertInstanceOf(BaseCommand::class, $this->command);
    }

    public function testIsNamedAndDescribed(): void
    {
        $this->assertSame('myadmin:set-permissions', $this->command->getName());
        $this->assertNotEmpty($this->command->getDescription());
        $this->assertNotEmpty($this->command->getHelp());
    }

    public function testOffersADryRunOption(): void
    {
        $this->assertTrue($this->command->getDefinition()->hasOption('dry-run'));
        $this->assertFalse(
            $this->command->getDefinition()->getOption('dry-run')->acceptValue(),
            '--dry-run is a flag, not a value option'
        );
    }

    public function testTakesNoArguments(): void
    {
        $this->assertSame([], $this->command->getDefinition()->getArguments());
    }

    /**
     * Regression for the guaranteed ArgumentCountError: execute() used to call
     * Plugin::setPermissions() with zero arguments against a signature requiring a
     * Composer\Script\Event. It now builds the Event, so the only call site must pass one.
     */
    public function testExecuteBuildsTheEventThatSetPermissionsRequires(): void
    {
        $source = $this->codeOf(SetPermissions::class);
        $this->assertStringContainsString('new Event(', $source, 'execute() must construct a Script\Event');
        $this->assertStringNotContainsString(
            'Plugin::setPermissions()',
            $source,
            'setPermissions() must never be called with no arguments'
        );
    }

    /**
     * The Plugin method this command drives must still accept exactly one required Event.
     */
    public function testSetPermissionsSignatureIsUnchanged(): void
    {
        $method = new ReflectionMethod('MyAdmin\Plugins\Plugin', 'setPermissions');
        $this->assertTrue($method->isStatic());
        $this->assertSame(1, $method->getNumberOfRequiredParameters());
        $this->assertSame(
            'Composer\Script\Event',
            (string)$method->getParameters()[0]->getType()
        );
    }
}
