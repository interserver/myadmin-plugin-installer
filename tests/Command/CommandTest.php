<?php

namespace Tests\MyAdmin\Plugins\Command;

use Composer\Command\BaseCommand;
use MyAdmin\Plugins\Command\Command;
use PHPUnit\Framework\TestCase;
use Tests\MyAdmin\Plugins\Support\SourceInspection;

/**
 * Test suite for the base `myadmin` status command.
 *
 * @covers \MyAdmin\Plugins\Command\Command
 */
class CommandTest extends TestCase
{
    use SourceInspection;

    /** @var Command */
    private $command;

    protected function setUp(): void
    {
        $this->command = new Command();
    }

    public function testIsAComposerCommand(): void
    {
        $this->assertInstanceOf(BaseCommand::class, $this->command);
    }

    public function testIsNamedAndDescribed(): void
    {
        $this->assertSame('myadmin', $this->command->getName());
        $this->assertNotEmpty($this->command->getDescription());
        $this->assertNotEmpty($this->command->getHelp());
    }

    /**
     * The description used to read "Creates a new user." on a command that created nothing.
     */
    public function testDescriptionMatchesWhatTheCommandActuallyDoes(): void
    {
        $this->assertStringNotContainsString('user', strtolower($this->command->getDescription()));
        $this->assertStringContainsString('status', strtolower($this->command->getDescription()));
    }

    public function testTakesNoArgumentsOrOptions(): void
    {
        $this->assertSame([], $this->command->getDefinition()->getArguments());
        $this->assertSame([], $this->command->getDefinition()->getOptions());
    }

    /**
     * The previous body printed Symfony console demo output — "User Creator",
     * `<info>foo</info>`, a formatter section and a string-truncation sample.
     */
    public function testNoLongerContainsDemoScaffolding(): void
    {
        $source = $this->codeOf(Command::class);
        $this->assertStringNotContainsString('User Creator', $source);
        $this->assertStringNotContainsString('<info>foo</info>', $source);
        $this->assertStringNotContainsString('truncate', $source);
    }

    /**
     * This command reports; it must not mutate anything.
     */
    public function testIsReadOnly(): void
    {
        $source = $this->codeOf(Command::class);
        $this->assertStringContainsString('rebuild(true)', $source, 'must use the dry-run form');
        foreach (['file_put_contents', 'unlink(', 'writeJson', 'mkdir('] as $mutator) {
            $this->assertStringNotContainsString($mutator, $source, $mutator.' would make this command non-read-only');
        }
    }
}
