<?php
/**
 * @author Joe Huss <detain@interserver.net>
 * @copyright 2026
 * @package MyAdmin
 * @category Testing
 */

namespace Tests\MyAdmin\Plugins\Testing;

use MyAdmin\Plugins\Testing\Contract\Finding;
use MyAdmin\Plugins\Testing\Harness;
use MyAdmin\Plugins\Testing\ServiceHandlerProbe;
use MyAdmin\Plugins\Testing\ServiceLifecycleEvent;
use MyAdmin\Plugins\Testing\ServicePluginTestCase;
use PHPUnit\Framework\TestCase;
use Tests\MyAdmin\Plugins\Testing\Fixtures\SptcCase;
use Tests\MyAdmin\Plugins\Testing\Fixtures\SptcConfiguredPlugin;
use Tests\MyAdmin\Plugins\Testing\Fixtures\SptcGatedPlugin;
use Tests\MyAdmin\Plugins\Testing\Fixtures\SptcLeakyPlugin;
use Tests\MyAdmin\Plugins\Testing\Fixtures\SptcTypedEventPlugin;
use Tests\MyAdmin\Plugins\Testing\Fixtures\SptcUngatedPlugin;

/**
 * Phase 3: the two behavioural assertions over service-lifecycle handlers.
 *
 * Organised by the question each group answers, because the failure modes are quite
 * different from one another:
 *
 *  1. **gate derivation** — does the harness find the right event key? Getting this wrong
 *     makes assertion B pass vacuously across half the fleet while looking green, which is
 *     the worst outcome available to this phase.
 *  2. **assertion A** — pass, fail, skip and not-applicable, and specifically that a throw
 *     naming a missing symbol is not treated the same as a throw that is the handler's own
 *     logic failing.
 *  3. **assertion B** — all three leak shapes, and the completeness of the effects sweep.
 *  4. **plumbing** — the event stand-in, the foreign-collision guard, the hatch ledger.
 *
 * @coversNothing
 */
class ServicePluginTestCaseTest extends TestCase
{
    /**
     * The statics on {@see SptcCase} and the harness fakes are both process-global, and this
     * class executes real handlers against them. Anything left behind changes the verdict of
     * a later test rather than failing here, which is much harder to trace.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        SptcCase::reset();
        ServicePluginTestCase::clearServiceOverrideLedger();
        Harness::reset();
        Harness::setAcl([]);
    }

    /**
     * @param string $handler
     * @return \MyAdmin\Plugins\Testing\Contract\Finding[]
     */
    private function acts($handler)
    {
        return (new SptcCase('testHandlerActsOnAServiceTypeItOwns'))->actsForTest($handler);
    }

    /**
     * @param string $handler
     * @return \MyAdmin\Plugins\Testing\Contract\Finding[]
     */
    private function inert($handler)
    {
        return (new SptcCase('testHandlerIsInertForAForeignServiceType'))->inertForTest($handler);
    }

    /**
     * @param \MyAdmin\Plugins\Testing\Contract\Finding[] $findings
     * @return string
     */
    private function messages(array $findings)
    {
        return implode("\n", array_map(static function (Finding $f) {
            return $f->describe();
        }, $findings));
    }

    // -----------------------------------------------------------------------
    // 1. Gate derivation
    // -----------------------------------------------------------------------

    /**
     * 46 fleet handlers gate on `category` and 38 on `type`. A harness that assumed either
     * one would silently switch assertion B off for the other half.
     *
     * @return void
     */
    public function testTheGateKeyIsReadFromTheHandlerRatherThanAssumed()
    {
        $case = new SptcCase('testHandlerActsOnAServiceTypeItOwns');

        $activate = $case->gateForTest('getActivate');
        $this->assertSame('category', $activate['key'], 'the `==` form gates on category here');
        $this->assertSame(['SPTC_ONE'], $activate['defines']);

        $deactivate = $case->gateForTest('getDeactivate');
        $this->assertSame('type', $deactivate['key'], 'the `in_array` form gates on type here');
        $this->assertSame(['SPTC_TWO', 'SPTC_THREE'], $deactivate['defines']);
    }

    /**
     * `quickservers-module::getQueue()` has its whole gate commented out directly above a
     * live `stopPropagation()`. A regex census of this fleet read it as gated and missed it.
     *
     * @return void
     */
    public function testACommentedOutGateIsNotMistakenForARealOne()
    {
        SptcCase::$target = SptcUngatedPlugin::class;
        $gate = (new SptcCase('testHandlerActsOnAServiceTypeItOwns'))->gateForTest('getQueue');

        $this->assertSame([], $gate['defines'], 'a commented-out gate must not count as a gate');
        $this->assertNull($gate['key']);
        $this->assertTrue($gate['stops'], 'the stopPropagation() below the comment is still live');
    }

