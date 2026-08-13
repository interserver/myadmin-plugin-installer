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
use MyAdmin\Plugins\Testing\Contract\TierB15NoOutput;
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
 * A `TierB12GoodPlugin` with a stray `echo` in front of the registrations.
 *
 * Correct on every assertion B-12 makes — it runs, it registers, and it registers under its
 * own module — and defective on the one B-15 makes. That separation is the point: B-12 must
 * report nothing here, having captured the bytes only so they cannot be blamed on the test.
 */
class TierB12EchoingPlugin
{
    /** @var string */
    public static $module = 'b12echo';

    /**
     * @return array<string,array{0:string,1:string}>
     */
    public static function getHooks()
    {
        return ['b12echo.settings' => [self::class, 'getSettings']];
    }

    /**
     * @param \Tests\MyAdmin\Plugins\Testing\Contract\TierB12Event $event
     * @return void
     */
    public static function getSettings(TierB12Event $event)
    {
        echo '<div class="alert">b12 settings leak</div>';
        $settings = $event->getSubject();
        $settings->add_text_setting(self::$module, 'General', 'b12echo_user', 'User', 'tip', 'root');
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
 * The commonest orphan shape: a `getSettings()` beside an **empty** hook table, whose body
 * registers nothing.
 *
 * Ten fleet packages are exactly this — drbl-backups, gluster-backups, google-analytics,
 * hotjar-analytics, kayako-chat, novnc-plugin, payum-payments, piwik-analytics,
 * raid-backups, slack-chat — because their registrations are commented out on purpose. The
 * body below is theirs verbatim: take the subject, do nothing with it.
 *
 * Registering nothing is what makes this fixture exercise the gate. Assertion 2 is the only
 * assertion reachability can withhold, so an orphan that *did* register would never consult
 * the gate at all and would pass on its own merits — see
 * {@see TierB12OrphanThatRegistersPlugin}, which pins that half.
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
        $event->getSubject();
    }
}

/**
 * An orphan whose body *does* register a setting, correctly scoped.
 *
 * All three assertions are then answered on their merits — it ran, it registered, and it
 * registered under its own module — so the verdict is a pass. The gate withholds assertion 2
 * only in the negative direction: "registered nothing" is inconsequential for a handler core
 * cannot dispatch, but "registered something" is a fact no dispatch table can take away, and
 * reporting a skip over it would understate coverage as badly as a false pass overstates it.
 */
class TierB12OrphanThatRegistersPlugin
{
    /** @var string */
    public static $module = 'b12orphanreg';

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
        $settings->add_text_setting(self::$module, 'General', 'b12orphanreg_key', 'Key', 'tip', '1');
    }
}

/**
 * **The R-3 regression fixture.** An orphaned handler that fatals on the first line of its
 * body — an undefined function, which is the shape a bare `function_requirements()` helper or
 * a core-only function takes when the plugin is loaded outside core.
 *
 * Against the inspector as originally shipped this plugin passed the entire catalogue: B-12
 * skipped it as ORPHANED *before executing it*, and B-15 executed it, caught the `Error` and
 * downgraded itself to a skip because "B-12/B-13 own the throw" — a B-12 that had declined to
 * run. 12 passes, 5 skips, 0 failures, reproduced twice. Assertion 1 is not the gate's
 * business, so it must now be red.
 */
class TierB12FatalOrphanPlugin
{
    /** @var string */
    public static $module = 'b12fatalorphan';

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
        tierb12_function_that_does_not_exist_anywhere();
    }
}

/**
 * An orphan that files a setting under someone else's section.
 *
 * Assertion 3 is a property of the body, not of the dispatch table: the day a hook finally
 * registers this handler, the stray section is a live defect, and the harness should have
 * said so on the day the body was written.
 */
class TierB12StrayOrphanPlugin
{
    /** @var string */
    public static $module = 'b12strayorphan';

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
        $settings->add_text_setting('Billing', 'General', 'b12strayorphan_key', 'Key', 'tip', '1');
    }
}

/**
 * A `getHooks()` with a side effect on the settings fake — the one thing that could make
 * "did the handler register anything?" answer differently depending on *when* it is asked.
 *
 * A-5 forbids this shape, and a plugin like it would be red there long before it were red
 * here. The fixture exists because the inspector reads the hook table *after* running the
 * handler now, and that reordering is only safe while the observation is snapshotted first.
 * Nothing else in the suite can tell the two orders apart.
 */
class TierB12HooksWithSideEffectPlugin
{
    /** @var string */
    public static $module = 'b12hookside';

