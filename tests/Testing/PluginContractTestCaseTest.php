<?php
/**
 * @author Joe Huss <detain@interserver.net>
 * @copyright 2026
 * @package MyAdmin
 * @category Testing
 */

namespace Tests\MyAdmin\Plugins\Testing;

use MyAdmin\Plugins\Testing\Contract\Finding;
use MyAdmin\Plugins\Testing\Contract\InspectorRegistry;
use MyAdmin\Plugins\Testing\Contract\PluginInspector;
use MyAdmin\Plugins\Testing\Contract\PluginSubject;
use MyAdmin\Plugins\Testing\PluginContractTestCase;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\TestResult;
use Tests\MyAdmin\Plugins\Testing\Fixtures\PctcCaseEveryHatch;
use Tests\MyAdmin\Plugins\Testing\Fixtures\PctcCaseMissingPlugin;
use Tests\MyAdmin\Plugins\Testing\Fixtures\PctcCaseOptedOutOfRequirementRoot;
use Tests\MyAdmin\Plugins\Testing\Fixtures\PctcCasePlain;
use Tests\MyAdmin\Plugins\Testing\Fixtures\PctcCasePrimedPlugin;
use Tests\MyAdmin\Plugins\Testing\Fixtures\PctcFixturePlugin;

/**
 * Pins the D7 split and the escape-hatch disclosure of the base test case plugin repos
 * subclass.
 *
 * ---------------------------------------------------------------------------------
 * HOW AN ABSTRACT TESTCASE IS TESTED
 * ---------------------------------------------------------------------------------
 * Every behaviour that has an observable outcome is driven the way PHPUnit drives it: a
 * concrete subclass is constructed with the inspector class as its data-provider row, run
 * against a private {@see TestResult}, and the *result* is asserted — failure vs skip vs
 * pass, and the message the reader will actually see. That is a stronger statement than
 * calling the method and catching an exception, because it also pins which PHPUnit outcome
 * bucket each case lands in, and that bucket is what the fleet CI reports.
 *
 * Nothing here uses `ReflectionMethod::setAccessible()`. The two protected seams —
 * `contractSubject()` and `describeOverrides()` — are reached through public accessors on
 * {@see PctcCasePlain}, because a reflection-based test keeps passing after the method it
 * pokes at has stopped being called by anything, which is the failure mode this whole phase
 * exists to prevent.
 *
 * ---------------------------------------------------------------------------------
 * WHY THE FIXTURE INSPECTORS ARE NOT IN THE REGISTRY
 * ---------------------------------------------------------------------------------
 * `testPluginSatisfiesContractAssertion()` takes the inspector class as an argument, so a
 * fixture inspector can be handed to it directly. Registering one would mean writing a file
 * into `src/Testing/Contract/`, which {@see InspectorRegistry} globs — every consumer in
 * this working tree, including a concurrently running suite, would pick it up.
 *
 * @covers \MyAdmin\Plugins\Testing\PluginContractTestCase
 */
class PluginContractTestCaseTest extends TestCase
{
    /**
     * Runs one contract assertion the way PHPUnit would, in its own result.
     *
     * The `$dataName` is what PHPUnit prints after the method name, so it is set to the same
     * shape `contractAssertions()` produces.
     *
     * @param string $caseClass      concrete PluginContractTestCase subclass
     * @param string $inspectorClass inspector to hand the subclass
     * @return TestResult
     */
    private function runContractCase($caseClass, $inspectorClass)
    {
        $case = new $caseClass('testPluginSatisfiesContractAssertion', [$inspectorClass], 'fixture case');
        $result = new TestResult();
        $case->run($result);

        $this->assertSame(1, $result->count(), 'exactly one contract assertion should have run');

        return $result;
    }

    /**
     * @param TestResult $result
     * @return string
     */
    private function soleFailureMessage(TestResult $result)
    {
        $this->assertSame(1, $result->failureCount(), 'expected exactly one failure');
        $this->assertSame(0, $result->errorCount(), 'a contract verdict must never surface as an error');
        $failures = $result->failures();

        return $failures[0]->thrownException()->getMessage();
    }

    /**
     * @param TestResult $result
     * @return string
     */
    private function soleSkipMessage(TestResult $result)
    {
        $this->assertSame(1, $result->skippedCount(), 'expected exactly one skip');
        $this->assertSame(0, $result->failureCount());
        $this->assertSame(0, $result->errorCount());
        $skipped = $result->skipped();

        return $skipped[0]->thrownException()->getMessage();
    }