    /**
     * A comment sitting *between* the gate's tokens.
     *
     * This is the case the comment filter actually exists for, and it took a surviving
     * mutant to establish that. Leaving comments in the stream does **not** make the
     * commented-out gate above read as real — PHP hands back a whole `//` comment as one
     * opaque token, so that case was never at risk — but it does shift every positional
     * offset in `analyse()`, and a gate missed that way makes assertion B pass vacuously.
     * The silent direction, in other words.
     *
     * @return void
     */
    public function testACommentBetweenTheGatesTokensDoesNotHideTheGate()
    {
        $gate = (new SptcCase('testHandlerActsOnAServiceTypeItOwns'))->gateForTest('getReactivate');

        $this->assertSame('category', $gate['key'], 'an interstitial comment must not hide the gate');
        $this->assertSame(['SPTC_ONE'], $gate['defines']);
    }

    /**
     * No fleet handler gates on two keys, but "whichever the scanner saw last" is the kind of
     * behaviour that changes silently when an unrelated line is edited.
     *
     * @return void
     */
    public function testWhenAHandlerHasTwoGatesTheFirstOneWinsDeterministically()
    {
        $gate = (new SptcCase('testHandlerActsOnAServiceTypeItOwns'))->gateForTest('getDeactivateIp');

        $this->assertSame('category', $gate['key'], 'the first gate in source order wins');
        $this->assertSame(['SPTC_FIRST'], $gate['defines'], 'and only its own defines are collected');
    }

    /**
     * If the scanner ever misses a gate, the handler must see a foreign value and go inert —
     * a visible assertion-A failure — rather than a matching one, which would make assertion
     * B pass while proving nothing.
     *
     * @return void
     */
    public function testBothGateKeysAreSeededForeignBeforeTheOwnedTypeIsWritten()
    {
        $seeded = ServiceHandlerProbe::seedArguments(
            ['key' => 'category', 'readKeys' => []],
            4242
        );

        $this->assertSame(4242, $seeded['category'], 'the derived key carries the owned id');
        $this->assertSame(
            ServiceHandlerProbe::FOREIGN_TYPE,
            $seeded['type'],
            'the other gate key stays foreign, so a scanner miss fails safe'
        );
    }

    /**
     * `GenericEvent::offsetGet()` throws for an argument that was never set, so a handler
     * reading `$event['field1']` blows up unless the seed covers it.
     *
     * @return void
     */
    public function testEveryEventKeyTheHandlerReadsIsSeeded()
    {
        $gate = ['key' => 'category', 'readKeys' => ['category', 'field1', 'newip']];
        $seeded = ServiceHandlerProbe::seedArguments($gate, 1);

        $this->assertArrayHasKey('field1', $seeded);
        $this->assertArrayHasKey('newip', $seeded);
    }

    /**
     * A repo may declare its owned types, but it may not move the gate key: doing so is how
     * assertion B would be silently disabled, which is the failure the derivation exists to
     * prevent.
     *
     * @return void
     */
    public function testADeclarationChangesTheOwnedTypesButNotTheScannedGateKey()
    {
        SptcCase::$types = ['getActivate' => ['SPTC_DECLARED']];
        $gate = (new SptcCase('testHandlerActsOnAServiceTypeItOwns'))->gateForTest('getActivate');

        $this->assertSame(['SPTC_DECLARED'], $gate['defines']);
        $this->assertSame('category', $gate['key'], 'the key still comes from the source');
        $this->assertStringContainsString('declared by the repo', $gate['source']);
    }

    /**
     * A flat list applies to every handler; a per-handler map that omits one leaves that one
     * derived. Unambiguous because a service-define name is never a handler name.
     *
     * @return void
     */
    public function testAPerHandlerMapLeavesUnlistedHandlersDerived()
    {
        SptcCase::$types = ['getActivate' => ['SPTC_DECLARED']];
        $case = new SptcCase('testHandlerActsOnAServiceTypeItOwns');

        $this->assertSame(['SPTC_TWO', 'SPTC_THREE'], $case->gateForTest('getDeactivate')['defines']);
        $this->assertStringContainsString('derived from source', $case->gateForTest('getDeactivate')['source']);
    }

