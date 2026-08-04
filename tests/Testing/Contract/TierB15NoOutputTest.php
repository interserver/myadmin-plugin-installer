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
use MyAdmin\Plugins\Testing\Contract\TierB13MenuExecute;
use MyAdmin\Plugins\Testing\Contract\TierB15NoOutput;
use MyAdmin\Plugins\Testing\Fakes\FakeApp;
use MyAdmin\Plugins\Testing\Harness;
use PHPUnit\Framework\TestCase;

// ---------------------------------------------------------------------------------
// Fixtures — every name prefixed `TierB15`, because a colliding class name in one
// process is fatal and three Tier-B inspectors are being built against this directory
// at the same time.
//
// Note the irony these fixtures create: this file's whole subject is code that prints,
// and `beStrictAboutOutputDuringTests="true"` fails any test that lets a byte escape.
// Every echo below therefore has to be swallowed by the inspector's own buffer — which
// is exactly the behaviour under test.
// ---------------------------------------------------------------------------------

/**
 * Stands in for `Symfony\Component\EventDispatcher\GenericEvent`.
 */
class TierB15Event
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
 * The correct route: markup goes through `add_output()`, which buffers.
 */
class TierB15QuietPlugin
{
    /** @var string */
    public static $module = 'b15quiet';

    /**
     * @param \Tests\MyAdmin\Plugins\Testing\Contract\TierB15Event $event
     * @return void
     */
    public static function getSettings(TierB15Event $event)
    {
        $event->getSubject()->add_text_setting(self::$module, 'General', 'b15quiet_key', 'Key', 'tip', '1');
        add_output('<div>this is buffered, not printed</div>');
    }

    /**
     * @param \Tests\MyAdmin\Plugins\Testing\Contract\TierB15Event $event
     * @return void
     */
    public static function getMenu(TierB15Event $event)
    {
        $event->getSubject()->add_link('admin', 'choice=none.b15quiet', '/images/myadmin/x.png', 'B15 Quiet');
    }
}

/**
 * Echoes from the settings handler. In production this emits before the layout — content
 * above `<!DOCTYPE html>`, and a "headers already sent" fatal if it lands early enough.
 */
class TierB15EchoingSettingsPlugin
{
    /** @var string */
    public static $module = 'b15echosettings';

    /**
     * @param \Tests\MyAdmin\Plugins\Testing\Contract\TierB15Event $event
     * @return void
     */
    public static function getSettings(TierB15Event $event)
    {
        echo '<div class="alert">b15 settings leak</div>';
        $event->getSubject()->add_text_setting(self::$module, 'General', 'b15echo_key', 'Key', 'tip', '1');
    }
}

/**
 * Same defect in the menu handler, which is worse: the menu renders on every page.
 */
class TierB15EchoingMenuPlugin
{
    /** @var string */
    public static $module = 'b15echomenu';

    /**
     * @param \Tests\MyAdmin\Plugins\Testing\Contract\TierB15Event $event
     * @return void
     */
    public static function getMenu(TierB15Event $event)
    {
        print 'b15 menu leak';
    }
}

/**
 * Prints far more than fits a failure line.
 */
class TierB15VerbosePlugin
{
    /** @var string */
    public static $module = 'b15verbose';

    /**
     * @param \Tests\MyAdmin\Plugins\Testing\Contract\TierB15Event $event
     * @return void
     */
    public static function getSettings(TierB15Event $event)
    {
        echo str_repeat('x', 500);
    }
}

/**
 * Leaves an output buffer pushed. The inspector has to drain it or every test after this
 * one runs at the wrong nesting level and PHPUnit attributes the content to the wrong
 * test.
 */
class TierB15UnbalancedBufferPlugin
{
    /** @var string */
    public static $module = 'b15unbalanced';

