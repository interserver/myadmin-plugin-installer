<?php
/**
 * @author Joe Huss <detain@interserver.net>
 * @copyright 2026
 * @package MyAdmin
 * @category Testing
 */

namespace Tests\MyAdmin\Plugins\Testing\Contract;

use MyAdmin\Plugins\Testing\Bootstrap;
use MyAdmin\Plugins\Testing\Contract\PluginSubject;
use MyAdmin\Plugins\Testing\Contract\TierB12SettingsExecute;
use MyAdmin\Plugins\Testing\Fakes\FakeApp;
use MyAdmin\Plugins\Testing\Harness;
use PHPUnit\Framework\TestCase;

// ---------------------------------------------------------------------------------
// Fixtures.
//
// Declared in this file rather than under tests/Testing/Fixtures/ so their names
// cannot collide with another Tier's fixtures — `include_once` in one process makes a
// duplicate class name fatal, and three inspectors are being built against the same
// directory at once. Every name here is prefixed `TierB12`.
// ---------------------------------------------------------------------------------

/**
 * Stands in for `Symfony\Component\EventDispatcher\GenericEvent`, which the installer
 * package does not depend on. Same two things the inspector uses: constructible from a
 * subject, and `getSubject()`.
 */
class TierB12Event
{
    /** @var object */
    private $subject;

    /**
     * @param object $subject
     */
    public function __construct($subject)
    {
        $this->subject = $subject;
    }

    /**
     * @return object
     */
    public function getSubject()
    {
        return $this->subject;
    }
}

/**
 * The shape 42 module-declaring plugins have: settings registered under `self::$module`,
 * and the handler registered under the per-module `<module>.settings` key.
 *
 * That key, not `system.settings`, is what 42 of the fleet's 56 registrations use. B-12's
 * reachability gate is keyed on the hook's *target*, so both forms count; this fixture is
 * the majority one.
 */
class TierB12GoodPlugin
{
    /** @var string */
    public static $module = 'b12good';

    /**
     * @return array<string,array{0:string,1:string}>
     */
    public static function getHooks()
    {
        return ['b12good.settings' => [self::class, 'getSettings']];
    }

    /**
     * @param \Tests\MyAdmin\Plugins\Testing\Contract\TierB12Event $event
     * @return void
     */
    public static function getSettings(TierB12Event $event)
    {
        $settings = $event->getSubject();
        $settings->setTarget('module');
        $settings->add_text_setting(self::$module, 'General', 'b12good_user', 'User', 'tip', 'root');
        $settings->add_password_setting(self::$module, 'General', 'b12good_pass', 'Pass', 'tip', 'secret');
        $settings->setTarget('global');
    }
}

/**
 * Runs to completion and registers nothing, **while being registered by a hook** — the
 * `detain/myadmin-powerdns` shape, and the one genuine day-one B-12 defect in the fleet.
 *
 * The hook entry is what makes this a failure rather than a skip: core has a path to the
 * handler, so "it registered nothing" is a statement about production behaviour. Removing
 * the registration here would turn the fixture into an orphan and silently delete the
 * coverage that catches powerdns.
 */
class TierB12SilentPlugin
{
    /** @var string */
    public static $module = 'b12silent';

    /**
     * @return array<string,array{0:string,1:string}>
     */
    public static function getHooks()
    {
        return ['b12silent.settings' => [self::class, 'getSettings']];
    }

    /**
     * @param \Tests\MyAdmin\Plugins\Testing\Contract\TierB12Event $event
     * @return void
     */
    public static function getSettings(TierB12Event $event)
    {
        $settings = $event->getSubject();
        $settings->handle_section_category(self::$module, 'General');
    }
}

/**
 * The commonest real failure: something in the body blows up.
 */
class TierB12ThrowingPlugin
{
    /** @var string */
    public static $module = 'b12throw';

    /**
     * @return array<string,array{0:string,1:string}>
     */
    public static function getHooks()
    {
        return ['b12throw.settings' => [self::class, 'getSettings']];
    }

    /**
     * @param \Tests\MyAdmin\Plugins\Testing\Contract\TierB12Event $event
     * @return void
     */
    public static function getSettings(TierB12Event $event)
    {
        throw new \RuntimeException('settings handler exploded');
    }
}

/**
 * Declares a module but files a setting under someone else's section, so it renders on a
 * settings page the module does not own.
 */
class TierB12StraySectionPlugin
{
    /** @var string */
    public static $module = 'b12stray';

    /**
     * @return array<string,array{0:string,1:string}>
     */
    public static function getHooks()
    {
        return ['b12stray.settings' => [self::class, 'getSettings']];
    }

