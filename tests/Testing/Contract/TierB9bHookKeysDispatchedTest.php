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
use MyAdmin\Plugins\Testing\Contract\TierB9bHookKeysDispatched;
use PHPUnit\Framework\TestCase;

/**
 * @covers \MyAdmin\Plugins\Testing\Contract\TierB9bHookKeysDispatched
 */
class TierB9bHookKeysDispatchedTest extends TestCase
{
    /** @var TierB9bHookKeysDispatched */
    private $inspector;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $this->inspector = new TierB9bHookKeysDispatched();
    }

    /**
     * @param string $class
     * @return array<int,Finding>
     */
    private function inspect($class)
    {
        return $this->inspector->inspect(new PluginSubject($class));
    }

    /**
     * @return void
     */
    public function testReportsItsOwnCatalogueIdSeparateFromB9()
    {
        $this->assertSame('B-9b', $this->inspector->id());
        $this->assertNotSame('B-9', $this->inspector->id());
        $this->assertNotSame('', $this->inspector->title());
    }

    // -------------------------------------------------------------------
    // The vocabulary is data, and silently losing an entry must fail
    // -------------------------------------------------------------------

    /**
     * @return void
     */
    public function testLiteralVocabularyContainsTheVerifiedKeys()
    {
        $keys = $this->inspector->dispatchedKeys();
        foreach ([
            'account.activated',
            'ui.menu',
            'system.settings',
            'mailinglist.subscribe',
            'function.requirements',
            'api.register',
            'licenses.deactivate_key',
            'licenses.deactivate_ip',
            'licenses.change_ip',
        ] as $expected) {
            $this->assertContains($expected, $keys);
        }
        $this->assertCount(9, $keys);
    }

    /**
     * `terminate` is the entry a core-only scan would wrongly omit: module plugins dispatch
     * it, not core. Losing it turns six webhosting/storage/mail packages red for no reason.
     *
     * @return void
     */
    public function testSuffixVocabularyContainsTerminateAndTheRest()
    {
        $suffixes = $this->inspector->dispatchedSuffixes();
        foreach ([
            'load_processing',
            'load_addons',
            'queue',
            'activate',
            'settings',
            'deactivate',
            'reactivate',
            'terminate',
        ] as $expected) {
            $this->assertContains($expected, $suffixes);
        }
        $this->assertCount(8, $suffixes);
    }

    /**
     * @return void
     */
    public function testAnyModuleMayPairWithADispatchedSuffix()
    {
        $this->assertTrue($this->inspector->isDispatched('vps.activate'));
        $this->assertTrue($this->inspector->isDispatched('webhosting.terminate'));
        $this->assertTrue($this->inspector->isDispatched('some_new_module.queue'));
        $this->assertTrue($this->inspector->isDispatched('licenses.change_ip'));
    }

    /**
     * @return void
     */
    public function testUnknownKeysAreNotDispatched()
    {
        $this->assertFalse($this->inspector->isDispatched('plugin.install'));
        $this->assertFalse($this->inspector->isDispatched('plugin.uninstall'));
        $this->assertFalse($this->inspector->isDispatched('vps.activated'));
        $this->assertFalse($this->inspector->isDispatched('nodothere'));
    }

    // -------------------------------------------------------------------
    // Pass path
    // -------------------------------------------------------------------

    /**
     * @return void
     */
    public function testPluginUsingOnlyDispatchedKeysProducesNoFindings()
    {
        $this->assertSame([], $this->inspect(TierB9bReachablePlugin::class));
    }

    /**
     * @return void
     */
    public function testEmptyHookTablePassesVacuously()
    {
        $this->assertSame([], $this->inspect(TierB9bEmptyHooksPlugin::class));
    }

    // -------------------------------------------------------------------
    // Fail path — the real cloudlinux-licensing shape
    // -------------------------------------------------------------------

    /**
     * Mirrors `detain/myadmin-cloudlinux-licensing`, the only fleet package with dead hook
     * keys: two handlers wired to `plugin.install` / `plugin.uninstall`, which nothing fires.
     *
     * @return void
     */
    public function testUndispatchedKeysAreReportedOnePerHook()
    {
        $findings = $this->inspect(TierB9bCloudlinuxShapedPlugin::class);
        $this->assertCount(2, $findings);

        $keys = [$findings[0]->context()['hook'], $findings[1]->context()['hook']];
        sort($keys);
        $this->assertSame(['plugin.install', 'plugin.uninstall'], $keys);

        foreach ($findings as $finding) {
            $this->assertTrue($finding->isFailure());
            $this->assertStringContainsString('never dispatched', $finding->message());
        }
    }

    /**
     * @return void
     */
    public function testOnlyTheUndispatchedKeyIsReportedWhenMixed()
    {
        $findings = $this->inspect(TierB9bMixedPlugin::class);
        $this->assertCount(1, $findings);
        $this->assertSame('plugin.install', $findings[0]->context()['hook']);
    }

    /**
     * @return void
     */
    public function testKeyWithoutASuffixIsUnreachable()
    {
        $findings = $this->inspect(TierB9bNoDotPlugin::class);
        $this->assertCount(1, $findings);
        $this->assertTrue($findings[0]->isFailure());
        $this->assertSame('nodothere', $findings[0]->context()['hook']);
    }

    /**
     * A near-miss suffix is the typo this check exists to catch.
     *
     * @return void
     */
    public function testNearMissSuffixIsReported()
    {
        $findings = $this->inspect(TierB9bTypoSuffixPlugin::class);
        $this->assertCount(1, $findings);
        $this->assertSame('vps.activated', $findings[0]->context()['hook']);
    }

    // -------------------------------------------------------------------
    // Skips
    // -------------------------------------------------------------------

    /**
     * @return void
     */
    public function testUnloadablePluginIsSkippedNotPassed()
    {
        $findings = $this->inspect('Tests\MyAdmin\Plugins\Testing\Contract\NoSuchB9bPlugin');
        $this->assertCount(1, $findings);
        $this->assertTrue($findings[0]->isSkipped());
    }

    /**
     * @return void
     */
    public function testThrowingGetHooksIsSkippedRatherThanPropagated()
    {
        $findings = $this->inspect(TierB9bThrowingHooksPlugin::class);
        $this->assertCount(1, $findings);
        $this->assertTrue($findings[0]->isSkipped());
        $this->assertStringContainsString('kaboom', $findings[0]->message());
    }

    /**
     * @return void
     */
    public function testPluginWithoutGetHooksIsSkipped()
    {
        $findings = $this->inspect(TierB9bNoHooksMethodPlugin::class);
        $this->assertCount(1, $findings);
        $this->assertTrue($findings[0]->isSkipped());
    }

    /**
     * @return void
     */
    public function testNonArrayHookTableIsSkipped()
    {
        $findings = $this->inspect(TierB9bNonArrayHooksPlugin::class);
        $this->assertCount(1, $findings);
        $this->assertTrue($findings[0]->isSkipped());
        $this->assertSame('boolean', $findings[0]->context()['returned']);
    }
}