    /**
     * @param \Tests\MyAdmin\Plugins\Testing\Contract\TierB15Event $event
     * @return void
     */
    public static function getSettings(TierB15Event $event)
    {
        echo 'before';
        ob_start();
        echo 'inside an abandoned buffer';
    }
}

/**
 * Throws without printing. B-15 can only report that its own observation is incomplete; the
 * throw belongs to B-12, which fails on it — a claim this file now verifies by running B-12
 * rather than by restating it in a comment.
 */
class TierB15ThrowingPlugin
{
    /** @var string */
    public static $module = 'b15throw';

    /**
     * @param \Tests\MyAdmin\Plugins\Testing\Contract\TierB15Event $event
     * @return void
     */
    public static function getSettings(TierB15Event $event)
    {
        throw new \RuntimeException('handler exploded before printing anything');
    }
}

/**
 * Prints and then throws. The print is the B-15 defect and must win over the incomplete
 * observation, or the actionable half of the report is lost.
 */
class TierB15EchoThenThrowPlugin
{
    /** @var string */
    public static $module = 'b15echothrow';

    /**
     * @param \Tests\MyAdmin\Plugins\Testing\Contract\TierB15Event $event
     * @return void
     */
    public static function getSettings(TierB15Event $event)
    {
        echo 'printed before the explosion';
        throw new \RuntimeException('and then it exploded');
    }
}

/**
 * Neither handler exists.
 */
class TierB15NoHandlersPlugin
{
    /** @var string */
    public static $module = 'b15nohandlers';
}

/**
 * **The R-3 shape.** `getSettings()` fatals on the first line of its body, and no hook
 * registers it.
 *
 * This is the plugin the deferral loop lost. B-12 skipped it as ORPHANED before executing
 * it; B-15 executed it, caught the `Error`, and skipped on the grounds that B-12 owned the
 * throw. Nothing was red anywhere. The deferral is only legitimate while the owner actually
 * fails on this plugin, which is what
 * {@see TierB15NoOutputTest::testDeferringOnASettingsThrowIsBackedByAFailureFromB12} runs
 * B-12 to establish rather than assume.
 */
class TierB15OrphanFatalSettingsPlugin
{
    /** @var string */
    public static $module = 'b15orphanfatal';

    /**
     * @return array<string,array{0:string,1:string}>
     */
    public static function getHooks()
    {
        return [];
    }

    /**
     * @param \Tests\MyAdmin\Plugins\Testing\Contract\TierB15Event $event
     * @return void
     */
    public static function getSettings(TierB15Event $event)
    {
        tierb15_function_that_does_not_exist_anywhere();
    }
}

/**
 * A `getMenu()` that throws without printing, so the other half of the deferral — the half
 * B-13 owns — can be held to the same standard.
 *
 * `getSettings()` is deliberately absent: it keeps the finding under test down to one, and
 * B-13 is the only owner in play.
 */
class TierB15ThrowingMenuPlugin
{
    /** @var string */
    public static $module = 'b15throwmenu';

    /**
     * @param \Tests\MyAdmin\Plugins\Testing\Contract\TierB15Event $event
     * @return void
     */
    public static function getMenu(TierB15Event $event)
    {
        throw new \RuntimeException('menu handler exploded before printing anything');
    }
}

/**
 * Pops more buffers than it pushed, destroying one that belonged to whoever called it.
 *
 * The mirror image of {@see TierB15UnbalancedBufferPlugin}, and the case the second drain
 * loop exists for: without it the inspector returns at a *lower* nesting level than it was
 * entered at, PHPUnit's own buffer is gone, and every test after this one is measured
 * against a stack that is one short.
 */
class TierB15OverPoppingPlugin
{
    /** @var string */
    public static $module = 'b15overpop';

    /**
     * @param \Tests\MyAdmin\Plugins\Testing\Contract\TierB15Event $event
     * @return void
     */
    public static function getSettings(TierB15Event $event)
    {
        echo 'this goes down with the buffer';
        ob_end_clean();
        ob_end_clean();
    }
}

