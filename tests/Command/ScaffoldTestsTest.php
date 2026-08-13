<?php

namespace Tests\MyAdmin\Plugins\Command;

use Composer\Command\BaseCommand;
use MyAdmin\Plugins\Command\ScaffoldTests;
use PHPUnit\Framework\TestCase;
use Tests\MyAdmin\Plugins\Support\SourceInspection;

/**
 * Test suite for the ScaffoldTests command.
 *
 * @covers \MyAdmin\Plugins\Command\ScaffoldTests
 */
class ScaffoldTestsTest extends TestCase
{
    use SourceInspection;

    /** @var ScaffoldTests */
    private $command;

    protected function setUp(): void
    {
        $this->command = new ScaffoldTests();
    }

    public function testIsAComposerCommand(): void
    {
        $this->assertInstanceOf(BaseCommand::class, $this->command);
    }

    public function testIsNamedAndDescribed(): void
    {
        $this->assertSame('myadmin:scaffold-tests', $this->command->getName());
        $this->assertNotEmpty($this->command->getDescription());
        $this->assertNotEmpty($this->command->getHelp());
    }

    /**
     * The path is optional so the command can be run bare from inside the package it is
     * scaffolding, which is how it is meant to be used.
     */
    public function testTakesAnOptionalPath(): void
    {
        $arguments = $this->command->getDefinition()->getArguments();

        $this->assertArrayHasKey('path', $arguments);
        $this->assertFalse($arguments['path']->isRequired());
    }

    /**
     * @dataProvider flags
     */
    public function testEveryOptionIsAFlag(string $option): void
    {
        $definition = $this->command->getDefinition();

        $this->assertTrue($definition->hasOption($option));
        $this->assertFalse($definition->getOption($option)->acceptValue(), '--'.$option.' is a flag');
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function flags(): array
    {
        return [
            'dry-run' => ['dry-run'],
            'write' => ['write'],
            'force' => ['force'],
        ];
    }

    /**
     * This writes source files into someone's repository. Defaulting to write and relying
     * on a --dry-run flag would mean the first exploratory run leaves files behind.
     */
    public function testWritingRequiresAnExplicitOptIn(): void
    {
        $source = $this->codeOf(ScaffoldTests::class);

        $this->assertStringContainsString("getOption('write')", $source);
        $this->assertStringContainsString('if (!$write', $source, 'nothing may be written without --write');
    }

    /**
     * The probe primes constants and registers a module, neither of which can be undone,
     * and it needs the target package's autoloader rather than this one's. Sharing the
     * process would break both.
     */
    public function testTheProbeRunsInAProcessOfItsOwn(): void
    {
        $source = $this->codeOf(ScaffoldTests::class);

        $this->assertStringContainsString('proc_open', $source);
        $this->assertStringContainsString('probe.php', $source);
    }

    /**
     * The probe writes its diagnosis to stderr — missing vendor/, no Plugin class — and
     * swallowing it would leave the operator with an exit code and nothing else.
     */
    public function testTheProbesStderrIsSurfacedRatherThanSwallowed(): void
    {
        $source = $this->codeOf(ScaffoldTests::class);

        $this->assertStringContainsString('$stderr', $source);
        $this->assertStringContainsString('writeError', $source);
    }

    /**
     * The file the probe measures must exist where the command looks for it; a rename that
     * broke this would only show up when someone actually ran the command.
     */
    public function testTheProbeScriptIsWhereTheCommandExpects(): void
    {
        $this->assertFileExists(dirname(__DIR__, 2).'/src/Testing/Scaffold/probe.php');
    }

    /**
     * MyAdmin core sets config.allow-plugins to false, so none of the myadmin:* commands
     * are registered there. Anyone who tries this from core needs to be told that in the
     * help rather than left to conclude the command is broken.
     */
    public function testTheHelpExplainsItCannotBeRunFromCore(): void
    {
        $this->assertStringContainsString('allow-plugins', $this->command->getHelp());
    }
}
