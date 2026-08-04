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
 * Throws without printing — B-12 owns the throw, so B-15 can only report that its own
 * observation is incomplete.
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
    }

    /**
     * @return void
     */
    public function testSkipsWhenNeitherHandlerExists()
    {
        $findings = $this->inspector->inspect(new PluginSubject(TierB15NoHandlersPlugin::class));

        $this->assertCount(1, $findings);
        $this->assertTrue($findings[0]->isSkipped());
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
