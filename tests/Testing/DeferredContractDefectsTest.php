<?php
/**
 * @author Joe Huss <detain@interserver.net>
 * @copyright 2026
 * @package MyAdmin
 * @category Testing
 */

namespace Tests\MyAdmin\Plugins\Testing;

use MyAdmin\Plugins\Testing\Contract\Finding;
use MyAdmin\Plugins\Testing\Contract\PluginInspector;
use MyAdmin\Plugins\Testing\Contract\PluginSubject;
use MyAdmin\Plugins\Testing\DeferralRegister;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\TestResult;
use Tests\MyAdmin\Plugins\Testing\Fixtures\DcdCase;

/**
 * Mutation-verifies every guard on the hoisted deferral register.
 *
 * ---------------------------------------------------------------------------------
 * WHY EACH TEST IS A MUTANT AND NOT A UNIT TEST
 * ---------------------------------------------------------------------------------
 * A deferral mechanism is only worth its complexity if it *cannot* absorb a defect it was
 * not shown. The version this replaces had a docblock asserting five guarantees and code
 * that had three of them, and the gap was invisible to a test suite that only checked the
 * happy path: every guard was reachable, none was exercised, and the mechanism silently
 * swallowed an additional defect for as long as it existed.
 *
 * So each test below is a *mutation*: a change to what the assertion reports, of the kind a
 * real plugin edit would produce, paired with the outcome the register must reach. Removing
 * any guard from {@see \MyAdmin\Plugins\Testing\DeferredContractDefects} turns exactly one of
 * these from red to green — verified by deleting each in turn.
 *
 * The mutants, and the real edit each stands for:
 *
 *  | mutant                                         | stands for                              |
 *  |------------------------------------------------|-----------------------------------------|
 *  | a fifth **failure**                             | a fifth broken registration             |
 *  | a fifth **skip**                                | a cross-package path in a standalone repo, which is the *default* arm for a plugin repo — 0 of 71 ship `include/` |
 *  | a fifth **notice**                              | any inspector observation riding along  |
 *  | a **changed** fingerprint                       | the registered path being edited        |
 *  | **not-applicable**, no failures                 | `getRequirements()` deleted outright     |
 *  | **pass**, no failures                           | the defect actually fixed               |
 *  | an expired `until`                              | the deadline arriving                   |
 *
 * ---------------------------------------------------------------------------------
 * THE REGISTER IS READ FROM DISK, NOT INJECTED
 * ---------------------------------------------------------------------------------
 * Every test writes a real `composer.json` into a scratch directory and points
 * {@see DcdCase} at it. That is the same file, read by the same class, that
 * `tools/fleet-matrix.php` reads to render the Deferrals section — so these tests also pin
 * that a suite cannot be exempted through a channel the fleet document cannot see.
 *
 * @covers \MyAdmin\Plugins\Testing\DeferredContractDefects
 * @covers \MyAdmin\Plugins\Testing\DeferralRegister
 */
class DeferredContractDefectsTest extends TestCase
{
    /** The four failures the fixture register defers, as fingerprints. */
    private const FINGERPRINTS = [
        'requirement "class.Novnc" registers /pkg/src/Novnc.php',
        'requirement "deactivate_kcare" registers /pkg/src/abuse.inc.php',
        'requirement "deactivate_abuse" registers /pkg/src/abuse.inc.php',
        'requirement "get_abuse_licenses" registers /pkg/src/abuse.inc.php',
    ];

