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
use MyAdmin\Plugins\Testing\Contract\TierA7HookKeyScoping;
use MyAdmin\Plugins\Testing\Contract\TierB9bHookKeysDispatched;
use PHPUnit\Framework\TestCase;

/**
 * Fixtures live at the bottom of this file with a `TierA7Fixture` prefix — unique per file,
 * because every test in this directory shares one process.
 *
 * @covers \MyAdmin\Plugins\Testing\Contract\TierA7HookKeyScoping
 */
class TierA7HookKeyScopingTest extends TestCase
{
    /**
     * @param string $class
     * @return array<int,Finding>
     */
    private function inspect($class)
    {
        $inspector = new TierA7HookKeyScoping();
        return $inspector->inspect(new PluginSubject($class));
    }

    /**
     * @param array<int,Finding> $findings
     * @return Finding
     */
    private function soleSkip(array $findings)
    {
        $this->assertCount(1, $findings, 'expected exactly one finding');
        $this->assertSame('A-7', $findings[0]->assertion());
        $this->assertTrue($findings[0]->isSkipped(), 'expected a skip, got '.$findings[0]->severity());
        return $findings[0];
    }

    /**
     * @param array<int,Finding> $findings
     * @return Finding
     */
    private function soleFailure(array $findings)
    {
        $this->assertCount(1, $findings, 'expected exactly one finding');
        $this->assertSame('A-7', $findings[0]->assertion());
        $this->assertTrue($findings[0]->isFailure(), 'expected a failure, got '.$findings[0]->severity());
        return $findings[0];
    }

    /**
     * @return void
     */
    public function testReportsItsCatalogueIdentity()
    {
        $inspector = new TierA7HookKeyScoping();
        $this->assertSame('A-7', $inspector->id());
        $this->assertNotSame('', $inspector->title());
    }

    /**
     * The vocabulary is not A-7's to hold. B-9b derives it from every dispatch site in core
     * and in every vendor plugin; A-7 aliases that constant so the two cannot disagree.
     * They did: A-7 carried six of the nine and false-failed `licenses.deactivate_key`.
     *
     * The exact membership is pinned by {@see TierB9bHookKeysDispatchedTest}, and the
     * behavioural consequences by {@see HookKeyVocabularyTest}. Re-listing the keys here
     * would recreate the copy this fix removed.
     *
     * @return void
     */
    public function testGlobalVocabularyIsAliasedFromTheDispatchVocabulary()
    {
        $this->assertSame(
            TierB9bHookKeysDispatched::LITERAL_KEYS,
            TierA7HookKeyScoping::GLOBAL_HOOK_KEYS,
            'A-7 must not carry its own copy of the hook-key vocabulary; B-9b owns it.'
        );
        $this->assertNotSame([], TierA7HookKeyScoping::GLOBAL_HOOK_KEYS);
    }

    /**
     * `isGlobalKey()` is the published predicate behind the constant, and it matches whole
     * keys — never prefixes, and never the `$module.'.<suffix>'` half of B-9b's vocabulary.
     *
     * @return void
     */
    public function testIsGlobalKeyMatchesWholeKeysOnly()
    {
        $this->assertTrue(TierA7HookKeyScoping::isGlobalKey('ui.menu'));
        $this->assertTrue(TierA7HookKeyScoping::isGlobalKey('licenses.deactivate_key'));
        $this->assertFalse(TierA7HookKeyScoping::isGlobalKey('ui'));
        $this->assertFalse(TierA7HookKeyScoping::isGlobalKey('ui.dashboard'));
        $this->assertFalse(TierA7HookKeyScoping::isGlobalKey('licenses'));
        $this->assertFalse(TierA7HookKeyScoping::isGlobalKey('licenses.activate'));
        $this->assertFalse(TierA7HookKeyScoping::isGlobalKey('vps.activate'));
        // Strict comparison, and load-bearing on the package's PHP 7.4 floor: there
        // `0 == 'system.settings'` is true, so a loose `in_array()` would hand a numeric
        // hook key — A-6's business — a global pass.
        $this->assertFalse(TierA7HookKeyScoping::isGlobalKey(0));
    }

