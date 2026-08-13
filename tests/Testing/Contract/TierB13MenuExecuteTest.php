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
use MyAdmin\Plugins\Testing\Contract\TierB13MenuExecute;
use MyAdmin\Plugins\Testing\Contract\TierB15NoOutput;
use MyAdmin\Plugins\Testing\Fakes\FakeApp;
use MyAdmin\Plugins\Testing\Harness;
use PHPUnit\Framework\TestCase;

// ---------------------------------------------------------------------------------
// Fixtures — every name prefixed `TierB13`, because a colliding class name in one
// process is fatal and three Tier-B inspectors are being built against this directory
// at the same time.
// ---------------------------------------------------------------------------------

/**
 * Stands in for `Symfony\Component\EventDispatcher\GenericEvent`.
 */
class TierB13Event
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
 * The correct shape, copied from `myadmin-abuse-plugin`: guard on the panel, then on the
 * permission, and add nothing when either fails.
 */
class TierB13GoodMenuPlugin
{
    /** @var string */
    public static $module = 'b13good';

    /**
     * @param \Tests\MyAdmin\Plugins\Testing\Contract\TierB13Event $event
     * @return void
     */
    public static function getMenu(TierB13Event $event)
    {
        $menu = $event->getSubject();
        if (\MyAdmin\App::ima() == 'admin') {
            if (has_acl('client_billing')) {
                $menu->add_link('admin', 'choice=none.b13good', '/images/myadmin/x.png', 'B13 Good');
            }
        }
    }
}

/**
 * **The bug class B-13 exists to catch.** Builds the admin item without ever checking
 * whether the viewer may see it, and blows up on the path a client takes. In production
 * this is not a missing menu entry — the menu renders on every page, so it is a fatal on
 * every client page load.
 */
class TierB13AclBlindPlugin
{
    /** @var string */
    public static $module = 'b13aclblind';

    /**
     * @param \Tests\MyAdmin\Plugins\Testing\Contract\TierB13Event $event
     * @return void
     */
    public static function getMenu(TierB13Event $event)
    {
        $menu = $event->getSubject();
        if (!has_acl('client_billing')) {
            throw new \RuntimeException('admin-only menu built without an ACL check');
        }
        $menu->add_link('admin', 'choice=none.b13aclblind', '/images/myadmin/x.png', 'B13 Acl Blind');
    }
}

/**
 * Executes cleanly in all four states and `echo`es in two of them — the client ones, which
 * B-15 did not visit until R-8. Correct on B-13's assertion, defective on B-15's.
 */
class TierB13ClientEchoingPlugin
{
    /** @var string */
    public static $module = 'b13clientecho';

    /**
     * @param \Tests\MyAdmin\Plugins\Testing\Contract\TierB13Event $event
     * @return void
     */
    public static function getMenu(TierB13Event $event)
    {
        if (\MyAdmin\App::ima() !== 'admin') {
            echo 'b13 client menu leak';
        }
    }
}

/**
 * The other half of the same bug class: fine for an admin, fatal for a client.
 */
class TierB13ClientHostilePlugin
{
    /** @var string */
    public static $module = 'b13clienthostile';

    /**
     * @param \Tests\MyAdmin\Plugins\Testing\Contract\TierB13Event $event
     * @return void
     */
    public static function getMenu(TierB13Event $event)
    {
        $menu = $event->getSubject();
        if (\MyAdmin\App::ima() != 'admin') {
            throw new \RuntimeException('client panel not handled');
        }
        $menu->add_link('admin', 'choice=none.b13ch', '/images/myadmin/x.png', 'B13 Client Hostile');
    }
}

/**
 * Broken in every state — all four combinations must be reported.
 */
class TierB13AlwaysThrowsPlugin
{
    /** @var string */
    public static $module = 'b13always';

    /**
     * @param \Tests\MyAdmin\Plugins\Testing\Contract\TierB13Event $event
     * @return void
     */
    public static function getMenu(TierB13Event $event)
    {
        throw new \RuntimeException('menu handler exploded');
    }
}