    /** @var array<int,string> scratch directories to remove */
    private $scratchDirs = [];

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        foreach ($this->scratchDirs as $dir) {
            @unlink($dir.'/composer.json');
            @rmdir($dir);
        }
        $this->scratchDirs = [];
        DcdCase::$packageDir = '';
        DcdInspector::$findings = [];
        parent::tearDown();
    }

    // -----------------------------------------------------------------------
    // Harness
    // -----------------------------------------------------------------------

    /**
     * Writes a scratch package whose manifest declares `$register`, and points the fixture
     * case at it.
     *
     * @param array<string,mixed>|null $register null to write a manifest with no `extra` at all
     * @return string the package directory
     */
    private function packageDeclaring($register)
    {
        $dir = sys_get_temp_dir().'/dcd-'.getmypid().'-'.mt_rand();
        mkdir($dir, 0777, true);
        $this->scratchDirs[] = $dir;

        $manifest = ['name' => 'detain/myadmin-dcd-fixture', 'type' => 'myadmin-plugin'];
        if ($register !== null) {
            $manifest['extra'] = [DeferralRegister::MANIFEST_KEY => $register];
        }
        file_put_contents($dir.'/composer.json', (string)json_encode($manifest, JSON_PRETTY_PRINT));

        DcdCase::$packageDir = $dir;
        return $dir;
    }

    /**
     * The register the mutants are measured against: B-10, in force, four fingerprints.
     *
     * @param string $until
     * @return array<string,mixed>
     */
    private function fixtureRegister($until = '2099-12-31')
    {
        return [
            'B-10' => [
                'until' => $until,
                'issue' => 'plugin_plan.md Phase 5, Bucket 1',
                'findings' => self::FINGERPRINTS,
            ],
        ];
    }

    /**
     * Findings for a healthy deferral: exactly the four deferred failures.
     *
     * @return array<int,Finding>
     */
    private function theFourDeferredFailures()
    {
        $findings = [];
        foreach (self::FINGERPRINTS as $fingerprint) {
            $findings[] = Finding::failure(
                'B-10',
                $fingerprint.', which resolves to /nowhere — no such file'
            );
        }
        return $findings;
    }

    /**
     * Runs one contract assertion the way PHPUnit would, in its own result.
     *
     * @param string $method    test method on the fixture case
     * @param array<int,mixed> $data
     * @return TestResult
     */
    private function runCase($method, array $data = [])
    {
        $case = new DcdCase($method, $data, 'deferral fixture');
        $result = new TestResult();
        $case->run($result);

        $this->assertSame(1, $result->count(), 'exactly one case should have run');

        return $result;
    }

    /**
     * @param array<int,Finding> $findings what the deferred inspector reports
     * @return TestResult
     */
    private function runDeferredAssertion(array $findings)
    {
        DcdInspector::$findings = $findings;

        return $this->runCase('testPluginSatisfiesContractAssertion', [DcdInspector::class]);
    }

    /**
     * @param TestResult $result
     * @return string
     */
    private function soleFailureMessage(TestResult $result)
    {
        $this->assertSame(1, $result->failureCount(), 'expected exactly one failure');
        $this->assertSame(0, $result->errorCount(), 'a deferral verdict must never surface as an error');
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
    // The positive control — without it, every mutant below is vacuous
    // -----------------------------------------------------------------------

    /**
     * The healthy case: the assertion reports exactly the deferred failures, so the run is a
     * skip that names the deadline, the issue and every covered finding.
     *
     * @return void
     */
    public function testAnIntactDeferralSkipsAndDisclosesEverythingItCovers()
    {
        $this->packageDeclaring($this->fixtureRegister());

        $message = $this->soleSkipMessage($this->runDeferredAssertion($this->theFourDeferredFailures()));

        $this->assertStringContainsString('B-10 deferred until 2099-12-31', $message);
        $this->assertStringContainsString('plugin_plan.md Phase 5, Bucket 1', $message);
        $this->assertStringContainsString('4 recorded finding(s)', $message);
        foreach (self::FINGERPRINTS as $fingerprint) {
            $this->assertStringContainsString($fingerprint, $message, 'every covered finding must be named');
        }
        $this->assertStringContainsString(
            'disclosed in the fleet triage matrix',
            $message,
            'the reader has to be told where the exemption is recorded fleet-wide'
        );
    }

    /**
     * An assertion this package has *not* deferred goes straight to the shipped base class,
     * untouched. Without this, a trait that swallowed everything would pass every test above.
     *
     * @return void
     */
    public function testAnUndeferredAssertionIsHandedToTheBaseClassUnchanged()
    {
        $this->packageDeclaring($this->fixtureRegister());
        DcdOtherInspector::$findings = [Finding::failure('B-15', 'an undeferred violation')];

        $message = $this->soleFailureMessage(
            $this->runCase('testPluginSatisfiesContractAssertion', [DcdOtherInspector::class])
        );

        $this->assertStringContainsString('violates the plugin contract', $message, 'the base class wording');
        $this->assertStringContainsString('an undeferred violation', $message);
        $this->assertStringNotContainsString('deferred until', $message);
    }

    /**
     * A package with no register at all behaves exactly as though the trait were absent.
     *
     * @return void
     */
    public function testAPackageWithNoRegisterDefersNothing()
    {
        $this->packageDeclaring(null);
        DcdInspector::$findings = [Finding::failure('B-10', 'an undeferred violation')];

        $message = $this->soleFailureMessage(
            $this->runCase('testPluginSatisfiesContractAssertion', [DcdInspector::class])
        );

        $this->assertStringContainsString('violates the plugin contract', $message);
    }

    // -----------------------------------------------------------------------
    // Mutants
    // -----------------------------------------------------------------------

    /**
     * Mutant: a fifth broken registration that surfaces as a **failure**.
     *
     * @return void
     */
    public function testAFifthFailureIsNotAbsorbed()
    {
        $this->packageDeclaring($this->fixtureRegister());
        $findings = $this->theFourDeferredFailures();
        $findings[] = Finding::failure('B-10', 'requirement "brand_new" registers /pkg/src/new.php');

        $message = $this->soleFailureMessage($this->runDeferredAssertion($findings));

        $this->assertStringContainsString('reported 5 failure(s) but 4 are deferred', $message);
        $this->assertStringContainsString('brand_new', $message, 'the extra defect must be shown');
    }

    /**
     * Mutant: a fifth broken registration that surfaces as a **skip**.
     *
     * This is the hole the text-scraping version had, and it is not an exotic case: B-10's
     * package-relative arm answers a cross-package requirement path with a skip, and that arm
     * is the default one in a standalone plugin repo. The failure count stays at four, so the
     * count check alone cannot see it.
     *
     * @return void
     */
    public function testAFifthFindingThatIsASkipIsNotAbsorbed()
    {
        $this->packageDeclaring($this->fixtureRegister());
        $findings = $this->theFourDeferredFailures();
        $findings[] = Finding::skipped('B-10', 'requirement "elsewhere" points outside this package');

        $message = $this->soleFailureMessage($this->runDeferredAssertion($findings));

        $this->assertStringContainsString('finding(s) that are not failures alongside the', $message);
        $this->assertStringContainsString('elsewhere', $message);
        $this->assertStringContainsString(
            'the count check alone would not have seen it',
            $message,
            'the message must say why the obvious guard misses this'
        );
    }

    /**
     * Mutant: a fifth finding that is a notice. Same guard, different severity — a deferral
     * covers failures it names and nothing else, whatever bucket the extra lands in.
     *
     * @return void
     */
    public function testAFifthFindingThatIsANoticeIsNotAbsorbed()
    {
        $this->packageDeclaring($this->fixtureRegister());
        $findings = $this->theFourDeferredFailures();
        $findings[] = Finding::notice('B-10', 'something else worth observing');

        $message = $this->soleFailureMessage($this->runDeferredAssertion($findings));

        $this->assertStringContainsString('finding(s) that are not failures alongside the', $message);
        $this->assertStringContainsString('something else worth observing', $message);
    }

    /**
     * Mutant: one registered path is edited, so the defect no longer has the shape that was
     * agreed. Four failures, four fingerprints, and the deferral still must not cover it.
     *
     * @return void
     */
    public function testAChangedPathBreaksTheFingerprint()
    {
        $this->packageDeclaring($this->fixtureRegister());
        $findings = $this->theFourDeferredFailures();
        $findings[3] = Finding::failure(
            'B-10',
            'requirement "get_abuse_licenses" registers /pkg/src/somewhere_else.php, which resolves'
            .' to /nowhere — no such file'
        );

        $message = $this->soleFailureMessage($this->runDeferredAssertion($findings));

        $this->assertStringContainsString('no longer reports the deferred finding', $message);
        $this->assertStringContainsString('get_abuse_licenses" registers /pkg/src/abuse.inc.php', $message);
        $this->assertStringContainsString('The defect changed shape', $message);
    }

    /**
     * Mutant: `getRequirements()` deleted outright — the most likely Phase 5 "fix", since the
     * handler registers nothing but the bogus entries. B-10 then reports **not applicable**,
     * which is not a failure and not a pass.
     *
     * The version this replaces reached its staleness check only when the parent returned
     * normally, and a not-applicable makes the parent *skip* — so this mutant survived, and
     * left behind a green suite with a dead deferral in it.
     *
     * @return void
     */
    public function testADeferralGoingNotApplicableIsReportedAsStale()
    {
        $this->packageDeclaring($this->fixtureRegister());
        $findings = [Finding::notApplicable('B-10', 'plugin registers no requirement paths')];

        $message = $this->soleFailureMessage($this->runDeferredAssertion($findings));

        $this->assertStringContainsString('reports no failures at all', $message);
        $this->assertStringContainsString('The deferral is stale', $message);
        $this->assertStringContainsString(
            'plugin registers no requirement paths',
            $message,
            'the reader has to be told what the assertion reports instead'
        );
    }

    /**
     * Mutant: the same, via a skip rather than a not-applicable.
     *
     * @return void
     */
    public function testADeferralGoingSkippedIsReportedAsStale()
    {
        $this->packageDeclaring($this->fixtureRegister());
        $findings = [Finding::skipped('B-10', 'the handler could not be invoked')];

        $message = $this->soleFailureMessage($this->runDeferredAssertion($findings));

        $this->assertStringContainsString('The deferral is stale', $message);
        $this->assertStringContainsString('the handler could not be invoked', $message);
    }

    /**
     * Mutant: the defect is actually fixed. A register that outlives its defect is a blind
     * spot, so the entry has to be deleted and the build says so.
     *
     * @return void
     */
    public function testADeferralWhoseDefectWasFixedIsReportedAsStale()
    {
        $this->packageDeclaring($this->fixtureRegister());

        $message = $this->soleFailureMessage($this->runDeferredAssertion([]));

        $this->assertStringContainsString('reports no failures at all', $message);
        $this->assertStringContainsString('delete the entry', $message);
    }

    /**
     * Mutant: the deadline arrives. Time-boxing is the whole difference between a deferral
     * and an exemption.
     *
     * @return void
     */
    public function testAnExpiredDeferralFailsEvenWhileTheDefectIsStillThere()
    {
        $this->packageDeclaring($this->fixtureRegister('2020-01-01'));

        $message = $this->soleFailureMessage($this->runDeferredAssertion($this->theFourDeferredFailures()));

        $this->assertStringContainsString('was deferred until 2020-01-01', $message);
        $this->assertStringContainsString('That date has passed', $message);
        $this->assertStringContainsString('unrecorded permanent exemption', $message);
    }

    /**
     * An inspector that throws is a harness defect (D7) and must not be mistaken for a stale
     * deferral — which is what "no failures were reported" would otherwise look like.
     *
     * @return void
     */
    public function testAThrowingInspectorIsReportedAsAHarnessBugRatherThanAStaleDeferral()
    {
        $this->packageDeclaring($this->fixtureRegister());
        DcdInspector::$findings = [];
        DcdInspector::$throw = true;

        try {
            $message = $this->soleFailureMessage(
                $this->runCase('testPluginSatisfiesContractAssertion', [DcdInspector::class])
            );
        } finally {
            DcdInspector::$throw = false;
        }

        $this->assertStringContainsString('HARNESS BUG (H-bug)', $message);
        $this->assertStringContainsString('the deferral is not the problem', $message);
        $this->assertStringNotContainsString('stale', $message);
    }

    // -----------------------------------------------------------------------
    // Guard 0 — a register that cannot be read defers nothing
    // -----------------------------------------------------------------------

    /**
     * @return array<string,array{0:array<string,mixed>,1:string}>
     */
    public function malformedRegisters()
    {
        return [
            'unknown assertion id' => [
                ['B-99' => ['until' => '2099-01-01', 'issue' => 'x', 'findings' => ['y']]],
                'is not a catalogue assertion id',
            ],
            'unreadable date' => [
                ['B-10' => ['until' => 'when we get round to it', 'issue' => 'x', 'findings' => ['y']]],
                'is not a date strtotime() can read',
            ],
            'no deadline' => [
                ['B-10' => ['issue' => 'x', 'findings' => ['y']]],
                'a deferral with no deadline is a permanent exemption',
            ],
            'no issue' => [
                ['B-10' => ['until' => '2099-01-01', 'findings' => ['y']]],
                'the record has to say what is going to fix this',
            ],
            'no fingerprints' => [
                ['B-10' => ['until' => '2099-01-01', 'issue' => 'x', 'findings' => []]],
                'without fingerprints the deferral covers whatever the assertion happens to report',
            ],
            'stray key' => [
                ['B-10' => ['until' => '2099-01-01', 'issue' => 'x', 'findings' => ['y'], 'why' => 'z']],
                'unknown key "why"',
            ],
        ];
    }

    /**
     * @dataProvider malformedRegisters
     * @param array<string,mixed> $register
     * @param string              $expected
     * @return void
     */
    public function testAMalformedRegisterFailsItsOwnTest(array $register, $expected)
    {
        $this->packageDeclaring($register);

        $message = $this->soleFailureMessage($this->runCase('testDeferralRegisterIsWellFormed'));

        $this->assertStringContainsString($expected, $message);
        $this->assertStringContainsString('silently defers nothing', $message);
    }

    /**
     * A malformed entry must also stop the assertion it names from being deferred, rather
     * than being deferred against whatever half of the record happened to parse.
     *
     * @return void
     */
    public function testAMalformedEntryIsNotSilentlyHonouredByTheAssertionItNames()
    {
        $this->packageDeclaring(['B-10' => ['until' => '2099-01-01', 'issue' => 'x', 'findings' => []]]);

        $message = $this->soleFailureMessage($this->runDeferredAssertion($this->theFourDeferredFailures()));

        $this->assertStringContainsString('its record is malformed', $message);
    }

    /**
     * A clean register — including an absent one — passes its own test with nothing to say.
     *
     * @return void
     */
    public function testAWellFormedRegisterPassesItsOwnTest()
    {
        $this->packageDeclaring($this->fixtureRegister());
        $this->assertTrue($this->runCase('testDeferralRegisterIsWellFormed')->wasSuccessful());

        $this->packageDeclaring(null);
        $this->assertTrue($this->runCase('testDeferralRegisterIsWellFormed')->wasSuccessful());
    }

    /**
     * The declaration channel itself: what the trait reads is what
     * `tools/fleet-matrix.php` reads, byte for byte, from the same file.
     *
     * @return void
     */
    public function testTheRegisterIsReadFromTheManifestTheFleetMatrixAlsoReads()
    {
        $dir = $this->packageDeclaring($this->fixtureRegister());

        $fromDisk = DeferralRegister::forPackageDir($dir);

        $this->assertSame(['B-10'], array_keys($fromDisk));
        $this->assertSame('2099-12-31', $fromDisk['B-10']['until']);
        $this->assertSame(self::FINGERPRINTS, $fromDisk['B-10']['findings']);
        $this->assertSame([], DeferralRegister::problemsForPackageDir($dir));
    }

    /**
     * A manifest whose `extra` key holds something that is not an object is reported, not
     * ignored: ignoring it would defer nothing while reading as though it deferred something.
     *
     * @return void
     */
    public function testANonObjectRegisterIsReportedRatherThanIgnored()
    {
        $dir = sys_get_temp_dir().'/dcd-'.getmypid().'-'.mt_rand();
        mkdir($dir, 0777, true);
        $this->scratchDirs[] = $dir;
        file_put_contents($dir.'/composer.json', (string)json_encode([
            'name' => 'detain/myadmin-dcd-fixture',
            'extra' => [DeferralRegister::MANIFEST_KEY => 'B-10'],
        ]));

        $this->assertSame([], DeferralRegister::forPackageDir($dir));
        $problems = DeferralRegister::problemsForPackageDir($dir);
        $this->assertCount(1, $problems);
        $this->assertStringContainsString('expected an object keyed by catalogue assertion id', $problems[0]);
    }

    /**
     * A package that is not on disk at all — a fixture, a scratch subject — has no register
     * and no complaint, rather than an unreadable-file error.
     *
     * @return void
     */
    public function testAnAbsentPackageDirectoryYieldsNeitherRegisterNorProblem()
    {
        $this->assertSame([], DeferralRegister::forPackageDir(null));
        $this->assertSame([], DeferralRegister::problemsForPackageDir(null));
        $this->assertSame([], DeferralRegister::forPackageDir('/nowhere/at/all'));
        $this->assertSame([], DeferralRegister::problemsForPackageDir('/nowhere/at/all'));
    }
}