    // -----------------------------------------------------------------------
    // The data provider
    // -----------------------------------------------------------------------

    /**
     * One case per catalogue assertion, with the id in the key so it lands in the test name.
     * Without that, a plugin repo's CI reports seventeen indistinguishable rows called
     * `testPluginSatisfiesContractAssertion with data set #4`.
     *
     * @return void
     */
    public function testProviderYieldsOneNamedCasePerInspector()
    {
        $cases = PluginContractTestCase::contractAssertions();
        $this->assertCount(count(InspectorRegistry::classes()), $cases);

        $ids = [];
        foreach ($cases as $key => $arguments) {
            $this->assertIsArray($arguments);
            $this->assertCount(1, $arguments, 'the provider must yield exactly the inspector class');
            $this->assertTrue(class_exists($arguments[0]), $arguments[0].' must be loadable');

            $inspector = new $arguments[0]();
            $this->assertStringStartsWith($inspector->id().' ', $key, 'the id must lead the test name');
            $this->assertStringContainsString($inspector->title(), $key);
            $ids[] = $inspector->id();
        }

        usort($ids, [InspectorRegistry::class, 'compareIds']);
        $this->assertSame(InspectorRegistry::ids(), $ids, 'the provider must cover the whole catalogue');
    }

    /**
     * The provider hands over class names, never instances: it runs during collection, and a
     * constructor that met a broken plugin there would abort collection with no attribution.
     *
     * @return void
     */
    public function testProviderYieldsClassNamesRatherThanConstructedInspectors()
    {
        foreach (PluginContractTestCase::contractAssertions() as $arguments) {
            $this->assertIsString($arguments[0]);
        }
    }

    // -----------------------------------------------------------------------
    // The NOT_SET sentinel
    // -----------------------------------------------------------------------

    /**
     * A subclass that overrides nothing must look, to the inspectors, exactly like a repo
     * that made no choices — not like one that chose the sentinel string.
     *
     * @return void
     */
    public function testSubclassThatOverridesNothingDeclaresNoOverrides()
    {
        $subject = (new PctcCasePlain('testPluginSatisfiesContractAssertion'))->contractSubjectForTest();

        $this->assertSame([], $subject->overridesInUse());
        $this->assertSame(PctcFixturePlugin::class, $subject->pluginClass());
        $this->assertNull($subject->expectedType());
        $this->assertNull($subject->requirementRoot());
        $this->assertSame([], $subject->serviceDefines());
        $this->assertSame([], $subject->constantOverrides());
        $this->assertFalse($subject->skipsRequirementCheck(), 'never overriding is not an opt-out');
    }

    /**
     * The sentinel's whole purpose, stated as one assertion pair: two subjects with the same
     * `requirementRoot()` value and opposite meanings.
     *
     * @return void
     */
    public function testExplicitNullRequirementRootIsAnOptOutRatherThanADefault()
    {
        $plain = (new PctcCasePlain('testPluginSatisfiesContractAssertion'))->contractSubjectForTest();
        $optedOut = (new PctcCaseOptedOutOfRequirementRoot('testPluginSatisfiesContractAssertion'))
            ->contractSubjectForTest();

        $this->assertNull($plain->requirementRoot());
        $this->assertNull($optedOut->requirementRoot());
        $this->assertSame(
            $plain->requirementRoot(),
            $optedOut->requirementRoot(),
            'the values must be identical, or this pair proves nothing'
        );

        $this->assertFalse($plain->skipsRequirementCheck());
        $this->assertTrue($optedOut->skipsRequirementCheck());

        $this->assertSame([], $plain->overridesInUse());
        $this->assertSame(['requirementRoot'], $optedOut->overridesInUse());
    }

    /**
     * @return void
     */
    public function testEveryOverriddenHookReachesTheSubjectWithItsDeclaredValue()
    {
        $subject = (new PctcCaseEveryHatch('testPluginSatisfiesContractAssertion'))->contractSubjectForTest();

        $this->assertSame(
            ['expectedType', 'requirementRoot', 'serviceDefines', 'constantOverrides'],
            $subject->overridesInUse()
        );
        $this->assertSame('module', $subject->expectedType());
        $this->assertSame('/srv/pctc-fixture', $subject->requirementRoot());
        $this->assertSame(['PCTC_FIXTURE_SERVICE' => 4242], $subject->serviceDefines());
        $this->assertSame(['PCTC_FIXTURE_BILLING' => 'prorate'], $subject->constantOverrides());
        $this->assertFalse($subject->skipsRequirementCheck(), 'a non-null root is not an opt-out');
    }