    /**
     * @param \Tests\MyAdmin\Plugins\Testing\Contract\TierB12Event $event
     * @return void
     */
    public static function getSettings(TierB12Event $event)
    {
        $settings = $event->getSubject();
        $settings->add_text_setting(self::$module, 'General', 'b12stray_ok', 'Ok', 'tip', '1');
        $settings->add_text_setting('Billing', 'General', 'b12stray_bad', 'Bad', 'tip', '1');
    }
}

/**
 * A plugin (not a module) — 27 of these exist, and the module-scoping rule cannot apply.
 * Registers through `system.settings`, which is the form a module-less plugin uses.
 */
class TierB12ModulelessPlugin
{
    /** @var string */
    public static $module = '';

    /**
     * @return array<string,array{0:string,1:string}>
     */
    public static function getHooks()
    {
        return ['system.settings' => [self::class, 'getSettings']];
    }

    /**
     * @param \Tests\MyAdmin\Plugins\Testing\Contract\TierB12Event $event
     * @return void
     */
    public static function getSettings(TierB12Event $event)
    {
        $settings = $event->getSubject();
        $settings->add_text_setting('Billing', 'General', 'b12moduleless', 'Key', 'tip', '1');
    }
}

/**
 * No handler at all.
 */
class TierB12NoHandlerPlugin
{
    /** @var string */
    public static $module = 'b12nohandler';
}

/**
 * Declared, but not as the `[__CLASS__, 'getSettings']` callable core dispatches.
 */
class TierB12NonStaticPlugin
{
    /** @var string */
    public static $module = 'b12nonstatic';

    /**
     * @param \Tests\MyAdmin\Plugins\Testing\Contract\TierB12Event $event
     * @return void
     */
    public function getSettings(TierB12Event $event)
    {
    }
}

/**
 * Type-hints an event class that does not exist in this environment — the situation
 * every plugin is in when the self-check runs outside its vendor tree and
 * `symfony/event-dispatcher` is absent.
 */
class TierB12MissingEventPlugin
{
    /** @var string */
    public static $module = 'b12missingevent';

    /**
     * @return array<string,array{0:string,1:string}>
     */
    public static function getHooks()
    {
        return ['b12missingevent.settings' => [self::class, 'getSettings']];
    }

    /**
     * @param \Tests\MyAdmin\Plugins\Testing\Contract\TierB12AbsentEvent $event
     * @return void
     */
    public static function getSettings(TierB12AbsentEvent $event)
    {
    }
}

/**
 * No parameter type — must fall back to the anonymous duck-typed event.
 */
class TierB12UntypedPlugin
{
    /** @var string */
    public static $module = 'b12untyped';

    /**
     * @return array<string,array{0:string,1:string}>
     */
    public static function getHooks()
    {
        return ['b12untyped.settings' => [self::class, 'getSettings']];
    }

    /**
     * @param object $event
     * @return void
     */
    public static function getSettings($event)
    {
        $settings = $event->getSubject();
        $settings->add_text_setting(self::$module, 'General', 'b12untyped_key', 'Key', 'tip', '1');
    }
}

/**
 * A static initialised from a bare constant — the `PRORATE_BILLING` shape that nine
 * repos have, and the reason the inspector must define constants before it reads
 * `$module`. `ConstantOrderingTest` owns the deterministic proof of the ordering rule;
 * this fixture proves the inspector end-to-end survives a plugin of that shape.
 */
class TierB12BareConstantPlugin
{
    /** @var string */
    public static $module = 'b12bare';

    /** @var array<string,mixed> */
    public static $settings = [
        'REPEAT_BILLING_METHOD' => TIERB12_FIXTURE_BILLING,
    ];

    /**
     * Calling this initialises the class, which evaluates `$settings` above — so the
     * reachability read is only survivable *after* the inspector has primed constants.
     * That is precisely the ordering {@see TierB12SettingsExecute::inspect()} relies on.
     *
     * @return array<string,array{0:string,1:string}>
     */
    public static function getHooks()
    {
        return ['b12bare.settings' => [self::class, 'getSettings']];
    }

    /**
     * @param \Tests\MyAdmin\Plugins\Testing\Contract\TierB12Event $event
     * @return void
     */
    public static function getSettings(TierB12Event $event)
    {
        $settings = $event->getSubject();
        $settings->add_text_setting(self::$module, 'General', 'b12bare_key', 'Key', 'tip', self::$settings['REPEAT_BILLING_METHOD']);
    }
}

// ---------------------------------------------------------------------------------
// Reachability fixtures.
//
// A handler core can never invoke has no observable execution behaviour, so B-12
// skips rather than judging it. These cover each way that question can be answered.
// ---------------------------------------------------------------------------------

