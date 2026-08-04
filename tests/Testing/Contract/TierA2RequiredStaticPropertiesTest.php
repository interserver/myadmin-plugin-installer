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
use MyAdmin\Plugins\Testing\Contract\TierA2RequiredStaticProperties;
use PHPUnit\Framework\TestCase;

/**
 * Fixtures live at the bottom of this file with a `TierA2Fixture` prefix — unique per file,
 * because every test in this directory shares one process.
 *
 * @covers \MyAdmin\Plugins\Testing\Contract\TierA2RequiredStaticProperties
 */
class TierA2RequiredStaticPropertiesTest extends TestCase
{
    /**
     * @param string $class
     * @return array<int,Finding>
     */
    private function inspect($class)
    {
        $inspector = new TierA2RequiredStaticProperties();
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
            $this->assertSame('A-2', $finding->assertion());
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
        $inspector = new TierA2RequiredStaticProperties();
        $this->assertSame('A-2', $inspector->id());
        $this->assertNotSame('', $inspector->title());
    }

    /**
     * @return void
     */
    public function testPublicStaticStringsOnAllFourPass()
    {
        $this->assertSame([], $this->inspect(TierA2FixtureGood::class));
    }

    /**
     * An empty `$help` is a Tier A-3 question, not an A-2 one — A-2 only cares that it is a
     * public static string.
     *
     * @return void
     */
    public function testEmptyHelpStringStillPasses()
    {
        $this->assertSame([], $this->inspect(TierA2FixtureEmptyHelp::class));
    }

    /**
     * @return void
     */
    public function testEveryMissingPropertyIsReportedSeparately()
    {
        $messages = $this->failureMessages($this->inspect(TierA2FixtureDeclaresNothing::class));
        $this->assertCount(4, $messages);
        foreach (['$name', '$description', '$help', '$type'] as $property) {
            $matched = false;
            foreach ($messages as $message) {
                if (strpos($message, 'does not declare '.$property.'.') !== false) {
                    $matched = true;
                }
            }
            $this->assertTrue($matched, 'no finding named the missing property '.$property);
        }
    }

    /**
     * @return void
     */
    public function testInstancePropertyIsAFailure()
    {
        $messages = $this->failureMessages($this->inspect(TierA2FixtureInstanceName::class));
        $this->assertCount(1, $messages);
        $this->assertStringContainsString('$name is an instance property', $messages[0]);
    }

    /**
     * @return void
     */
    public function testProtectedStaticPropertyIsAFailure()
    {
        $messages = $this->failureMessages($this->inspect(TierA2FixtureProtectedDescription::class));
        $this->assertCount(1, $messages);
        $this->assertStringContainsString('protected static', $messages[0]);
    }

    /**
     * @return void
     */
    public function testPrivateStaticPropertyIsAFailure()
    {
        $messages = $this->failureMessages($this->inspect(TierA2FixturePrivateHelp::class));
        $this->assertCount(1, $messages);
        $this->assertStringContainsString('private static', $messages[0]);
    }

    /**
     * @return void
     */
    public function testNonStringValueIsAFailure()
    {
        $messages = $this->failureMessages($this->inspect(TierA2FixtureIntegerType::class));
        $this->assertCount(1, $messages);
        $this->assertStringContainsString('$type must hold a string; found integer', $messages[0]);
    }

    /**
     * @return void
     */
    public function testUninitialisedPropertyCountsAsNonString()
    {
        $messages = $this->failureMessages($this->inspect(TierA2FixtureUninitialisedName::class));
        $this->assertCount(1, $messages);
        $this->assertStringContainsString('$name must hold a string; found NULL', $messages[0]);
    }

    /**
     * A static initializer that references an undefined class constant throws on first
     * access. That is a value that could not be read, not a value that was read and found
     * wanting — so it must be a skip, and must not be reported as a string violation.
     *
     * Scoped to the one property that is genuinely unrecoverable. PHP throws for **all four**
     * properties of this fixture, but only `$name`'s own declaration is a constant expression;
     * the other three are plain literals the source fallback recovers, and those must now
     * produce real verdicts — see
     * {@see testPoisonedButRecoverableSiblingsAreJudgedNotSkipped()}. Asserting the count is
     * what stops a future regression from re-skipping all four and still passing here.
     *
     * @return void
     */
    public function testUnreadableInitializerIsSkippedNotFailed()
    {
        $findings = $this->inspect(TierA2FixtureUnreadableName::class);
        $this->assertCount(1, $findings, 'only $name is unrecoverable; its siblings are literals');
        $this->assertTrue($findings[0]->isSkipped(), 'expected a skip, got: '.$findings[0]->describe());
        $this->assertStringContainsString('$name', $findings[0]->message());
    }

    /**
     * The skip must say *why*, and must not read as "the property is absent". Those are
     * different defects with different fixes, and a matrix that conflates them sends whoever
     * triages it looking for a declaration that is already there.
     *
     * @return void
     */
    public function testTheUnevaluableSkipCarriesTheReasonAndDoesNotClaimAbsence()
    {
        $findings = $this->inspect(TierA2FixtureUnreadableName::class);
        $this->assertCount(1, $findings);
        $message = $findings[0]->message();
        $this->assertStringContainsString('ABSENT', $message, 'the swallowed error must be surfaced');
        $this->assertStringNotContainsString('does not declare', $message);
        $context = $findings[0]->context();
        $this->assertSame('unevaluable', $context['problem']);
        $this->assertIsString($context['error']);
    }