    /**
     * The sentinel must never be mistaken for a repo's answer.
     *
     * @return void
     */
    public function testSentinelValueNeverReachesTheSubject()
    {
        $subject = (new PctcCasePlain('testPluginSatisfiesContractAssertion'))->contractSubjectForTest();

        $this->assertNotSame(PluginContractTestCase::NOT_SET, $subject->expectedType());
        $this->assertNotSame(PluginContractTestCase::NOT_SET, $subject->requirementRoot());
        $this->assertNotContains(PluginContractTestCase::NOT_SET, $subject->serviceDefines());
        $this->assertNotContains(PluginContractTestCase::NOT_SET, $subject->constantOverrides());
    }

    // -----------------------------------------------------------------------
    // Escape-hatch disclosure (gate G2)
    // -----------------------------------------------------------------------

    /**
     * @return void
     */
    public function testOverrideDescriptionSaysSoWhenThereAreNone()
    {
        $this->assertSame(
            'No per-repo overrides are in effect.',
            (new PctcCasePlain('testPluginSatisfiesContractAssertion'))->describeOverridesForTest()
        );
    }

    /**
     * @return void
     */
    public function testOverrideDescriptionNamesEveryActiveHatch()
    {
        $this->assertSame(
            'Per-repo overrides in effect (review under gate G2): requirementRoot.',
            (new PctcCaseOptedOutOfRequirementRoot('testPluginSatisfiesContractAssertion'))
                ->describeOverridesForTest()
        );

        $this->assertSame(
            'Per-repo overrides in effect (review under gate G2): '
            .'expectedType, requirementRoot, serviceDefines, constantOverrides.',
            (new PctcCaseEveryHatch('testPluginSatisfiesContractAssertion'))->describeOverridesForTest()
        );
    }

    // -----------------------------------------------------------------------
    // D7: a returned failure is a plugin defect
    // -----------------------------------------------------------------------

    /**
     * @return void
     */
    public function testReturnedFailureFailsTheTestAndNamesThePluginAndTheAssertion()
    {
        $message = $this->soleFailureMessage(
            $this->runContractCase(PctcCasePlain::class, PctcInspectorReturnsFailure::class)
        );

        // Anchored at the start, not merely "contains": the id also appears further down
        // inside the finding's own `describe()`, so a `contains` check keeps passing after
        // the headline that names the assertion has been dropped.
        $this->assertStringStartsWith('F-2 — reported plugin defect', $message);
        $this->assertStringContainsString(PctcFixturePlugin::class, $message);
        $this->assertStringContainsString('violates the plugin contract', $message);
        $this->assertStringContainsString('the fixture plugin did the wrong thing', $message);
        $this->assertStringContainsString("offender='getSettings'", $message, 'finding context must survive');
    }

    /**
     * The half of D7 that is easy to lose: a plugin defect must not be dressed up as a
     * harness defect, or it lands on the wrong person's branch.
     *
     * @return void
     */
    public function testReturnedFailureIsNotLabelledAHarnessBug()
    {
        $message = $this->soleFailureMessage(
            $this->runContractCase(PctcCasePlain::class, PctcInspectorReturnsFailure::class)
        );

        $this->assertStringNotContainsString('HARNESS BUG', $message);
        $this->assertStringNotContainsString('H-bug', $message);
    }

    /**
     * @return void
     */
    public function testEveryReturnedFailureIsListedNotJustTheFirst()
    {
        $message = $this->soleFailureMessage(
            $this->runContractCase(PctcCasePlain::class, PctcInspectorReturnsTwoFailures::class)
        );

        $this->assertStringContainsString('first fixture violation', $message);
        $this->assertStringContainsString('second fixture violation', $message);
    }

