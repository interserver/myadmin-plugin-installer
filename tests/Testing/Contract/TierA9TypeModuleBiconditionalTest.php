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
use MyAdmin\Plugins\Testing\Contract\TierA9TypeModuleBiconditional;
use PHPUnit\Framework\TestCase;
use Tests\MyAdmin\Plugins\Testing\Fixtures\UnevaluableMetadataPlugin;

/**
 * Fixtures live at the bottom of this file with a `TierA9Fixture` prefix — unique per file,
 * because every test in this directory shares one process.
 *
 * @covers \MyAdmin\Plugins\Testing\Contract\TierA9TypeModuleBiconditional
 */
class TierA9TypeModuleBiconditionalTest extends TestCase
{
    /**
     * @param string $class
     * @return array<int,Finding>
     */
    private function inspect($class)
    {
        $inspector = new TierA9TypeModuleBiconditional();
        return $inspector->inspect(new PluginSubject($class));
    }

    /**
     * @param array<int,Finding> $findings
     * @return Finding
     */
    private function soleFailure(array $findings)
    {
        $this->assertCount(1, $findings, 'expected exactly one finding');
        $this->assertSame('A-9', $findings[0]->assertion());
        $this->assertTrue($findings[0]->isFailure(), 'expected a failure, got '.$findings[0]->severity());
        return $findings[0];
    }

    /**
     * @param array<int,Finding> $findings
     * @return Finding
     */
    private function soleSkip(array $findings)
    {
        $this->assertCount(1, $findings, 'expected exactly one finding');
        $this->assertSame('A-9', $findings[0]->assertion());
        $this->assertTrue($findings[0]->isSkipped(), 'expected a skip, got '.$findings[0]->severity());
        return $findings[0];
    }

    /**
     * @return void
     */
    public function testReportsItsCatalogueIdentity()
    {
        $inspector = new TierA9TypeModuleBiconditional();
        $this->assertSame('A-9', $inspector->id());
        $this->assertNotSame('', $inspector->title());
    }

    /**
     * The unscoped type is published rather than re-typed wherever it is needed.
     *
     * @return void
     */
    public function testTheUnscopedTypeIsPublished()
    {
        $this->assertSame('plugin', TierA9TypeModuleBiconditional::UNSCOPED_TYPE);
    }

    // -----------------------------------------------------------------------
    // Passing path — both directions of the biconditional
    // -----------------------------------------------------------------------

    /**
     * @return void
     */
    public function testPluginWithoutAModulePasses()
    {
        $this->assertSame([], $this->inspect(TierA9FixturePluginNoModule::class));
    }

    /**
     * @return void
     */
    public function testPluginDeclaringAnEmptyModulePasses()
    {
        $this->assertSame([], $this->inspect(TierA9FixturePluginEmptyModule::class));
    }

    /**
     * A `$module` that is declared as null is no module at all — the same reading A-7 takes.
     *
     * @return void
     */
    public function testPluginDeclaringANullModulePasses()
    {
        $this->assertSame([], $this->inspect(TierA9FixturePluginNullModule::class));
    }

    /**
     * @return array<string,array{0:string}>
     */
    public function scopedTypeProvider()
    {
        return [
            'service' => [TierA9FixtureServiceWithModule::class],
            'module' => [TierA9FixtureModuleWithModule::class],
            'addon' => [TierA9FixtureAddonWithModule::class],
        ];
    }

    /**
     * @dataProvider scopedTypeProvider
     * @param string $class
     * @return void
     */
    public function testEveryScopedTypeWithAModulePasses($class)
    {
        $this->assertSame([], $this->inspect($class));
    }

    // -----------------------------------------------------------------------
    // Failing path — the two contradictions
    // -----------------------------------------------------------------------

    /**
     * @return void
     */
    public function testPluginThatAlsoClaimsAModuleFails()
    {
        $finding = $this->soleFailure($this->inspect(TierA9FixturePluginWithModule::class));
        $this->assertSame('unscoped-type-with-module', $finding->context()['problem']);
        $this->assertSame('plugin', $finding->context()['type']);
        $this->assertSame('a9claimed', $finding->context()['module']);
        $this->assertStringContainsString('contradict each other', $finding->message());
    }