/**
 * The commonest orphan shape: a full `getSettings()` beside an **empty** hook table.
 *
 * Ten fleet packages are exactly this — drbl-backups, gluster-backups, google-analytics,
 * hotjar-analytics, kayako-chat, novnc-plugin, payum-payments, piwik-analytics,
 * raid-backups, slack-chat — because their registrations are commented out on purpose.
 * The handler would register settings if anything ran it; nothing does.
 */
class TierB12OrphanPlugin
{
    /** @var string */
    public static $module = 'b12orphan';

    /**
     * @return array<string,array{0:string,1:string}>
     */
    public static function getHooks()
    {
        return [];
    }

    /**
     * @param \Tests\MyAdmin\Plugins\Testing\Contract\TierB12Event $event
     * @return void
     */
    public static function getSettings(TierB12Event $event)
    {
        $settings = $event->getSubject();
        $settings->add_text_setting(self::$module, 'General', 'b12orphan_key', 'Key', 'tip', '1');
    }
}

/**
 * The other orphan shape: hooks exist, but none of them names `getSettings`.
 *
 * Three fleet packages — modernbill-plugin, monitoring-plugin, webuzo-vps — register
 * `function.requirements` and nothing else. A gate that merely asked "is the hook table
 * non-empty?" would call these reachable and report them as defects.
 */
class TierB12PartialHooksPlugin
{
    /** @var string */
    public static $module = 'b12partial';

    /**
     * @return array<string,array{0:string,1:string}>
     */
    public static function getHooks()
    {
        return ['function.requirements' => [self::class, 'getRequirements']];
    }

    /**
     * @param \Tests\MyAdmin\Plugins\Testing\Contract\TierB12Event $event
     * @return void
     */
    public static function getRequirements(TierB12Event $event)
    {
    }

    /**
     * @param \Tests\MyAdmin\Plugins\Testing\Contract\TierB12Event $event
     * @return void
     */
    public static function getSettings(TierB12Event $event)
    {
        $settings = $event->getSubject();
        $settings->add_text_setting(self::$module, 'General', 'b12partial_key', 'Key', 'tip', '1');
    }
}

/**
 * Registered under the literal `system.settings` key with a module declared — the arm the
 * 14 module-less-plugin registrations use. Behaviour here must be identical to the
 * `<module>.settings` arm; the gate reads the target, not the key.
 */
class TierB12SystemHookPlugin
{
    /** @var string */
    public static $module = 'b12system';

    /**
     * @return array<string,array{0:string,1:string}>
     */
    public static function getHooks()
    {
        return ['system.settings' => [self::class, 'getSettings']];
    }

    /**
     * @param \Tests\MyAdmin\Plugins\Testing\Contract\TierB12Event $event
     * @return void
     */
    public static function getSettings(TierB12Event $event)
    {
        $settings = $event->getSubject();
        $settings->add_text_setting(self::$module, 'General', 'b12system_key', 'Key', 'tip', '1');
    }
}

/**
 * Registered under a key nothing dispatches.
 *
 * B-12 still executes it: the author wired the handler up, so its execution behaviour is
 * what B-12 is for. That the key is dead is {@see \MyAdmin\Plugins\Testing\Contract\TierB9bHookKeysDispatched}'s
 * finding, and reporting it here as well would put one defect in two matrix columns.
 */
class TierB12UndispatchedKeyPlugin
{
    /** @var string */
    public static $module = 'b12undispatched';

    /**
     * @return array<string,array{0:string,1:string}>
     */
    public static function getHooks()
    {
        return ['plugin.install' => [self::class, 'getSettings']];
    }

    /**
     * @param \Tests\MyAdmin\Plugins\Testing\Contract\TierB12Event $event
     * @return void
     */
    public static function getSettings(TierB12Event $event)
    {
        $settings = $event->getSubject();
        $settings->add_text_setting(self::$module, 'General', 'b12undispatched_key', 'Key', 'tip', '1');
    }
}

/**
 * `getHooks()` blows up, so whether the handler is registered is unknowable. A-5's defect,
 * reported by A-5 — B-12 defers instead of adding a second red cell for one cause.
 */
class TierB12ThrowingHooksPlugin
{
    /** @var string */
    public static $module = 'b12throwhooks';

    /**
     * @return array<string,array{0:string,1:string}>
     */
    public static function getHooks()
    {
        throw new \RuntimeException('hook table exploded');
    }

    /**
     * @param \Tests\MyAdmin\Plugins\Testing\Contract\TierB12Event $event
     * @return void
     */
    public static function getSettings(TierB12Event $event)
    {
        $settings = $event->getSubject();
        $settings->add_text_setting(self::$module, 'General', 'b12throwhooks_key', 'Key', 'tip', '1');
    }
}