// ---------------------------------------------------------------------------
// Fixtures
//
// Prefixed `Dcd` because every test file in this suite shares one process: a fixture name
// that collides with another file's is a fatal redeclaration, not a test failure.
// ---------------------------------------------------------------------------

/**
 * An inspector wearing B-10's catalogue id, reporting whatever the current mutant needs.
 *
 * It has to answer `B-10` rather than a fixture id such as `F-1`, because the register is
 * validated against the real catalogue — a deferral naming an assertion that does not exist
 * is itself one of the things under test.
 */
class DcdInspector implements PluginInspector
{
    /** @var array<int,Finding> */
    public static $findings = [];

    /** @var bool whether to violate the inspector contract by throwing */
    public static $throw = false;

    /**
     * @return string
     */
    public function id()
    {
        return 'B-10';
    }

    /**
     * @return string
     */
    public function title()
    {
        return 'Requirement paths resolve';
    }

    /**
     * @param PluginSubject $subject
     * @return array<int,Finding>
     */
    public function inspect(PluginSubject $subject)
    {
        if (self::$throw) {
            throw new \LogicException('the fixture inspector exploded');
        }
        return self::$findings;
    }
}

/**
 * A second catalogue id, so "this one is not deferred" can be driven without touching the
 * deferred one's findings.
 */
class DcdOtherInspector extends DcdInspector
{
    /** @var array<int,Finding> */
    public static $findings = [];

    /**
     * @return string
     */
    public function id()
    {
        return 'B-15';
    }

    /**
     * @return string
     */
    public function title()
    {
        return 'Handlers do not print';
    }

    /**
     * @param PluginSubject $subject
     * @return array<int,Finding>
     */
    public function inspect(PluginSubject $subject)
    {
        return self::$findings;
    }
}