// -----------------------------------------------------------------------
// Fixtures — names unique to this file.
// -----------------------------------------------------------------------

class TierB9bReachablePlugin
{
    /**
     * @return array<string,array<int,string>>
     */
    public static function getHooks()
    {
        return [
            'function.requirements' => [__CLASS__, 'getRequirements'],
            'ui.menu' => [__CLASS__, 'getMenu'],
            'licenses.deactivate_ip' => [__CLASS__, 'getDeactivateIp'],
            'quickservers.terminate' => [__CLASS__, 'getTerminate'],
            'anything_at_all.queue' => [__CLASS__, 'getQueue'],
        ];
    }
}

class TierB9bCloudlinuxShapedPlugin
{
    /**
     * @return array<string,array<int,string>>
     */
    public static function getHooks()
    {
        return [
            'plugin.install' => [__CLASS__, 'getInstall'],
            'plugin.uninstall' => [__CLASS__, 'getUninstall'],
        ];
    }
}

class TierB9bMixedPlugin
{
    /**
     * @return array<string,array<int,string>>
     */
    public static function getHooks()
    {
        return [
            'system.settings' => [__CLASS__, 'getSettings'],
            'plugin.install' => [__CLASS__, 'getInstall'],
            'licenses.change_ip' => [__CLASS__, 'getChangeIp'],
        ];
    }
}

class TierB9bNoDotPlugin
{
    /**
     * @return array<string,array<int,string>>
     */
    public static function getHooks()
    {
        return ['nodothere' => [__CLASS__, 'getSomething']];
    }
}

class TierB9bTypoSuffixPlugin
{
    /**
     * @return array<string,array<int,string>>
     */
    public static function getHooks()
    {
        return ['vps.activated' => [__CLASS__, 'getActivate']];
    }
}

class TierB9bEmptyHooksPlugin
{
    /**
     * @return array<string,mixed>
     */
    public static function getHooks()
    {
        return [];
    }
}

class TierB9bThrowingHooksPlugin
{
    /**
     * @return array<string,mixed>
     */
    public static function getHooks()
    {
        throw new \RuntimeException('kaboom');
    }
}

class TierB9bNoHooksMethodPlugin
{
}

class TierB9bNonArrayHooksPlugin
{
    /**
     * @return bool
     */
    public static function getHooks()
    {
        return false;
    }
}