    /**
     * @return void
     */
    public function testAFlatListAppliesToEveryHandler()
    {
        SptcCase::$types = ['SPTC_EVERYWHERE'];
        $case = new SptcCase('testHandlerActsOnAServiceTypeItOwns');

        $this->assertSame(['SPTC_EVERYWHERE'], $case->gateForTest('getActivate')['defines']);
        $this->assertSame(['SPTC_EVERYWHERE'], $case->gateForTest('getDeactivate')['defines']);
    }

    /**
     * Declaring is an escape hatch — a narrow list makes assertion A pass on a type the repo
     * chose and stops B ever looking at the rest — so gate G2 requires it to be logged.
     *
     * @return void
     */
    public function testDeclaringOwnedTypesIsRecordedInTheHatchLedger()
    {
        SptcCase::$types = ['getActivate' => ['SPTC_DECLARED']];
        (new SptcCase('testHandlerActsOnAServiceTypeItOwns'))->gateForTest('getActivate');

        $ledger = ServicePluginTestCase::serviceOverrideLedger();
        $this->assertCount(1, $ledger);
        $this->assertSame('handledTypes', $ledger[0]['override']);
        $this->assertSame('getActivate', $ledger[0]['handler']);
        $this->assertSame(['SPTC_DECLARED'], $ledger[0]['declared']);
    }

    /**
     * @return void
     */
    public function testDerivingOwnedTypesIsNotRecordedAsAHatch()
    {
        (new SptcCase('testHandlerActsOnAServiceTypeItOwns'))->gateForTest('getActivate');

        $this->assertSame([], ServicePluginTestCase::serviceOverrideLedger());
    }

    // -----------------------------------------------------------------------
    // 2. Assertion A
    // -----------------------------------------------------------------------

    /**
     * @return void
     */
    public function testAHandlerThatActsOnATypeItOwnsPasses()
    {
        $this->assertSame([], $this->acts('getActivate'));
    }

    /**
     * The seven `*-vps::getDeactivate()` handlers act and deliberately do not stop. The phase
     * plan's `isPropagationStopped() === true` clause would fail all seven, and they are
     * right and the clause is wrong.
     *
     * @return void
     */
    public function testAHandlerThatActsWithoutStoppingPropagationStillPasses()
    {
        $findings = $this->acts('getDeactivate');

        $this->assertSame([], $findings, $this->messages($findings));
    }

    /**
     * A handler whose gate matches and which then does nothing is dead code on a lifecycle
     * hook: the service it exists to provision silently never gets provisioned.
     *
     * @return void
     */
    public function testAHandlerThatMatchesItsOwnTypeAndDoesNothingFails()
    {
        $findings = $this->acts('getChangeIp');

        $this->assertCount(1, $findings);
        $this->assertTrue($findings[0]->isFailure(), $this->messages($findings));
        $this->assertStringContainsString('changed nothing', $findings[0]->message());
    }

    /**
     * 17 of the fleet's 84 gated handlers die on a symbol the harness cannot provide. Calling
     * those a pass would claim a check that never ran.
     *
     * @return void
     */
    public function testAThrowNamingAMissingSymbolIsSkippedRatherThanPassedOrFailed()
    {
        $findings = $this->acts('getTerminate');

        $this->assertCount(1, $findings);
        $this->assertTrue($findings[0]->isSkipped(), $this->messages($findings));
        $this->assertArrayHasKey('blockedBy', $findings[0]->context(), 'a skip must say what blocked it');
    }

    /**
     * The one that matters: `xen-vps::getDeactivate()` dereferences `$serviceClass` on the
     * line before it is assigned. Bucketed with the seventeen missing-symbol skips, that live
     * production crash would never have been reported.
     *
     * @return void
     */
    public function testAThrowThatIsTheHandlersOwnLogicFailsRatherThanSkipping()
    {
        $findings = $this->acts('getQueue');

        $this->assertCount(1, $findings);
        $this->assertTrue($findings[0]->isFailure(), $this->messages($findings));
        $this->assertStringContainsString('not a missing dependency', $findings[0]->message());
    }