    /**
     * @return void
     */
    public function testFailureMessageDisclosesTheOverridesItWasReachedUnder()
    {
        $none = $this->soleFailureMessage(
            $this->runContractCase(PctcCasePlain::class, PctcInspectorReturnsFailure::class)
        );
        $this->assertStringContainsString('No per-repo overrides are in effect.', $none);

        $hatched = $this->soleFailureMessage(
            $this->runContractCase(PctcCaseEveryHatch::class, PctcInspectorReturnsFailure::class)
        );
        $this->assertStringContainsString(
            'Per-repo overrides in effect (review under gate G2): '
            .'expectedType, requirementRoot, serviceDefines, constantOverrides.',
            $hatched
        );
    }

    // -----------------------------------------------------------------------
    // D7: a thrown exception is a harness defect
    // -----------------------------------------------------------------------

    /**
     * @return void
     */
    public function testThrownExceptionIsReportedAsAHarnessBugAndNotAsAnError()
    {
        $message = $this->soleFailureMessage(
            $this->runContractCase(PctcCasePlain::class, PctcInspectorThrows::class)
        );

        $this->assertStringContainsString('HARNESS BUG (H-bug)', $message);
        $this->assertStringContainsString('not a plugin defect', $message);
        $this->assertStringContainsString('F-3', $message, 'the inspector must be named');
        $this->assertStringContainsString('LogicException', $message, 'the exception class must be named');
        $this->assertStringContainsString('the fixture inspector exploded', $message);
        $this->assertStringContainsString('Fix the inspector, not '.PctcFixturePlugin::class, $message);
    }

    /**
     * @return void
     */
    public function testHarnessBugMessageAlsoDisclosesTheOverridesInEffect()
    {
        $message = $this->soleFailureMessage(
            $this->runContractCase(PctcCaseOptedOutOfRequirementRoot::class, PctcInspectorThrows::class)
        );

        $this->assertStringContainsString(
            'Per-repo overrides in effect (review under gate G2): requirementRoot.',
            $message
        );
    }

    /**
     * `Throwable`, not `Exception`: an inspector that dereferences null throws an `Error`,
     * and that is still a harness defect rather than a crashed run.
     *
     * @return void
     */
    public function testAnErrorIsCaughtToo()
    {
        $message = $this->soleFailureMessage(
            $this->runContractCase(PctcCasePlain::class, PctcInspectorThrowsAnError::class)
        );

        $this->assertStringContainsString('HARNESS BUG (H-bug)', $message);
        $this->assertStringContainsString('TypeError', $message);
    }

    // -----------------------------------------------------------------------
    // Skips are not passes
    // -----------------------------------------------------------------------

    /**
     * A check that could not run must never read as a check that passed, or the triage
     * matrix overstates its own coverage.
     *
     * @return void
     */
    public function testInspectorThatOnlySkipsMakesTheTestSkippedNotPassed()
    {
        $result = $this->runContractCase(PctcCasePlain::class, PctcInspectorOnlySkips::class);
        $message = $this->soleSkipMessage($result);

        $this->assertStringContainsString('F-4', $message);
        $this->assertStringContainsString('could not run against '.PctcFixturePlugin::class, $message);
        $this->assertStringContainsString('first fixture reason', $message);
        $this->assertStringContainsString('second fixture reason', $message);
    }

    /**
     * Failure outranks skip. An inspector that skipped one check and failed another has
     * found a defect, and a skip verdict would bury it.
     *
     * @return void
     */
    public function testASkipAlongsideAFailureStillFails()
    {
        $result = $this->runContractCase(PctcCasePlain::class, PctcInspectorSkipsAndFails::class);
        $message = $this->soleFailureMessage($result);

        $this->assertSame(0, $result->skippedCount());
        $this->assertStringContainsString('fixture violation beside a skip', $message);
        $this->assertStringNotContainsString('fixture reason beside a failure', $message);
    }

    /**
     * The boundary in `count($skips) === count($findings)`: a partial skip is a result, not
     * an absence of one.
     *
     * @return void
     */
    public function testASkipAlongsideANoticeDoesNotSkipTheTest()
    {
        $result = $this->runContractCase(PctcCasePlain::class, PctcInspectorSkipsAndNotices::class);

        $this->assertSame(0, $result->skippedCount(), 'a partly-runnable check did run');
        $this->assertSame(0, $result->failureCount());
        $this->assertTrue($result->wasSuccessful());
    }

    // -----------------------------------------------------------------------
    // Notices and the passing path
    // -----------------------------------------------------------------------