    /**
     * @return array<string,array{0:string,1:string}>
     */
    public static function getHooks()
    {
        Harness::settings()->add_text_setting(self::$module, 'General', 'b12hookside_key', 'Key', 'tip', '1');
        return [];
    }

    /**
     * @param \Tests\MyAdmin\Plugins\Testing\Contract\TierB12Event $event
     * @return void
     */
    public static function getSettings(TierB12Event $event)
    {
        $event->getSubject();
    }
}

/**
 * Records whether its body ran, so the *ordering* can be asserted directly rather than
 * inferred from a verdict.
 *
 * The gate must be consulted after the handler, not before it. Every other consequence of
 * getting that backwards is indirect; this fixture makes it a single boolean.
 */
class TierB12OrphanRecordingPlugin
{
    /** @var string */
    public static $module = 'b12orphanrec';

    /** @var bool set by the handler, read by the test */
    public static $ran = false;

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
        self::$ran = true;
    }
}

/**
 * The other orphan shape: hooks exist, but none of them names `getSettings`.
 *
 * Three fleet packages — modernbill-plugin, monitoring-plugin, webuzo-vps — register
 * `function.requirements` and nothing else. A gate that merely asked "is the hook table
 * non-empty?" would call these reachable and report them as defects.
 *
 * Registers nothing, for the reason {@see TierB12OrphanPlugin} gives.
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
        $event->getSubject();
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
 *
 * The body registers nothing, because that is the only branch on which the answer matters.
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
        $event->getSubject();
    }
}

/**
 * `getHooks()` blows up **and** so does the handler.
 *
 * The A-5 deferral is the same shape of promise the orphan skip is, and it was wrong in the
 * same way: a gate ahead of `invokeArgs()` meant an unevaluable hook table hid a fatal
 * handler behind a skip that pointed at an inspector which reports something else entirely.
 * A-5 owns the hook table; the throw is B-12's, whatever the hook table does.
 */
class TierB12ThrowingHooksAndSettingsPlugin
{
    /** @var string */
    public static $module = 'b12throwboth';

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
        throw new \RuntimeException('and so did the settings handler');
    }
}