    /**
     * The discriminator must key on the engine raising `Error`, not on the words in the
     * message: a plugin's own `RuntimeException('customer not found')` is a defect report,
     * not a missing class.
     *
     * @return void
     */
    public function testAPluginsOwnExceptionIsNeverMistakenForAMissingSymbol()
    {
        $this->assertFalse(ServiceHandlerProbe::isUnresolvableDependency(
            new \RuntimeException('customer not found')
        ));
        $this->assertFalse(ServiceHandlerProbe::isUnresolvableDependency(
            new \Error('Call to a member function getId() on null')
        ));
        $this->assertTrue(ServiceHandlerProbe::isUnresolvableDependency(
            new \Error('Class "Detain\Foo" not found')
        ));
        $this->assertTrue(ServiceHandlerProbe::isUnresolvableDependency(
            new \Error('Call to undefined function Detain\activate_cpanel()')
        ));
        // A plugin that catches an Error and rethrows it as its own exception is describing
        // its own domain. Keying on the message alone would skip it; keying on the engine
        // having raised \Error is what keeps that a failure.
        $this->assertFalse(
            ServiceHandlerProbe::isUnresolvableDependency(
                new \RuntimeException('Call to undefined function Detain\activate_cpanel()')
            ),
            'only the engine raises \\Error for an unresolved name; a rethrow is the plugin talking'
        );
    }

    /**
     * @return void
     */
    public function testAHandlerThePluginDoesNotDeclareIsNotApplicableRatherThanSkipped()
    {
        // SptcGatedPlugin declares all seven, so the absent case needs a different fixture.
        SptcCase::$target = SptcUngatedPlugin::class;
        $findings = $this->acts('getChangeIp');

        $this->assertCount(1, $findings);
        $this->assertTrue($findings[0]->isNotApplicable(), $this->messages($findings));
    }

    /**
     * An ungated handler owns no type, so there is nothing to drive assertion A with. That is
     * "nothing of this kind here", not "could not run".
     *
     * @return void
     */
    public function testAnUngatedHandlerIsNotApplicableToAssertionA()
    {
        SptcCase::$target = SptcUngatedPlugin::class;
        $findings = $this->acts('getDeactivate');

        $this->assertCount(1, $findings);
        $this->assertTrue($findings[0]->isNotApplicable(), $this->messages($findings));
    }

    /**
     * Driving a handler with a type it owns runs the real lifecycle action, and the fakes are
     * not a sandbox — `zonemta-mail::getDeactivate()` opens a MongoDB connection and calls
     * `deleteOne()`. A repo must be able to decline, and declining must never read as a pass.
     *
     * @return void
     */
    public function testARepoCanDeclineToExecuteOwnedTypesAndGetsASkipNotAPass()
    {
        SptcCase::$exercise = false;
        $findings = $this->acts('getActivate');

        $this->assertCount(1, $findings);
        $this->assertTrue($findings[0]->isSkipped(), $this->messages($findings));
        $this->assertSame('exercisesOwnedTypes()', $findings[0]->context()['blockedBy']);
    }

    /**
     * Turning assertion A off must not turn assertion B off: a handler that returns at its
     * gate touches nothing, so B stays safe to run everywhere.
     *
     * @return void
     */
    public function testDecliningToExecuteOwnedTypesLeavesAssertionBRunning()
    {
        SptcCase::$exercise = false;

        $this->assertSame([], $this->inert('getActivate'), 'assertion B still ran and passed');
    }

    /**
     * A plugin that references nothing but its own harness-stubbed names is clear to run.
     *
     * @return void
     */
    public function testAPluginWithNoRealConfigurationIsClearToRunAssertionA()
    {
        $subject = (new SptcCase('t'))->subjectForTest();
        $this->assertSame([], ServiceHandlerProbe::unownedConstants($subject));
    }

    /**
     * The guard exists to stop a run inside a configured MyAdmin checkout turning an
     * assertion into a live delete — `zonemta-mail::getDeactivate()` really does call
     * `deleteOne()`. It must fire on a `define()`d application constant...
     *
     * ...and must **not** fire on `PHP_EOL`. The first revision compared against `defined()`
     * alone and skipped assertion A for five entire packages because their source mentioned
     * `PHP_EOL`, `MYSQL_ASSOC` or `SOAP_1_2`. Both directions are asserted here, off one
     * fixture that names one of each, because a guard tested in only the positive direction
     * is exactly how that regression got in.
     *
     * @return void
     */
    public function testRealConfigurationBlocksAssertionAButEngineConstantsDoNot()
    {
        if (!defined('SPTC_REAL_CONFIG')) {
            // Irreversible, hence the guard: PHP cannot undefine a constant, so this name is
            // deliberately unique to this fixture and used nowhere else.
            define('SPTC_REAL_CONFIG', 'mongodb://real-host:27017');
        }

        SptcCase::$target = SptcConfiguredPlugin::class;
        $subject = (new SptcCase('t'))->subjectForTest();
        $unowned = ServiceHandlerProbe::unownedConstants($subject);

        $this->assertContains(
            'SPTC_REAL_CONFIG',
            $unowned,
            'a define()d application constant the harness did not set must block assertion A'
        );
        $this->assertNotContains(
            'PHP_EOL',
            $unowned,
            'PHP_EOL is the engine\'s, not configuration; reporting it skipped five whole packages once'
        );
    }