    /**
     * The sentinel predicate is derived from `ConstantStub::SENTINEL_FORMAT`, so it has to
     * recognise a real sentinel and reject anything a plugin author would plausibly write.
     *
     * @return void
     */
    public function testStubSentinelIsRecognisedWithoutSwallowingRealModuleNames()
    {
        $this->assertTrue(TierA7HookKeyScoping::isStubSentinel('__STUB_VPS_MODULE__'));
        $this->assertFalse(TierA7HookKeyScoping::isStubSentinel('vps'));
        $this->assertFalse(TierA7HookKeyScoping::isStubSentinel(''));
        // Prefix without the closing marker, closing marker without the prefix, and the
        // degenerate `sprintf(SENTINEL_FORMAT, '')` — one per clause of the shape test.
        $this->assertFalse(TierA7HookKeyScoping::isStubSentinel('__STUB_VPS_MODULE'));
        $this->assertFalse(TierA7HookKeyScoping::isStubSentinel('STUB_VPS__'));
        $this->assertFalse(TierA7HookKeyScoping::isStubSentinel('__STUB___'));
    }

    // -----------------------------------------------------------------------
    // Passing path
    // -----------------------------------------------------------------------

    /**
     * @return void
     */
    public function testGlobalKeysWithoutAModulePass()
    {
        $this->assertSame([], $this->inspect(TierA7FixtureGlobalsOnly::class));
    }

    /**
     * The six core-wide events, spelled out as a fixture. The other three entries in the
     * vocabulary — core's literal `licenses.*` dispatches — are covered by
     * {@see testKeysCoreDispatchesLiterallyAreAcceptedUnderAnyModule} below, and the whole
     * vocabulary is swept by {@see HookKeyVocabularyTest}.
     *
     * @return void
     */
    public function testTheSixCoreWideGlobalKeysAreAccepted()
    {
        $this->assertSame([], $this->inspect(TierA7FixtureAllGlobals::class));
    }

    /**
     * REGRESSION — defect (a) of R-7.
     *
     * `licenses.deactivate_key`, `licenses.deactivate_ip` and `licenses.change_ip` are
     * dispatched from literal strings in core, so a plugin owning some *other* module may
     * listen to them and will be called. A-7 used to fail all three, with a message claiming
     * the prefix was one "nothing dispatches to" — while B-9b, over the same class in the
     * same run, reported them as dispatched.
     *
     * @return void
     */
    public function testKeysCoreDispatchesLiterallyAreAcceptedUnderAnyModule()
    {
        $this->assertSame([], $this->inspect(TierA7FixtureLicensesListener::class));
    }

    /**
     * @return void
     */
    public function testModuleScopedKeysMatchingTheDeclaredModulePass()
    {
        $this->assertSame([], $this->inspect(TierA7FixtureScopedCorrectly::class));
    }

    /**
     * @return void
     */
    public function testGlobalsMixedWithCorrectlyScopedKeysPass()
    {
        $this->assertSame([], $this->inspect(TierA7FixtureMixed::class));
    }

    /**
     * `ui.menu` is global, but `ui` is not a global *prefix* — a plugin that owns the `ui`
     * module may legitimately register `ui.dashboard`.
     *
     * @return void
     */
    public function testNonGlobalKeyUnderAGlobalPrefixPassesWhenItMatchesTheModule()
    {
        $this->assertSame([], $this->inspect(TierA7FixtureOwnsUiModule::class));
    }

    // -----------------------------------------------------------------------
    // Failing path — the bug class: a module-scoped hook that nothing dispatches to
    // -----------------------------------------------------------------------