/**
 * A hook table whose only value is not a `[class, method]` pair. Registration is then
 * unanswerable rather than false, so claiming the handler is orphaned would be a guess —
 * Tier-A-8 owns hook value shape.
 *
 * Registers nothing, for the reason {@see TierB12OrphanPlugin} gives.
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
        $event->getSubject();
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
    public function testAPluginWithNoHandlerIsNotApplicableRatherThanPassedOrSkipped()
    {
        $findings = $this->inspector->inspect(new PluginSubject(TierB12NoHandlerPlugin::class));

        $this->assertCount(1, $findings);
        $this->assertTrue($findings[0]->isNotApplicable());
        $this->assertFalse($findings[0]->isSkipped(), 'reflection answered the question; nothing here went unrun');
        $this->assertNotSame([], $findings, 'and it must not be reported as a pass either');
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
     * Ten fleet packages declare a `getSettings()` beside an empty hook table and register
     * nothing from it. Core has no path to the method, so "it registered no settings" is a
     * complaint about a settings page production never renders — inconsequential, and dead
     * code is a different check's defect.
     *
     * Reported rather than empty: an empty result reads as a pass and would have the matrix
     * claim the plugin satisfied an assertion that was never put to it.
     *
     * Not-applicable rather than skipped, since R-4. The handler ran — see
     * testExecutesTheHandlerBeforeConsultingReachability — and two of this inspector's three
     * assertions reached a verdict on it, so "could not run" is a false statement. The
     * argument in full is on TierB12SettingsExecute::orphaned().
     *
     * @return void
     */
    public function testReportsAnOrphanedHandlerAsNotApplicableRatherThanFailingOrSkippingIt()
    {
        $findings = $this->inspector->inspect(new PluginSubject(TierB12OrphanPlugin::class));

        $this->assertCount(1, $findings);
        $this->assertNotSame([], $findings, 'a withheld assertion must not be reported as a pass');
        $this->assertTrue($findings[0]->isNotApplicable());
        $this->assertFalse($findings[0]->isSkipped(), 'the handler was executed, so the check did run');
        $this->assertFalse($findings[0]->isFailure());
        $this->assertSame('B-12', $findings[0]->assertion());
        $this->assertStringContainsString('system.settings', $findings[0]->message());
        $this->assertStringContainsString('inconsequential', $findings[0]->message());
    }

    /**
     * The gate withholds one assertion, and only in one direction.
     *
     * "It registered nothing" is vacuous for a handler core cannot dispatch. "It registered
     * something" is not: it is a fact about the body, true whatever the hook table says. So
     * an orphan that runs clean and registers correctly is a pass, and reporting "could not
     * run" over it would understate coverage — the same misreport as a false pass, in the
     * other direction.
     *
     * @return void
     */
    public function testAcceptsAnOrphanedHandlerThatRegistersSettingsCleanly()
    {
        $findings = $this->inspector->inspect(new PluginSubject(TierB12OrphanThatRegistersPlugin::class));

        $this->assertSame([], $findings, $this->describe($findings));
    }

    /**
     * **R-3.** The bug this inspector shipped with, in one test.
     *
     * `getSettings()` fatals on the first line of its body, and no hook registers it. The
     * gate used to return before `invokeArgs()`, so the throw was never observed here; B-15
     * observed it, caught it, and deferred to this inspector on the grounds that it owned
     * the throw. It had not run. The whole catalogue went 12 pass / 5 skip / 0 fail against
     * this exact plugin.
     *
     * Assertion 1 is a property of the body. Reachability has nothing to say about it, and a
     * fatal here is a fatal the day someone uncomments the hook.
     *
     * @return void
     */
    public function testReportsAThrowFromAnOrphanedHandler()
    {
        $findings = $this->inspector->inspect(new PluginSubject(TierB12FatalOrphanPlugin::class));

        $this->assertCount(1, $findings, $this->describe($findings));
        $this->assertTrue($findings[0]->isFailure(), 'a handler that fatals is a defect whether or not a hook registers it');
        $this->assertFalse($findings[0]->isSkipped(), 'deferring here is what let the fatal through');
        $this->assertStringContainsString('threw', $findings[0]->message());
        $this->assertStringContainsString(
            'tierb12_function_that_does_not_exist_anywhere',
            $findings[0]->message(),
            'the message must name the fatal, not merely record that one happened'
        );
        $this->assertSame(\Error::class, $findings[0]->context()['exception']);
    }

    /**
     * Assertion 3 is a property of the body too: a section name is wrong in the source,
     * before any dispatcher has an opinion about it.
     *
     * @return void
     */
    public function testReportsAStraySectionFromAnOrphanedHandler()
    {
        $findings = $this->inspector->inspect(new PluginSubject(TierB12StrayOrphanPlugin::class));

        $this->assertCount(1, $findings, $this->describe($findings));
        $this->assertTrue($findings[0]->isFailure());
        $this->assertSame('b12strayorphan', $findings[0]->context()['expected']);
        $this->assertSame('billing', $findings[0]->context()['found']);
    }

    /**
     * The ordering, asserted directly rather than inferred from a verdict: the handler runs
     * first, and the gate is consulted afterwards with the result in hand.
     *
     * Any reintroduced pre-execution gate leaves `$ran` false here, whatever verdict it
     * happens to produce.
     *
     * @return void
     */
    public function testExecutesTheHandlerBeforeConsultingReachability()
    {
        TierB12OrphanRecordingPlugin::$ran = false;

        $findings = $this->inspector->inspect(new PluginSubject(TierB12OrphanRecordingPlugin::class));

        $this->assertTrue(
            TierB12OrphanRecordingPlugin::$ran,
            'the orphan skip must be reached from below invokeArgs(), not from above it'
        );
        $this->assertCount(1, $findings);
        $this->assertTrue($findings[0]->isNotApplicable());
        $this->assertTrue($findings[0]->context()['executed'], 'the verdict must record that the body did run');
    }

    /**
     * What the handler registered is snapshotted before the hook table is read, so reading
     * that table cannot decide the verdict it is being consulted about.
     *
     * Asked in the other order, this plugin's `getHooks()` side effect would make an empty
     * handler look like it had registered a setting, and the orphan skip would silently
     * become a pass.
     *
     * @return void
     */
    public function testWhatTheHandlerRegisteredIsSnapshottedBeforeTheHookTableIsRead()
    {
        $findings = $this->inspector->inspect(new PluginSubject(TierB12HooksWithSideEffectPlugin::class));

        $this->assertCount(1, $findings, $this->describe($findings));
        $this->assertTrue(
            $findings[0]->isNotApplicable(),
            'a setting registered by getHooks() is not a setting the handler registered'
        );
        $this->assertTrue($findings[0]->context()['orphaned']);
    }

    /**
     * The orphaned-handler signal must survive every change of severity this finding has
     * been through — failure, then skip, now not-applicable. It is carried in the message
     * *and* in structured context, so the triage matrix can key on `orphaned` without parsing
     * prose. This is the assertion that makes "`o` hides the dead-code fact" false.
     *
     * @return void
     */
    public function testTheOrphanVerdictRecordsThatTheHandlerIsDeadCode()
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
    public function testReportsAnOrphanedHandlerAsNotApplicableWhenOtherHooksExist()
    {
        $findings = $this->inspector->inspect(new PluginSubject(TierB12PartialHooksPlugin::class));

        $this->assertCount(1, $findings);
        $this->assertTrue($findings[0]->isNotApplicable());
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
        $this->assertFalse(
            $findings[0]->isNotApplicable(),
            'a finding carrying blockedBy is always a skip: this inspector reached no verdict'
        );
        $this->assertSame('A-5', $findings[0]->context()['blockedBy']);
        $this->assertStringContainsString('Tier-A-5', $findings[0]->message());
        $this->assertTrue($findings[0]->context()['executed'], 'the deferral is about assertion 2 only; the body still ran');
    }

    /**
     * An unevaluable hook table defers assertion 2 and nothing else. A-5 reports a broken
     * `getHooks()`; it says nothing whatever about a `getSettings()` that fatals, so
     * deferring the throw to it would be the same empty promise the orphan gate used to make.
     *
     * @return void
     */
    public function testReportsAThrowEvenWhenTheHookTableCannotBeEvaluated()
    {
        $findings = $this->inspector->inspect(new PluginSubject(TierB12ThrowingHooksAndSettingsPlugin::class));

        $this->assertCount(1, $findings, $this->describe($findings));
        $this->assertTrue($findings[0]->isFailure());
        $this->assertStringContainsString('and so did the settings handler', $findings[0]->message());
        $this->assertArrayNotHasKey('blockedBy', $findings[0]->context(), 'A-5 does not report a throwing getSettings');
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
        $this->assertFalse(
            $findings[0]->isNotApplicable(),
            'a finding carrying blockedBy is always a skip: this inspector reached no verdict'
        );
        $this->assertSame('A-8', $findings[0]->context()['blockedBy']);
        $this->assertArrayNotHasKey('orphaned', $findings[0]->context(), 'unanswerable is not the same as orphaned');
        $this->assertTrue($findings[0]->context()['executed'], 'the deferral is about assertion 2 only; the body still ran');
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

    // -----------------------------------------------------------------------
    // Buffer discipline (R-8) — captured here, reported by B-15
    // -----------------------------------------------------------------------

    /**
     * **R-8.** `getSettings()` is executed here, so an echoing handler used to print straight
     * into the PHPUnit process and `beStrictAboutOutputDuringTests="true"` +
     * `failOnRisky="true"` turned it into `R  This test printed output: …` attributed to
     * B-12 — no plugin name, no handler name, reader pointed at the harness. That is exactly
     * the report {@see \MyAdmin\Plugins\Testing\Contract\TierB15NoOutput} was written to
     * replace, so producing it from here was self-defeating.
     *
     * @return void
     */
    public function testAnEchoingHandlerDoesNotEscapeIntoTheTestProcess()
    {
        $level = ob_get_level();
        ob_start();

        $findings = $this->inspector->inspect(new PluginSubject(TierB12EchoingPlugin::class));

        $escaped = ob_get_clean();

        $this->assertSame('', $escaped, 'the inspector must swallow the handler output, not re-emit it');
        $this->assertSame($level, ob_get_level(), 'and must leave the buffer stack where it found it');
        $this->assertSame([], $findings, 'the echo is not B-12\'s defect: '.$this->describe($findings));
    }

    /**
     * The discard above is only honest while B-15 reports what was dropped, so the premise is
     * executed rather than believed — the same standard
     * `TierB15NoOutputTest::testDeferringOnASettingsThrowIsBackedByAFailureFromB12` holds the
     * reverse deferral to. Making B-12 report the bytes as well would put one defect in two
     * matrix columns; making it drop them silently would report the defect nowhere. Neither
     * is possible while this test passes.
     *
     * @return void
     */
    public function testTheDiscardedOutputIsBackedByAFailureFromB15()
    {
        $subject = new PluginSubject(TierB12EchoingPlugin::class);

        $mine = $this->inspector->inspect($subject);
        $owner = (new TierB15NoOutput())->inspect($subject);

        $this->assertSame([], $mine, $this->describe($mine));

        $this->assertCount(1, $owner, $this->describe($owner));
        $this->assertTrue(
            $owner[0]->isFailure(),
            'B-12 discards the bytes because B-15 reports them — if B-15 is silent they are reported nowhere'
        );
        $this->assertSame('B-15', $owner[0]->assertion());
        $this->assertStringContainsString('b12 settings leak', $owner[0]->message());
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
