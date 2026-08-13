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
use Tests\MyAdmin\Plugins\Testing\Fixtures\PctcCaseHatchedRoot;
use Tests\MyAdmin\Plugins\Testing\Fixtures\PctcCaseMissingPlugin;
use Tests\MyAdmin\Plugins\Testing\Fixtures\PctcCaseOptedOutOfRequirementRoot;
use Tests\MyAdmin\Plugins\Testing\Fixtures\PctcCasePlain;
use Tests\MyAdmin\Plugins\Testing\Fixtures\PctcCasePrimedPlugin;
use Tests\MyAdmin\Plugins\Testing\Fixtures\PctcFixturePlugin;
use Tests\MyAdmin\Plugins\Testing\Fixtures\PctcHatchedRequirementPlugin;

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
     * The case object from the most recent {@see runContractCase()}, for the handful of
     * properties that live on the test rather than on its result.
     *
     * @var \MyAdmin\Plugins\Testing\PluginContractTestCase|null
     */
    private $lastCase;

    /** @var array<int,string> scratch directories to remove, deepest first */
    private $scratchDirs = [];

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
        $this->lastCase = $case;

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

    /**
     * @param TestResult $result
     * @return string
     */
    private function soleIncompleteMessage(TestResult $result)
    {
        $this->assertSame(1, $result->notImplementedCount(), 'expected exactly one incomplete run');
        $this->assertSame(0, $result->failureCount());
        $this->assertSame(0, $result->errorCount());
        $this->assertSame(0, $result->skippedCount());
        $incomplete = $result->notImplemented();

        return $incomplete[0]->thrownException()->getMessage();
    }

    /**
     * The ledger is process-global by necessity — eighteen PHPUnit instances and seventy-one
     * fleet subjects have no object to share — so every test that reads it starts from empty
     * and leaves it empty. Without this, an assertion on "the ledger holds one entry" would
     * pass or fail depending on which tests ran first.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        PluginContractTestCase::clearOverrideLedger();
        foreach (array_reverse($this->scratchDirs) as $dir) {
            $this->removeTree($dir);
        }
        $this->scratchDirs = [];
        PctcCaseHatchedRoot::$root = '';
    }

    /**
     * @param string $dir
     * @return void
     */
    private function removeTree($dir)
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir.'/'.$entry;
            if (is_dir($path)) {
                $this->removeTree($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }

    /**
     * A throwaway requirement root, optionally holding the one file
     * {@see PctcHatchedRequirementPlugin} registers.
     *
     * @param bool $withTheFile
     * @return string absolute path
     */
    private function makeHatchRoot($withTheFile)
    {
        $root = sys_get_temp_dir().'/pctc-hatch-'.getmypid().'-'.mt_rand();
        mkdir($root, 0777, true);
        $this->scratchDirs[] = $root;
        if ($withTheFile) {
            $full = $root.PctcHatchedRequirementPlugin::SOURCE;
            mkdir(dirname($full), 0777, true);
            file_put_contents($full, "<?php\n");
        }
        return $root;
    }

    // -----------------------------------------------------------------------
    // The data provider
    // -----------------------------------------------------------------------

    /**
     * One case per catalogue assertion, with the id in the key so it lands in the test name.
     * Without that, a plugin repo's CI reports eighteen indistinguishable rows called
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
     * The disclosure names each hatch **and its value**. Naming `requirementRoot` without
     * saying what it points at is not actionable: the reviewed abuse case is entirely about
     * which directory it names, and both misuse directions produce the same hatch name.
     *
     * @return void
     */
    public function testOverrideDescriptionNamesEveryActiveHatchAndItsValue()
    {
        $this->assertSame(
            'Per-repo overrides in effect (review under gate G2): '
            .'requirementRoot=null (opts out of the B-10 requirement-path check).',
            (new PctcCaseOptedOutOfRequirementRoot('testPluginSatisfiesContractAssertion'))
                ->describeOverridesForTest()
        );

        $this->assertSame(
            'Per-repo overrides in effect (review under gate G2): '
            ."expectedType='module', requirementRoot='/srv/pctc-fixture', "
            ."serviceDefines=[PCTC_FIXTURE_SERVICE=4242], constantOverrides=[PCTC_FIXTURE_BILLING='prorate'].",
            (new PctcCaseEveryHatch('testPluginSatisfiesContractAssertion'))->describeOverridesForTest()
        );
    }

    /**
     * An explicit `null` root and a non-null one are both `requirementRoot`, and only one of
     * them removes an assertion. A disclosure that rendered them alike would name the hatch
     * without disclosing the thing worth reviewing.
     *
     * @return void
     */
    public function testOptOutIsDistinguishableFromAnOrdinaryRootInTheDisclosure()
    {
        $optOut = (new PctcCaseOptedOutOfRequirementRoot('testPluginSatisfiesContractAssertion'))
            ->describeOverridesForTest();
        $ordinary = (new PctcCaseEveryHatch('testPluginSatisfiesContractAssertion'))
            ->describeOverridesForTest();

        $this->assertStringContainsString('opts out of the B-10 requirement-path check', $optOut);
        $this->assertStringNotContainsString('opts out of', $ordinary);
    }

    /**
     * Tripwire for a fifth hatch. {@see PluginSubject::overridesInUse()} is the authority on
     * which hooks exist; `overrideValues()` has to be taught each one by hand. A hatch added
     * there and forgotten here would be logged with no value at all — recorded, but not
     * reviewable, which is the half of G2 that matters.
     *
     * @return void
     */
    public function testEveryDeclaredHatchHasAReadableValue()
    {
        $subject = (new PctcCaseEveryHatch('testPluginSatisfiesContractAssertion'))->contractSubjectForTest();
        $values = PctcCasePlain::overrideValuesForTest($subject);

        $this->assertSame($subject->overridesInUse(), array_keys($values));
        foreach ($values as $name => $value) {
            $this->assertNotSame(
                PluginContractTestCase::OVERRIDE_VALUE_UNKNOWN,
                $value,
                $name.' is declared by PluginSubject::overridesInUse() but PluginContractTestCase'
                .'::overrideValues() cannot read it — teach it the new hatch.'
            );
        }
    }

    /**
     * What the tripwire above protects, driven through a subject that declares a hatch this
     * class has not been taught. G2 asks for the hatch to be *logged*, so the name has to
     * survive even when the value cannot be read — and the gap has to look like a gap rather
     * than like a hatch whose value happened to be null, which is a thing three of the four
     * real hatches can legitimately be.
     *
     * @return void
     */
    public function testAHatchWithNoReadableValueIsStillLoggedByName()
    {
        $subject = new PctcSubjectWithAnExtraHatch(PctcFixturePlugin::class, ['requirementRoot' => '/srv/x']);
        $values = PctcCasePlain::overrideValuesForTest($subject);

        $this->assertSame(['requirementRoot', 'pctcFutureHatch'], array_keys($values));
        $this->assertSame('/srv/x', $values['requirementRoot']);
        $this->assertSame(PluginContractTestCase::OVERRIDE_VALUE_UNKNOWN, $values['pctcFutureHatch']);

        $described = (new PctcCasePlain('testPluginSatisfiesContractAssertion'))
            ->describeOverridesForSubject($subject);
        $this->assertStringContainsString('pctcFutureHatch=(value not exposed to the override log)', $described);
        $this->assertStringNotContainsString('pctcFutureHatch=null', $described);
    }

    /**
     * And the ledger records it too — a hatch a generator cannot value is still a hatch a
     * reviewer has to see.
     *
     * @return void
     */
    public function testAHatchWithNoReadableValueStillReachesTheLedger()
    {
        PluginContractTestCase::clearOverrideLedger();
        $subject = new PctcSubjectWithAnExtraHatch(PctcFixturePlugin::class, ['requirementRoot' => '/srv/x']);

        PctcCasePlain::recordOverrideUseForTest(
            $subject,
            PluginContractTestCase::SOURCE_FLEET,
            'B-10',
            'pass'
        );

        $ledger = PluginContractTestCase::overrideLedger();
        $this->assertCount(1, $ledger);
        $this->assertSame(
            ['requirementRoot' => '/srv/x', 'pctcFutureHatch' => PluginContractTestCase::OVERRIDE_VALUE_UNKNOWN],
            $ledger[0]['overrides']
        );
    }

    /**
     * A skip is a verdict reached under whatever hatches were active, exactly like a
     * failure. Disclosing on one and not the other is how "requirementRoot(): null" came to
     * be reported by B-10 as a skip whose message never mentioned that a repo had switched
     * the assertion off.
     *
     * @return void
     */
    public function testSkipMessageDisclosesTheOverridesItWasReachedUnder()
    {
        $none = $this->soleSkipMessage(
            $this->runContractCase(PctcCasePlain::class, PctcInspectorOnlySkips::class)
        );
        $this->assertStringContainsString('No per-repo overrides are in effect.', $none);

        $hatched = $this->soleSkipMessage(
            $this->runContractCase(PctcCaseOptedOutOfRequirementRoot::class, PctcInspectorOnlySkips::class)
        );
        $this->assertStringContainsString(
            'Per-repo overrides in effect (review under gate G2): '
            .'requirementRoot=null (opts out of the B-10 requirement-path check).',
            $hatched
        );
    }

    /**
     * @return void
     */
    public function testIncompleteMessageDisclosesTheOverridesItWasReachedUnder()
    {
        $message = $this->soleIncompleteMessage(
            $this->runContractCase(PctcCaseOptedOutOfRequirementRoot::class, PctcInspectorOnlyNotices::class)
        );

        $this->assertStringContainsString('Per-repo overrides in effect (review under gate G2)', $message);
        $this->assertStringContainsString('requirementRoot=null', $message);
    }

    // -----------------------------------------------------------------------
    // Gate G2: the hatch record ("each is logged when used")
    // -----------------------------------------------------------------------

    /**
     * Guards every ledger assertion below from being vacuous. If the fixture's hatch stopped
     * changing the verdict, "green under a hatch" would still be true and would prove
     * nothing — the ledger would be recording an override that was doing no work.
     *
     * Both roots exist, so both clear the `is_dir()` gate; the only difference is whether the
     * file the plugin registers is inside. That is the abuse shape exactly: a legal-looking
     * hatch value that turns a real dangling-path failure green.
     *
     * @return void
     */
    public function testTheFixtureHatchGenuinelyChangesTheVerdict()
    {
        PctcCaseHatchedRoot::$root = $this->makeHatchRoot(false);
        $withoutTheFile = $this->runContractCase(
            PctcCaseHatchedRoot::class,
            \MyAdmin\Plugins\Testing\Contract\TierB10RequirementPathsResolve::class
        );
        $this->assertStringContainsString(
            'hatched.php',
            $this->soleFailureMessage($withoutTheFile),
            'the un-silenced run must report the dangling path'
        );

        PctcCaseHatchedRoot::$root = $this->makeHatchRoot(true);
        $withTheFile = $this->runContractCase(
            PctcCaseHatchedRoot::class,
            \MyAdmin\Plugins\Testing\Contract\TierB10RequirementPathsResolve::class
        );
        $this->assertTrue($withTheFile->wasSuccessful(), 'and the hatched run must be green');
        $this->assertSame(0, $withTheFile->failureCount());
        $this->assertSame(0, $withTheFile->skippedCount());
        $this->assertSame(0, $withTheFile->notImplementedCount(), 'green, with nothing on screen to read');
    }

    /**
     * The G2 requirement, and the one thing the old disclosure could not do: a hatch that
     * **succeeds** in silencing a defect leaves a record.
     *
     * `describeOverrides()` used to be reachable only from `$this->fail()`, so the log fired
     * exactly when the hatch had failed to hide anything. The run below is green — there is
     * no failure message for a disclosure to ride on — and the hatch is still recorded, with
     * the value that did the silencing.
     *
     * @return void
     */
    public function testAHatchThatSilencesADefectIsRecordedOnThePassingPath()
    {
        PluginContractTestCase::clearOverrideLedger();
        $root = $this->makeHatchRoot(true);
        PctcCaseHatchedRoot::$root = $root;

        $result = $this->runContractCase(
            PctcCaseHatchedRoot::class,
            \MyAdmin\Plugins\Testing\Contract\TierB10RequirementPathsResolve::class
        );
        $this->assertTrue($result->wasSuccessful(), 'the premise is a green run');

        $ledger = PluginContractTestCase::overrideLedger();
        $this->assertCount(1, $ledger, 'a green run under a hatch must still be recorded');
        $this->assertSame(PctcHatchedRequirementPlugin::class, $ledger[0]['plugin']);
        $this->assertSame(PluginContractTestCase::SOURCE_PHPUNIT, $ledger[0]['source']);
        $this->assertSame('B-10', $ledger[0]['assertion']);
        $this->assertSame('pass', $ledger[0]['outcome'], 'pass-under-a-hatch is the entry G2 is looking for');
        $this->assertSame(['requirementRoot' => $root], $ledger[0]['overrides']);
    }

    /**
     * The record is structured, not prose. A matrix generator has to group hatch use by
     * package and assertion across sixty-nine repos, and one that recovered its data by
     * scraping a failure message would break the next time a message is reworded — which
     * this very changeset does.
     *
     * @return void
     */
    public function testTheHatchRecordIsStructuredDataRatherThanAFormattedString()
    {
        PluginContractTestCase::clearOverrideLedger();
        $this->runContractCase(PctcCaseEveryHatch::class, PctcInspectorPasses::class);

        $ledger = PluginContractTestCase::overrideLedger();
        $this->assertCount(1, $ledger);
        $this->assertSame(
            ['plugin', 'source', 'assertion', 'outcome', 'overrides'],
            array_keys($ledger[0]),
            'the entry shape is what a generator consumes; changing it is a breaking change'
        );
        $this->assertSame(
            [
                'expectedType' => 'module',
                'requirementRoot' => '/srv/pctc-fixture',
                'serviceDefines' => ['PCTC_FIXTURE_SERVICE' => 4242],
                'constantOverrides' => ['PCTC_FIXTURE_BILLING' => 'prorate'],
            ],
            $ledger[0]['overrides'],
            'values must be recorded as values, not rendered'
        );
    }

    /**
     * Every outcome, not just the green one. A record that skipped the failing and skipping
     * paths would leave a reviewer unable to tell "this hatch is load-bearing" from "this
     * hatch is inert".
     *
     * @return void
     */
    public function testEveryOutcomeIsRecordedAgainstTheHatchThatWasInEffect()
    {
        $expected = [
            PctcInspectorPasses::class => 'pass',
            PctcInspectorOnlyNotices::class => 'notice',
            PctcInspectorOnlySkips::class => 'skip',
            // Recorded as its own outcome even though the PHPUnit bucket is `skipped`. A G2
            // reviewer asking what a hatch bought a package needs "the assertion did not
            // apply" and "the assertion could not be evaluated" kept apart.
            PctcInspectorOnlyNotApplicable::class => 'not-applicable',
            PctcInspectorSkipsAndNotApplicable::class => 'skip',
            PctcInspectorReturnsFailure::class => 'fail',
            PctcInspectorThrows::class => 'harness-bug',
        ];

        foreach ($expected as $inspector => $outcome) {
            PluginContractTestCase::clearOverrideLedger();
            $this->runContractCase(PctcCaseOptedOutOfRequirementRoot::class, $inspector);

            $ledger = PluginContractTestCase::overrideLedger();
            $this->assertCount(1, $ledger, $inspector.' must contribute exactly one entry');
            $this->assertSame($outcome, $ledger[0]['outcome'], $inspector.' recorded the wrong outcome');
            $this->assertSame(['requirementRoot' => null], $ledger[0]['overrides']);
        }
    }

    /**
     * "Logged when used" — not "logged". A repo that overrides nothing would otherwise
     * contribute 18 entries per run and 1278 across the fleet, and a hatch record nobody can
     * read is the same as no hatch record.
     *
     * @return void
     */
    public function testARunWithNoHatchInUseIsNotRecorded()
    {
        PluginContractTestCase::clearOverrideLedger();

        $this->runContractCase(PctcCasePlain::class, PctcInspectorPasses::class);
        $this->runContractCase(PctcCasePlain::class, PctcInspectorReturnsFailure::class);
        $this->runContractCase(PctcCasePlain::class, PctcInspectorOnlySkips::class);

        $this->assertSame([], PluginContractTestCase::overrideLedger());
    }

    /**
     * @return void
     */
    public function testTheLedgerCanBeCleared()
    {
        PluginContractTestCase::clearOverrideLedger();
        $this->runContractCase(PctcCaseEveryHatch::class, PctcInspectorPasses::class);
        $this->assertNotSame([], PluginContractTestCase::overrideLedger());

        PluginContractTestCase::clearOverrideLedger();
        $this->assertSame([], PluginContractTestCase::overrideLedger());
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
            ."expectedType='module', requirementRoot='/srv/pctc-fixture', "
            ."serviceDefines=[PCTC_FIXTURE_SERVICE=4242], constantOverrides=[PCTC_FIXTURE_BILLING='prorate'].",
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
            'Per-repo overrides in effect (review under gate G2): '
            .'requirementRoot=null (opts out of the B-10 requirement-path check).',
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
    // Not-applicable: the fourth matrix state in a four-bucket test runner
    // -----------------------------------------------------------------------

    /**
     * PHPUnit 9 has four outcomes and the matrix now has five states, so one of them has to
     * share. Not-applicable takes `skipped`, which means a PHPUnit reader cannot tell it from
     * a genuine could-not-run even though the matrix can — so the *message* has to, and this
     * pins that it does.
     *
     * The collapse is only ever allowed this way round: understating coverage sends a reader
     * to look at something that was in fact fine, whereas the reverse would tell them there
     * was nothing to look at.
     *
     * @return void
     */
    public function testAnInapplicableRunIsBucketedWithSkipsButSaysSoInItsMessage()
    {
        $result = $this->runContractCase(PctcCasePlain::class, PctcInspectorOnlyNotApplicable::class);
        $message = $this->soleSkipMessage($result);

        $this->assertStringContainsString('F-8', $message);
        $this->assertStringContainsString('is not applicable to '.PctcFixturePlugin::class, $message);
        $this->assertStringNotContainsString('could not run', $message, 'because it did run');
        $this->assertStringContainsString('fixture has nothing of this kind', $message);
        $this->assertStringContainsString('renders it `o`', $message, 'and the reader is told where it does show');
    }

    /**
     * Not a pass, for the same reason a skip is not: a green cell would have the matrix claim
     * an assertion held when nothing was put to it.
     *
     * @return void
     */
    public function testAnInapplicableRunIsNotGreen()
    {
        $result = $this->runContractCase(PctcCasePlain::class, PctcInspectorOnlyNotApplicable::class);

        $this->assertSame(1, $result->skippedCount());
        $this->assertSame(0, $result->failureCount());
        $this->assertSame(0, $result->notImplementedCount(), 'incomplete belongs to notices');
    }

    /**
     * A skip beside a not-applicable is still nothing verified, so the case is bucketed
     * skipped — and the message keeps both halves, because the coverage hole is the half
     * somebody has to act on.
     *
     * @return void
     */
    public function testASkipBesideAnInapplicableFindingSkipsAndDisclosesBoth()
    {
        $result = $this->runContractCase(PctcCasePlain::class, PctcInspectorSkipsAndNotApplicable::class);
        $message = $this->soleSkipMessage($result);

        $this->assertStringContainsString('could not run', $message, 'the hole must lead');
        $this->assertStringContainsString('fixture reason beside an inapplicable finding', $message);
        $this->assertStringContainsString('fixture has nothing of this kind either', $message);
    }

    /**
     * A failure beside a not-applicable finding still fails. This is B-11's real shape — a
     * handler that registers no routes and prints while doing it — and the fleet's 18
     * genuine failures must not move because of a vocabulary change.
     *
     * @return void
     */
    public function testAFailureBesideAnInapplicableFindingStillFails()
    {
        $result = $this->runContractCase(PctcCasePlain::class, PctcInspectorNotApplicableAndFails::class);

        $this->assertSame(0, $result->skippedCount());
        $this->assertStringContainsString(
            'fixture violation beside an inapplicable finding',
            $this->soleFailureMessage($result)
        );
    }

    /**
     * A notice keeps the case out of the skipped bucket, and the inapplicable finding must
     * not be lost on the way past — the swallowing this vocabulary's every predicate exists
     * to prevent.
     *
     * @return void
     */
    public function testAnInapplicableFindingBesideANoticeIsDisclosedRatherThanDropped()
    {
        $result = $this->runContractCase(PctcCasePlain::class, PctcInspectorNoticesAndNotApplicable::class);
        $message = $this->soleIncompleteMessage($result);

        $this->assertStringContainsString('fixture observation beside an inapplicable finding', $message);
        $this->assertStringContainsString('does not apply to this plugin', $message);
        $this->assertStringContainsString('fixture has nothing of this kind at all', $message);
    }

    /**
     * End-to-end through a real inspector rather than a fixture: B-13 has nothing to run
     * against a plugin with no `getMenu()`, and says so in those words. Proves the wiring
     * holds for something not written to make this test pass.
     *
     * @return void
     */
    public function testARealInspectorReachesTheSameInapplicableVerdict()
    {
        $result = $this->runContractCase(
            PctcCasePlain::class,
            \MyAdmin\Plugins\Testing\Contract\TierB13MenuExecute::class
        );

        $this->assertStringContainsString('B-13 is not applicable to', $this->soleSkipMessage($result));
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
     * The R-5 defect, stated as an assertion: an inspector returning one notice and nothing
     * else used to produce a run byte-for-byte identical to one that found nothing at all.
     * A severity documented as "reported" that no consumer reads is worse than no severity,
     * because the next author routes a downgraded failure through it and turns a false
     * failure into silence.
     *
     * @return void
     */
    public function testALoneNoticeIsReportedRatherThanReadingAsASilentGreen()
    {
        $noticing = $this->runContractCase(PctcCasePlain::class, PctcInspectorOnlyNotices::class);
        $silent = $this->runContractCase(PctcCasePlain::class, PctcInspectorPasses::class);

        $this->assertSame(1, $noticing->notImplementedCount(), 'a notice must land in a bucket of its own');
        $this->assertSame(0, $silent->notImplementedCount(), 'and a run with nothing to say must not');

        $message = $this->soleIncompleteMessage($noticing);
        $this->assertStringContainsString('F-7', $message, 'the assertion must be named');
        $this->assertStringContainsString(PctcFixturePlugin::class, $message, 'the plugin must be named');
        $this->assertStringContainsString('fixture observation, informational only', $message);
        $this->assertStringContainsString('satisfies this assertion', $message, 'and it is not a violation');
        $this->assertStringContainsString('No per-repo overrides are in effect.', $message);
    }

    /**
     * The bucket is the reason this rendering was chosen: incomplete is the only PHPUnit
     * outcome that is visible by default, distinct from pass, fail and skip, and non-fatal
     * under the `failOnRisky` / `failOnWarning` settings every fleet `phpunit.xml.dist`
     * carries. If a notice ever failed a build, sixty-nine repos go red over something that
     * is not a defect.
     *
     * @return void
     */
    public function testANoticeDoesNotFailTheBuildAndIsNotRisky()
    {
        $result = $this->runContractCase(PctcCasePlain::class, PctcInspectorOnlyNotices::class);

        $this->assertTrue($result->wasSuccessful(), 'a notice must never fail the build');
        $this->assertSame(0, $result->failureCount());
        $this->assertSame(0, $result->errorCount());
        $this->assertSame(0, $result->warningCount(), 'failOnWarning="true" would make a warning fatal');
        $this->assertSame(
            0,
            $result->riskyCount(),
            'the contract assertion must be recorded before the test is marked incomplete'
        );
    }

    /**
     * An incomplete run has to carry the same recorded assertion a green one does, or
     * "this cell was checked" stops being true of it — and the ordering that guarantees it
     * (assert, then mark incomplete) is invisible to every other test here, because PHPUnit 9
     * does not apply its no-assertions risky check to an incomplete test.
     *
     * @return void
     */
    public function testTheContractAssertionIsRecordedEvenWhenTheRunIsIncomplete()
    {
        $green = $this->runContractCase(PctcCasePlain::class, PctcInspectorPasses::class);
        $greenAssertions = $this->lastCase->getNumAssertions();

        $incomplete = $this->runContractCase(PctcCasePlain::class, PctcInspectorOnlyNotices::class);

        $this->assertSame(1, $incomplete->notImplementedCount(), 'the premise is an incomplete run');
        $this->assertSame(0, $green->notImplementedCount());
        $this->assertGreaterThan(0, $greenAssertions, 'a green run records the contract assertion');
        $this->assertSame(
            $greenAssertions,
            $this->lastCase->getNumAssertions(),
            'an incomplete run must record it too — assert first, mark incomplete second'
        );
    }

    /**
     * The mixed case the base class documents as deliberately *not* a skip. It used to be a
     * plain green in which the notice and the skip both vanished; the notice now carries
     * both into a message a maintainer sees.
     *
     * @return void
     */
    public function testASkipAlongsideANoticeIsDisclosedRatherThanDropped()
    {
        $result = $this->runContractCase(PctcCasePlain::class, PctcInspectorSkipsAndNotices::class);
        $message = $this->soleIncompleteMessage($result);

        $this->assertStringContainsString('fixture observation', $message, 'the notice must be shown');
        $this->assertStringContainsString('fixture reason beside a notice', $message, 'and so must the skip');
        $this->assertStringContainsString('could not run', $message);
    }

    /**
     * Failure still outranks a notice: an inspector that found a violation and also observed
     * something must report the violation, or the notice buries the defect.
     *
     * @return void
     */
    public function testANoticeAlongsideAFailureStillFails()
    {
        $result = $this->runContractCase(PctcCasePlain::class, PctcInspectorNoticesAndFails::class);

        $this->assertSame(0, $result->notImplementedCount());
        $this->assertStringContainsString('fixture violation beside a notice', $this->soleFailureMessage($result));
    }

    /**
     * A passing contract assertion has to *assert* something. Plugin repos run with
     * `failOnRisky="true"`, so a pass path that recorded no assertion would turn all
     * eighteen green cases red fleet-wide.
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
     * The fleet self-check runs all eighteen inspectors over seventy-one plugins in one
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
     * The fleet path is the one that produces the gate-G2 artefact, and it used to emit no
     * override record whatsoever — so the artefact the gate is reviewed against could not
     * carry the hatch log the gate asks for, no matter how the reviewer read it.
     *
     * @return void
     */
    public function testInspectAllRecordsHatchUseOncePerAssertion()
    {
        PluginContractTestCase::clearOverrideLedger();
        $subject = new PluginSubject(PctcFixturePlugin::class, ['requirementRoot' => '/srv/fleet-fixture']);

        PluginContractTestCase::inspectAll($subject);

        $ledger = PluginContractTestCase::overrideLedger();
        $this->assertCount(count(InspectorRegistry::ids()), $ledger, 'one entry per catalogue assertion');
        $this->assertSame(InspectorRegistry::ids(), array_column($ledger, 'assertion'));
        foreach ($ledger as $entry) {
            $this->assertSame(PluginContractTestCase::SOURCE_FLEET, $entry['source']);
            $this->assertSame(PctcFixturePlugin::class, $entry['plugin']);
            $this->assertSame(['requirementRoot' => '/srv/fleet-fixture'], $entry['overrides']);
            $this->assertContains(
                $entry['outcome'],
                ['pass', 'notice', 'skip', 'not-applicable', 'fail', 'harness-bug']
            );
        }
    }

    /**
     * The hatch record is a side channel, never an extra row. A nineteenth key here would
     * become a nineteenth matrix cell per package and would move the 18 x 71 census the gate
     * is read against.
     *
     * @return void
     */
    public function testInspectAllStillReturnsExactlyTheCatalogueRowsWhenAHatchIsInUse()
    {
        $rows = PluginContractTestCase::inspectAll(
            new PluginSubject(PctcFixturePlugin::class, ['requirementRoot' => '/srv/fleet-fixture'])
        );

        $this->assertSame(InspectorRegistry::ids(), array_keys($rows));
    }

    /**
     * @return void
     */
    public function testInspectAllRecordsNothingForASubjectWithNoHatch()
    {
        PluginContractTestCase::clearOverrideLedger();

        PluginContractTestCase::inspectAll(new PluginSubject(PctcFixturePlugin::class));

        $this->assertSame([], PluginContractTestCase::overrideLedger());
    }

    /**
     * A throwing inspector is filed as a harness bug in the record too, so a hatch cannot be
     * blamed for — or exonerated by — a cell that never produced a verdict.
     *
     * @return void
     */
    public function testInspectAllRecordsAHarnessBugOutcomeAgainstTheHatch()
    {
        PluginContractTestCase::clearOverrideLedger();

        PluginContractTestCase::inspectAll(
            new PctcExplodingSubject(PctcFixturePlugin::class, ['requirementRoot' => '/srv/fleet-fixture'])
        );

        $ledger = PluginContractTestCase::overrideLedger();
        $this->assertCount(count(InspectorRegistry::ids()), $ledger);
        foreach ($ledger as $entry) {
            $this->assertSame('harness-bug', $entry['outcome']);
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

class PctcInspectorNoticesAndFails extends PctcFixtureInspector
{
    /**
     * @return string
     */
    public function id()
    {
        return 'F-5b';
    }

    /**
     * @param PluginSubject $subject
     * @return array<int,Finding>
     */
    public function inspect(PluginSubject $subject)
    {
        return [
            Finding::notice($this->id(), 'fixture observation beside a failure'),
            Finding::failure($this->id(), 'fixture violation beside a notice'),
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

class PctcInspectorOnlyNotApplicable extends PctcFixtureInspector
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
        return [Finding::notApplicable($this->id(), 'the fixture has nothing of this kind')];
    }
}

class PctcInspectorSkipsAndNotApplicable extends PctcFixtureInspector
{
    /**
     * @return string
     */
    public function id()
    {
        return 'F-8b';
    }

    /**
     * @param PluginSubject $subject
     * @return array<int,Finding>
     */
    public function inspect(PluginSubject $subject)
    {
        return [
            Finding::skipped($this->id(), 'fixture reason beside an inapplicable finding'),
            Finding::notApplicable($this->id(), 'the fixture has nothing of this kind either'),
        ];
    }
}

class PctcInspectorNotApplicableAndFails extends PctcFixtureInspector
{
    /**
     * @return string
     */
    public function id()
    {
        return 'F-8c';
    }

    /**
     * @param PluginSubject $subject
     * @return array<int,Finding>
     */
    public function inspect(PluginSubject $subject)
    {
        return [
            Finding::notApplicable($this->id(), 'the fixture has nothing of one kind'),
            Finding::failure($this->id(), 'fixture violation beside an inapplicable finding'),
        ];
    }
}

class PctcInspectorNoticesAndNotApplicable extends PctcFixtureInspector
{
    /**
     * @return string
     */
    public function id()
    {
        return 'F-8d';
    }

    /**
     * @param PluginSubject $subject
     * @return array<int,Finding>
     */
    public function inspect(PluginSubject $subject)
    {
        return [
            Finding::notice($this->id(), 'fixture observation beside an inapplicable finding'),
            Finding::notApplicable($this->id(), 'the fixture has nothing of this kind at all'),
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
 * A subject declaring a hatch that {@see PluginSubject} does not ship.
 *
 * Stands in for the future, which is the only place the "hatch this class cannot read" arm
 * can be reached from: the four real hatches are all readable, so with the shipped subject
 * that arm is dead code and no test can tell a correct implementation from one that logs a
 * bare null instead. Overriding the public `overridesInUse()` is how the fifth hatch is
 * simulated without touching production.
 */
class PctcSubjectWithAnExtraHatch extends PluginSubject
{
    /**
     * @return array<int,string>
     */
    public function overridesInUse()
    {
        return array_merge(parent::overridesInUse(), ['pctcFutureHatch']);
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