    /**
     * @return void
     */
    public function testModuleScopedKeyWithNoDeclaredModuleFails()
    {
        $finding = $this->soleFailure($this->inspect(TierA7FixtureNoModule::class));
        $this->assertSame('no-module', $finding->context()['problem']);
        $this->assertStringContainsString('declares no $module', $finding->message());
        $this->assertStringContainsString('a7vps.activate', $finding->message());
        $this->assertStringContainsString('public static $module = \'a7vps\';', $finding->message());
    }

    /**
     * @return void
     */
    public function testEmptyModuleIsTreatedAsNoModule()
    {
        $finding = $this->soleFailure($this->inspect(TierA7FixtureEmptyModule::class));
        $this->assertSame('no-module', $finding->context()['problem']);
    }

    /**
     * @return void
     */
    public function testNonStringModuleIsTreatedAsNoModule()
    {
        $finding = $this->soleFailure($this->inspect(TierA7FixtureNullModule::class));
        $this->assertSame('no-module', $finding->context()['problem']);
    }

    /**
     * The key's suffix (`activate`) is one B-9b knows is dispatched per module, so the
     * defect is that it is dispatched for *someone else's* module. A-7 used to assert
     * flatly that nothing dispatched the prefix — a claim about the fleet's dispatch sites
     * that B-9b's own data contradicts.
     *
     * @return void
     */
    public function testPrefixThatDisagreesWithTheDeclaredModuleFails()
    {
        $finding = $this->soleFailure($this->inspect(TierA7FixtureMismatchedModule::class));
        $this->assertSame('prefix-mismatch', $finding->context()['problem']);
        $this->assertSame('a7vps', $finding->context()['prefix']);
        $this->assertSame('a7vpsaddon', $finding->context()['module']);
        $this->assertTrue($finding->context()['dispatched']);
        $this->assertStringContainsString('for the "a7vps" module, not for this one', $finding->message());
        $this->assertStringNotContainsString('nothing dispatches to', $finding->message());
    }

    /**
     * The cloudlinux-licensing shape, and the one live A-7 failure in the fleet matrix:
     * `plugin.install` under `$module = "licenses"`. Nothing dispatches the `plugin.` prefix
     * in any form, so the original wording is the right one here and must survive — this is
     * a genuine P-bug and the fix must not silence it.
     *
     * @return void
     */
    public function testPrefixNothingDispatchesAtAllKeepsTheBlunterWording()
    {
        $findings = $this->inspect(TierA7FixtureCloudlinuxShaped::class);
        $this->assertCount(2, $findings);
        $keys = [$findings[0]->context()['key'], $findings[1]->context()['key']];
        sort($keys);
        $this->assertSame(['plugin.install', 'plugin.uninstall'], $keys);
        foreach ($findings as $finding) {
            $this->assertTrue($finding->isFailure());
            $this->assertSame('prefix-mismatch', $finding->context()['problem']);
            $this->assertFalse($finding->context()['dispatched']);
            $this->assertStringContainsString('nothing dispatches to', $finding->message());
        }
    }

    // -----------------------------------------------------------------------
    // Input validation — defect (b) of R-7: an unreadable $module is a skip
    // -----------------------------------------------------------------------

    /**
     * REGRESSION — the self-refuting message.
     *
     * The fixture's source reads `public static string $module = 'a7typed';`. The typed
     * declaration is invisible to `PluginSubject`'s source fallback, and a sibling static
     * whose initializer names an undefined constant makes PHP throw on *any* static read of
     * the class — the exact shape of the ten `*-module` fleet packages. A-7 used to read the
     * resulting null as "no module" and emit
     *
     *     … declares no $module. … Add "public static $module = 'a7typed';"
     *
     * quoting back the value the class already declares.
     *
     * @return void
     */
    public function testUnevaluableModuleIsSkippedNotFailed()
    {
        $finding = $this->soleSkip($this->inspect(TierA7FixtureTypedModule::class));
        $this->assertSame('unevaluable-module', $finding->context()['problem']);
        $this->assertStringContainsString('could not be determined', $finding->message());
        $this->assertStringContainsString('A7_FIXTURE_UNDEFINED_BILLING', $finding->message());
        $this->assertStringNotContainsString('declares no $module', $finding->message());
        $this->assertStringNotContainsString('Add "public static $module', $finding->message());
        $this->assertSame(['a7typed.activate'], $finding->context()['keys']);
    }