/**
 * Prints only when the viewer is not an admin — invisible to a single-state observation.
 *
 * This is the shape that made B-15's old one-state `getMenu()` run a lie: B-13 executed the
 * client states, captured the bytes and discarded them, and B-15 never saw them.
 */
class TierB15ClientOnlyEchoMenuPlugin
{
    /** @var string */
    public static $module = 'b15clientecho';

    /**
     * @param \Tests\MyAdmin\Plugins\Testing\Contract\TierB15Event $event
     * @return void
     */
    public static function getMenu(TierB15Event $event)
    {
        if (\MyAdmin\App::ima() !== 'admin') {
            echo 'b15 client-only menu leak';
        }
    }
}

/**
 * Prints only when `has_acl()` denies — the other axis of B-13's cross product.
 */
class TierB15DeniedOnlyEchoMenuPlugin
{
    /** @var string */
    public static $module = 'b15deniedecho';

    /**
     * @param \Tests\MyAdmin\Plugins\Testing\Contract\TierB15Event $event
     * @return void
     */
    public static function getMenu(TierB15Event $event)
    {
        if (!has_acl('client_billing')) {
            echo 'b15 acl-denied menu leak';
        }
    }
}

/**
 * Records the panel/ACL state of every invocation, so a test can compare the states B-15
 * executes against the states B-13 executes without either list being restated in the test.
 */
class TierB15StateRecordingMenuPlugin
{
    /** @var string */
    public static $module = 'b15states';

    /** @var array<int,string> */
    public static $seen = [];

    /**
     * @param \Tests\MyAdmin\Plugins\Testing\Contract\TierB15Event $event
     * @return void
     */
    public static function getMenu(TierB15Event $event)
    {
        self::$seen[] = \MyAdmin\App::ima() . '/' . (has_acl('client_billing') ? 'granted' : 'denied');
    }
}

/**
 * B-15 — plugin handlers must not `echo`.
 *
 * @covers \MyAdmin\Plugins\Testing\Contract\TierB15NoOutput
 */
