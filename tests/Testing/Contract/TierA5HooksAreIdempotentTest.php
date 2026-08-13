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
use MyAdmin\Plugins\Testing\Contract\TierA5HooksAreIdempotent;
use PHPUnit\Framework\TestCase;

/**
 * Fixtures live at the bottom of this file with a `TierA5Fixture` prefix — unique per file,
 * because every test in this directory shares one process.
 *
 * @covers \MyAdmin\Plugins\Testing\Contract\TierA5HooksAreIdempotent
 */
class TierA5HooksAreIdempotentTest extends TestCase
{
    /**
     * @param string $class
     * @return array<int,Finding>
     */
    private function inspect($class)
    {
        $inspector = new TierA5HooksAreIdempotent();
        return $inspector->inspect(new PluginSubject($class));
    }

    /**
     * @param array<int,Finding> $findings
     * @return Finding
     */
    private function soleFailure(array $findings)
    {
        $this->assertCount(1, $findings, 'expected exactly one finding');
        $this->assertSame('A-5', $findings[0]->assertion());
        $this->assertTrue($findings[0]->isFailure(), 'expected a failure, got '.$findings[0]->severity());
        return $findings[0];
    }

    /**
     * @return void
     */
    public function testReportsItsCatalogueIdentity()
    {
        $inspector = new TierA5HooksAreIdempotent();
        $this->assertSame('A-5', $inspector->id());
        $this->assertNotSame('', $inspector->title());
    }

    /**
     * @return void
     */
    public function testStableNonEmptyHookTablePasses()
    {
        $this->assertSame([], $this->inspect(TierA5FixtureGood::class));
    }

    /**
     * Declaration order is not part of the contract — the same keys mapped to the same
     * targets is idempotent even if the array is built in a different order.
     *
     * @return void
     */
    public function testReorderedButEqualTableIsStillIdempotent()
    {
        $this->assertSame([], $this->inspect(TierA5FixtureReordered::class));
    }

    /**
     * @return void
     */
    public function testMissingGetHooksFails()
    {
        $finding = $this->soleFailure($this->inspect(TierA5FixtureNoGetHooks::class));
        $this->assertStringContainsString('does not declare getHooks()', $finding->message());
        $this->assertSame('missing', $finding->context()['problem']);
    }

    /**
     * @return void
     */
    public function testProtectedGetHooksFails()
    {
        $finding = $this->soleFailure($this->inspect(TierA5FixtureProtectedGetHooks::class));
        $this->assertSame('not-public', $finding->context()['problem']);
    }

    /**
     * PluginScanner uses call_user_func([$class, 'getHooks']).
     *
     * @return void
     */
    public function testNonStaticGetHooksFails()
    {
        $finding = $this->soleFailure($this->inspect(TierA5FixtureInstanceGetHooks::class));
        $this->assertSame('not-static', $finding->context()['problem']);
    }

    /**
     * @return void
     */
    public function testGetHooksWithARequiredArgumentFails()
    {
        $finding = $this->soleFailure($this->inspect(TierA5FixtureArgumentGetHooks::class));
        $this->assertSame('requires-arguments', $finding->context()['problem']);
    }

    /**
     * A throwing getHooks() is exactly the condition PluginScanner swallows by keeping the
     * stale hooks.json entry, so it must be a failure here and must not escape the
     * inspector.
     *
     * @return void
     */
    public function testThrowingGetHooksIsReportedNotPropagated()
    {
        $finding = $this->soleFailure($this->inspect(TierA5FixtureThrowingGetHooks::class));
        $this->assertSame('threw', $finding->context()['problem']);
        $this->assertStringContainsString('tier-a5 fixture explosion', $finding->message());
    }

    /**
     * @return void
     */
    public function testNonArrayReturnFails()
    {
        $finding = $this->soleFailure($this->inspect(TierA5FixtureStringReturn::class));
        $this->assertSame('not-array', $finding->context()['problem']);
    }

    /**
     * Ten fleet packages return [] because their registrations are commented out on
     * purpose. This pin exists so nobody "tightens" A-5 into failing them: an empty table
     * is a pass, and it is still held to the idempotence rule.
     *
     * @return void
     */
    public function testEmptyHookTableIsNotAViolation()
    {
        $this->assertSame([], $this->inspect(TierA5FixtureEmptyReturn::class));
    }