    /**
     * NOTICE exists so B-14's informational direction can be reported without failing a
     * build. If it ever failed one, the reporting would be removed rather than the notice.
     *
     * @return void
     */
    public function testNoticesDoNotFailTheTest()
    {
        $result = $this->runContractCase(PctcCasePlain::class, PctcInspectorOnlyNotices::class);

        $this->assertSame(0, $result->failureCount());
        $this->assertSame(0, $result->skippedCount());
        $this->assertSame(0, $result->errorCount());
        $this->assertTrue($result->wasSuccessful());
    }

    /**
     * A passing contract assertion has to *assert* something. Plugin repos run with
     * `failOnRisky="true"`, so a pass path that recorded no assertion would turn all
     * seventeen green cases red fleet-wide.
     *
     * @return void
     */
    public function testAPassingAssertionIsNotRisky()
    {
        foreach ([PctcInspectorPasses::class, PctcInspectorOnlyNotices::class] as $inspector) {
            $result = $this->runContractCase(PctcCasePlain::class, $inspector);

            $this->assertTrue($result->wasSuccessful(), $inspector.' should pass');
            $this->assertSame(0, $result->riskyCount(), $inspector.' must record an assertion');
        }
    }

    /**
     * End-to-end through a real inspector rather than a fixture: A-1 cannot run against a
     * class that does not exist, and reports that as a skip. Proves the wiring holds for
     * something that was not written to make this test pass.
     *
     * @return void
     */
    public function testARealInspectorReachesTheSameSkipVerdict()
    {
        $result = $this->runContractCase(
            PctcCaseMissingPlugin::class,
            \MyAdmin\Plugins\Testing\Contract\TierA1ClassIsConstructible::class
        );

        $this->assertStringContainsString('A-1 could not run against', $this->soleSkipMessage($result));
    }

    // -----------------------------------------------------------------------
    // Constant priming
    // -----------------------------------------------------------------------

    /**
     * Pins that an inspector runs against a *primed* process.
     *
     * Ten fleet packages declare a static whose initializer names a bare constant, and PHP
     * evaluates every static initializer of a class on the first access to any one of them.
     * Without priming, the inspectors that execute plugin code — A-1 constructs the plugin,
     * A-5 calls `getHooks()` — throw `Error: Undefined constant` on those ten, which the
     * H-bug path then reports as a harness defect. Measured on the fleet: 20 false failures
     * and 70 false skips, all of them attributed to the wrong file.
     *
     * The detector is a constant that nothing else in this suite references, so it can only
     * be defined by the priming under test. That also makes the pin order-independent: no
     * earlier test can have defined it, and this is the only test that touches the fixture.
     *
     * @return void
     */
    public function testConstantsArePrimedBeforeAnInspectorRuns()
    {
        $result = $this->runContractCase(
            PctcCasePrimedPlugin::class,
            PctcInspectorNeedsPrimedConstants::class
        );

        $this->assertTrue(
            $result->wasSuccessful(),
            'PluginContractTestCase must prime constants before running an inspector: '
            .($result->failureCount() > 0 ? $result->failures()[0]->thrownException()->getMessage() : '')
        );
        $this->assertTrue(
            defined('PCTC_FIXTURE_PRORATE_BILLING'),
            'the plugin`s own bare constant must have been stubbed'
        );
    }

    // -----------------------------------------------------------------------
    // inspectAll(): the matrix row
    // -----------------------------------------------------------------------

    /**
     * @return void
     */
    public function testInspectAllReturnsOneRowPerCatalogueIdInDisplayOrder()
    {
        $rows = PluginContractTestCase::inspectAll(new PluginSubject(PctcFixturePlugin::class));

        $this->assertSame(InspectorRegistry::ids(), array_keys($rows));
        foreach ($rows as $id => $findings) {
            $this->assertIsArray($findings, $id.' must contribute a cell');
            foreach ($findings as $finding) {
                $this->assertInstanceOf(Finding::class, $finding);
                $this->assertSame($id, $finding->assertion(), 'a finding must be filed under its own column');
            }
        }
    }

