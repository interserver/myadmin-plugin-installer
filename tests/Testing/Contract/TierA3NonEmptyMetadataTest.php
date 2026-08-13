<?php
/**
 * @author Joe Huss <detain@interserver.net>
 * @copyright 2026
 * @package MyAdmin
 * @category Testing
 */

namespace Tests\MyAdmin\Plugins\Testing\Contract;

use MyAdmin\Plugins\Testing\Contract\Finding;
use MyAdmin\Plugins\Testing\Contract\PluginSubject;
use MyAdmin\Plugins\Testing\Contract\TierA3NonEmptyMetadata;
use PHPUnit\Framework\TestCase;

/**
 * Fixtures live at the bottom of this file with a `TierA3Fixture` prefix — unique per file,
 * because every test in this directory shares one process.
 *
 * @covers \MyAdmin\Plugins\Testing\Contract\TierA3NonEmptyMetadata
 */
class TierA3NonEmptyMetadataTest extends TestCase
{
    /**
     * @param string $class
     * @return array<int,Finding>
     */
    private function inspect($class)
    {
        $inspector = new TierA3NonEmptyMetadata();
        return $inspector->inspect(new PluginSubject($class));
    }

    /**
     * @param array<int,Finding> $findings
     * @return array<int,string>
     */
    private function failureMessages(array $findings)
    {
        $messages = [];
        foreach ($findings as $finding) {
            $this->assertSame('A-3', $finding->assertion());
            if ($finding->isFailure()) {
                $messages[] = $finding->message();
            }
        }
        return $messages;
    }

    /**
     * @return void
     */
    public function testReportsItsCatalogueIdentity()
    {
        $inspector = new TierA3NonEmptyMetadata();
        $this->assertSame('A-3', $inspector->id());
        $this->assertNotSame('', $inspector->title());
    }

    /**
     * @return void
     */
    public function testPopulatedNameAndDescriptionPass()
    {
        $this->assertSame([], $this->inspect(TierA3FixtureGood::class));
    }

    /**
     * The whole point of separating A-3 from A-2: most of the fleet ships `$help = ''` and
     * that is legal. If this ever starts failing, the assertion has been broadened, not the
     * fleet degraded.
     *
     * @return void
     */
    public function testEmptyHelpIsNotAViolation()
    {
        $this->assertSame([], $this->inspect(TierA3FixtureEmptyHelp::class));
    }

    /**
     * @return void
     */
    public function testEmptyNameFails()
    {
        $messages = $this->failureMessages($this->inspect(TierA3FixtureEmptyName::class));
        $this->assertCount(1, $messages);
        $this->assertStringContainsString('$name is empty', $messages[0]);
    }

    /**
     * @return void
     */
    public function testEmptyDescriptionFails()
    {
        $messages = $this->failureMessages($this->inspect(TierA3FixtureEmptyDescription::class));
        $this->assertCount(1, $messages);
        $this->assertStringContainsString('$description is empty', $messages[0]);
    }

    /**
     * @return void
     */
    public function testWhitespaceOnlyValuesCountAsEmpty()
    {
        $messages = $this->failureMessages($this->inspect(TierA3FixtureWhitespaceName::class));
        $this->assertCount(1, $messages);
        $this->assertStringContainsString('$name is empty', $messages[0]);
    }

    /**
     * @return void
     */
    public function testBothEmptyProduceTwoSeparateFindings()
    {
        $messages = $this->failureMessages($this->inspect(TierA3FixtureBothEmpty::class));
        $this->assertCount(2, $messages);
    }

    /**
     * A-2 already fails on a missing property; repeating it here would double-count one
     * defect, so A-3 records that it could not run.
     *
     * The message is asserted, not just the severity. An absent property and a property
     * holding null both reach a skip, and only the wording separates "you never declared
     * this" from "you declared it wrong" — a mutant that deleted the absence branch entirely
     * stayed green until this pinned it.
     *
     * @return void
     */
    public function testMissingPropertyIsSkippedRatherThanDoubleReported()
    {
        $findings = $this->inspect(TierA3FixtureDeclaresNothing::class);
        $this->assertCount(2, $findings);
        $named = [];
        foreach ($findings as $finding) {
            $this->assertTrue($finding->isSkipped(), 'expected a skip, got: '.$finding->describe());
            $this->assertStringContainsString('declares no public static', $finding->message());
            $named[] = $finding->context()['property'];
        }
        $this->assertSame(['name', 'description'], $named);
    }

    /**
     * @return void
     */
    public function testNonStringValueIsSkippedRatherThanDoubleReported()
    {
        $findings = $this->inspect(TierA3FixtureIntegerName::class);
        $this->assertCount(1, $findings);
        $this->assertTrue($findings[0]->isSkipped());
        $this->assertStringContainsString('holds integer', $findings[0]->message());
    }

    /**
     * The shape of the ten `*-module` fleet packages: sound metadata on a class whose
     * `$settings` initializer references a constant nothing has defined. PHP evaluates every
     * static initializer on first access to any of them, so reading `$name` here throws —
     * before the retarget that produced two skips per package and left twenty G2 matrix
     * cells reading "never ran" for plugins that are in fact compliant.
     *
     * @return void
     */
    public function testPoisonedButRecoverableMetadataPasses()
    {
        $this->assertSame([], $this->inspect(TierA3FixturePoisonedButValid::class));
    }

