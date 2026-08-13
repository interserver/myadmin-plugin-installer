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
use MyAdmin\Plugins\Testing\Contract\TierA8HookValueShape;
use PHPUnit\Framework\TestCase;

/**
 * Fixtures live at the bottom of this file with a `TierA8Fixture` prefix — unique per file,
 * because every test in this directory shares one process.
 *
 * @covers \MyAdmin\Plugins\Testing\Contract\TierA8HookValueShape
 */
class TierA8HookValueShapeTest extends TestCase
{
    /**
     * @param string $class
     * @return array<int,Finding>
     */
    private function inspect($class)
    {
        $inspector = new TierA8HookValueShape();
        return $inspector->inspect(new PluginSubject($class));
    }

    /**
     * @param array<int,Finding> $findings
     * @return Finding
     */
    private function soleFailure(array $findings)
    {
        $this->assertCount(1, $findings, 'expected exactly one finding');
        $this->assertSame('A-8', $findings[0]->assertion());
        $this->assertTrue($findings[0]->isFailure(), 'expected a failure, got '.$findings[0]->severity());
        return $findings[0];
    }

    /**
     * @return void
     */
    public function testReportsItsCatalogueIdentity()
    {
        $inspector = new TierA8HookValueShape();
        $this->assertSame('A-8', $inspector->id());
        $this->assertNotSame('', $inspector->title());
    }

    /**
     * @return void
     */
    public function testTwoElementCallableArraysPass()
    {
        $this->assertSame([], $this->inspect(TierA8FixtureGood::class));
    }

    /**
     * The target class need not be the plugin itself; A-8 judges shape only.
     *
     * @return void
     */
    public function testForeignClassStringPasses()
    {
        $this->assertSame([], $this->inspect(TierA8FixtureForeignTarget::class));
    }

    /**
     * @return void
     */
    public function testBareCallableStringFails()
    {
        $finding = $this->soleFailure($this->inspect(TierA8FixtureStringTarget::class));
        $this->assertSame('not-array', $finding->context()['problem']);
        $this->assertSame('string', $finding->context()['found']);
        $this->assertSame('a8mod.settings', $finding->context()['key']);
    }

    /**
     * A closure survives getHooks() but not the hooks.json round-trip.
     *
     * @return void
     */
    public function testClosureTargetFails()
    {
        $finding = $this->soleFailure($this->inspect(TierA8FixtureClosureTarget::class));
        $this->assertSame('not-array', $finding->context()['problem']);
        $this->assertSame('object', $finding->context()['found']);
    }

    /**
     * @return void
     */
    public function testThreeElementTargetFails()
    {
        $finding = $this->soleFailure($this->inspect(TierA8FixtureThreeElements::class));
        $this->assertSame('wrong-arity', $finding->context()['problem']);
        $this->assertSame(3, $finding->context()['count']);
    }

    /**
     * @return void
     */
    public function testSingleElementTargetFails()
    {
        $finding = $this->soleFailure($this->inspect(TierA8FixtureOneElement::class));
        $this->assertSame('wrong-arity', $finding->context()['problem']);
        $this->assertSame(1, $finding->context()['count']);
    }

    /**
     * @return void
     */
    public function testAssociativeTargetFails()
    {
        $finding = $this->soleFailure($this->inspect(TierA8FixtureAssociative::class));
        $this->assertSame('wrong-arity', $finding->context()['problem']);
        $this->assertStringContainsString("'class'", $finding->message());
    }

    /**
     * @return void
     */
    public function testNonStringClassSlotFails()
    {
        $finding = $this->soleFailure($this->inspect(TierA8FixtureIntegerClass::class));
        $this->assertSame('bad-class', $finding->context()['problem']);
        $this->assertSame('integer', $finding->context()['found']);
    }

    /**
     * @return void
     */
    public function testNullMethodSlotFails()
    {
        $finding = $this->soleFailure($this->inspect(TierA8FixtureNullMethod::class));
        $this->assertSame('bad-method', $finding->context()['problem']);
        $this->assertSame('NULL', $finding->context()['found']);
    }