class TierB15NoOutputTest extends TestCase
{
    /** @var \MyAdmin\Plugins\Testing\Contract\TierB15NoOutput */
    private $inspector;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        Bootstrap::init(['module' => 'b15harness']);
        Harness::reset();
        $this->inspector = new TierB15NoOutput();
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
        $this->assertSame('B-15', $this->inspector->id());
        $this->assertNotSame('', $this->inspector->title());
    }

    // -----------------------------------------------------------------------
    // Pass path
    // -----------------------------------------------------------------------

    /**
     * `add_output()` is the correct route and must not be mistaken for a leak.
     *
     * @return void
     */
    public function testPassesWhenHandlersBufferThroughAddOutput()
    {
        $findings = $this->inspector->inspect(new PluginSubject(TierB15QuietPlugin::class));

        $this->assertSame([], $findings, $this->describe($findings));
    }

    // -----------------------------------------------------------------------
    // Fail paths
    // -----------------------------------------------------------------------

    /**
     * The message has to name the plugin, the method and the bytes — the three things
     * PHPUnit's bare `R  This test printed output:` does not tell you.
     *
     * @return void
     */
    public function testReportsAnEchoingSettingsHandler()
    {
        $findings = $this->inspector->inspect(new PluginSubject(TierB15EchoingSettingsPlugin::class));

        $this->assertCount(1, $findings, $this->describe($findings));
        $this->assertTrue($findings[0]->isFailure());
        $this->assertSame('B-15', $findings[0]->assertion());
        $this->assertStringContainsString(TierB15EchoingSettingsPlugin::class, $findings[0]->message());
        $this->assertStringContainsString('getSettings', $findings[0]->message());
        $this->assertStringContainsString('b15 settings leak', $findings[0]->message());
        $this->assertSame('getSettings', $findings[0]->context()['method']);
    }

    /**
     * @return void
     */
    public function testReportsAnEchoingMenuHandler()
    {
        $findings = $this->inspector->inspect(new PluginSubject(TierB15EchoingMenuPlugin::class));

        $this->assertCount(1, $findings, $this->describe($findings));
        $this->assertTrue($findings[0]->isFailure());
        $this->assertSame('getMenu', $findings[0]->context()['method']);
        $this->assertStringContainsString('b15 menu leak', $findings[0]->message());
    }

    /**
     * @return void
     */
    public function testTruncatesLongOutputInTheMessage()
    {
        $findings = $this->inspector->inspect(new PluginSubject(TierB15VerbosePlugin::class));

        $this->assertCount(1, $findings);
        $this->assertSame(500, $findings[0]->context()['bytes'], 'the full length must survive in the context');
        $this->assertStringContainsString('[truncated]', $findings[0]->message());
        $this->assertLessThan(500, strlen($findings[0]->context()['output']));
    }

    /**
     * @return void
     */
    public function testReportsOutputThatPrecededAThrow()
    {
        $findings = $this->inspector->inspect(new PluginSubject(TierB15EchoThenThrowPlugin::class));

        $this->assertCount(1, $findings, $this->describe($findings));
        $this->assertTrue($findings[0]->isFailure(), 'the printed bytes are the actionable half and must win over the throw');
        $this->assertStringContainsString('printed before the explosion', $findings[0]->message());
    }

    // -----------------------------------------------------------------------
    // Skip paths
    // -----------------------------------------------------------------------

    /**
     * @return void
     */
    public function testSkipsWhenTheHandlerThrewWithoutPrinting()
    {
        $findings = $this->inspector->inspect(new PluginSubject(TierB15ThrowingPlugin::class));

        $this->assertCount(1, $findings);
        $this->assertTrue($findings[0]->isSkipped(), 'an unfinished handler is an incomplete observation, not a pass');
        $this->assertNotSame([], $findings);
        $this->assertSame(\RuntimeException::class, $findings[0]->context()['exception']);
        $this->assertSame('B-12', $findings[0]->context()['blockedBy'], 'a deferral must name who it defers to');
        $this->assertStringContainsString(
            'handler exploded before printing anything',
            $findings[0]->message(),
            'the exception message was caught and read here; discarding it is what made the old skip information-free'
        );
    }

    // -----------------------------------------------------------------------
    // The deferral — verified against the owner, not asserted about it
    // -----------------------------------------------------------------------

    /**
     * **R-3.** A skip that defers is only honest while the inspector it defers to reports
     * the defect. Until R-3 that was false and nothing noticed: B-12 gated `getSettings()`
     * on reachability *before* executing it, so for an orphaned handler it skipped, this
     * inspector deferred to that skip, and a plugin whose `getSettings()` fatals on line 1
     * passed the whole catalogue 12 / 5 / 0.
     *
     * So the premise is executed rather than believed. Run both inspectors over one subject
     * — the exact plugin that used to slip through — and require the owner to be red
     * whenever this one defers. Putting any gate back in front of B-12's `invokeArgs()`
     * turns this test red instead of quietly reopening the loop.
     *
     * @return void
     */
    public function testDeferringOnASettingsThrowIsBackedByAFailureFromB12()
    {
        $subject = new PluginSubject(TierB15OrphanFatalSettingsPlugin::class);

        $deferrals = $this->inspector->inspect($subject);
        $owner = (new TierB12SettingsExecute())->inspect($subject);

        $this->assertCount(1, $deferrals, $this->describe($deferrals));
        $this->assertTrue($deferrals[0]->isSkipped());
        $this->assertSame('B-12', $deferrals[0]->context()['blockedBy']);

        $this->assertCount(1, $owner, $this->describe($owner));
        $this->assertTrue(
            $owner[0]->isFailure(),
            'B-15 defers the throw to B-12, so B-12 must fail on it — if it skips, the defect is reported nowhere'
        );
        $this->assertStringContainsString('tierb15_function_that_does_not_exist_anywhere', $owner[0]->message());
    }

    /**
     * The same obligation for the menu half. B-13 has never had a reachability gate — it
     * runs `getMenu()` in four panel/ACL states, one of them this inspector's admin +
     * grant-all — so the deferral holds there today; this pins that it keeps holding.
     *
     * @return void
     */
    public function testDeferringOnAMenuThrowIsBackedByAFailureFromB13()
    {
        $subject = new PluginSubject(TierB15ThrowingMenuPlugin::class);

        $deferrals = $this->inspector->inspect($subject);
        $owner = (new TierB13MenuExecute())->inspect($subject);

        $this->assertCount(1, $deferrals, $this->describe($deferrals));
        $this->assertTrue($deferrals[0]->isSkipped());
        $this->assertSame('B-13', $deferrals[0]->context()['blockedBy'], 'getMenu() is B-13\'s handler, not B-12\'s');

        $this->assertNotSame([], $owner, 'B-15 defers the menu throw to B-13, so B-13 must report it');
        $this->assertTrue($owner[0]->isFailure());
        $this->assertStringContainsString('menu handler exploded', $owner[0]->message());
    }

    /**
     * @return void
     */
    public function testAPluginWithNeitherHandlerIsNotApplicableRatherThanSkipped()
    {
        $findings = $this->inspector->inspect(new PluginSubject(TierB15NoHandlersPlugin::class));

        $this->assertCount(1, $findings);
        $this->assertTrue($findings[0]->isNotApplicable());
        $this->assertFalse(
            $findings[0]->isSkipped(),
            'there is no handler whose output could be observed, which is not the same as failing to observe one'
        );
        $this->assertStringContainsString('getSettings', $findings[0]->message());
    }

    /**
     * @return void
     */
    public function testSkipsWhenThePluginClassIsNotLoadable()
    {
        $findings = $this->inspector->inspect(new PluginSubject('Tests\\MyAdmin\\Plugins\\Testing\\Contract\\TierB15NoSuchPlugin'));

        $this->assertCount(1, $findings);
        $this->assertTrue($findings[0]->isSkipped());
        $this->assertStringContainsString('not loadable', $findings[0]->message());
    }

    // -----------------------------------------------------------------------
    // Buffer discipline — the part that corrupts the whole process when wrong
    // -----------------------------------------------------------------------

    /**
     * Nothing may escape to the real output stream. If it did,
     * `beStrictAboutOutputDuringTests` would fail *this* test rather than report the
     * plugin — the opaque failure B-15 exists to replace.
     *
     * @return void
     */
    public function testTheInspectorItselfPrintsNothing()
    {
        $level = ob_get_level();
        ob_start();
        $findings = $this->inspector->inspect(new PluginSubject(TierB15EchoingSettingsPlugin::class));
        $escaped = ob_get_clean();

        $this->assertSame('', $escaped, 'the inspector must swallow the plugin output, not re-emit it');
        $this->assertCount(1, $findings);
        $this->assertSame($level, ob_get_level());
    }

    /**
     * @return void
     */
    public function testDrainsBuffersThePluginLeftOpen()
    {
        $level = ob_get_level();

        $findings = $this->inspector->inspect(new PluginSubject(TierB15UnbalancedBufferPlugin::class));

        $this->assertSame($level, ob_get_level(), 'an abandoned buffer must not survive the inspection');
        $this->assertCount(1, $findings);
        $this->assertStringContainsString('before', $findings[0]->message());
        $this->assertStringContainsString('inside an abandoned buffer', $findings[0]->message());
    }

    /**
     * A throwing handler must not skip the drain — that is what the `finally` is for.
     *
     * @return void
     */
    public function testBufferLevelIsRestoredWhenTheHandlerThrows()
    {
        $level = ob_get_level();

        $this->inspector->inspect(new PluginSubject(TierB15ThrowingPlugin::class));

        $this->assertSame($level, ob_get_level());
    }

    /**
     * **R-8, mutant 1.** The drain pops innermost-first, so the chunks have to be
     * reassembled outermost-first to come back in the order they were written. Nothing
     * pinned that: reversing the concatenation kept every existing assertion green, because
     * they all ask whether a substring is *present*, never in what order.
     *
     * An out-of-order excerpt is not cosmetic. The reader's job is to find the `echo`, and
     * the message is the only positional evidence they get; showing the tail of a handler
     * before its head sends them to the wrong line.
     *
     * @return void
     */
    public function testCaptureReassemblesNestedBuffersInTheOrderTheyWereWritten()
    {
        $result = TierB15NoOutput::capture(function () {
            echo 'FIRST';
            ob_start();
            echo 'SECOND';
            ob_start();
            echo 'THIRD';
        });

        $this->assertSame('FIRSTSECONDTHIRD', $result['output']);
        $this->assertNull($result['error']);
    }

    /**
     * The same rule as it reaches the report a human reads.
     *
     * @return void
     */
    public function testAnAbandonedBufferIsQuotedInTheOrderItWasWritten()
    {
        $findings = $this->inspector->inspect(new PluginSubject(TierB15UnbalancedBufferPlugin::class));

        $this->assertCount(1, $findings, $this->describe($findings));
        $this->assertSame(
            'beforeinside an abandoned buffer',
            $findings[0]->context()['output'],
            'the outer buffer was written first and must be quoted first'
        );
    }

    /**
     * **R-8, mutant 2.** A handler that pops past our buffer takes the caller's with it.
     * Deleting the loop that pushes replacements left every existing assertion green,
     * because no fixture ever over-popped — so the one line standing between the fleet run
     * and a corrupted buffer stack was held in place by nothing at all.
     *
     * A sacrificial buffer is pushed by the test so the over-pop destroys that rather than
     * PHPUnit's own: this test is about the depth being restored, not about proving PHPUnit
     * survives having its buffer deleted.
     *
     * @return void
     */
    public function testRestoresTheNestingDepthWhenAHandlerPopsPastItsBuffer()
    {
        $level = ob_get_level();
        ob_start();

        $findings = $this->inspector->inspect(new PluginSubject(TierB15OverPoppingPlugin::class));

        $restored = ob_get_level();
        while (ob_get_level() > $level) {
            ob_end_clean();
        }

        $this->assertSame(
            $level + 1,
            $restored,
            'a handler that pops past our buffer must leave the nesting depth where it found it'
        );
        $this->assertSame(
            [],
            $findings,
            'the bytes went down with the destroyed buffer, so there is nothing left to report: '
                .$this->describe($findings)
        );
    }

    /**
     * `capture()` is public API for five other inspectors, so its throw contract is pinned
     * here rather than inferred from the handlers that happen to use it.
     *
     * @return void
     */
    public function testCaptureReturnsTheThrowAndTheBytesThatPrecededIt()
    {
        $level = ob_get_level();

        $result = TierB15NoOutput::capture(function () {
            echo 'printed';
            throw new \RuntimeException('then exploded');
        });

        $this->assertSame($level, ob_get_level(), 'the finally must drain even when the callable throws');
        $this->assertSame('printed', $result['output']);
        $this->assertInstanceOf(\RuntimeException::class, $result['error']);
        $this->assertSame('then exploded', $result['error']->getMessage());
    }

    // -----------------------------------------------------------------------
    // Menu states — B-12/B-13 may only discard what this inspector observes
    // -----------------------------------------------------------------------

    /**
     * **R-8.** B-13 captures its four `getMenu()` runs and discards the bytes on the grounds
     * that B-15 reports them. That is only true while B-15 executes the same four states, so
     * the two lists are compared directly rather than restated: the fixture records the state
     * it was invoked in, and the expectation is built from
     * {@see TierB13MenuExecute::combinations()} itself.
     *
     * Reverting this inspector to its old single `ima=admin` + grant-all pass turns this red,
     * instead of silently reopening the hole B-13's discard now depends on being closed.
     *
     * @return void
     */
    public function testObservesTheMenuInEveryStateB13Executes()
    {
        TierB15StateRecordingMenuPlugin::$seen = [];

        $findings = $this->inspector->inspect(new PluginSubject(TierB15StateRecordingMenuPlugin::class));

        $expected = [];
        foreach (TierB13MenuExecute::combinations() as $combination) {
            $expected[] = $combination['ima'].'/'.($combination['grant'] ? 'granted' : 'denied');
        }

        $this->assertSame([], $findings, $this->describe($findings));
        $this->assertSame($expected, TierB15StateRecordingMenuPlugin::$seen);
    }

    /**
     * The behavioural half of the same point: a leak only a client can trigger.
     *
     * @return void
     */
    public function testReportsAMenuThatOnlyEchoesForClients()
    {
        $findings = $this->inspector->inspect(new PluginSubject(TierB15ClientOnlyEchoMenuPlugin::class));

        $this->assertCount(1, $findings, $this->describe($findings));
        $this->assertTrue($findings[0]->isFailure());
        $this->assertStringContainsString('b15 client-only menu leak', $findings[0]->message());
        $this->assertSame(
            'ima=client, has_acl()=true',
            $findings[0]->context()['combination'],
            'the report must name the state it was seen in, or the reader cannot reproduce it'
        );
    }

    /**
     * And the other axis, so a revert to two states rather than four is caught as well.
     *
     * @return void
     */
    public function testReportsAMenuThatOnlyEchoesWhenTheAclDenies()
    {
        $findings = $this->inspector->inspect(new PluginSubject(TierB15DeniedOnlyEchoMenuPlugin::class));

        $this->assertCount(1, $findings, $this->describe($findings));
        $this->assertTrue($findings[0]->isFailure());
        $this->assertStringContainsString('b15 acl-denied menu leak', $findings[0]->message());
        $this->assertSame('ima=admin, has_acl()=false', $findings[0]->context()['combination']);
    }

    // -----------------------------------------------------------------------
    // Isolation
    // -----------------------------------------------------------------------

    /**
     * 69 plugins, one process. Captured output from plugin *n* appearing in plugin
     * *n+1*'s finding would put a real defect against an innocent repo.
     *
     * @return void
     */
    public function testCapturedOutputDoesNotLeakIntoTheNextPlugin()
    {
        $first = $this->inspector->inspect(new PluginSubject(TierB15EchoingSettingsPlugin::class));
        $second = $this->inspector->inspect(new PluginSubject(TierB15QuietPlugin::class));

        $this->assertCount(1, $first);
        $this->assertSame([], $second, $this->describe($second));

        // …and the reverse: a clean plugin must not mask the next one's leak.
        $third = $this->inspector->inspect(new PluginSubject(TierB15EchoingMenuPlugin::class));
        $this->assertCount(1, $third);
        $this->assertStringContainsString('b15 menu leak', $third[0]->message());
        $this->assertStringNotContainsString('b15 settings leak', $third[0]->message());
    }

    /**
     * @return void
     */
    public function testLeavesTheHarnessClean()
    {
        $this->inspector->inspect(new PluginSubject(TierB15QuietPlugin::class));

        $this->assertSame(0, Harness::settings()->settingCount());
        $this->assertSame(0, Harness::menu()->linkCount());
        $this->assertTrue(Harness::output()->isEmpty(), 'buffered output must not survive the inspection');
        $this->assertSame('client', FakeApp::ima());
        $this->assertFalse(has_acl('client_billing'));
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