    /**
     * A table of nothing but global keys is decidable without a `$module`, so an unreadable
     * one must not drag it into a skip. Downgrading a real pass to "could not tell" is the
     * mirror-image mistake of the failure this fix removes.
     *
     * @return void
     */
    public function testUnevaluableModuleStillPassesWhenEveryKeyIsGlobal()
    {
        $this->assertSame([], $this->inspect(TierA7FixtureTypedModuleGlobalsOnly::class));
    }

    /**
     * A `$module` that resolves to a `ConstantStub` placeholder is a harness artefact, not a
     * declared module name. Comparing prefixes against it invents a mismatch that exists
     * only under test.
     *
     * @return void
     */
    public function testStubSentinelModuleIsSkippedNotFailed()
    {
        $finding = $this->soleSkip($this->inspect(TierA7FixtureStubbedModule::class));
        $this->assertSame('stubbed-module', $finding->context()['problem']);
        $this->assertStringContainsString('ConstantStub placeholder', $finding->message());
        $this->assertSame('__STUB_A7_FIXTURE_MODULE__', $finding->context()['module']);
    }

    /**
     * A plugin that genuinely declares no `$module` is still a failure — the guard keys off
     * the *recovery error*, not off emptiness, so it must not swallow the real defect.
     *
     * @return void
     */
    public function testAbsentModuleIsStillAFailureNotASkip()
    {
        $finding = $this->soleFailure($this->inspect(TierA7FixtureNoModule::class));
        $this->assertSame('no-module', $finding->context()['problem']);
    }

    /**
     * A key that merely starts with a global prefix is not global — matching is against the
     * whole key.
     *
     * @return void
     */
    public function testGlobalPrefixDoesNotLicenseArbitraryEventsUnderIt()
    {
        $finding = $this->soleFailure($this->inspect(TierA7FixtureFakeGlobal::class));
        $this->assertSame('no-module', $finding->context()['problem']);
        $this->assertSame('ui.dashboard', $finding->context()['key']);
    }

    /**
     * @return void
     */
    public function testEachOffendingKeyProducesItsOwnFinding()
    {
        $findings = $this->inspect(TierA7FixtureSeveralOffenders::class);
        $this->assertCount(2, $findings);
        $keys = [$findings[0]->context()['key'], $findings[1]->context()['key']];
        $this->assertSame(['a7other.activate', 'a7third.queue'], $keys);
        foreach ($findings as $finding) {
            $this->assertTrue($finding->isFailure());
        }
    }

    // -----------------------------------------------------------------------
    // Boundaries
    // -----------------------------------------------------------------------

    /**
     * A non-string key has no prefix to scope; A-6 owns it.
     *
     * @return void
     */
    public function testNonStringKeysAreLeftToTierA6()
    {
        $this->assertSame([], $this->inspect(TierA7FixtureNumericKey::class));
    }

    /**
     * @return void
     */
    public function testUnobtainableHookTableIsSkipped()
    {
        $findings = $this->inspect(TierA7FixtureNoGetHooks::class);
        $this->assertCount(1, $findings);
        $this->assertTrue($findings[0]->isSkipped());
        $this->assertStringContainsString('see A-5', $findings[0]->message());
    }

    /**
     * @return void
     */
    public function testMissingClassIsSkippedNotPassed()
    {
        $findings = $this->inspect('Tests\\MyAdmin\\Plugins\\Testing\\Contract\\TierA7FixtureAbsent');
        $this->assertCount(1, $findings);
        $this->assertTrue($findings[0]->isSkipped());
        $this->assertSame('A-7', $findings[0]->assertion());
    }
}