    /**
     * The half that matters more: a recovered value is a real observation, so an empty one is
     * a real failure. Skipping it because the class is *also* constant-poisoned would hide a
     * genuine defect behind an unrelated harness limitation — which is exactly the
     * understatement `Finding::SKIPPED` is not allowed to be used for.
     *
     * @return void
     */
    public function testPoisonedButRecoverableEmptyNameFails()
    {
        $messages = $this->failureMessages($this->inspect(TierA3FixturePoisonedEmptyName::class));
        $this->assertCount(1, $messages);
        $this->assertStringContainsString('$name is empty', $messages[0]);
    }

    /**
     * A value that genuinely cannot be recovered still skips — but the skip has to say why,
     * and must not read as "the property is absent". `$description` on the same fixture is a
     * plain literal and is judged normally, which is what pins that the skip is per-property
     * rather than per-class.
     *
     * @return void
     */
    public function testUnrecoverableValueSkipsWithTheReasonAndDoesNotClaimAbsence()
    {
        $findings = $this->inspect(TierA3FixtureUnrecoverableName::class);
        $this->assertCount(1, $findings, '$description is a literal and must be judged, not skipped');
        $this->assertTrue($findings[0]->isSkipped());
        $this->assertStringContainsString('$name', $findings[0]->message());
        $this->assertStringContainsString('ABSENT', $findings[0]->message(), 'the swallowed error');
        $this->assertStringNotContainsString('declares no public static', $findings[0]->message());
        $this->assertSame('unevaluable', $findings[0]->context()['problem']);
    }

    /**
     * @return void
     */
    public function testMissingClassIsSkippedNotPassed()
    {
        $findings = $this->inspect('Tests\\MyAdmin\\Plugins\\Testing\\Contract\\TierA3FixtureAbsent');
        $this->assertCount(1, $findings);
        $this->assertTrue($findings[0]->isSkipped());
        $this->assertSame('A-3', $findings[0]->assertion());
    }
}

// ---------------------------------------------------------------------------
// Fixtures
// ---------------------------------------------------------------------------

class TierA3FixtureGood
{
    public static $name = 'Tier A3 Good';
    public static $description = 'Has both a name and a description.';
    public static $help = 'Optional help.';
    public static $type = 'service';
}

class TierA3FixtureEmptyHelp
{
    public static $name = 'Tier A3 Empty Help';
    public static $description = 'Ships no help text, which is legal.';
    public static $help = '';
    public static $type = 'plugin';
}

class TierA3FixtureEmptyName
{
    public static $name = '';
    public static $description = 'Nameless in the admin listing.';
    public static $help = '';
    public static $type = 'plugin';
}

class TierA3FixtureEmptyDescription
{
    public static $name = 'Tier A3 Empty Description';
    public static $description = '';
    public static $help = '';
    public static $type = 'plugin';
}

class TierA3FixtureWhitespaceName
{
    public static $name = "  \t\n ";
    public static $description = 'Name is whitespace only.';
    public static $help = '';
    public static $type = 'plugin';
}

class TierA3FixtureBothEmpty
{
    public static $name = '';
    public static $description = '';
    public static $help = '';
    public static $type = 'plugin';
}

class TierA3FixtureDeclaresNothing
{
}

class TierA3FixtureIntegerName
{
    public static $name = 42;
    public static $description = 'Name is not a string at all.';
    public static $help = '';
    public static $type = 'plugin';
}

/**
 * Holds no `ABSENT`. Referencing a class constant that does not exist is what makes every
 * static on the fixtures below throw on first access, without needing a global constant name
 * that another test could accidentally define.
 */
class TierA3FixtureConstHolder
{
    const PRESENT = 'present';
}

/** The ten `*-module` fleet packages in miniature: good metadata, poisoned `$settings`. */
class TierA3FixturePoisonedButValid
{
    public static $name = 'Tier A3 Poisoned But Valid';
    public static $description = 'Compliant metadata on a constant-poisoned class.';
    public static $help = '';
    public static $type = 'module';

    /** @var array<string,mixed> the poison — an array, which the fallback never evaluates */
    public static $settings = ['REPEAT_BILLING_METHOD' => TierA3FixtureConstHolder::ABSENT];
}

/** Poisoned like the fleet, and separately wrong: `$name` is a recoverable empty string. */
class TierA3FixturePoisonedEmptyName
{
    public static $name = '';
    public static $description = 'Constant-poisoned, and nameless in the admin listing.';
    public static $help = '';
    public static $type = 'module';

    /** @var array<string,mixed> */
    public static $settings = ['REPEAT_BILLING_METHOD' => TierA3FixtureConstHolder::ABSENT];
}

/** `$name`'s own initializer is the constant expression, so no literal can be recovered. */
class TierA3FixtureUnrecoverableName
{
    public static $name = TierA3FixtureConstHolder::ABSENT;
    public static $description = 'Description is a plain literal and must still be judged.';
    public static $help = '';
    public static $type = 'module';
}