    /**
     * The fleet self-check runs all seventeen inspectors over sixty-nine plugins in one
     * process. One inspector throwing must cost one cell, not the run — and the cell has to
     * say whose bug it is, because at that scale a mislabelled H-bug reads as sixty-nine
     * broken plugins.
     *
     * @return void
     */
    public function testInspectAllConvertsAThrownExceptionIntoAHarnessBugFinding()
    {
        $rows = PluginContractTestCase::inspectAll(new PctcExplodingSubject(PctcFixturePlugin::class));

        $this->assertSame(InspectorRegistry::ids(), array_keys($rows), 'no row may be lost to a throw');

        foreach ($rows as $id => $findings) {
            $this->assertCount(1, $findings, $id.' should hold exactly the harness-bug finding');
            $finding = $findings[0];
            $this->assertTrue($finding->isFailure(), $id.' must be a failure, not a skip');
            $this->assertSame($id, $finding->assertion());
            $this->assertStringContainsString('HARNESS BUG (H-bug): inspector threw RuntimeException', $finding->message());
            $this->assertStringContainsString('pctc exploding subject', $finding->message());
            $this->assertSame(
                ['harnessBug' => true, 'exception' => 'RuntimeException'],
                $finding->context()
            );
        }
    }

    /**
     * Guards the test above from going vacuous: it only proves anything while every
     * inspector actually touches the subject. If one stopped, its row would be a genuine
     * verdict and the loop would be asserting the wrong thing about it.
     *
     * @return void
     */
    public function testTheExplodingSubjectReachesEveryInspector()
    {
        $subject = new PctcExplodingSubject(PctcFixturePlugin::class);
        $reached = 0;

        foreach (InspectorRegistry::all() as $inspector) {
            try {
                $inspector->inspect($subject);
            } catch (\Throwable $e) {
                $this->assertSame('pctc exploding subject', $e->getMessage());
                $reached++;
            }
        }

        $this->assertSame(count(InspectorRegistry::ids()), $reached);
    }
}

// ---------------------------------------------------------------------------
// Fixtures
//
// Prefixed `Pctc` because every test file in this suite shares one process: a fixture name
// that collides with another file's is a fatal redeclaration, not a test failure. None of
// these are TestCase subclasses, so PHPUnit will not try to collect them from this file.
// ---------------------------------------------------------------------------

/**
 * Base for the fixture inspectors: catalogue identity without the boilerplate.
 */
abstract class PctcFixtureInspector implements PluginInspector
{
    /**
     * @return string
     */
    public function title()
    {
        return 'fixture assertion';
    }
}

class PctcInspectorPasses extends PctcFixtureInspector
{
    /**
     * @return string
     */
    public function id()
    {
        return 'F-1';
    }

    /**
     * @param PluginSubject $subject
     * @return array<int,Finding>
     */
    public function inspect(PluginSubject $subject)
    {
        return [];
    }
}

class PctcInspectorReturnsFailure extends PctcFixtureInspector
{
    /**
     * @return string
     */
    public function id()
    {
        return 'F-2';
    }

    /**
     * @return string
     */
    public function title()
    {
        return 'reported plugin defect';
    }

    /**
     * @param PluginSubject $subject
     * @return array<int,Finding>
     */
    public function inspect(PluginSubject $subject)
    {
        return [Finding::failure(
            $this->id(),
            'the fixture plugin did the wrong thing',
            ['offender' => 'getSettings']
        )];
    }
}

class PctcInspectorReturnsTwoFailures extends PctcFixtureInspector
{
    /**
     * @return string
     */
    public function id()
    {
        return 'F-2b';
    }

    /**
     * @param PluginSubject $subject
     * @return array<int,Finding>
     */
    public function inspect(PluginSubject $subject)
    {
        return [
            Finding::failure($this->id(), 'first fixture violation'),
            Finding::failure($this->id(), 'second fixture violation'),
        ];
    }
}

class PctcInspectorThrows extends PctcFixtureInspector
{
    /**
     * @return string
     */
    public function id()
    {
        return 'F-3';
    }

    /**
     * @param PluginSubject $subject
     * @return array<int,Finding>
     */
    public function inspect(PluginSubject $subject)
    {
        throw new \LogicException('the fixture inspector exploded');
    }
}

class PctcInspectorThrowsAnError extends PctcFixtureInspector
{
    /**
     * @return string
     */
    public function id()
    {
        return 'F-3b';
    }

    /**
     * @param PluginSubject $subject
     * @return array<int,Finding>
     */
    public function inspect(PluginSubject $subject)
    {
        throw new \TypeError('the fixture inspector mistyped something');
    }
}

class PctcInspectorOnlySkips extends PctcFixtureInspector
{
    /**
     * @return string
     */
    public function id()
    {
        return 'F-4';
    }