    /**
     * The point of the change. This fixture has the exact shape of the ten `*-module` fleet
     * packages: four perfectly good metadata literals, poisoned by an unrelated `$settings`
     * that references a constant nothing has defined. Every one of its statics throws on
     * `getValue()`, so before the retarget this produced four skips — four G2 matrix cells
     * reading "never ran" for a plugin that is in fact fully compliant.
     *
     * @return void
     */
    public function testPoisonedButRecoverableMetadataPasses()
    {
        $this->assertSame([], $this->inspect(TierA2FixturePoisonedButValid::class));
    }

    /**
     * The same fixture as the skip test above, read from the other side: `$description`,
     * `$help` and `$type` all throw exactly as `$name` does, yet all three are recoverable
     * literals and must therefore be judged rather than skipped.
     *
     * @return void
     */
    public function testPoisonedButRecoverableSiblingsAreJudgedNotSkipped()
    {
        foreach ($this->inspect(TierA2FixtureUnreadableName::class) as $finding) {
            $this->assertStringNotContainsString('$description', $finding->message());
            $this->assertStringNotContainsString('$help', $finding->message());
            $this->assertStringNotContainsString('$type', $finding->message());
        }
    }

    /**
     * A recovered value is a real observation and must be judged like any other. Skipping it
     * because the class *also* happens to be constant-poisoned would hide a genuine contract
     * violation behind an unrelated harness limitation.
     *
     * @return void
     */
    public function testPoisonedButRecoverableNonStringIsAFailureNotASkip()
    {
        $findings = $this->inspect(TierA2FixturePoisonedIntegerType::class);
        $messages = $this->failureMessages($findings);
        $this->assertCount(1, $messages, 'exactly one property is wrong: '.count($findings).' findings');
        $this->assertStringContainsString('$type must hold a string; found integer', $messages[0]);
    }

    /**
     * @return void
     */
    public function testMissingClassIsSkippedNotPassed()
    {
        $findings = $this->inspect('Tests\\MyAdmin\\Plugins\\Testing\\Contract\\TierA2FixtureAbsent');
        $this->assertCount(1, $findings);
        $this->assertTrue($findings[0]->isSkipped());
        $this->assertSame('A-2', $findings[0]->assertion());
    }
}

// ---------------------------------------------------------------------------
// Fixtures
// ---------------------------------------------------------------------------

class TierA2FixtureGood
{
    public static $name = 'Tier A2 Good';
    public static $description = 'A well-formed plugin.';
    public static $help = 'Some help.';
    public static $type = 'service';
}

class TierA2FixtureEmptyHelp
{
    public static $name = 'Tier A2 Empty Help';
    public static $description = 'Ships no help text, which is legal.';
    public static $help = '';
    public static $type = 'plugin';
}

class TierA2FixtureDeclaresNothing
{
}

class TierA2FixtureInstanceName
{
    /** @var string */
    public $name = 'Tier A2 Instance Name';
    public static $description = 'Declares $name on the instance instead of the class.';
    public static $help = '';
    public static $type = 'plugin';
}

class TierA2FixtureProtectedDescription
{
    public static $name = 'Tier A2 Protected Description';
    protected static $description = 'Hidden from MyAdmin.';
    public static $help = '';
    public static $type = 'plugin';
}

class TierA2FixturePrivateHelp
{
    public static $name = 'Tier A2 Private Help';
    public static $description = 'Help is private.';
    private static $help = '';
    public static $type = 'plugin';
}

class TierA2FixtureIntegerType
{
    public static $name = 'Tier A2 Integer Type';
    public static $description = 'Type is not a string.';
    public static $help = '';
    public static $type = 7;
}

class TierA2FixtureUninitialisedName
{
    public static $name;
    public static $description = 'Name was never given a value.';
    public static $help = '';
    public static $type = 'plugin';
}

class TierA2FixtureConstHolder
{
    const PRESENT = 'present';
}

class TierA2FixtureUnreadableName
{
    public static $name = TierA2FixtureConstHolder::ABSENT;
    public static $description = 'Static initializer explodes on first access.';
    public static $help = '';
    public static $type = 'plugin';
}

/**
 * The shape of the ten `*-module` fleet packages, in miniature: sound metadata, poisoned by
 * one unrelated array initializer. Every static on this class throws on `getValue()`, and
 * every one of the four required properties is nonetheless recoverable from source.
 */
class TierA2FixturePoisonedButValid
{
    public static $name = 'Tier A2 Poisoned But Valid';
    public static $description = 'Compliant metadata on a constant-poisoned class.';
    public static $help = '';
    public static $type = 'module';

    /** @var array<string,mixed> the poison — an array, which the fallback never evaluates */
    public static $settings = ['REPEAT_BILLING_METHOD' => TierA2FixtureConstHolder::ABSENT];
}

/** Poisoned like the fleet, and separately wrong: `$type` is a recoverable non-string. */
class TierA2FixturePoisonedIntegerType
{
    public static $name = 'Tier A2 Poisoned Integer Type';
    public static $description = 'Constant-poisoned, and $type is an integer.';
    public static $help = '';
    public static $type = 7;

    /** @var array<string,mixed> */
    public static $settings = ['REPEAT_BILLING_METHOD' => TierA2FixtureConstHolder::ABSENT];
}