    /**
     * With real configuration present, assertion A must decline to execute the handler and
     * say why — a skip, never a pass.
     *
     * @return void
     */
    public function testAssertionASkipsRatherThanExecutingAgainstRealConfiguration()
    {
        if (!defined('SPTC_REAL_CONFIG')) {
            define('SPTC_REAL_CONFIG', 'mongodb://real-host:27017');
        }

        SptcCase::$target = SptcConfiguredPlugin::class;
        $findings = $this->acts('getActivate');

        $this->assertCount(1, $findings);
        $this->assertTrue($findings[0]->isSkipped(), $this->messages($findings));
        $this->assertSame('real configuration constants', $findings[0]->context()['blockedBy']);
    }

    /**
     * Assertion B keeps running against a configured host, because a handler that returns at
     * its gate reaches nothing. Losing that would disable the regression guard on exactly the
     * machines most likely to be running it.
     *
     * @return void
     */
    public function testAssertionBStillRunsAgainstRealConfiguration()
    {
        if (!defined('SPTC_REAL_CONFIG')) {
            define('SPTC_REAL_CONFIG', 'mongodb://real-host:27017');
        }

        SptcCase::$target = SptcConfiguredPlugin::class;
        $findings = $this->inert('getActivate');

        $this->assertSame([], $findings, $this->messages($findings));
    }

    // -----------------------------------------------------------------------
    // 3. Assertion B
    // -----------------------------------------------------------------------

    /**
     * The whole fleet passes this today — all 84 gated handlers were run against a foreign
     * type and all 84 were completely inert. This pins that the check is actually wired up
     * and not passing because nothing runs.
     *
     * @return void
     */
    public function testAWellGatedHandlerIsInertForAForeignType()
    {
        $findings = $this->inert('getActivate');

        $this->assertSame([], $findings, $this->messages($findings));
    }

    /**
     * Leak shape 1, the dangerous one: `stopPropagation()` outside the gate silences every
     * co-listener on the hook key for every service type.
     *
     * @return void
     */
    public function testStoppingPropagationForAForeignTypeFails()
    {
        SptcCase::$target = SptcLeakyPlugin::class;
        $findings = $this->inert('getActivate');

        $this->assertCount(1, $findings);
        $this->assertTrue($findings[0]->isFailure(), $this->messages($findings));
        $this->assertStringContainsString('stopPropagation()', $findings[0]->message());
        $this->assertTrue($findings[0]->context()['stoppedPropagation']);
    }

    /**
     * Leak shape 2: no propagation stopped, but a history row written. Invisible unless the
     * effects sweep covers every recorder rather than just the log.
     *
     * @return void
     */
    public function testActingOnAForeignTypeFailsEvenWhenPropagationIsNotStopped()
    {
        SptcCase::$target = SptcLeakyPlugin::class;
        $findings = $this->inert('getDeactivate');

        $this->assertCount(1, $findings);
        $this->assertTrue($findings[0]->isFailure(), $this->messages($findings));
        $this->assertStringContainsString('history', $findings[0]->message());
    }

    /**
     * Leak shape 3: nothing recorded and nothing stopped, but the handler reached code that
     * throws — which it could only do by executing outside its own gate.
     *
     * @return void
     */
    public function testThrowingOnAForeignTypeFails()
    {
        SptcCase::$target = SptcLeakyPlugin::class;
        $findings = $this->inert('getTerminate');

        $this->assertCount(1, $findings);
        $this->assertTrue($findings[0]->isFailure(), $this->messages($findings));
        $this->assertStringContainsString('outside its own gate', $findings[0]->message());
    }