    /**
     * @return void
     */
    public function testEmptyClassAndMethodProduceTwoSeparateFindings()
    {
        $findings = $this->inspect(TierA8FixtureEmptyStrings::class);
        $this->assertCount(2, $findings);
        $this->assertSame('bad-class', $findings[0]->context()['problem']);
        $this->assertSame('bad-method', $findings[1]->context()['problem']);
        $this->assertStringContainsString('an empty string', $findings[0]->message());
    }

    /**
     * @return void
     */
    public function testEachBadEntryProducesItsOwnFinding()
    {
        $findings = $this->inspect(TierA8FixtureSeveralOffenders::class);
        $this->assertCount(2, $findings);
        $this->assertSame('a8mod.activate', $findings[0]->context()['key']);
        $this->assertSame('a8mod.queue', $findings[1]->context()['key']);
    }

    /**
     * @return void
     */
    public function testUnobtainableHookTableIsSkipped()
    {
        $findings = $this->inspect(TierA8FixtureNoGetHooks::class);
        $this->assertCount(1, $findings);
        $this->assertTrue($findings[0]->isSkipped());
        $this->assertStringContainsString('see A-5', $findings[0]->message());
    }

    /**
     * @return void
     */
    public function testMissingClassIsSkippedNotPassed()
    {
        $findings = $this->inspect('Tests\\MyAdmin\\Plugins\\Testing\\Contract\\TierA8FixtureAbsent');
        $this->assertCount(1, $findings);
        $this->assertTrue($findings[0]->isSkipped());
        $this->assertSame('A-8', $findings[0]->assertion());
    }
}

// ---------------------------------------------------------------------------
// Fixtures
// ---------------------------------------------------------------------------

class TierA8FixtureGood
{
    /**
     * @return array<string,array<int,string>>
     */
    public static function getHooks()
    {
        return [
            'a8mod.settings' => [__CLASS__, 'getSettings'],
            'a8mod.activate' => [self::class, 'getActivate'],
        ];
    }
}

class TierA8FixtureForeignTarget
{
    /**
     * @return array<string,array<int,string>>
     */
    public static function getHooks()
    {
        return ['a8mod.settings' => ['Some\\Other\\Handler', 'getSettings']];
    }
}

class TierA8FixtureStringTarget
{
    /**
     * @return array<string,string>
     */
    public static function getHooks()
    {
        return ['a8mod.settings' => __CLASS__.'::getSettings'];
    }
}

class TierA8FixtureClosureTarget
{
    /**
     * @return array<string,\Closure>
     */
    public static function getHooks()
    {
        return ['a8mod.settings' => function () {
            return null;
        }];
    }
}

class TierA8FixtureThreeElements
{
    /**
     * @return array<string,array<int,string>>
     */
    public static function getHooks()
    {
        return ['a8mod.settings' => [__CLASS__, 'getSettings', 'extra']];
    }
}

class TierA8FixtureOneElement
{
    /**
     * @return array<string,array<int,string>>
     */
    public static function getHooks()
    {
        return ['a8mod.settings' => [__CLASS__]];
    }
}

class TierA8FixtureAssociative
{
    /**
     * @return array<string,array<string,string>>
     */
    public static function getHooks()
    {
        return ['a8mod.settings' => ['class' => __CLASS__, 'method' => 'getSettings']];
    }
}

class TierA8FixtureIntegerClass
{
    /**
     * @return array<string,array<int,mixed>>
     */
    public static function getHooks()
    {
        return ['a8mod.settings' => [7, 'getSettings']];
    }
}

class TierA8FixtureNullMethod
{
    /**
     * @return array<string,array<int,mixed>>
     */
    public static function getHooks()
    {
        return ['a8mod.settings' => [__CLASS__, null]];
    }
}

class TierA8FixtureEmptyStrings
{
    /**
     * @return array<string,array<int,string>>
     */
    public static function getHooks()
    {
        return ['a8mod.settings' => ['', '']];
    }
}

class TierA8FixtureSeveralOffenders
{
    /**
     * @return array<string,mixed>
     */
    public static function getHooks()
    {
        return [
            'a8mod.settings' => [__CLASS__, 'getSettings'],
            'a8mod.activate' => 'not an array',
            'a8mod.queue' => [__CLASS__, 'getQueue', 'extra'],
        ];
    }
}

class TierA8FixtureNoGetHooks
{
    public static $module = 'a8mod';
}
