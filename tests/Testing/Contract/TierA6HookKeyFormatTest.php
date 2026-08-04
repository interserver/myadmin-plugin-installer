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
use MyAdmin\Plugins\Testing\Contract\TierA6HookKeyFormat;
use PHPUnit\Framework\TestCase;

/**
 * Fixtures live at the bottom of this file with a `TierA6Fixture` prefix — unique per file,
 * because every test in this directory shares one process.
 *
 * @covers \MyAdmin\Plugins\Testing\Contract\TierA6HookKeyFormat
 */
class TierA6HookKeyFormatTest extends TestCase
{
    /**
     * @param string $class
     * @return array<int,Finding>
     */
    private function inspect($class)
    {
        $inspector = new TierA6HookKeyFormat();
        return $inspector->inspect(new PluginSubject($class));
    }

    /**
     * @param array<int,Finding> $findings
     * @return array<int,string>
     */
    private function offendingKeys(array $findings)
    {
        $keys = [];
        foreach ($findings as $finding) {
            $this->assertSame('A-6', $finding->assertion());
            $this->assertTrue($finding->isFailure(), 'expected a failure, got: '.$finding->describe());
            $keys[] = (string)$finding->context()['key'];
        }
        return $keys;
    }

    /**
     * @return void
     */
    public function testReportsItsCatalogueIdentity()
    {
        $inspector = new TierA6HookKeyFormat();
        $this->assertSame('A-6', $inspector->id());
        $this->assertNotSame('', $inspector->title());
    }

    /**
     * @return void
     */
    public function testWellFormedKeysPass()
    {
        $this->assertSame([], $this->inspect(TierA6FixtureGood::class));
    }

    /**
     * @return void
     */
    public function testUppercaseKeyFails()
    {
        $this->assertSame(['Alpha.activate'], $this->offendingKeys($this->inspect(TierA6FixtureUppercase::class)));
    }

    /**
     * @return void
     */
    public function testKeyWithNoDotFails()
    {
        $this->assertSame(['alphaactivate'], $this->offendingKeys($this->inspect(TierA6FixtureNoDot::class)));
    }

    /**
     * @return void
     */
    public function testKeyWithTwoDotsFails()
    {
        $this->assertSame(['alpha.sub.activate'], $this->offendingKeys($this->inspect(TierA6FixtureTwoDots::class)));
    }

    /**
     * @return void
     */
    public function testKeyWithAHyphenFails()
    {
        $this->assertSame(['alpha-mod.activate'], $this->offendingKeys($this->inspect(TierA6FixtureHyphen::class)));
    }

    /**
     * @return void
     */
    public function testKeyWithADigitFails()
    {
        $this->assertSame(['alpha2.activate'], $this->offendingKeys($this->inspect(TierA6FixtureDigit::class)));
    }

    /**
     * @return void
     */
    public function testKeyWithTrailingWhitespaceFails()
    {
        $this->assertSame(['alpha.activate '], $this->offendingKeys($this->inspect(TierA6FixtureTrailingSpace::class)));
    }

    /**
     * PHP silently casts a numeric-looking array key to an integer, so this can never match
     * the pattern — it has to be caught as a type problem, not a format one.
     *
     * @return void
     */
    public function testNumericKeyIsReportedAsANonStringKey()
    {
        $findings = $this->inspect(TierA6FixtureNumericKey::class);
        $this->assertCount(1, $findings);
        $this->assertTrue($findings[0]->isFailure());
        $this->assertSame(0, $findings[0]->context()['key']);
        $this->assertSame('integer', $findings[0]->context()['found']);
    }

    /**
     * @return void
     */
    public function testEachBadKeyProducesItsOwnFinding()
    {
        $keys = $this->offendingKeys($this->inspect(TierA6FixtureSeveralBadKeys::class));
        $this->assertSame(['Alpha.activate', 'alpha-two.activate', 'alphanodot'], $keys);
    }

    /**
     * @return void
     */
    public function testUnobtainableHookTableIsSkipped()
    {
        $findings = $this->inspect(TierA6FixtureNoGetHooks::class);
        $this->assertCount(1, $findings);
        $this->assertTrue($findings[0]->isSkipped());
        $this->assertStringContainsString('see A-5', $findings[0]->message());
    }

    /**
     * @return void
     */
    public function testMissingClassIsSkippedNotPassed()
    {
        $findings = $this->inspect('Tests\\MyAdmin\\Plugins\\Testing\\Contract\\TierA6FixtureAbsent');
        $this->assertCount(1, $findings);
        $this->assertTrue($findings[0]->isSkipped());
        $this->assertSame('A-6', $findings[0]->assertion());
    }
}

// ---------------------------------------------------------------------------
// Fixtures
// ---------------------------------------------------------------------------

class TierA6FixtureGood
{
    /**
     * @return array<string,array<int,string>>
     */
    public static function getHooks()
    {
        return [
            'alpha.settings' => [__CLASS__, 'getSettings'],
            'alpha_mod.some_event' => [__CLASS__, 'getSomeEvent'],
            'ui.menu' => [__CLASS__, 'getMenu'],
        ];
    }
}

class TierA6FixtureUppercase
{
    /**
     * @return array<string,array<int,string>>
     */
    public static function getHooks()
    {
        return ['Alpha.activate' => [__CLASS__, 'getActivate']];
    }
}

class TierA6FixtureNoDot
{
    /**
     * @return array<string,array<int,string>>
     */
    public static function getHooks()
    {
        return ['alphaactivate' => [__CLASS__, 'getActivate']];
    }
}

class TierA6FixtureTwoDots
{
    /**
     * @return array<string,array<int,string>>
     */
    public static function getHooks()
    {
        return ['alpha.sub.activate' => [__CLASS__, 'getActivate']];
    }
}

class TierA6FixtureHyphen
{
    /**
     * @return array<string,array<int,string>>
     */
    public static function getHooks()
    {
        return ['alpha-mod.activate' => [__CLASS__, 'getActivate']];
    }
}

class TierA6FixtureDigit
{
    /**
     * @return array<string,array<int,string>>
     */
    public static function getHooks()
    {
        return ['alpha2.activate' => [__CLASS__, 'getActivate']];
    }
}

class TierA6FixtureTrailingSpace
{
    /**
     * @return array<string,array<int,string>>
     */
    public static function getHooks()
    {
        return ['alpha.activate ' => [__CLASS__, 'getActivate']];
    }
}

class TierA6FixtureNumericKey
{
    /**
     * @return array<mixed,array<int,string>>
     */
    public static function getHooks()
    {
        return ['0' => [__CLASS__, 'getActivate']];
    }
}

class TierA6FixtureSeveralBadKeys
{
    /**
     * @return array<string,array<int,string>>
     */
    public static function getHooks()
    {
        return [
            'Alpha.activate' => [__CLASS__, 'getActivate'],
            'alpha.settings' => [__CLASS__, 'getSettings'],
            'alpha-two.activate' => [__CLASS__, 'getActivateTwo'],
            'alphanodot' => [__CLASS__, 'getNoDot'],
        ];
    }
}

class TierA6FixtureNoGetHooks
{
    public static $module = 'a6mod';
}