    /**
     * "No side effects" checked against the log alone silently passes a handler that wrote a
     * history row, issued a query or queued an insert. The sweep has to name every recorder
     * the harness owns, and this pins the list against {@see Harness} so a fake added later
     * cannot be quietly left out of it.
     *
     * @return void
     */
    public function testTheEffectsSweepCoversEveryRecorderTheHarnessExposes()
    {
        $missing = [];
        foreach (ServiceHandlerProbe::RECORDERS as $name) {
            if (!method_exists(Harness::class, $name)) {
                $missing[] = $name;
            }
        }
        $this->assertSame([], $missing, 'ServiceHandlerProbe::RECORDERS names a non-existent Harness accessor');

        // And the other direction: every Harness accessor returning a recording fake is swept.
        $unswept = [];
        foreach (get_class_methods(Harness::class) as $method) {
            if (!in_array($method, ['settings', 'menu', 'db', 'history', 'session', 'accounts', 'variables', 'smarty', 'table', 'events', 'output'], true)) {
                continue;
            }
            if (!in_array($method, ServiceHandlerProbe::RECORDERS, true)) {
                $unswept[] = $method;
            }
        }
        $this->assertSame([], $unswept, 'a Harness fake exists that the no-side-effects sweep never looks at');
    }

    /**
     * The two channels that are not fakes at all — the log sink and the two global-function
     * recorders on Harness — are swept too. Easy to forget precisely because they have no
     * `calls()`.
     *
     * @return void
     */
    public function testTheSweepAlsoCoversTheChannelsThatAreNotFakes()
    {
        $service = new \MyAdmin\Plugins\Testing\Fakes\FakeServiceClass();
        $event = new ServiceLifecycleEvent($service, []);

        Harness::reset();
        \MyAdmin\Plugins\Testing\Log::add('m', 'info', 'something happened');
        $this->assertNotSame([], ServiceHandlerProbe::observedEffects($service, $event, [], ''));

        Harness::reset();
        Harness::recordInsertQuery('t', ['a' => 1], 'insert into t');
        $this->assertNotSame([], ServiceHandlerProbe::observedEffects($service, $event, [], ''));

        Harness::reset();
        Harness::recordDialog('title', 'text', true, '');
        $this->assertNotSame([], ServiceHandlerProbe::observedEffects($service, $event, [], ''));

        Harness::reset();
        $this->assertNotSame([], ServiceHandlerProbe::observedEffects($service, $event, [], 'printed bytes'));

        Harness::reset();
        $this->assertSame([], ServiceHandlerProbe::observedEffects($service, $event, [], ''), 'a clean slate is clean');
    }

    /**
     * Reaching into the subject is often the only observable thing a handler does —
     * `zonemta-mail::getReactivate()` calls `getUsername()` twice and nothing else. Untracked,
     * that handler reads as inert and both assertions report the opposite of the truth.
     *
     * @return void
     */
    public function testTouchingTheServiceSubjectCountsAsAnEffect()
    {
        $service = new \MyAdmin\Plugins\Testing\Fakes\FakeServiceClass();
        $event = new ServiceLifecycleEvent($service, []);
        Harness::reset();

        $service->getUsername();

        $effects = ServiceHandlerProbe::observedEffects($service, $event, [], '');
        $this->assertNotSame([], $effects);
        $this->assertStringContainsString('service subject', implode(',', $effects));
    }

    /**
     * A handler that only writes `$event['success']` has still acted, and the dispatcher
     * hands that value back to the caller.
     *
     * @return void
     */
    public function testMutatingAnEventArgumentCountsAsAnEffect()
    {
        $service = new \MyAdmin\Plugins\Testing\Fakes\FakeServiceClass();
        $event = new ServiceLifecycleEvent($service, ['success' => false]);
        Harness::reset();

        $event['success'] = true;

        $effects = ServiceHandlerProbe::observedEffects($service, $event, ['success' => false], '');
        $this->assertStringContainsString('success', implode(',', $effects));
    }

    /**
     * @return void
     */
    public function testAnUngatedHandlerThatStopsPropagationIsDisclosedAsANotice()
    {
        SptcCase::$target = SptcUngatedPlugin::class;
        $findings = $this->inert('getActivate');

        $this->assertCount(1, $findings);
        $this->assertTrue($findings[0]->isNotice(), $this->messages($findings));
        $this->assertTrue($findings[0]->context()['ungatedAndStops']);
    }

    /**
     * Three fleet handlers are ungated and stop, and all three are currently alone on their
     * hook key. Reporting them as failures would be manufacturing a defect that does not
     * exist; the notice keeps the hazard on the record without doing that.
     *
     * @return void
     */
    public function testAnUngatedHandlerThatStopsIsNotReportedAsAFailure()
    {
        SptcCase::$target = SptcUngatedPlugin::class;
        $findings = $this->inert('getActivate');

        $this->assertFalse($findings[0]->isFailure());
    }