/**
 * A hook table whose only value is not a `[class, method]` pair. Registration is then
 * unanswerable rather than false, so claiming the handler is orphaned would be a guess —
 * Tier-A-8 owns hook value shape.
 */
class TierB12MalformedHooksPlugin
{
    /** @var string */
    public static $module = 'b12malformed';

    /**
     * @return array<string,mixed>
     */
    public static function getHooks()
    {
        return ['b12malformed.settings' => ['not', 'a', 'pair']];
    }

    /**
     * @param \Tests\MyAdmin\Plugins\Testing\Contract\TierB12Event $event
     * @return void
     */
    public static function getSettings(TierB12Event $event)
    {
        $settings = $event->getSubject();
        $settings->add_text_setting(self::$module, 'General', 'b12malformed_key', 'Key', 'tip', '1');
    }
}

/**
 * Base carrying the handler, so the inherited-registration arm can be exercised.
 */
class TierB12InheritableBase
{
    /** @var string */
    public static $module = 'b12inherited';

    /**
     * @param \Tests\MyAdmin\Plugins\Testing\Contract\TierB12Event $event
     * @return void
     */
    public static function getSettings(TierB12Event $event)
    {
        $settings = $event->getSubject();
        $settings->add_text_setting(self::$module, 'General', 'b12inherited_key', 'Key', 'tip', '1');
    }
}

/**
 * Registers the **parent's** `getSettings`, which is the same method the subject would run.
 * A gate comparing only against the subject's own class name would call this orphaned and
 * skip a handler production dispatches to.
 */
class TierB12InheritedPlugin extends TierB12InheritableBase
{
    /**
     * @return array<string,array{0:string,1:string}>
     */
    public static function getHooks()
    {
        return ['b12inherited.settings' => [TierB12InheritableBase::class, 'getSettings']];
    }
}

/**
 * Registers `getsettings` in lower case, and names the class with a leading separator.
 * PHP resolves both to the same callable, so B-12 must too — a case-sensitive comparison
 * would report a working handler as dead code.
 */
class TierB12OddCaseHookPlugin
{
    /** @var string */
    public static $module = 'b12oddcase';

    /**
     * @return array<string,array{0:string,1:string}>
     */
    public static function getHooks()
    {
        return ['b12oddcase.settings' => ['\\' . self::class, 'getsettings']];
    }

    /**
     * @param \Tests\MyAdmin\Plugins\Testing\Contract\TierB12Event $event
     * @return void
     */
    public static function getSettings(TierB12Event $event)
    {
        $settings = $event->getSubject();
        $settings->add_text_setting(self::$module, 'General', 'b12oddcase_key', 'Key', 'tip', '1');
    }
}

/**
 * Three-level hierarchy, so that "the hook names an ancestor" is separable from "the hook
 * names the class that declares the handler". `getSettings` is declared here.
 */
class TierB12DeepBase
{
    /** @var string */
    public static $module = 'b12deep';

    /**
     * @param \Tests\MyAdmin\Plugins\Testing\Contract\TierB12Event $event
     * @return void
     */
    public static function getSettings(TierB12Event $event)
    {
        $settings = $event->getSubject();
        $settings->add_text_setting(self::$module, 'General', 'b12deep_key', 'Key', 'tip', '1');
    }
}

/**
 * The intermediate rung — declares nothing, which is the whole point.
 */
class TierB12DeepMiddle extends TierB12DeepBase
{
}

/**
 * Registers `[TierB12DeepMiddle::class, 'getSettings']`: a class that is neither the subject
 * nor the declaring class, yet resolves to the same callable. Only walking the parent chain
 * finds it — matching the subject and the declaring class alone would call this orphaned.
 */
class TierB12DeepPlugin extends TierB12DeepMiddle
{
    /**
     * @return array<string,array{0:string,1:string}>
     */
    public static function getHooks()
    {
        return ['b12deep.settings' => [TierB12DeepMiddle::class, 'getSettings']];
    }
}

/**
 * `getHooks()` that only works once constants are primed.
 *
 * Every other fixture here shares one file with the rest, and `ConstantStub` scans per
 * *file* — so priming any fixture defines the bare constants of all of them, which makes
 * the inspector's internal ordering unobservable. This fixture sidesteps that by depending
 * on a constant supplied through the subject's `constantOverrides` instead of through the
 * file scan: it is defined only by {@see TierB12SettingsExecute::prime()}, and nothing else
 * in the process defines it.
 *
 * The dependency is expressed with `defined()` on a **string** so the token scanner does not
 * see a bare constant reference and pre-define a sentinel for it.
 */
