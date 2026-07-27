<?php

namespace Tests\MyAdmin\Plugins\Command;

use Composer\Command\BaseCommand;
use MyAdmin\Plugins\Command\UpdatePlugins;
use PHPUnit\Framework\TestCase;
use Tests\MyAdmin\Plugins\Support\SourceInspection;

/**
 * Test suite for the UpdatePlugins command.
 *
 * @covers \MyAdmin\Plugins\Command\UpdatePlugins
 */
class UpdatePluginsTest extends TestCase
{
    use SourceInspection;

    /** @var UpdatePlugins */
    private $command;

    protected function setUp(): void
    {
        $this->command = new UpdatePlugins();
    }

    public function testIsAComposerCommand(): void
    {
        $this->assertInstanceOf(BaseCommand::class, $this->command);
    }

    public function testIsNamedAndDescribed(): void
    {
        $this->assertSame('myadmin:update-plugins', $this->command->getName());
        $this->assertNotEmpty($this->command->getDescription());
        $this->assertNotEmpty($this->command->getHelp());
    }

    public function testOffersDryRunAndShowSkippedOptions(): void
    {
        $definition = $this->command->getDefinition();
        $this->assertTrue($definition->hasOption('dry-run'));
        $this->assertTrue($definition->hasOption('show-skipped'));
    }

    /**
     * The previous body was Symfony demo scaffolding: it printed "User Creator" and
     * `<info>foo</info>` then returned 0 without touching a single plugin, despite a
     * description promising to find and cache them.
     */
    public function testNoLongerContainsDemoScaffolding(): void
    {
        $source = $this->codeOf(UpdatePlugins::class);
        $this->assertStringNotContainsString('User Creator', $source);
        $this->assertStringNotContainsString('<info>foo</info>', $source);
        $this->assertStringNotContainsString('formatSection', $source);
    }

    public function testDelegatesToThePluginScanner(): void
    {
        $source = $this->codeOf(UpdatePlugins::class);
        $this->assertStringContainsString('PluginScanner', $source);
        $this->assertStringContainsString('rebuild(', $source);
    }

    public function testHelpDocumentsThePresenceBasedPruningRule(): void
    {
        $help = $this->command->getHelp();
        $this->assertStringContainsString('pruned', $help);
        $this->assertStringContainsString('disk', $help);
    }
}