/**
 * 27 of 69 plugins have no menu at all.
 */
class TierB13NoMenuPlugin
{
    /** @var string */
    public static $module = 'b13nomenu';
}

/**
 * Declared, but not as the callable core dispatches.
 */
class TierB13NonStaticPlugin
{
    /** @var string */
    public static $module = 'b13nonstatic';

    /**
     * @param \Tests\MyAdmin\Plugins\Testing\Contract\TierB13Event $event
     * @return void
     */
    public function getMenu(TierB13Event $event)
    {
    }
}

/**
 * No parameter type — must fall back to the anonymous duck-typed event.
 */
class TierB13UntypedPlugin
{
    /** @var string */
    public static $module = 'b13untyped';

    /**
     * @param object $event
     * @return void
     */
    public static function getMenu($event)
    {
        $menu = $event->getSubject();
        $menu->add_link('client', 'choice=none.b13untyped', '/images/myadmin/x.png', 'B13 Untyped');
    }
}

/**
 * Registers nothing whatever the state — the shape `myadmin-fraudrecord-plugin` ships
 * verbatim, an `ima`/`has_acl()` guard around an empty body. Legitimate, and must not be
 * reported: B-13 asserts that the handler survives all four states, not that it produced
 * a link in any of them.
 */
class TierB13NoLinksPlugin
{
    /** @var string */
    public static $module = 'b13nolinks';

    /**
     * @param \Tests\MyAdmin\Plugins\Testing\Contract\TierB13Event $event
     * @return void
     */
    public static function getMenu(TierB13Event $event)
    {
        $menu = $event->getSubject();
        if (\MyAdmin\App::ima() == 'admin') {
            if (has_acl('client_billing')) {
                // Intentionally empty, exactly as the fleet original is.
            }
        }
    }
}

/**
 * B-13 — `getMenu()` executes clean for admin and client, with and without ACL.
 *
 * @covers \MyAdmin\Plugins\Testing\Contract\TierB13MenuExecute
 */