class TierB12HookNeedsConstantPlugin
{
    /** @var string */
    public static $module = 'b12hookconst';

    /**
     * @return array<string,array{0:string,1:string}>
     */
    public static function getHooks()
    {
        if (!defined('TIERB12_PRIMED_BEFORE_HOOKS')) {
            throw new \RuntimeException('getHooks() was called before constants were primed');
        }
        return ['b12hookconst.settings' => [self::class, 'getSettings']];
    }

    /**
     * @param \Tests\MyAdmin\Plugins\Testing\Contract\TierB12Event $event
     * @return void
     */
    public static function getSettings(TierB12Event $event)
    {
        $settings = $event->getSubject();
        $settings->add_text_setting(self::$module, 'General', 'b12hookconst_key', 'Key', 'tip', '1');
    }
}

/**
 * B-12 — `getSettings()` executes clean.
 *
 * @covers \MyAdmin\Plugins\Testing\Contract\TierB12SettingsExecute
 */
class TierB12SettingsExecuteTest extends TestCase
{
    /** @var \MyAdmin\Plugins\Testing\Contract\TierB12SettingsExecute */
    private $inspector;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        Bootstrap::init(['module' => 'b12harness']);
        Harness::reset();
        $this->inspector = new TierB12SettingsExecute();
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        Harness::reset();
        Harness::setAcl([]);
        FakeApp::setIma('client');
    }

    /**
     * @return void
     */
    public function testIdentifiesItselfWithTheCatalogueId()
    {
        $this->assertSame('B-12', $this->inspector->id());
        $this->assertNotSame('', $this->inspector->title());
    }

    // -----------------------------------------------------------------------
    // Pass path
    // -----------------------------------------------------------------------

    /**
     * @return void
     */
    public function testPassesForAPluginThatRegistersSettingsUnderItsModule()
    {
        $findings = $this->inspector->inspect(new PluginSubject(TierB12GoodPlugin::class));

        $this->assertSame([], $findings, $this->describe($findings));
    }

    /**
     * A plugin declaring no module cannot violate the module-scoping rule, so registering
     * under `Billing` is correct rather than stray.
     *
     * @return void
     */
    public function testAcceptsAnySectionWhenThePluginDeclaresNoModule()
    {
        $findings = $this->inspector->inspect(new PluginSubject(TierB12ModulelessPlugin::class));

        $this->assertSame([], $findings, $this->describe($findings));
    }

    /**
     * @return void
     */
    public function testAcceptsAnUntypedEventParameter()
    {
        $findings = $this->inspector->inspect(new PluginSubject(TierB12UntypedPlugin::class));

        $this->assertSame([], $findings, $this->describe($findings));
    }

    /**
     * @return void
     */
    public function testExecutesAPluginWhoseStaticsNeedStubbedConstants()
    {
        $findings = $this->inspector->inspect(new PluginSubject(TierB12BareConstantPlugin::class));

        $this->assertSame([], $findings, $this->describe($findings));
        $this->assertTrue(defined('TIERB12_FIXTURE_BILLING'), 'the inspector must define the plugin\'s bare constants');
    }

    /**
     * The explicit override must win over the scanned sentinel, and must be applied
     * before the class is touched.
     *
     * @return void
     */
    public function testConstantOverridesFromTheSubjectAreApplied()
    {
        $subject = new PluginSubject(TierB12GoodPlugin::class, [
            'constantOverrides' => ['TIERB12_EXPLICIT_OVERRIDE' => 'pinned'],
        ]);

        $findings = $this->inspector->inspect($subject);

        $this->assertSame([], $findings, $this->describe($findings));
        $this->assertSame('pinned', constant('TIERB12_EXPLICIT_OVERRIDE'));
    }

    // -----------------------------------------------------------------------
    // Fail paths
    // -----------------------------------------------------------------------

    /**
     * @return void
     */
    public function testReportsAHandlerThatThrows()
    {
        $findings = $this->inspector->inspect(new PluginSubject(TierB12ThrowingPlugin::class));

        $this->assertCount(1, $findings);
        $this->assertTrue($findings[0]->isFailure());
        $this->assertSame('B-12', $findings[0]->assertion());
        $this->assertStringContainsString('settings handler exploded', $findings[0]->message());
        $this->assertStringContainsString(TierB12ThrowingPlugin::class, $findings[0]->message());
        $this->assertSame(\RuntimeException::class, $findings[0]->context()['exception']);
    }

    /**
     * The `detain/myadmin-powerdns` case, and the reason the reachability gate must not be
     * allowed to swallow this branch. The plugin registers the handler *and* the handler
     * registers nothing, so core has a settings page with nothing on it.
     *
     * Asserting it is not skipped is the load-bearing half: the gate added below turns 13
     * orphans into skips, and a gate that was one condition too broad would take this
     * genuine failure with them and leave the fleet reading 69/69 green.
     *
     * @return void
     */
    public function testReportsAHandlerThatRegistersNothing()
    {
        $findings = $this->inspector->inspect(new PluginSubject(TierB12SilentPlugin::class));

        $this->assertCount(1, $findings);
        $this->assertTrue($findings[0]->isFailure());
        $this->assertFalse($findings[0]->isSkipped(), 'a registered handler that registers nothing is a defect, not an unobservable one');
        $this->assertStringContainsString('registered no settings', $findings[0]->message());
    }

    /**
     * @return void
     */
    public function testReportsSettingsRegisteredOutsideTheDeclaredModule()
    {
        $findings = $this->inspector->inspect(new PluginSubject(TierB12StraySectionPlugin::class));

        $this->assertCount(1, $findings);
        $this->assertTrue($findings[0]->isFailure());
        $this->assertStringContainsString('billing', $findings[0]->message());
        $this->assertSame('b12stray', $findings[0]->context()['expected']);
        $this->assertSame('billing', $findings[0]->context()['found']);
    }

    /**
     * Not a skip: core dispatches `[__CLASS__, 'getSettings']`, so a non-static handler
     * is unreachable in production too.
     *
     * The fixture deliberately declares no `getHooks()`, which pins the ordering: the
     * shape check runs *ahead* of the reachability gate, so a handler that could never be
     * dispatched in any form is still reported as the defect it is rather than skipped for
     * want of a registration.
     *
     * @return void
     */
    public function testReportsANonStaticHandlerAsAFailure()
    {
        $findings = $this->inspector->inspect(new PluginSubject(TierB12NonStaticPlugin::class));

        $this->assertCount(1, $findings);
        $this->assertTrue($findings[0]->isFailure());
        $this->assertStringContainsString('not public static', $findings[0]->message());
    }

    // -----------------------------------------------------------------------
    // Skip paths — a skip must never read as a pass
    // -----------------------------------------------------------------------

    /**
     * @return void
     */
    public function testSkipsWhenThePluginDeclaresNoHandler()
    {
        $findings = $this->inspector->inspect(new PluginSubject(TierB12NoHandlerPlugin::class));

        $this->assertCount(1, $findings);
        $this->assertTrue($findings[0]->isSkipped());
        $this->assertNotSame([], $findings, 'a skip must not be reported as a pass');
        $this->assertStringContainsString('getSettings', $findings[0]->message());
    }

    /**
     * @return void
     */
    public function testSkipsWhenThePluginClassIsNotLoadable()
    {
        $findings = $this->inspector->inspect(new PluginSubject('Tests\\MyAdmin\\Plugins\\Testing\\Contract\\TierB12NoSuchPlugin'));

        $this->assertCount(1, $findings);
        $this->assertTrue($findings[0]->isSkipped());
        $this->assertStringContainsString('not loadable', $findings[0]->message());
    }

    /**
     * @return void
     */
    public function testSkipsWhenTheDeclaredEventClassIsNotLoadable()
    {
        $findings = $this->inspector->inspect(new PluginSubject(TierB12MissingEventPlugin::class));

        $this->assertCount(1, $findings);
        $this->assertTrue($findings[0]->isSkipped());
        $this->assertStringContainsString('TierB12AbsentEvent', $findings[0]->message());
    }

    // -----------------------------------------------------------------------
    // Reachability — a handler core can never invoke has nothing to observe
    // -----------------------------------------------------------------------

    /**
     * The gate is on the hook's target, not on a fixed key, so the literal
     * `system.settings` arm and the `<module>.settings` arm must behave identically.
     * `TierB12GoodPlugin` covers the per-module form; this covers the literal one.
     *
     * @return void
     */
    public function testExecutesAHandlerRegisteredUnderTheLiteralSystemSettingsKey()
    {
        $findings = $this->inspector->inspect(new PluginSubject(TierB12SystemHookPlugin::class));

        $this->assertSame([], $findings, $this->describe($findings));
    }

    /**
     * Ten fleet packages declare a full `getSettings()` beside an empty hook table. Core
     * has no path to the method, so "it registered no settings" is a complaint about a body
     * production never runs — a claim about dead code, which is a different check's defect.
     *
     * Skipped rather than empty: an empty result reads as a pass and would have the matrix
     * claim coverage of a handler that was never executed.
     *
     * @return void
     */
    public function testSkipsAnOrphanedHandlerRatherThanFailingIt()
    {
        $findings = $this->inspector->inspect(new PluginSubject(TierB12OrphanPlugin::class));

        $this->assertCount(1, $findings);
        $this->assertNotSame([], $findings, 'a skip must not be reported as a pass');
        $this->assertTrue($findings[0]->isSkipped());
        $this->assertFalse($findings[0]->isFailure());
        $this->assertSame('B-12', $findings[0]->assertion());
        $this->assertStringContainsString('system.settings', $findings[0]->message());
        $this->assertStringContainsString('cannot judge it', $findings[0]->message());
    }

    /**
     * The orphaned-handler signal must survive the change from failure to skip. It is
     * carried in the message *and* in structured context, so the triage matrix can key on
     * `orphaned` without parsing prose.
     *
     * @return void
     */
    public function testTheOrphanSkipRecordsThatTheHandlerIsDeadCode()
    {
        $findings = $this->inspector->inspect(new PluginSubject(TierB12OrphanPlugin::class));

        $this->assertStringContainsString('ORPHANED', $findings[0]->message());
        $this->assertStringContainsString('dead code', $findings[0]->message());
        $this->assertTrue($findings[0]->context()['orphaned']);
        $this->assertSame(0, $findings[0]->context()['hooks']);
        $this->assertStringContainsString('orphaned=true', $findings[0]->describe());
    }

    /**
     * modernbill-plugin, monitoring-plugin and webuzo-vps register `function.requirements`
     * and nothing else. A gate that only asked whether the hook table was non-empty would
     * call all three reachable.
     *
     * @return void
     */
    public function testSkipsAnOrphanedHandlerWhenOtherHooksExist()
    {
        $findings = $this->inspector->inspect(new PluginSubject(TierB12PartialHooksPlugin::class));

        $this->assertCount(1, $findings);
        $this->assertTrue($findings[0]->isSkipped());
        $this->assertTrue($findings[0]->context()['orphaned']);
        $this->assertSame(1, $findings[0]->context()['hooks']);
        $this->assertSame('function.requirements', $findings[0]->context()['hookKeys']);
    }

    /**
     * A hook whose key nothing dispatches is still a registration: the author wired the
     * handler up, so its execution behaviour is exactly what B-12 is for. B-9b owns the
     * dead-key finding, and duplicating it here would put one defect in two columns.
     *
     * @return void
     */
    public function testExecutesAHandlerRegisteredUnderAKeyNothingDispatches()
    {
        $findings = $this->inspector->inspect(new PluginSubject(TierB12UndispatchedKeyPlugin::class));

        $this->assertSame([], $findings, $this->describe($findings));
    }

    /**
     * An inherited handler registered under the parent's name is the same callable the
     * subject would run. Comparing only against the subject's own class name would report a
     * live handler as dead.
     *
     * @return void
     */
    public function testTreatsAHandlerRegisteredUnderAParentClassAsReachable()
    {
        $findings = $this->inspector->inspect(new PluginSubject(TierB12InheritedPlugin::class));

        $this->assertSame([], $findings, $this->describe($findings));
    }

    /**
     * A hook may name an ancestor that neither is the subject nor declares the handler —
     * PHP still resolves it to the same callable. Matching only those two names would
     * report a live handler as dead code.
     *
     * @return void
     */
    public function testTreatsAHandlerRegisteredUnderAnIntermediateAncestorAsReachable()
    {
        $findings = $this->inspector->inspect(new PluginSubject(TierB12DeepPlugin::class));

        $this->assertSame([], $findings, $this->describe($findings));
    }

    /**
     * The hook table must not be read until constants are primed.
     *
     * Calling `getHooks()` initialises the class, which evaluates every static initializer —
     * the `PRORATE_BILLING` shape nine repos have. A-5 catches the resulting `Error` and
     * answers null, so getting this order wrong does not crash: it silently downgrades a
     * perfectly registered handler to "hook table unobtainable, see A-5", which is a false
     * skip attributed to the wrong assertion.
     *
     * @return void
     */
    public function testPrimesConstantsBeforeReadingTheHookTable()
    {
        $subject = new PluginSubject(TierB12HookNeedsConstantPlugin::class, [
            'constantOverrides' => ['TIERB12_PRIMED_BEFORE_HOOKS' => true],
        ]);

        $findings = $this->inspector->inspect($subject);

        $this->assertSame([], $findings, $this->describe($findings));
    }

    /**
     * PHP class and method names are case-insensitive, and a leading `\` is decoration.
     * All three spellings name one callable, so all three must count as registered.
     *
     * @return void
     */
    public function testMatchesTheHookTargetCaseInsensitively()
    {
        $findings = $this->inspector->inspect(new PluginSubject(TierB12OddCaseHookPlugin::class));

        $this->assertSame([], $findings, $this->describe($findings));
    }

    /**
     * When the hook table cannot be obtained, whether the handler is registered is
     * unknowable — and A-5 already turns that condition red. B-12 defers rather than adding
     * a second failing cell for one root cause.
     *
     * @return void
     */
    public function testSkipsPointingAtA5WhenTheHookTableCannotBeEvaluated()
    {
        $findings = $this->inspector->inspect(new PluginSubject(TierB12ThrowingHooksPlugin::class));

        $this->assertCount(1, $findings);
        $this->assertTrue($findings[0]->isSkipped());
        $this->assertSame('A-5', $findings[0]->context()['blockedBy']);
        $this->assertStringContainsString('Tier-A-5', $findings[0]->message());
    }

    /**
     * A hook table with no `[class, method]` pair in it makes registration unanswerable
     * rather than false. Reporting "orphaned" there would be a guess, and hook value shape
     * is A-8's assertion.
     *
     * @return void
     */
    public function testSkipsPointingAtA8WhenNoHookValueIsAClassMethodPair()
    {
        $findings = $this->inspector->inspect(new PluginSubject(TierB12MalformedHooksPlugin::class));

        $this->assertCount(1, $findings);
        $this->assertTrue($findings[0]->isSkipped());
        $this->assertSame('A-8', $findings[0]->context()['blockedBy']);
        $this->assertArrayNotHasKey('orphaned', $findings[0]->context(), 'unanswerable is not the same as orphaned');
    }

    // -----------------------------------------------------------------------
    // Isolation — the property the 69-plugin self-check depends on
    // -----------------------------------------------------------------------

    /**
     * The inspector runs back-to-back over 69 plugins in one process. If the first
     * plugin's registrations survived into the second, the second would satisfy the
     * "at least one setting" check for free — exactly the false pass B-12 exists to
     * prevent. Both directions are checked, because a leak in either is fatal to the
     * matrix.
     *
     * @return void
     */
    public function testRegisteredSettingsDoNotLeakIntoTheNextPlugin()
    {
        $first = $this->inspector->inspect(new PluginSubject(TierB12GoodPlugin::class));
        $second = $this->inspector->inspect(new PluginSubject(TierB12SilentPlugin::class));

        $this->assertSame([], $first, $this->describe($first));
        $this->assertCount(1, $second, 'the silent plugin must still be reported after a plugin that registered settings');
        $this->assertStringContainsString('registered no settings', $second[0]->message());

        // …and the reverse: a failing plugin must not poison the one after it.
        $third = $this->inspector->inspect(new PluginSubject(TierB12StraySectionPlugin::class));
        $fourth = $this->inspector->inspect(new PluginSubject(TierB12GoodPlugin::class));

        $this->assertCount(1, $third);
        $this->assertSame([], $fourth, $this->describe($fourth));
    }

    /**
     * Nothing may be left on the fakes for whichever inspector runs next.
     *
     * @return void
     */
    public function testLeavesTheHarnessClean()
    {
        $this->inspector->inspect(new PluginSubject(TierB12GoodPlugin::class));

        $this->assertSame(0, Harness::settings()->settingCount(), 'settings must not survive the inspection');
        $this->assertSame('global', Harness::settings()->target());
        $this->assertSame('client', FakeApp::ima(), 'ima must be restored, not left as the inspector set it');
        $this->assertFalse(has_acl('client_billing'), 'the ACL allowlist must be restored');
    }

    /**
     * The reachability gate returns *after* priming, so it owns a release just like every
     * other early return. Thirteen fleet packages take this path; a grant or an `ima` left
     * behind by any of them would change the verdict on whichever plugin ran next.
     *
     * @return void
     */
    public function testTheOrphanSkipAlsoLeavesTheHarnessClean()
    {
        $this->inspector->inspect(new PluginSubject(TierB12OrphanPlugin::class));

        $this->assertSame(0, Harness::settings()->settingCount(), 'settings must not survive the inspection');
        $this->assertSame('client', FakeApp::ima(), 'ima must be restored on the reachability skip path too');
        $this->assertFalse(has_acl('client_billing'), 'the ACL allowlist must be restored');

        // …and the plugin after an orphan must still be judged normally.
        $next = $this->inspector->inspect(new PluginSubject(TierB12SilentPlugin::class));
        $this->assertCount(1, $next);
        $this->assertTrue($next[0]->isFailure());
    }

    /**
     * @param array<int,\MyAdmin\Plugins\Testing\Contract\Finding> $findings
     * @return string
     */
    private function describe(array $findings)
    {
        $lines = [];
        foreach ($findings as $finding) {
            $lines[] = $finding->describe();
        }
        return implode("\n", $lines);
    }
}