    /**
     * @param PluginSubject $subject
     * @return array<int,Finding>
     */
    public function inspect(PluginSubject $subject)
    {
        return [
            Finding::skipped($this->id(), 'first fixture reason'),
            Finding::skipped($this->id(), 'second fixture reason'),
        ];
    }
}

class PctcInspectorSkipsAndFails extends PctcFixtureInspector
{
    /**
     * @return string
     */
    public function id()
    {
        return 'F-5';
    }

    /**
     * @param PluginSubject $subject
     * @return array<int,Finding>
     */
    public function inspect(PluginSubject $subject)
    {
        return [
            Finding::skipped($this->id(), 'fixture reason beside a failure'),
            Finding::failure($this->id(), 'fixture violation beside a skip'),
        ];
    }
}

class PctcInspectorSkipsAndNotices extends PctcFixtureInspector
{
    /**
     * @return string
     */
    public function id()
    {
        return 'F-6';
    }

    /**
     * @param PluginSubject $subject
     * @return array<int,Finding>
     */
    public function inspect(PluginSubject $subject)
    {
        return [
            Finding::skipped($this->id(), 'fixture reason beside a notice'),
            Finding::notice($this->id(), 'fixture observation'),
        ];
    }
}

class PctcInspectorOnlyNotices extends PctcFixtureInspector
{
    /**
     * @return string
     */
    public function id()
    {
        return 'F-7';
    }

    /**
     * @param PluginSubject $subject
     * @return array<int,Finding>
     */
    public function inspect(PluginSubject $subject)
    {
        return [Finding::notice($this->id(), 'fixture observation, informational only')];
    }
}

/**
 * Fails unless the process was primed before it ran.
 *
 * Asks the question every Tier-A inspector asks — "what does this static hold?" — and files
 * the swallowed `Error` as a defect instead of ignoring it. That is precisely the discipline
 * `PluginSubject::staticPropertyError()` documents for inspectors, so this fixture is a
 * faithful stand-in for a real one rather than a contrivance.
 */
class PctcInspectorNeedsPrimedConstants extends PctcFixtureInspector
{
    /**
     * @return string
     */
    public function id()
    {
        return 'F-8';
    }

    /**
     * @param PluginSubject $subject
     * @return array<int,Finding>
     */
    public function inspect(PluginSubject $subject)
    {
        $error = $subject->staticPropertyError('settings');
        if ($error !== null) {
            return [Finding::failure($this->id(), 'constants were not primed: '.$error)];
        }
        if (!is_array($subject->staticProperty('settings'))) {
            return [Finding::failure($this->id(), 'settings did not evaluate to an array')];
        }

        return [];
    }
}

/**
 * A subject that throws on every question an inspector can ask it.
 *
 * Stands in for a genuinely broken harness without needing one: the inspectors stay as
 * shipped, and the throw comes from the layer beneath them — which is where the real H-bug
 * came from too, when `PluginSubject::type()` fatalled on constant-poisoned plugins.
 *
 * `pluginClass()` deliberately still answers. It is a plain field read that cannot throw in
 * production, and leaving it working keeps the fixture usable by any caller that has to name
 * the plugin before inspecting it — priming its constants, for instance.
 */
class PctcExplodingSubject extends PluginSubject
{
    /**
     * @return bool
     */
    public function isLoadable()
    {
        throw new \RuntimeException('pctc exploding subject');
    }

    /**
     * @return \ReflectionClass
     */
    public function reflection()
    {
        throw new \RuntimeException('pctc exploding subject');
    }

    /**
     * @param string $name
     * @return bool
     */
    public function hasStaticProperty($name)
    {
        throw new \RuntimeException('pctc exploding subject');
    }

    /**
     * @param string $name
     * @return mixed
     */
    public function staticProperty($name)
    {
        throw new \RuntimeException('pctc exploding subject');
    }

    /**
     * @param string $name
     * @return string|null
     */
    public function staticPropertyError($name)
    {
        throw new \RuntimeException('pctc exploding subject');
    }

    /**
     * @return string|null
     */
    public function module()
    {
        throw new \RuntimeException('pctc exploding subject');
    }

    /**
     * @return string|null
     */
    public function type()
    {
        throw new \RuntimeException('pctc exploding subject');
    }

    /**
     * @return string|null
     */
    public function packageDir()
    {
        throw new \RuntimeException('pctc exploding subject');
    }
}
