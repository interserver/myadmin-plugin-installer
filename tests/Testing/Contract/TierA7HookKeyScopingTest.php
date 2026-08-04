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
     * The vocabulary is a published constant so the matrix, the docs and any future
     * inspector reference one list instead of re-typing it.
     *
     * @return void
     */
    public function testGlobalVocabularyIsPublishedAndExact()
    {
        $this->assertSame(
            [
                'system.settings',
                'function.requirements',
                'ui.menu',
                'api.register',
                'account.activated',
                'mailinglist.subscribe',
            ],
            TierA7HookKeyScoping::GLOBAL_HOOK_KEYS
        );
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
     * @return void
     */
    public function testEveryGlobalKeyIsAccepted()
    {
        $this->assertSame([], $this->inspect(TierA7FixtureAllGlobals::class));
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
     * @return void
     */
    public function testPrefixThatDisagreesWithTheDeclaredModuleFails()
    {
        $finding = $this->soleFailure($this->inspect(TierA7FixtureMismatchedModule::class));
        $this->assertSame('prefix-mismatch', $finding->context()['problem']);
        $this->assertSame('a7vps', $finding->context()['prefix']);
        $this->assertSame('a7vpsaddon', $finding->context()['module']);
        $this->assertStringContainsString('nothing dispatches to', $finding->message());
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
