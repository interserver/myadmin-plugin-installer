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
use MyAdmin\Plugins\Testing\Contract\TierA4TypeIsKnown;
use PHPUnit\Framework\TestCase;

/**
 * Fixtures live at the bottom of this file with a `TierA4Fixture` prefix — unique per file,
 * because every test in this directory shares one process.
 *
 * @covers \MyAdmin\Plugins\Testing\Contract\TierA4TypeIsKnown
 */
class TierA4TypeIsKnownTest extends TestCase
{
    /**
     * @param string              $class
     * @param array<string,mixed> $options
     * @return array<int,Finding>
     */
    private function inspect($class, array $options = [])
    {
        $inspector = new TierA4TypeIsKnown();
        return $inspector->inspect(new PluginSubject($class, $options));
    }

    /**
     * @param array<int,Finding> $findings
     * @return array<int,string>
     */
    private function failureMessages(array $findings)
    {
        $messages = [];
        foreach ($findings as $finding) {
            $this->assertSame('A-4', $finding->assertion());
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
        $inspector = new TierA4TypeIsKnown();
        $this->assertSame('A-4', $inspector->id());
        $this->assertNotSame('', $inspector->title());
    }

    /**
     * @return void
     */
    public function testEveryKnownTypeIsAccepted()
    {
        $this->assertSame(
            ['service', 'plugin', 'module', 'addon'],
            TierA4TypeIsKnown::KNOWN_TYPES
        );
        $this->assertSame([], $this->inspect(TierA4FixtureService::class));
        $this->assertSame([], $this->inspect(TierA4FixturePlugin::class));
        $this->assertSame([], $this->inspect(TierA4FixtureModule::class));
        $this->assertSame([], $this->inspect(TierA4FixtureAddon::class));
    }

    /**
     * @return void
     */
    public function testUnknownTypeFails()
    {
        $messages = $this->failureMessages($this->inspect(TierA4FixtureTypo::class));
        $this->assertCount(1, $messages);
        $this->assertStringContainsString('"srevice"', $messages[0]);
        $this->assertStringContainsString('service, plugin, module, addon', $messages[0]);
    }

    /**
     * @return void
     */
    public function testTypeIsCaseSensitive()
    {
        $messages = $this->failureMessages($this->inspect(TierA4FixtureUppercase::class));
        $this->assertCount(1, $messages);
        $this->assertStringContainsString('"Service"', $messages[0]);
    }

    /**
     * @return void
     */
    public function testMatchingExpectedTypePasses()
    {
        $this->assertSame([], $this->inspect(TierA4FixtureService::class, ['expectedType' => 'service']));
    }

    /**
     * @return void
     */
    public function testKnownTypeThatDisagreesWithTheExpectedOneFails()
    {
        $messages = $this->failureMessages(
            $this->inspect(TierA4FixturePlugin::class, ['expectedType' => 'service'])
        );
        $this->assertCount(1, $messages);
        $this->assertStringContainsString('expects "service"', $messages[0]);
    }

    /**
     * Two independent things to fix, so two findings.
     *
     * @return void
     */
    public function testUnknownAndUnexpectedTypeProduceTwoFindings()
    {
        $messages = $this->failureMessages(
            $this->inspect(TierA4FixtureTypo::class, ['expectedType' => 'service'])
        );
        $this->assertCount(2, $messages);
    }

    /**
     * The message is asserted, not just the severity. An absent `$type` and a `$type` holding
     * null both reach a skip, and only the wording separates "you never declared this" from
     * "you declared it wrong" — a mutant that deleted the absence branch entirely stayed
     * green until this pinned it.
     *
     * @return void
     */
    public function testMissingTypePropertyIsSkipped()
    {
        $findings = $this->inspect(TierA4FixtureNoType::class);
        $this->assertCount(1, $findings);
        $this->assertTrue($findings[0]->isSkipped());
        $this->assertStringContainsString('declares no public static $type', $findings[0]->message());
    }

    /**
     * @return void
     */
    public function testNonStringTypeIsSkipped()
    {
        $findings = $this->inspect(TierA4FixtureArrayType::class);
        $this->assertCount(1, $findings);
        $this->assertTrue($findings[0]->isSkipped());
        $this->assertStringContainsString('holds array', $findings[0]->message());
    }

    /**
     * The shape of the ten `*-module` fleet packages, all of which declare `$type = 'module'`
     * alongside a `$settings` initializer referencing an undefined billing constant. PHP
     * evaluates every static initializer of a class on first access to any of them, so
     * `getValue()` throws on a `$type` that is a plain string literal — which is why this
     * inspector used to skip a seventh of the fleet on the one check that catches a typo
     * nothing else in MyAdmin would ever report.
     *
     * @return void
     */
    public function testPoisonedButRecoverableTypeIsJudgedNotSkipped()
    {
        $this->assertSame([], $this->inspect(TierA4FixturePoisonedModule::class));
    }

    /**
     * The reason the skip was worth removing. `'srevice'` is invisible at runtime — the
     * plugin simply never appears under any heading — so an inspector that skips instead of
     * reading it leaves the defect undetectable everywhere.
     *
     * @return void
     */
    public function testPoisonedButRecoverableUnknownTypeFails()
    {
        $messages = $this->failureMessages($this->inspect(TierA4FixturePoisonedTypo::class));
        $this->assertCount(1, $messages);
        $this->assertStringContainsString('"srevice"', $messages[0]);
    }

    /**
     * The expected-type half of the check has to reach the poisoned packages too, otherwise a
     * repo's own declaration of intent is unenforced on exactly the packages that need it.
     *
     * @return void
     */
    public function testPoisonedTypeIsStillComparedAgainstTheExpectedOne()
    {
        $messages = $this->failureMessages(
            $this->inspect(TierA4FixturePoisonedModule::class, ['expectedType' => 'service'])
        );
        $this->assertCount(1, $messages);
        $this->assertStringContainsString('expects "service"', $messages[0]);
    }

    /**
     * A `$type` whose own initializer cannot be recovered still skips — with the reason, and
     * without claiming the property is absent. Those are different defects with different
     * fixes; conflating them sends whoever reads the matrix hunting for a declaration that
     * is already there.
     *
     * @return void
     */
    public function testUnrecoverableTypeSkipsWithTheReasonAndDoesNotClaimAbsence()
    {
        $findings = $this->inspect(TierA4FixtureUnrecoverableType::class);
        $this->assertCount(1, $findings);
        $this->assertTrue($findings[0]->isSkipped());
        $this->assertStringContainsString('ABSENT', $findings[0]->message(), 'the swallowed error');
        $this->assertStringNotContainsString('declares no public static', $findings[0]->message());
        $this->assertSame('unevaluable', $findings[0]->context()['problem']);
    }

    /**
     * @return void
     */
    public function testMissingClassIsSkippedNotPassed()
    {
        $findings = $this->inspect('Tests\\MyAdmin\\Plugins\\Testing\\Contract\\TierA4FixtureAbsent');
        $this->assertCount(1, $findings);
        $this->assertTrue($findings[0]->isSkipped());
        $this->assertSame('A-4', $findings[0]->assertion());
    }
}

// ---------------------------------------------------------------------------
// Fixtures
// ---------------------------------------------------------------------------

class TierA4FixtureService
{
    public static $name = 'Tier A4 Service';
    public static $description = 'A service plugin.';
    public static $help = '';
    public static $type = 'service';
}

class TierA4FixturePlugin
{
    public static $name = 'Tier A4 Plugin';
    public static $description = 'A plain plugin.';
    public static $help = '';
    public static $type = 'plugin';
}

class TierA4FixtureModule
{
    public static $name = 'Tier A4 Module';
    public static $description = 'A module.';
    public static $help = '';
    public static $type = 'module';
}

class TierA4FixtureAddon
{
    public static $name = 'Tier A4 Addon';
    public static $description = 'An addon.';
    public static $help = '';
    public static $type = 'addon';
}

class TierA4FixtureTypo
{
    public static $name = 'Tier A4 Typo';
    public static $description = 'Misspelt type, silently invisible in the admin listing.';
    public static $help = '';
    public static $type = 'srevice';
}

class TierA4FixtureUppercase
{
    public static $name = 'Tier A4 Uppercase';
    public static $description = 'Right word, wrong case.';
    public static $help = '';
    public static $type = 'Service';
}

class TierA4FixtureNoType
{
    public static $name = 'Tier A4 No Type';
    public static $description = 'Declares no $type at all.';
    public static $help = '';
}

class TierA4FixtureArrayType
{
    public static $name = 'Tier A4 Array Type';
    public static $description = 'Type is an array.';
    public static $help = '';
    public static $type = ['service'];
}

/**
 * Holds no `ABSENT`. A missing *class* constant is what poisons the fixtures below without
 * needing a global constant name another test could accidentally define.
 */
class TierA4FixtureConstHolder
{
    const PRESENT = 'present';
}

/** The ten `*-module` fleet packages in miniature: `$type = 'module'`, poisoned `$settings`. */
class TierA4FixturePoisonedModule
{
    public static $name = 'Tier A4 Poisoned Module';
    public static $description = 'Valid type on a constant-poisoned class.';
    public static $help = '';
    public static $type = 'module';

    /** @var array<string,mixed> the poison — an array, which the fallback never evaluates */
    public static $settings = ['REPEAT_BILLING_METHOD' => TierA4FixtureConstHolder::ABSENT];
}

/** Poisoned like the fleet, and separately wrong: a recoverable misspelt `$type`. */
class TierA4FixturePoisonedTypo
{
    public static $name = 'Tier A4 Poisoned Typo';
    public static $description = 'Constant-poisoned, and the type is misspelt.';
    public static $help = '';
    public static $type = 'srevice';

    /** @var array<string,mixed> */
    public static $settings = ['REPEAT_BILLING_METHOD' => TierA4FixtureConstHolder::ABSENT];
}

/** `$type`'s own initializer is the constant expression, so no literal can be recovered. */
class TierA4FixtureUnrecoverableType
{
    public static $name = 'Tier A4 Unrecoverable Type';
    public static $description = 'The type itself is a constant expression.';
    public static $help = '';
    public static $type = TierA4FixtureConstHolder::ABSENT;
}