class TierB13MenuExecuteTest extends TestCase
{
    /** @var \MyAdmin\Plugins\Testing\Contract\TierB13MenuExecute */
    private $inspector;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        Bootstrap::init(['module' => 'b13harness']);
        Harness::reset();
        $this->inspector = new TierB13MenuExecute();
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
        $this->assertSame('B-13', $this->inspector->id());
        $this->assertNotSame('', $this->inspector->title());
    }

    /**
     * Four states, not two: the cross product is the whole point of the assertion.
     *
     * @return void
     */
    public function testCoversAllFourPanelPermissionCombinations()
    {
        $labels = [];
        foreach (TierB13MenuExecute::combinations() as $combination) {
            $labels[] = $combination['ima'] . '/' . ($combination['grant'] ? 'granted' : 'denied');
        }

        $this->assertCount(4, $labels);
        $this->assertSame($labels, array_unique($labels), 'each combination must appear exactly once');
        $this->assertContains('admin/granted', $labels);
        $this->assertContains('admin/denied', $labels);
        $this->assertContains('client/granted', $labels);
        $this->assertContains('client/denied', $labels);
    }

    // -----------------------------------------------------------------------
    // Pass path
    // -----------------------------------------------------------------------

    /**
     * @return void
     */
    public function testPassesForAMenuThatGuardsOnBothImaAndAcl()
    {
        $findings = $this->inspector->inspect(new PluginSubject(TierB13GoodMenuPlugin::class));

        $this->assertSame([], $findings, $this->describe($findings));
    }

    /**
     * Adding nothing for a client is correct behaviour for an admin-only menu, so B-13
     * must not require that any link was registered.
     *
     * @return void
     */
    public function testDoesNotRequireThatLinksWereAdded()
    {
        $findings = $this->inspector->inspect(new PluginSubject(TierB13NoLinksPlugin::class));

        $this->assertSame([], $findings, $this->describe($findings));
    }

    /**
     * @return void
     */
    public function testAcceptsAnUntypedEventParameter()
    {
        $findings = $this->inspector->inspect(new PluginSubject(TierB13UntypedPlugin::class));

        $this->assertSame([], $findings, $this->describe($findings));
    }

    // -----------------------------------------------------------------------
    // Fail paths — one Finding per failing combination
    // -----------------------------------------------------------------------

    /**
     * The headline case. Two of the four states are broken, and the report has to say
     * *which* two — "getMenu() throws" would send the reader looking in the wrong branch.
     *
     * @return void
     */
    public function testReportsEachAclDeniedCombinationSeparately()
    {
        $findings = $this->inspector->inspect(new PluginSubject(TierB13AclBlindPlugin::class));

        $this->assertCount(2, $findings, $this->describe($findings));
        $combinations = [];
        foreach ($findings as $finding) {
            $this->assertTrue($finding->isFailure());
            $this->assertSame('B-13', $finding->assertion());
            $this->assertStringContainsString('without an ACL check', $finding->message());
            $this->assertSame('denied', $finding->context()['acl']);
            $combinations[] = $finding->context()['combination'];
        }
        $this->assertSame(['ima=admin, has_acl()=false', 'ima=client, has_acl()=false'], $combinations);
    }

    /**
     * @return void
     */
    public function testReportsBothClientCombinationsForAnAdminOnlyHandler()
    {
        $findings = $this->inspector->inspect(new PluginSubject(TierB13ClientHostilePlugin::class));

        $this->assertCount(2, $findings, $this->describe($findings));
        foreach ($findings as $finding) {
            $this->assertTrue($finding->isFailure());
            $this->assertSame('client', $finding->context()['ima']);
            $this->assertStringContainsString('client panel not handled', $finding->message());
        }
    }

    /**
     * @return void
     */
    public function testReportsAllFourWhenTheHandlerAlwaysThrows()
    {
        $findings = $this->inspector->inspect(new PluginSubject(TierB13AlwaysThrowsPlugin::class));

        $this->assertCount(4, $findings, $this->describe($findings));
        $this->assertStringContainsString(TierB13AlwaysThrowsPlugin::class, $findings[0]->message());
        $this->assertSame(\RuntimeException::class, $findings[0]->context()['exception']);
    }

    /**
     * @return void
     */
    public function testReportsANonStaticHandlerAsAFailure()
    {
        $findings = $this->inspector->inspect(new PluginSubject(TierB13NonStaticPlugin::class));

        $this->assertCount(1, $findings);
        $this->assertTrue($findings[0]->isFailure());
        $this->assertStringContainsString('not public static', $findings[0]->message());
    }

    // -----------------------------------------------------------------------
    // Paths where nothing is verified — a skip and a not-applicable are not the same
    // -----------------------------------------------------------------------

    /**
     * 28 of 71 fleet plugins have no menu at all. That is not a pass, and since R-4 it is not
     * a skip either: reflection answered the question outright, so nothing about this package
     * went unchecked. Asserting `isSkipped()` is false is the load-bearing half — it is what
     * keeps these 28 out of the count of genuine coverage holes.
     *
     * @return void
     */
    public function testAPluginWithNoMenuIsNotApplicableRatherThanPassedOrSkipped()
    {
        $findings = $this->inspector->inspect(new PluginSubject(TierB13NoMenuPlugin::class));

        $this->assertCount(1, $findings);
        $this->assertTrue($findings[0]->isNotApplicable());
        $this->assertFalse($findings[0]->isSkipped(), 'no menu is not the same as a check that could not run');
        $this->assertNotSame([], $findings, 'and it must not be reported as a pass');
        $this->assertStringContainsString('getMenu', $findings[0]->message());
    }

    /**
     * @return void
     */
    public function testSkipsWhenThePluginClassIsNotLoadable()
    {
        $findings = $this->inspector->inspect(new PluginSubject('Tests\\MyAdmin\\Plugins\\Testing\\Contract\\TierB13NoSuchPlugin'));

        $this->assertCount(1, $findings);
        $this->assertTrue($findings[0]->isSkipped());
        $this->assertStringContainsString('not loadable', $findings[0]->message());
    }

    // -----------------------------------------------------------------------
    // Isolation
    // -----------------------------------------------------------------------

    /**
     * Four executions per plugin, 69 plugins, one process. If combination *n* left a menu
     * entry or an ACL grant behind, combination *n+1* — and the next plugin — would run
     * against a state no real request ever produces.
     *
     * @return void
     */
    public function testStateDoesNotLeakBetweenConsecutivePlugins()
    {
        $first = $this->inspector->inspect(new PluginSubject(TierB13GoodMenuPlugin::class));
        $second = $this->inspector->inspect(new PluginSubject(TierB13AclBlindPlugin::class));

        $this->assertSame([], $first, $this->describe($first));
        $this->assertCount(2, $second, 'the ACL-blind plugin must still be caught after a clean one');

        // …and the reverse: a plugin that threw twice must not affect the next one.
        $third = $this->inspector->inspect(new PluginSubject(TierB13GoodMenuPlugin::class));
        $this->assertSame([], $third, $this->describe($third));

        $this->assertSame(0, Harness::menu()->linkCount(), 'menu entries must not survive the inspection');
    }

    /**
     * `Harness::reset()` deliberately leaves `ima` and the ACL allowlist alone, so the
     * inspector has to restore them itself or every inspector after it inherits
     * `ima=client` with permissions denied — the last combination it happened to run.
     *
     * @return void
     */
    public function testRestoresImaAndAclAfterRunning()
    {
        Harness::setAcl(['client_billing']);
        FakeApp::setIma('admin');

        $this->inspector->inspect(new PluginSubject(TierB13GoodMenuPlugin::class));

        $this->assertSame('client', FakeApp::ima());
        $this->assertFalse(has_acl('client_billing'));
    }

    // -----------------------------------------------------------------------
    // Buffer discipline (R-8) — captured here, reported by B-15
    // -----------------------------------------------------------------------

    /**
     * **R-8.** Four invocations of plugin code, none of them buffered until now. A handler
     * echoing on any one of them printed into the PHPUnit process, and
     * `beStrictAboutOutputDuringTests="true"` + `failOnRisky="true"` reported it as
     * `R  This test printed output: …` against B-13 — without naming the plugin, the handler,
     * or which of the four states produced it.
     *
     * The fixture prints only for clients on purpose: that is the half of the cross product
     * B-15 could not see before R-8, so it is the half where a silent discard would have
     * lost the defect entirely.
     *
     * @return void
     */
    public function testAnEchoingHandlerDoesNotEscapeIntoTheTestProcess()
    {
        $level = ob_get_level();
        ob_start();

        $findings = $this->inspector->inspect(new PluginSubject(TierB13ClientEchoingPlugin::class));

        $escaped = ob_get_clean();

        $this->assertSame('', $escaped, 'the inspector must swallow the handler output, not re-emit it');
        $this->assertSame($level, ob_get_level(), 'and must leave the buffer stack where it found it');
        $this->assertSame([], $findings, 'the echo is not B-13\'s defect: '.$this->describe($findings));
    }

    /**
     * The discard is only honest while B-15 reports what was dropped, and B-15 can only do
     * that while it executes the same four states this inspector does. Both halves are put to
     * the test here rather than restated: the fixture is silent in the one state B-15 used to
     * run, so a B-15 that narrowed back to `ima=admin` + grant-all would leave this red.
     *
     * @return void
     */
    public function testTheDiscardedOutputIsBackedByAFailureFromB15()
    {
        $subject = new PluginSubject(TierB13ClientEchoingPlugin::class);

        $mine = $this->inspector->inspect($subject);
        $owner = (new TierB15NoOutput())->inspect($subject);

        $this->assertSame([], $mine, $this->describe($mine));

        $this->assertCount(1, $owner, $this->describe($owner));
        $this->assertTrue(
            $owner[0]->isFailure(),
            'B-13 discards the bytes because B-15 reports them — if B-15 is silent they are reported nowhere'
        );
        $this->assertSame('B-15', $owner[0]->assertion());
        $this->assertStringContainsString('b13 client menu leak', $owner[0]->message());
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