    /**
     * @return void
     */
    public function testNonIdempotentHookTableFails()
    {
        $finding = $this->soleFailure($this->inspect(TierA5FixtureNonIdempotent::class));
        $this->assertSame('not-idempotent', $finding->context()['problem']);
        $this->assertStringContainsString('not idempotent', $finding->message());
    }

    /**
     * @return void
     */
    public function testMissingClassIsSkippedNotFailed()
    {
        $findings = $this->inspect('Tests\\MyAdmin\\Plugins\\Testing\\Contract\\TierA5FixtureAbsent');
        $this->assertCount(1, $findings);
        $this->assertTrue($findings[0]->isSkipped());
        $this->assertSame('A-5', $findings[0]->assertion());
    }

    // -----------------------------------------------------------------------
    // The shared accessor A-6/A-7/A-8 depend on
    // -----------------------------------------------------------------------

    /**
     * @return void
     */
    public function testHookTableReturnsTheArrayWhenItIsObtainable()
    {
        $hooks = TierA5HooksAreIdempotent::hookTable(new PluginSubject(TierA5FixtureGood::class));
        $this->assertSame(['a5mod.settings' => [TierA5FixtureGood::class, 'getSettings']], $hooks);
    }

    /**
     * @return void
     */
    public function testHookTableReturnsNullWhenGetHooksCannotBeCalled()
    {
        $this->assertNull(
            TierA5HooksAreIdempotent::hookTable(new PluginSubject(TierA5FixtureNoGetHooks::class))
        );
        $this->assertNull(
            TierA5HooksAreIdempotent::hookTable(new PluginSubject(TierA5FixtureThrowingGetHooks::class))
        );
        $this->assertNull(
            TierA5HooksAreIdempotent::hookTable(new PluginSubject(TierA5FixtureStringReturn::class))
        );
        $this->assertNull(
            TierA5HooksAreIdempotent::hookTable(
                new PluginSubject('Tests\\MyAdmin\\Plugins\\Testing\\Contract\\TierA5FixtureAbsent')
            )
        );
    }

    // -----------------------------------------------------------------------
    // Buffer discipline (R-8)
    // -----------------------------------------------------------------------

    /**
     * A `getHooks()` with a leftover `var_dump()` must not print into the PHPUnit process.
     *
     * Unbuffered it did, and `beStrictAboutOutputDuringTests="true"` + `failOnRisky="true"`
     * then failed whichever test happened to be running with `R  This test printed output: …`
     * — attribution by coincidence, given seven inspectors reach `getHooks()`.
     *
     * @return void
     */
    public function testGetHooksOutputIsCapturedRatherThanEscaping()
    {
        $level = ob_get_level();
        ob_start();

        $findings = $this->inspect(TierA5FixturePrintingHooks::class);

        $escaped = ob_get_clean();

        $this->assertSame('', $escaped, 'the inspector must swallow the hook-table output, not re-emit it');
        $this->assertSame($level, ob_get_level());
        $this->assertNotSame([], $findings);
    }

    /**
     * B-15 never calls `getHooks()`, so if A-5 drops the bytes nothing reports them. Both
     * invocations count: the assertion calls the method twice and the second print is as
     * real as the first.
     *
     * @return void
     */
    public function testPrintingGetHooksIsReportedAsAFailure()
    {
        $finding = $this->soleFailure($this->inspect(TierA5FixturePrintingHooks::class));

        $this->assertSame('printed', $finding->context()['problem']);
        $this->assertSame('getHooks', $finding->context()['site']);
        $this->assertStringContainsString('a5 hooks leak', $finding->message());
        $this->assertSame(
            26,
            $finding->context()['bytes'],
            'the assertion invokes getHooks() twice, so both prints are part of the evidence'
        );
    }

    /**
     * A hook table that is both wrong *and* noisy is two defects, and neither may swallow the
     * other: A-5 is the only reporter of both.
     *
     * @return void
     */
    public function testPrintingIsReportedAlongsideTheAssertionsOwnFailure()
    {
        $findings = $this->inspect(TierA5FixturePrintingNonArrayHooks::class);

        $this->assertCount(2, $findings, 'the wrong return type and the printed bytes are separate defects');
        $this->assertSame('not-array', $findings[0]->context()['problem']);
        $this->assertSame('printed', $findings[1]->context()['problem']);
    }