// ---------------------------------------------------------------------------
// Fixtures
// ---------------------------------------------------------------------------

class TierA7FixtureGlobalsOnly
{
    /**
     * @return array<string,array<int,string>>
     */
    public static function getHooks()
    {
        return [
            'system.settings' => [__CLASS__, 'getSettings'],
            'ui.menu' => [__CLASS__, 'getMenu'],
        ];
    }
}

class TierA7FixtureAllGlobals
{
    /**
     * @return array<string,array<int,string>>
     */
    public static function getHooks()
    {
        return [
            'system.settings' => [__CLASS__, 'a'],
            'function.requirements' => [__CLASS__, 'b'],
            'ui.menu' => [__CLASS__, 'c'],
            'api.register' => [__CLASS__, 'd'],
            'account.activated' => [__CLASS__, 'e'],
            'mailinglist.subscribe' => [__CLASS__, 'f'],
        ];
    }
}

class TierA7FixtureScopedCorrectly
{
    public static $module = 'a7vps';

    /**
     * @return array<string,array<int,string>>
     */
    public static function getHooks()
    {
        return [
            'a7vps.activate' => [__CLASS__, 'getActivate'],
            'a7vps.deactivate' => [__CLASS__, 'getDeactivate'],
        ];
    }
}

class TierA7FixtureMixed
{
    public static $module = 'a7vps';

    /**
     * @return array<string,array<int,string>>
     */
    public static function getHooks()
    {
        return [
            'ui.menu' => [__CLASS__, 'getMenu'],
            'a7vps.settings' => [__CLASS__, 'getSettings'],
            'function.requirements' => [__CLASS__, 'getRequirements'],
        ];
    }
}

class TierA7FixtureOwnsUiModule
{
    public static $module = 'ui';

    /**
     * @return array<string,array<int,string>>
     */
    public static function getHooks()
    {
        return [
            'ui.menu' => [__CLASS__, 'getMenu'],
            'ui.dashboard' => [__CLASS__, 'getDashboard'],
        ];
    }
}

class TierA7FixtureNoModule
{
    /**
     * @return array<string,array<int,string>>
     */
    public static function getHooks()
    {
        return ['a7vps.activate' => [__CLASS__, 'getActivate']];
    }
}

class TierA7FixtureEmptyModule
{
    public static $module = '';

    /**
     * @return array<string,array<int,string>>
     */
    public static function getHooks()
    {
        return ['a7vps.activate' => [__CLASS__, 'getActivate']];
    }
}

class TierA7FixtureNullModule
{
    public static $module = null;

    /**
     * @return array<string,array<int,string>>
     */
    public static function getHooks()
    {
        return ['a7vps.activate' => [__CLASS__, 'getActivate']];
    }
}

class TierA7FixtureMismatchedModule
{
    public static $module = 'a7vpsaddon';

    /**
     * @return array<string,array<int,string>>
     */
    public static function getHooks()
    {
        return ['a7vps.activate' => [__CLASS__, 'getActivate']];
    }
}

/**
 * The shape of `detain/myadmin-cloudlinux-licensing`, the fleet's one live A-7 failure.
 */
class TierA7FixtureCloudlinuxShaped
{
    public static $module = 'licenses';

    /**
     * @return array<string,array<int,string>>
     */
    public static function getHooks()
    {
        return [
            'plugin.install' => [__CLASS__, 'getInstall'],
            'plugin.uninstall' => [__CLASS__, 'getUninstall'],
            'licenses.settings' => [__CLASS__, 'getSettings'],
            'licenses.activate' => [__CLASS__, 'getActivate'],
            'licenses.deactivate_ip' => [__CLASS__, 'getDeactivateIp'],
            'licenses.change_ip' => [__CLASS__, 'getChangeIp'],
            'function.requirements' => [__CLASS__, 'getRequirements'],
            'ui.menu' => [__CLASS__, 'getMenu'],
        ];
    }
}