    /**
     * @return void
     */
    public function testAnUngatedHandlerThatNeverStopsIsNotApplicable()
    {
        SptcCase::$target = SptcUngatedPlugin::class;
        $findings = $this->inert('getDeactivate');

        $this->assertCount(1, $findings);
        $this->assertTrue($findings[0]->isNotApplicable(), $this->messages($findings));
    }

    /**
     * A "foreign" type that the plugin actually owns would test the opposite of what the
     * assertion claims, and would fail loudly looking exactly like a real defect.
     *
     * @return void
     */
    public function testAForeignTypeThatCollidesWithAnOwnedTypeIsRefused()
    {
        SptcCase::$foreign = ['SPTC_ONE'];
        $findings = $this->inert('getActivate');

        $this->assertCount(1, $findings);
        $this->assertTrue($findings[0]->isSkipped(), $this->messages($findings));
        $this->assertStringContainsString('foreignTypes()', $findings[0]->message());
    }

    /**
     * Driving a handler with a real neighbour's id is the sharper version of the assertion,
     * because that is the collision production actually produces.
     *
     * @return void
     */
    public function testMoreThanOneForeignTypeCanBeSupplied()
    {
        SptcCase::$target = SptcLeakyPlugin::class;
        SptcCase::$foreign = [ServiceHandlerProbe::FOREIGN_TYPE, 'SPTC_SOMEONE_ELSE'];
        $findings = $this->inert('getActivate');

        $this->assertCount(2, $findings, 'one finding per foreign type, not one for the first');
    }

    // -----------------------------------------------------------------------
    // 4. Plumbing
    // -----------------------------------------------------------------------

    /**
     * A handler hinting an event class this environment cannot load must be skipped, never
     * handed the stand-in: running it against a shape it did not ask for would report a
     * result that means nothing.
     *
     * @return void
     */
    public function testAnUnloadableEventClassIsSkippedRatherThanSubstituted()
    {
        SptcCase::$target = SptcTypedEventPlugin::class;
        $findings = $this->inert('getActivate');

        $this->assertCount(1, $findings);
        $this->assertTrue($findings[0]->isSkipped(), $this->messages($findings));
        $this->assertStringContainsString('not loadable', $findings[0]->message());
    }

    /**
     * The stand-in must copy `GenericEvent`'s semantics, including the one that catches
     * people out. A lenient double that returned null for an unset argument would let a
     * handler run further under test than it can in production.
     *
     * @return void
     */
    public function testTheEventStandInThrowsForAnUnsetArgumentJustAsGenericEventDoes()
    {
        $event = new ServiceLifecycleEvent(null, ['set' => 1]);

        $this->assertSame(1, $event['set']);
        $this->expectException(\InvalidArgumentException::class);
        $unused = $event['never_set'];
    }

    /**
     * @return void
     */
    public function testTheEventStandInCarriesPropagationState()
    {
        $event = new ServiceLifecycleEvent(null, []);

        $this->assertFalse($event->isPropagationStopped());
        $event->stopPropagation();
        $this->assertTrue($event->isPropagationStopped());
    }

    /**
     * The stand-in is not a Symfony class and must never be declared under Symfony's name —
     * the D2 failure mode a test double occupying a production class name.
     *
     * @return void
     */
    public function testTheEventStandInDoesNotOccupyASymfonyClassName()
    {
        $this->assertStringStartsWith('MyAdmin\Plugins\Testing\\', ServiceLifecycleEvent::class);
    }

    /**
     * Two inspectors under `Contract/` would become two new fleet-matrix columns and change
     * the 1278-cell census gate G2 is reviewed against. Nothing in this phase may live there.
     *
     * @return void
     */
    public function testPhaseThreeAddsNothingToTheInspectorCatalogue()
    {
        foreach ([ServicePluginTestCase::class, ServiceHandlerProbe::class, ServiceLifecycleEvent::class] as $class) {
            $file = (new \ReflectionClass($class))->getFileName();
            $this->assertStringNotContainsString(
                DIRECTORY_SEPARATOR . 'Contract' . DIRECTORY_SEPARATOR,
                (string)$file,
                $class . ' is inside Contract/, where InspectorRegistry would discover it'
            );
        }
    }