    /**
     * The consumers' half of the split. `hookTable()` buffers too — otherwise A-6/A-7/A-8/B-9/
     * B-9b/B-12 would each be the one blamed for the print — and it drops what it caught,
     * because {@see TierA5HooksAreIdempotent::inspect()} makes the identical call and reports it.
     *
     * @return void
     */
    public function testHookTableSwallowsOutputAndStillReturnsTheTable()
    {
        $level = ob_get_level();
        ob_start();

        $hooks = TierA5HooksAreIdempotent::hookTable(new PluginSubject(TierA5FixturePrintingHooks::class));

        $escaped = ob_get_clean();

        $this->assertSame('', $escaped, 'a consumer of hookTable() must not be blamed for the plugin printing');
        $this->assertSame($level, ob_get_level());
        $this->assertSame(['a5printing.settings' => [TierA5FixturePrintingHooks::class, 'getSettings']], $hooks);
    }
}

// ---------------------------------------------------------------------------
// Fixtures
// ---------------------------------------------------------------------------

class TierA5FixtureGood
{
    public static $module = 'a5mod';

    /**
     * @return array<string,array<int,string>>
     */
    public static function getHooks()
    {
        return ['a5mod.settings' => [__CLASS__, 'getSettings']];
    }
}

class TierA5FixtureReordered
{
    /** @var int */
    public static $calls = 0;

    /**
     * @return array<string,array<int,string>>
     */
    public static function getHooks()
    {
        self::$calls++;
        $hooks = [
            'a5mod.settings' => [__CLASS__, 'getSettings'],
            'a5mod.activate' => [__CLASS__, 'getActivate'],
        ];
        return self::$calls % 2 === 0 ? array_reverse($hooks, true) : $hooks;
    }
}

class TierA5FixtureNoGetHooks
{
    public static $module = 'a5mod';
}

class TierA5FixtureProtectedGetHooks
{
    /**
     * @return array<string,array<int,string>>
     */
    protected static function getHooks()
    {
        return ['a5mod.settings' => [__CLASS__, 'getSettings']];
    }
}

class TierA5FixtureInstanceGetHooks
{
    /**
     * @return array<string,array<int,string>>
     */
    public function getHooks()
    {
        return ['a5mod.settings' => [__CLASS__, 'getSettings']];
    }
}

class TierA5FixtureArgumentGetHooks
{
    /**
     * @param string $module
     * @return array<string,array<int,string>>
     */
    public static function getHooks($module)
    {
        return [$module.'.settings' => [__CLASS__, 'getSettings']];
    }
}

class TierA5FixtureThrowingGetHooks
{
    /**
     * @return array<string,array<int,string>>
     */
    public static function getHooks()
    {
        throw new \RuntimeException('tier-a5 fixture explosion');
    }
}

class TierA5FixtureStringReturn
{
    /**
     * @return string
     */
    public static function getHooks()
    {
        return 'a5mod.settings';
    }
}

class TierA5FixtureEmptyReturn
{
    /**
     * @return array<string,array<int,string>>
     */
    public static function getHooks()
    {
        return [];
    }
}

/**
 * A hook table with a leftover debug print. `PluginScanner` calls `getHooks()` during
 * `composer install`, where there is no page to print to, and MyAdmin calls it at boot.
 */
class TierA5FixturePrintingHooks
{
    /** @var string */
    public static $module = 'a5printing';

    /**
     * @return array<string,array<int,string>>
     */
    public static function getHooks()
    {
        echo 'a5 hooks leak';
        return ['a5printing.settings' => [__CLASS__, 'getSettings']];
    }
}

/**
 * Prints *and* returns the wrong type — two defects that must both survive the report.
 */
class TierA5FixturePrintingNonArrayHooks
{
    /**
     * @return string
     */
    public static function getHooks()
    {
        echo 'a5 noisy and wrong';
        return 'not an array';
    }
}

class TierA5FixtureNonIdempotent
{
    /** @var int */
    public static $calls = 0;

    /**
     * @return array<string,array<int,string>>
     */
    public static function getHooks()
    {
        self::$calls++;
        return ['a5mod.event'.self::$calls => [__CLASS__, 'handle']];
    }
}