/**
 * Owns `a7cpanel`, but listens to the three `licenses.*` events core dispatches from
 * literal strings. Legitimate, and A-7 used to fail all three.
 */
class TierA7FixtureLicensesListener
{
    public static $module = 'a7cpanel';

    /**
     * @return array<string,array<int,string>>
     */
    public static function getHooks()
    {
        return [
            'licenses.deactivate_key' => [__CLASS__, 'getDeactivateKey'],
            'licenses.deactivate_ip' => [__CLASS__, 'getDeactivateIp'],
            'licenses.change_ip' => [__CLASS__, 'getChangeIp'],
            'a7cpanel.activate' => [__CLASS__, 'getActivate'],
            'ui.menu' => [__CLASS__, 'getMenu'],
        ];
    }
}

/**
 * `$module` is declared, and its value is right there in the source — but the declaration is
 * typed, which the source fallback's modifier scan does not match, and the sibling `$settings`
 * initializer names a constant that is never defined, so PHP throws on every static read of
 * this class. That combination is what made A-7's message self-refuting.
 *
 * `A7_FIXTURE_UNDEFINED_BILLING` must stay undefined for the life of the process. Nothing
 * defines it; `ConstantStub` is never pointed at this file.
 */
class TierA7FixtureTypedModule
{
    /** @var array<string,mixed> */
    public static $settings = ['REPEAT_BILLING_METHOD' => A7_FIXTURE_UNDEFINED_BILLING];

    /** @var string */
    public static string $module = 'a7typed';

    /**
     * @return array<string,array<int,string>>
     */
    public static function getHooks()
    {
        return ['a7typed.activate' => [__CLASS__, 'getActivate']];
    }
}

/**
 * Same unreadable `$module`, but every key is global — so the verdict does not depend on it
 * and must stay a pass.
 */
class TierA7FixtureTypedModuleGlobalsOnly
{
    /** @var array<string,mixed> */
    public static $settings = ['REPEAT_BILLING_METHOD' => A7_FIXTURE_UNDEFINED_BILLING];

    /** @var string */
    public static string $module = 'a7typed';

    /**
     * @return array<string,array<int,string>>
     */
    public static function getHooks()
    {
        return [
            'ui.menu' => [__CLASS__, 'getMenu'],
            'licenses.change_ip' => [__CLASS__, 'getChangeIp'],
        ];
    }
}

/**
 * `public static $module = SOME_CONSTANT;` after `ConstantStub` has primed the constant.
 * Written as the resulting literal so the fixture does not have to define a process-global
 * constant to reproduce the value.
 */
class TierA7FixtureStubbedModule
{
    public static $module = '__STUB_A7_FIXTURE_MODULE__';

    /**
     * @return array<string,array<int,string>>
     */
    public static function getHooks()
    {
        return ['a7vps.activate' => [__CLASS__, 'getActivate']];
    }
}

class TierA7FixtureFakeGlobal
{
    /**
     * @return array<string,array<int,string>>
     */
    public static function getHooks()
    {
        return [
            'ui.menu' => [__CLASS__, 'getMenu'],
            'ui.dashboard' => [__CLASS__, 'getDashboard'],
        ];
    }
}

class TierA7FixtureSeveralOffenders
{
    public static $module = 'a7vps';

    /**
     * @return array<string,array<int,string>>
     */
    public static function getHooks()
    {
        return [
            'a7vps.activate' => [__CLASS__, 'getActivate'],
            'a7other.activate' => [__CLASS__, 'getOther'],
            'ui.menu' => [__CLASS__, 'getMenu'],
            'a7third.queue' => [__CLASS__, 'getThird'],
        ];
    }
}

class TierA7FixtureNumericKey
{
    /**
     * @return array<mixed,array<int,string>>
     */
    public static function getHooks()
    {
        return ['0' => [__CLASS__, 'getActivate']];
    }
}

class TierA7FixtureNoGetHooks
{
    public static $module = 'a7vps';
}