    /**
     * @return array<string,array{0:string,1:string}>
     */
    public function scopedWithoutModuleProvider()
    {
        return [
            'service, no $module at all' => [TierA9FixtureServiceNoModule::class, 'service'],
            'module, empty $module' => [TierA9FixtureModuleEmptyModule::class, 'module'],
            'addon, null $module' => [TierA9FixtureAddonNullModule::class, 'addon'],
        ];
    }

    /**
     * @dataProvider scopedWithoutModuleProvider
     * @param string $class
     * @param string $type
     * @return void
     */
    public function testScopedTypeWithoutAModuleFails($class, $type)
    {
        $finding = $this->soleFailure($this->inspect($class));
        $this->assertSame('scoped-type-without-module', $finding->context()['problem']);
        $this->assertSame($type, $finding->context()['type']);
        $this->assertSame('', $finding->context()['module']);
        $this->assertStringContainsString('see A-7', $finding->message());
    }

    /**
     * An unknown `$type` is still not `"plugin"`, so it still needs a `$module`. A-4 owns the
     * spelling; A-9 owns the pairing, and the two are independent defects.
     *
     * @return void
     */
    public function testAnUnknownTypeIsStillHeldToTheScopedRule()
    {
        $finding = $this->soleFailure($this->inspect(TierA9FixtureUnknownTypeNoModule::class));
        $this->assertSame('scoped-type-without-module', $finding->context()['problem']);
        $this->assertSame('srevice', $finding->context()['type']);
    }

    // -----------------------------------------------------------------------
    // Skips — never double-count another inspector's root cause
    // -----------------------------------------------------------------------

    /**
     * @return void
     */
    public function testMissingTypeIsSkippedNotFailed()
    {
        $finding = $this->soleSkip($this->inspect(TierA9FixtureNoType::class));
        // Branch-specific on purpose: the "unresolvable $type" skip below also cites A-2,
        // so matching on that alone would let the two branches be collapsed unnoticed.
        $this->assertStringContainsString('declares no public static $type', $finding->message());
    }

    /**
     * @return void
     */
    public function testNonStringTypeIsSkipped()
    {
        $finding = $this->soleSkip($this->inspect(TierA9FixtureNonStringType::class));
        $this->assertSame('integer', $finding->context()['found']);
        $this->assertNull($finding->context()['error']);
    }

    /**
     * An empty `$type` belongs to A-3. Failing it here as "not plugin, so needs a module"
     * would report one typo as two defects.
     *
     * @return void
     */
    public function testEmptyTypeIsSkipped()
    {
        $finding = $this->soleSkip($this->inspect(TierA9FixtureEmptyType::class));
        $this->assertStringContainsString('could not be resolved to a non-empty string', $finding->message());
        $this->assertStringContainsString('A-3', $finding->message());
    }

    /**
     * @return void
     */
    public function testMissingClassIsSkippedNotPassed()
    {
        $finding = $this->soleSkip(
            $this->inspect('Tests\\MyAdmin\\Plugins\\Testing\\Contract\\TierA9FixtureAbsent')
        );
        $this->assertStringContainsString('see A-1', $finding->message());
    }

    /**
     * A `$module` that is declared but unresolvable must not be read as "no module": that
     * would turn a harness limitation into a plugin failure.
     *
     * @return void
     */
    public function testAModuleThatCannotBeResolvedIsSkippedRatherThanRead()
    {
        $this->assertFalse(defined('TIER_A9_FIXTURE_MODULE_NAME'), 'must stay undefined for this to test anything');

        $finding = $this->soleSkip($this->inspect(TierA9FixtureUnresolvableModule::class));
        $this->assertSame('service', $finding->context()['type']);
        $this->assertStringContainsString('TIER_A9_FIXTURE_MODULE_NAME', $finding->context()['error']);
        $this->assertStringContainsString('would invent a failure', $finding->message());
    }

    // -----------------------------------------------------------------------
    // Integration with the PluginSubject source fallback
    // -----------------------------------------------------------------------