    /**
     * Seven cases per assertion, always, so a handler the plugin does not declare is reported
     * as not-applicable rather than being silently absent from the run.
     *
     * @return void
     */
    public function testTheProviderYieldsOneNamedCasePerLifecycleHandler()
    {
        $cases = ServicePluginTestCase::serviceLifecycleHandlers();

        $this->assertCount(count(ServiceHandlerProbe::HANDLERS), $cases);
        $this->assertArrayHasKey('getDeactivateIp()', $cases, 'the seventh handler kind the plan omits');
        foreach ($cases as $name => $arguments) {
            $this->assertCount(1, $arguments);
            $this->assertContains($arguments[0], ServiceHandlerProbe::HANDLERS, $name);
        }
    }

    /**
     * @return void
     */
    public function testANonStaticHandlerIsAFailureBecauseTheDispatcherCanNeverInvokeIt()
    {
        SptcCase::$target = SptcNonStaticHandlerPlugin::class;
        $findings = $this->inert('getActivate');

        $this->assertCount(1, $findings);
        $this->assertTrue($findings[0]->isFailure(), $this->messages($findings));
        $this->assertStringContainsString('public static', $findings[0]->message());
    }

    /**
     * `inspectLifecycle()` is the fleet-sweep entry point and must never let a probe bug
     * abort the sweep or be reported as a plugin defect.
     *
     * @return void
     */
    public function testTheFleetEntryPointReturnsBothAssertionsForEveryHandler()
    {
        $rows = (new SptcCase('t'))->inspectLifecycle();

        $this->assertSame(
            [ServicePluginTestCase::ASSERTION_ACTS, ServicePluginTestCase::ASSERTION_INERT],
            array_keys($rows)
        );
        foreach ($rows as $id => $handlers) {
            $this->assertSame(ServiceHandlerProbe::HANDLERS, array_keys($handlers), $id);
        }
    }

    /**
     * The precedence must match {@see \MyAdmin\Plugins\Testing\PluginContractTestCase}'s, so
     * a reader who has learned one has learned both.
     *
     * @return void
     */
    public function testAFailureBesideASkipStillFails()
    {
        $case = new SptcCase('t');

        try {
            $case->reportForTest('S-1', 'getActivate', [
                Finding::skipped('S-1', 'could not look'),
                Finding::failure('S-1', 'a real defect'),
            ]);
            $this->fail('expected the run to fail');
        } catch (\PHPUnit\Framework\AssertionFailedError $e) {
            // Asserting the message, not merely that something failed. Disabling the fail()
            // branch still trips the assertSame() below it, so a test that only checked
            // "it failed" left the reporting path — the part a maintainer actually reads —
            // completely unpinned. A surviving mutant is how that was found.
            $this->assertStringContainsString('a real defect', $e->getMessage());
            $this->assertStringContainsString('getActivate()', $e->getMessage());
            $this->assertStringContainsString('acts on a service type it owns', $e->getMessage());
        }
    }

    /**
     * @return void
     */
    public function testARunThatIsAllNotApplicableSaysSoRatherThanClaimingItCouldNotRun()
    {
        $case = new SptcCase('t');

        try {
            $case->reportForTest('S-2', 'getActivate', [Finding::notApplicable('S-2', 'nothing of this kind')]);
            $this->fail('expected the run to be skipped');
        } catch (\PHPUnit\Framework\SkippedTestError $e) {
            $this->assertStringContainsString('is not applicable', $e->getMessage());
            $this->assertStringNotContainsString('could not run', $e->getMessage());
        }
    }

    /**
     * @return void
     */
    public function testANoticeMakesTheRunIncompleteRatherThanGreenOrFailed()
    {
        $case = new SptcCase('t');

        try {
            $case->reportForTest('S-2', 'getActivate', [Finding::notice('S-2', 'worth saying')]);
            $this->fail('expected the run to be incomplete');
        } catch (\PHPUnit\Framework\IncompleteTestError $e) {
            $this->assertStringContainsString('worth saying', $e->getMessage());
        }
    }
}

/**
 * A handler the dispatcher could never call, declared here rather than in `Fixtures/`
 * because it is a plugin class and not a `TestCase`, so PHPUnit will not collect it.
 */
class SptcNonStaticHandlerPlugin
{
    /** @var string */
    public static $name = 'Sptc Non Static';

    /** @var string */
    public static $description = 'handler declared non-static';

    /** @var string */
    public static $help = 'nothing to configure';

    /** @var string */
    public static $type = 'service';

    /** @var string */
    public static $module = 'sptcnonstatic';

    /**
     * @param object $event
     * @return void
     */
    public function getActivate($event)
    {
    }
}