    /**
     * The ten `*-module` fleet packages reach this inspector with unevaluable initializers.
     * They must be **inspected**, not skipped — otherwise A-9 covers 59 of 69 packages while
     * the matrix claims it covers all of them.
     *
     * @return void
     */
    public function testAConstantPoisonedPluginIsInspectedRatherThanSkipped()
    {
        $this->assertFalse(defined('PLUGIN_SUBJECT_FIXTURE_BILLING'), 'the fixture must still be poisoned');

        $subject = new PluginSubject(UnevaluableMetadataPlugin::class);
        $this->assertIsString(
            $subject->staticPropertyError('type'),
            'this fixture is only meaningful while its initializers actually throw'
        );

        $inspector = new TierA9TypeModuleBiconditional();
        $this->assertSame([], $inspector->inspect($subject), 'type=module + $module set: passes');
    }

    /**
     * And the failing direction still reports through the fallback — a defect must not become
     * invisible just because the package also has a poisoned initializer.
     *
     * @return void
     */
    public function testAConstantPoisonedPluginStillReportsItsContradiction()
    {
        $finding = $this->soleFailure($this->inspect(TierA9FixturePoisonedPluginWithModule::class));
        $this->assertSame('unscoped-type-with-module', $finding->context()['problem']);
        $this->assertSame('plugin', $finding->context()['type']);
        $this->assertSame('a9poisoned', $finding->context()['module']);
    }
}

// ---------------------------------------------------------------------------
// Fixtures
// ---------------------------------------------------------------------------

class TierA9FixturePluginNoModule
{
    /** @var string */
    public static $type = 'plugin';
}

class TierA9FixturePluginEmptyModule
{
    /** @var string */
    public static $type = 'plugin';

    /** @var string */
    public static $module = '';
}

class TierA9FixturePluginNullModule
{
    /** @var string */
    public static $type = 'plugin';

    /** @var string|null */
    public static $module = null;
}

class TierA9FixtureServiceWithModule
{
    /** @var string */
    public static $type = 'service';

    /** @var string */
    public static $module = 'a9vps';
}

class TierA9FixtureModuleWithModule
{
    /** @var string */
    public static $type = 'module';

    /** @var string */
    public static $module = 'a9mail';
}

class TierA9FixtureAddonWithModule
{
    /** @var string */
    public static $type = 'addon';

    /** @var string */
    public static $module = 'a9vpsaddon';
}

class TierA9FixturePluginWithModule
{
    /** @var string */
    public static $type = 'plugin';

    /** @var string */
    public static $module = 'a9claimed';
}

class TierA9FixtureServiceNoModule
{
    /** @var string */
    public static $type = 'service';
}

class TierA9FixtureModuleEmptyModule
{
    /** @var string */
    public static $type = 'module';

    /** @var string */
    public static $module = '';
}

class TierA9FixtureAddonNullModule
{
    /** @var string */
    public static $type = 'addon';

    /** @var string|null */
    public static $module = null;
}

class TierA9FixtureUnknownTypeNoModule
{
    /** @var string */
    public static $type = 'srevice';
}

class TierA9FixtureNoType
{
    /** @var string */
    public static $module = 'a9orphan';
}

class TierA9FixtureNonStringType
{
    /** @var int */
    public static $type = 4;

    /** @var string */
    public static $module = 'a9numeric';
}

class TierA9FixtureEmptyType
{
    /** @var string */
    public static $type = '';

    /** @var string */
    public static $module = 'a9blank';
}

/**
 * `$module` is declared but initialised from a constant that is never defined, so neither
 * reflection nor the source fallback can produce a value.
 */
class TierA9FixtureUnresolvableModule
{
    /** @var string */
    public static $type = 'service';

    /** @var string */
    public static $module = TIER_A9_FIXTURE_MODULE_NAME;
}

/**
 * The fleet's ten-package shape, but contradictory: plain-literal metadata that A-9 can
 * recover from source, alongside an initializer that poisons the class.
 */
class TierA9FixturePoisonedPluginWithModule
{
    /** @var string */
    public static $type = 'plugin';

    /** @var string */
    public static $module = 'a9poisoned';

    /** @var array<string,mixed> */
    public static $settings = ['REPEAT_BILLING_METHOD' => TIER_A9_FIXTURE_BILLING];
}
