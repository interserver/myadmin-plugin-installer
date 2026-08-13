<?php
/**
 * @author Joe Huss <detain@interserver.net>
 * @copyright 2026
 * @package MyAdmin
 * @category Testing
 */

namespace Tests\MyAdmin\Plugins\Testing;

use MyAdmin\Plugins\Testing\Contract\Finding;
use MyAdmin\Plugins\Testing\FleetMatrix;
use PHPUnit\Framework\TestCase;

/**
 * Pins the fleet matrix's selection rules, its arithmetic, and what its report is
 * willing to leave out.
 *
 * ---------------------------------------------------------------------------------
 * WHY THE OMISSION TESTS ARE THE POINT
 * ---------------------------------------------------------------------------------
 * The artefact this class replaced was wrong in two ways that no assertion about a
 * happy path would have caught: it enumerated the fleet from one hardcoded vendor
 * directory and so measured 69 of 71 packages, and it cut each package's evidence at
 * two findings and so disclosed 27 of 49 dangling requirements. Both defects made the
 * document look *more* complete, not less — a shorter list of problems reads as a
 * cleaner fleet.
 *
 * So the tests below spend most of their effort on what happens when data is absent or
 * long: a hole must count as a hole rather than vanish from the denominator, and a
 * 400-character finding must survive rendering intact. Asserting that 3 passes count as
 * 3 would have passed against the broken snapshot too.
 *
 * @covers \MyAdmin\Plugins\Testing\FleetMatrix
 */
class FleetMatrixTest extends TestCase
{
    /** @var array<int,string> a small stand-in catalogue; the real ids are pinned by InspectorRegistryTest */
    private const IDS = ['A-1', 'B-9', 'B-10'];

    // -----------------------------------------------------------------------
    // Fleet selection
    // -----------------------------------------------------------------------

    /**
     * @return void
     */
    public function testScopeIsDecidedByComposerTypeAlone()
    {
        $this->assertTrue(FleetMatrix::isInScope(['type' => 'myadmin-plugin']));
        $this->assertFalse(FleetMatrix::isInScope(['type' => 'myadmin-module']));
        $this->assertFalse(FleetMatrix::isInScope(['type' => 'library']));
        $this->assertFalse(FleetMatrix::isInScope([]));
    }

    /**
     * A package with no `src/Plugin.php` is still in scope. Membership and inspectability
     * are separate questions, and conflating them is how a package drops out of the audit
     * because it is broken.
     *
     * @return void
     */
    public function testScopeDoesNotDependOnThePackageBeingInspectable()
    {
        $this->assertTrue(FleetMatrix::isInScope(['type' => 'myadmin-plugin', 'autoload' => []]));
    }

    /**
     * @return void
     */
    public function testPluginClassComesFromThePrefixThatMapsToSrc()
    {
        $composer = ['autoload' => ['psr-4' => ['Detain\\MyAdminFoo\\' => 'src']]];
        $this->assertSame('Detain\\MyAdminFoo\\Plugin', FleetMatrix::pluginClassFor($composer));
    }

    /**
     * The map is authoritative even when the namespace is nothing a name transform would
     * produce — `myadmin-powerdns` really is `Detain\MyAdminPowerDns`, lowercase `ns` and all.
     *
     * @return void
     */
    public function testPluginClassIsNotDerivedFromThePackageName()
    {
        $composer = [
            'name' => 'detain/myadmin-powerdns',
            'autoload' => ['psr-4' => ['Detain\\MyAdminPowerDns\\' => 'src']],
        ];
        $this->assertSame('Detain\\MyAdminPowerDns\\Plugin', FleetMatrix::pluginClassFor($composer));
    }

    /**
     * @return void
     */
    public function testPluginClassToleratesTrailingSlashesAndPathLists()
    {
        $this->assertSame(
            'Ns\\Plugin',
            FleetMatrix::pluginClassFor(['autoload' => ['psr-4' => ['Ns\\' => 'src/']]])
        );
        $this->assertSame(
            'Ns\\Plugin',
            FleetMatrix::pluginClassFor(['autoload' => ['psr-4' => ['Ns\\' => ['lib', 'src']]]])
        );
    }

    /**
     * @return void
     */
    public function testPluginClassSkipsPrefixesThatDoNotMapToSrc()
    {
        $composer = [
            'autoload' => ['psr-4' => ['Other\\Tests\\' => 'tests', 'Real\\' => 'src']],
        ];
        $this->assertSame('Real\\Plugin', FleetMatrix::pluginClassFor($composer));
    }

    /**
     * @return void
     */
    public function testPluginClassIsNullWhenNothingMapsToSrc()
    {
        $this->assertNull(FleetMatrix::pluginClassFor([]));
        $this->assertNull(FleetMatrix::pluginClassFor(['autoload' => []]));
        $this->assertNull(FleetMatrix::pluginClassFor(['autoload' => ['psr-4' => ['Ns\\' => 'lib']]]));
    }

    // -----------------------------------------------------------------------
    // Verdict vocabulary
    // -----------------------------------------------------------------------

    /**
     * @return void
     */
    public function testVerdictIsPassWhenNothingWasReported()
    {
        $this->assertSame(FleetMatrix::PASS, FleetMatrix::verdictFor([]));
    }

    /**
     * A notice is detail about a cell that passed. Promoting it would make this document
     * disagree with the suite about how many assertions hold.
     *
     * @return void
     */
    public function testNoticesDoNotChangeAVerdict()
    {
        $this->assertSame(FleetMatrix::PASS, FleetMatrix::verdictFor([Finding::NOTICE, Finding::NOTICE]));
    }

    /**
     * @return void
     */
    public function testSkippedDominatesPassAndFailureDominatesSkipped()
    {
        $this->assertSame(FleetMatrix::SKIP, FleetMatrix::verdictFor([Finding::NOTICE, Finding::SKIPPED]));
        $this->assertSame(FleetMatrix::FAIL, FleetMatrix::verdictFor([Finding::SKIPPED, Finding::FAILURE]));
        $this->assertSame(FleetMatrix::FAIL, FleetMatrix::verdictFor([Finding::FAILURE, Finding::SKIPPED]));
    }

    /**
     * The fourth state, and the reason it exists: `backups-module` registers no routes and no
     * requirement paths, and before R-4 that identical fact rendered green in one column and
     * grey in another. Neither reading was available to this method, because both inspectors
     * handed it a vocabulary that could not express "there was nothing of this kind".
     *
     * @return void
     */
    public function testAnEntirelyInapplicableCellIsNeitherAPassNorASkip()
    {
        $verdict = FleetMatrix::verdictFor([Finding::NOT_APPLICABLE, Finding::NOT_APPLICABLE]);

        $this->assertSame(FleetMatrix::NOT_APPLICABLE, $verdict);
        $this->assertNotSame(FleetMatrix::PASS, $verdict, 'a vacuous cell verifies nothing');
        $this->assertNotSame(FleetMatrix::SKIP, $verdict, 'and the check did run');
    }

    /**
     * Not-applicable sits *below* skip, deliberately. A cell holding both has a coverage hole
     * in it, and the reader who has to act is the one chasing the half that could not run;
     * rendering it `o` would hide that behind the state that means "no action needed".
     *
     * @return void
     */
    public function testASkipBesideAnInapplicableFindingIsASkip()
    {
        $this->assertSame(
            FleetMatrix::SKIP,
            FleetMatrix::verdictFor([Finding::NOT_APPLICABLE, Finding::SKIPPED])
        );
        $this->assertSame(
            FleetMatrix::SKIP,
            FleetMatrix::verdictFor([Finding::SKIPPED, Finding::NOT_APPLICABLE])
        );
    }

    /**
     * A failure beside an inapplicable finding is still a failure. B-11 produces exactly this
     * pair — a handler that registers no routes but prints while doing it — and silencing one
     * of the fleet's genuine failures is the worst thing this change could do.
     *
     * @return void
     */
    public function testAFailureBesideAnInapplicableFindingStillFails()
    {
        $this->assertSame(
            FleetMatrix::FAIL,
            FleetMatrix::verdictFor([Finding::NOT_APPLICABLE, Finding::FAILURE])
        );
    }

    /**
     * Unanimity is required. An inspector that made four clean observations and one "nothing
     * of this kind here" has observed plenty, and calling that cell vacuous understates
     * coverage — the mirror image of the overstatement the state was added to fix.
     *
     * @return void
     */
    public function testOneInapplicableFindingAmongObservationsDoesNotMakeTheCellVacuous()
    {
        $this->assertSame(
            FleetMatrix::PASS,
            FleetMatrix::verdictFor([Finding::NOT_APPLICABLE, Finding::NOTICE])
        );
    }

    /**
     * `[]` stays a pass. It is the inspectors' documented pass signal, and reinterpreting it
     * here would move the decision back out of the inspector — exactly what putting the
     * fourth state in `Finding` was for.
     *
     * @return void
     */
    public function testAnEmptyResultIsStillAPassAndNotAVacuousCell()
    {
        $this->assertSame(FleetMatrix::PASS, FleetMatrix::verdictFor([]));
    }

    // -----------------------------------------------------------------------
    // Arithmetic
    // -----------------------------------------------------------------------

    /**
     * @return void
     */
    public function testCensusCountsEachVerdictPerAssertion()
    {
        $census = FleetMatrix::census($this->rows(), self::IDS);
        $this->assertSame($this->counts([FleetMatrix::PASS => 2]), $census['A-1']);
        $this->assertSame(
            $this->counts([FleetMatrix::PASS => 1, FleetMatrix::SKIP => 1]),
            $census['B-9']
        );
        $this->assertSame(
            $this->counts([FleetMatrix::FAIL => 1, FleetMatrix::NOT_APPLICABLE => 1]),
            $census['B-10']
        );
    }

    /**
     * Every state gets a count, including the ones nobody reported. A census that omitted a
     * zero row would make "no cell was inapplicable" and "inapplicability was not measured"
     * the same reading — the distinction this whole change is about, one level up.
     *
     * @return void
     */
    public function testTheCensusCarriesACountForEveryVerdictIncludingTheAbsentOnes()
    {
        $census = FleetMatrix::census($this->rows(), self::IDS);

        $this->assertSame(FleetMatrix::VERDICTS, array_keys($census['A-1']));
        $this->assertContains(FleetMatrix::NOT_APPLICABLE, array_keys($census['A-1']));
    }

    /**
     * @return void
     */
    public function testCensusHasARowForAnAssertionNoPackageReported()
    {
        $census = FleetMatrix::census($this->rows(), ['A-1', 'B-15']);
        $this->assertArrayHasKey('B-15', $census);
        $this->assertSame(2, $census['B-15'][FleetMatrix::MISSING]);
        $this->assertSame(0, $census['B-15'][FleetMatrix::PASS]);
    }

    /**
     * A cell whose collector died has no verdict. Counting it as anything else — including
     * dropping it — makes an interrupted run indistinguishable from a complete one.
     *
     * @return void
     */
    public function testAnAbsentCellCountsAsNotRunRatherThanDisappearing()
    {
        $rows = ['a/one' => ['class' => 'A', 'cells' => []]];
        $census = FleetMatrix::census($rows, self::IDS);
        foreach (self::IDS as $id) {
            $this->assertSame(1, $census[$id][FleetMatrix::MISSING], $id.' must be reported as not run');
        }
    }

    /**
     * @return void
     */
    public function testAnUnrecognisedVerdictStringIsTreatedAsNotRun()
    {
        $rows = ['a/one' => ['class' => 'A', 'cells' => ['A-1' => ['verdict' => 'green', 'messages' => []]]]];
        $census = FleetMatrix::census($rows, ['A-1']);
        $this->assertSame(1, $census['A-1'][FleetMatrix::MISSING]);
        $this->assertSame(0, $census['A-1'][FleetMatrix::PASS]);
    }

    /**
     * The denominator is what the run *owed*, not what it delivered.
     *
     * @return void
     */
    public function testTotalCellCountIsFleetTimesCatalogueEvenWhenCellsAreMissing()
    {
        $rows = $this->rows();
        $rows['c/three'] = ['class' => 'C', 'cells' => []];
        $totals = FleetMatrix::totals($rows, self::IDS);

        $this->assertSame(9, $totals['cells']);
        $this->assertSame(3, $totals[FleetMatrix::MISSING]);
        $this->assertSame(3, $totals[FleetMatrix::PASS]);
        $this->assertSame(1, $totals[FleetMatrix::FAIL]);
        $this->assertSame(1, $totals[FleetMatrix::SKIP]);
        $this->assertSame(1, $totals[FleetMatrix::NOT_APPLICABLE]);
        $this->assertSame(
            $totals['cells'],
            array_sum(array_intersect_key($totals, array_flip(FleetMatrix::VERDICTS))),
            'every cell must land in exactly one state; a fifth state that nothing sums would '
                .'shrink the denominator silently'
        );
    }

    // -----------------------------------------------------------------------
    // Evidence
    // -----------------------------------------------------------------------

    /**
     * @return void
     */
    public function testFailuresAreGroupedByAssertionAndKeepTheirMessages()
    {
        $failures = FleetMatrix::failuresBy($this->rows(), self::IDS);
        $this->assertSame(['B-10'], array_keys($failures));
        $this->assertSame(['b/two'], array_keys($failures['B-10']));
        $this->assertSame(['[B-10] dangling one', '[B-10] dangling two'], $failures['B-10']['b/two']);
    }

    /**
     * The snapshot this replaced cut every package's evidence at two findings, which hid
     * 22 of 49 dangling requirements — including the one that turned out to fatal a live
     * billing path. Nothing here may impose a cap.
     *
     * @return void
     */
    public function testEveryFailingFindingSurvivesRendering()
    {
        $messages = [];
        for ($i = 1; $i <= 6; $i++) {
            $messages[] = '[B-10] requirement number '.$i.' '.str_repeat('detail ', 60);
        }
        $rows = ['a/one' => ['class' => 'A', 'cells' => ['B-10' => ['verdict' => 'fail', 'messages' => $messages]]]];

        $markdown = FleetMatrix::renderMarkdown($rows, ['B-10']);

        foreach ($messages as $message) {
            $this->assertStringContainsString($message, $markdown);
        }
        $this->assertSame(6, substr_count($markdown, '  - [B-10] requirement number '));
    }

    /**
     * @return void
     */
    public function testPassingCellsContributeNoEvidence()
    {
        $failures = FleetMatrix::failuresBy($this->rows(), self::IDS);
        $this->assertArrayNotHasKey('A-1', $failures);
        $this->assertArrayNotHasKey('B-9', $failures);
    }

    // -----------------------------------------------------------------------
    // Rendering
    // -----------------------------------------------------------------------

    /**
     * @return void
     */
    public function testHeadlineReportsTheMeasuredCounts()
    {
        $markdown = FleetMatrix::renderMarkdown($this->rows(), self::IDS);
        $this->assertStringContainsString(
            '**3 assertions x 2 packages = 6 cells** — 3 pass, 1 fail, 1 skip, 1 not applicable.',
            $markdown
        );
        $this->assertStringNotContainsString('NOT RUN', $markdown);
    }

    /**
     * An incomplete run must announce itself in the first thing anyone reads, because the
     * failure mode is someone quoting the pass count as a gate result.
     *
     * @return void
     */
    public function testHeadlineDisownsItselfWhenCellsAreMissing()
    {
        $rows = $this->rows();
        $rows['c/three'] = ['class' => 'C', 'cells' => []];
        $markdown = FleetMatrix::renderMarkdown($rows, self::IDS);

        $this->assertStringContainsString('**3 NOT RUN', $markdown);
        $this->assertStringContainsString('must not be read as a gate result', $markdown);
    }

    /**
     * @return void
     */
    public function testCensusGainsANotRunColumnOnlyWhenSomethingDidNotRun()
    {
        $clean = FleetMatrix::renderMarkdown($this->rows(), self::IDS);
        $this->assertStringContainsString('| id | pass | fail | skip | n/a | note |', $clean);

        $rows = $this->rows();
        $rows['c/three'] = ['class' => 'C', 'cells' => []];
        $this->assertStringContainsString(
            '| id | pass | fail | skip | n/a | not run | note |',
            FleetMatrix::renderMarkdown($rows, self::IDS)
        );
    }

    /**
     * The `n/a` column, unlike `not run`, is unconditional. A table without it could not be
     * told apart from one produced before the state existed, and "no cell was inapplicable"
     * is a measurement while "this generator never looked" is not.
     *
     * @return void
     */
    public function testTheInapplicableColumnIsPrintedEvenWhenEveryCountIsZero()
    {
        $rows = ['a/one' => ['class' => 'A', 'cells' => [
            'A-1' => ['verdict' => 'pass', 'messages' => []],
        ]]];

        $markdown = FleetMatrix::renderMarkdown($rows, ['A-1']);

        $this->assertStringContainsString('| id | pass | fail | skip | n/a | note |', $markdown);
        $this->assertStringContainsString('| A-1 | 1 | 0 | 0 | 0 |  |', $markdown);
    }

    /**
     * @return void
     */
    public function testEditorialNotesAppearAgainstTheirAssertion()
    {
        $markdown = FleetMatrix::renderMarkdown($this->rows(), self::IDS, [
            'notes' => ['B-10' => 'dangling requirement paths'],
        ]);
        $this->assertStringContainsString('| B-10 | 0 | 1 | 0 | 1 | dangling requirement paths |', $markdown);
        $this->assertStringContainsString('| A-1 | 2 | 0 | 0 | 0 |  |', $markdown);
    }

    /**
     * A package that claims the type but cannot be inspected is a defect to publish, not a
     * row to drop — dropping it is what made the fleet look like 69 packages.
     *
     * @return void
     */
    public function testExcludedPackagesAreNamedWithTheirReason()
    {
        $markdown = FleetMatrix::renderMarkdown($this->rows(), self::IDS, [
            'excluded' => ['x/broken' => 'ships no `src/Plugin.php`'],
        ]);
        $this->assertStringContainsString('## Excluded packages', $markdown);
        $this->assertStringContainsString('- **x/broken** — ships no `src/Plugin.php`', $markdown);
    }

    /**
     * @return void
     */
    public function testExcludedSectionIsAbsentWhenNothingWasExcluded()
    {
        $this->assertStringNotContainsString(
            '## Excluded packages',
            FleetMatrix::renderMarkdown($this->rows(), self::IDS)
        );
    }

    /**
     * "No hatches were used" and "nobody looked for hatches" must not render the same. Escape-hatch
     * auditability is its own G2 checklist item, so the section is unconditional — unlike
     * "Excluded packages", which is a detail of the run rather than a thing being certified.
     *
     * @return void
     */
    public function testTheHatchSectionIsPresentEvenWhenNoPackageUsesOne()
    {
        $markdown = FleetMatrix::renderMarkdown($this->rows(), self::IDS);
        $this->assertStringContainsString('## Escape hatches', $markdown);
        $this->assertStringContainsString('No package overrides a contract default.', $markdown);
    }

    /**
     * The abuse case is *which* directory a package pointed the harness at, so the value is on
     * the page, not just the hatch's name.
     *
     * @return void
     */
    public function testHatchRowsNameTheOverrideAndItsValue()
    {
        $markdown = FleetMatrix::renderMarkdown($this->rows(), self::IDS, [
            'hatches' => [
                'a/one' => [[
                    'assertion' => 'B-10',
                    'outcome' => 'pass',
                    'overrides' => ['requirementRoot' => '/somewhere/else'],
                ]],
            ],
        ]);

        $this->assertStringContainsString('| one | B-10 | pass | requirementRoot | `/somewhere/else` |', $markdown);
        $this->assertStringNotContainsString('No package overrides a contract default.', $markdown);
    }

    /**
     * @return void
     */
    public function testEveryOverrideOnAnEntryGetsItsOwnRow()
    {
        $markdown = FleetMatrix::renderMarkdown($this->rows(), self::IDS, [
            'hatches' => [
                'a/one' => [[
                    'assertion' => 'A-1',
                    'outcome' => 'skip',
                    'overrides' => ['requirementRoot' => '/r', 'constantOverrides' => ['PRORATE_BILLING' => 2]],
                ]],
            ],
        ]);

        $this->assertStringContainsString('| one | A-1 | skip | requirementRoot | `/r` |', $markdown);
        $this->assertStringContainsString(
            '| one | A-1 | skip | constantOverrides | `PRORATE_BILLING=2` |',
            $markdown
        );
    }

    /**
     * @return void
     */
    public function testGridRendersOneGlyphPerVerdict()
    {
        $rows = $this->rows();
        $rows['c/three'] = ['class' => 'C', 'cells' => []];
        $markdown = FleetMatrix::renderMarkdown($rows, self::IDS);

        $this->assertStringContainsString('| one | . | . | o |', $markdown);
        $this->assertStringContainsString('| two | . | - | **F** |', $markdown);
        $this->assertStringContainsString('| three | **?** | **?** | **?** |', $markdown);
    }

    /**
     * Five glyphs and their meanings, in the legend beside the grid. A reader who meets `o`
     * for the first time has nowhere else to look, and an unexplained glyph is how a state
     * gets read as whatever the reader already expected. The two states that are easiest to
     * confuse — `o` and `-` — each carry their disambiguating gloss here.
     *
     * Asserted as one whole line rather than as five substrings: the prose above the census
     * also contains the words "could not run", so a per-phrase assertion passes against a
     * legend that has silently dropped them.
     *
     * @return void
     */
    public function testTheLegendExplainsEveryGlyphItCanPrint()
    {
        $markdown = FleetMatrix::renderMarkdown($this->rows(), self::IDS);

        $this->assertStringContainsString(
            '`.` pass · `o` not applicable (ran; nothing of this kind here)'
                .' · `F` fail · `-` skip (could not run) · `?` not run',
            $markdown
        );
    }

    /**
     * @return void
     */
    public function testGridColumnsFollowTheCatalogueOrderNotTheDataOrder()
    {
        $rows = [
            'a/one' => ['class' => 'A', 'cells' => [
                'B-10' => ['verdict' => 'fail', 'messages' => ['x']],
                'A-1' => ['verdict' => 'pass', 'messages' => []],
                'B-9' => ['verdict' => 'skip', 'messages' => ['y']],
            ]],
        ];
        $markdown = FleetMatrix::renderMarkdown($rows, self::IDS);

        $this->assertStringContainsString('| package | A-1 | B-9 | B-10 |', $markdown);
        $this->assertStringContainsString('| one | . | - | **F** |', $markdown);
    }

    /**
     * @return void
     */
    public function testShortNameDropsTheVendorAndTheMyadminMarker()
    {
        $this->assertSame('kvm-vps', FleetMatrix::shortName('detain/myadmin-kvm-vps'));
        $this->assertSame('scrub-ips-module', FleetMatrix::shortName('ganesh/myadmin-scrub-ips-module'));
        $this->assertSame('something-else', FleetMatrix::shortName('vendor/something-else'));
        $this->assertSame('bare', FleetMatrix::shortName('bare'));
    }

    /**
     * @return void
     */
    public function testTheReproductionCommandIsPrinted()
    {
        $markdown = FleetMatrix::renderMarkdown($this->rows(), self::IDS, ['generator' => 'php tools/fleet-matrix.php']);
        $this->assertStringContainsString("```bash\nphp tools/fleet-matrix.php\n```", $markdown);
        $this->assertStringContainsString('do not hand-edit', $markdown);
    }

    /**
     * @return void
     */
    public function testHatchesAreCollectedFromTheRecordsThatCarryThem()
    {
        $records = [
            ['package' => 'a/one', 'hatches' => []],
            ['package' => 'b/two', 'hatches' => [['assertion' => 'B-10', 'outcome' => 'pass', 'overrides' => ['r' => '/x']]]],
            ['package' => 'c/three'],
        ];
        $collected = FleetMatrix::collectHatches($records);

        $this->assertSame(['b/two'], array_keys($collected));
        $this->assertSame('B-10', $collected['b/two'][0]['assertion']);
    }

    /**
     * @return void
     */
    public function testCollectingHatchesFromNothingYieldsNothing()
    {
        $this->assertSame([], FleetMatrix::collectHatches([]));
    }

    // -----------------------------------------------------------------------
    // Deferrals — the other kind of exemption
    // -----------------------------------------------------------------------

    /**
     * Same argument as the hatch section: "nobody defers anything" and "deferrals were never
     * looked for" must not render identically, so the section is unconditional.
     *
     * @return void
     */
    public function testTheDeferralSectionIsPresentEvenWhenNoPackageDefersAnything()
    {
        $markdown = FleetMatrix::renderMarkdown($this->rows(), self::IDS);

        $this->assertStringContainsString('## Deferrals', $markdown);
        $this->assertStringContainsString('No package defers a catalogue assertion.', $markdown);
    }

    /**
     * @return void
     */
    public function testDeferralsAreCollectedFromTheRecordsThatCarryThem()
    {
        $records = [
            ['package' => 'a/one', 'deferrals' => [], 'deferralProblems' => []],
            ['package' => 'b/two', 'deferrals' => ['B-10' => ['until' => '2099-01-01']], 'deferralProblems' => []],
            ['package' => 'c/three', 'deferrals' => [], 'deferralProblems' => ['broken']],
            ['package' => 'd/four'],
        ];

        $collected = FleetMatrix::collectDeferrals($records);

        $this->assertSame(['b/two', 'c/three'], array_keys($collected));
        $this->assertSame(['B-10'], array_keys($collected['b/two']['deferrals']));
        $this->assertSame(['broken'], $collected['c/three']['problems']);
        $this->assertSame([], FleetMatrix::collectDeferrals([]));
    }

    /**
     * A deferral is a record, not an override: the cell it names keeps whatever verdict the
     * fleet run produced, and the row says which one that was.
     *
     * @return void
     */
    public function testADeferralIsDisclosedWithoutChangingTheCellItNames()
    {
        $markdown = FleetMatrix::renderMarkdown($this->rows(), self::IDS, [
            'deferrals' => [
                'b/two' => [
                    'deferrals' => ['B-10' => [
                        'until' => '2099-01-01',
                        'issue' => 'plugin_plan.md Phase 5',
                        'findings' => ['one', 'two'],
                    ]],
                    'problems' => [],
                ],
            ],
        ]);

        $this->assertStringContainsString('| two | B-10 | 2099-01-01 | fail | active | plugin_plan.md Phase 5 | 2 |', $markdown);
        // The census and the grid must be untouched: b/two's B-10 is still a failure.
        $this->assertStringContainsString('| B-10 | 0 | 1 | 0 | 1 |', $markdown);
        $this->assertStringContainsString('| two | . | - | **F** |', $markdown);
    }

    /**
     * The fleet-side staleness check. The package's own suite enforces this against its own
     * run; a deferral whose cell is no longer failing has nothing left to defer, and the
     * document says so rather than listing it as a live exemption.
     *
     * @return void
     */
    public function testADeferralWhoseCellIsNotFailingIsMarkedStale()
    {
        $markdown = FleetMatrix::renderMarkdown($this->rows(), self::IDS, [
            'deferrals' => [
                'a/one' => [
                    'deferrals' => ['B-10' => ['until' => '2099-01-01', 'issue' => 'x', 'findings' => ['one']]],
                    'problems' => [],
                ],
            ],
        ]);

        $this->assertStringContainsString('| one | B-10 | 2099-01-01 | not-applicable | **stale** |', $markdown);
    }

    /**
     * A long-past `until` renders exactly like a current one, and the document says nothing
     * about expiry.
     *
     * This pins a deliberate omission, so it is worth stating why. The matrix is generated and
     * `--check`ed in CI. A clock-dependent cell would make it go stale on a *date* rather than
     * on a *change*, turning a build red for a reason unrelated to the commit under test — and
     * the first thing anyone does with a build that fails for no reason is regenerate the file
     * to make it quiet, which is precisely how a document stops being read.
     *
     * Nothing is lost by omitting it. Expiry is enforced in {@see DeferredContractDefects},
     * which fails the owning package's own suite past `until`; and the `until` column is
     * printed right here, so a reader can see the date has passed. What is dropped is the
     * derived claim, never the fact.
     *
     * @return void
     */
    public function testAnExpiredDeferralIsNotMarkedExpiredBecauseTheMatrixMustStayDeterministic()
    {
        $markdown = FleetMatrix::renderMarkdown($this->rows(), self::IDS, [
            'deferrals' => [
                'b/two' => [
                    'deferrals' => ['B-10' => ['until' => '2000-01-01', 'issue' => 'x', 'findings' => ['one']]],
                    'problems' => [],
                ],
            ],
        ]);

        $this->assertStringNotContainsString('expired', $markdown);
        $this->assertStringContainsString('| two | B-10 | 2000-01-01 | fail | active |', $markdown);
    }

    /**
     * A register that cannot be read defers nothing while reading as though it defers
     * something, so the document reports it rather than omitting the package.
     *
     * @return void
     */
    public function testAMalformedRegisterIsReportedInFull()
    {
        $markdown = FleetMatrix::renderMarkdown($this->rows(), self::IDS, [
            'deferrals' => [
                'b/two' => [
                    'deferrals' => ['B-10' => ['until' => 'soon', 'issue' => 'x', 'findings' => ['one']]],
                    'problems' => ['B-10: "until" (soon) is not a date strtotime() can read'],
                ],
            ],
        ]);

        $this->assertStringContainsString('**malformed**', $markdown);
        $this->assertStringContainsString('Malformed registers — these defer nothing', $markdown);
        $this->assertStringContainsString('is not a date strtotime() can read', $markdown);
    }

    // -----------------------------------------------------------------------
    // The shim's wiring
    // -----------------------------------------------------------------------

    /**
     * The one thing about `tools/fleet-matrix.php` a unit test cannot reach: that the child
     * process actually puts the hatch ledger in its record. Delete that key and every
     * assertion above still passes while the fleet's escape-hatch audit silently reports
     * nothing — the exact failure this section exists to prevent.
     *
     * Skips rather than fails without a core checkout, because the fleet *is* the plugin
     * packages installed beside this one; there is nothing to inspect in a standalone clone.
     *
     * @return void
     */
    public function testTheChildProcessReportsCellsAndHatchesTogether()
    {
        $vendorDir = dirname(dirname(dirname(dirname(__DIR__))));
        $tool = dirname(dirname(__DIR__)).'/tools/fleet-matrix.php';
        $composer = $vendorDir.'/detain/myadmin-abuse-plugin/composer.json';
        if (!is_file($vendorDir.'/autoload.php') || !is_file($composer)) {
            $this->markTestSkipped('needs a MyAdmin core checkout — the fleet lives beside this package');
        }

        $class = FleetMatrix::pluginClassFor((array)json_decode((string)file_get_contents($composer), true));
        $this->assertNotNull($class);

        $command = escapeshellarg(PHP_BINARY).' '.escapeshellarg($tool)
            .' --vendor-dir='.escapeshellarg($vendorDir)
            .' --package=detain/myadmin-abuse-plugin'
            .' --class='.escapeshellarg($class).' 2>/dev/null';
        $record = json_decode((string)shell_exec($command), true);

        $this->assertIsArray($record, 'the child must emit one decodable JSON record');
        $this->assertArrayHasKey('cells', $record);
        $this->assertArrayHasKey('hatches', $record, 'the hatch ledger must travel with the verdicts');
        $this->assertArrayHasKey(
            'deferrals',
            $record,
            'the deferral register must travel with the verdicts too — it is the only channel the'
            .' fleet document has for it, since this process cannot load a package\'s test classes'
        );
        $this->assertArrayHasKey('deferralProblems', $record);
        $this->assertNotSame([], $record['cells']);
    }

    // -----------------------------------------------------------------------
    // Fixtures
    // -----------------------------------------------------------------------

    /**
     * A census row with the named counts and zero for every other state.
     *
     * @param array<string,int> $counts
     * @return array<string,int>
     */
    private function counts(array $counts)
    {
        return array_replace(array_fill_keys(FleetMatrix::VERDICTS, 0), $counts);
    }

    /**
     * Two packages: one clean apart from an inapplicable cell, one with a skip and a
     * two-finding failure.
     *
     * @return array<string,array<string,mixed>>
     */
    private function rows()
    {
        return [
            'a/one' => ['class' => 'A', 'cells' => [
                'A-1' => ['verdict' => 'pass', 'messages' => []],
                'B-9' => ['verdict' => 'pass', 'messages' => []],
                // Not a pass: this package registers nothing B-10 could check. Carried in the
                // shared fixture rather than a private one so the arithmetic, the census
                // columns, the headline and the grid are all exercised against a fleet that
                // contains the fourth state — which the real fleet does, 187 times.
                'B-10' => ['verdict' => 'not-applicable', 'messages' => ['[B-10] registers no requirement paths']],
            ]],
            'b/two' => ['class' => 'B', 'cells' => [
                'A-1' => ['verdict' => 'pass', 'messages' => []],
                'B-9' => ['verdict' => 'skip', 'messages' => ['[B-9] nothing to check']],
                'B-10' => ['verdict' => 'fail', 'messages' => ['[B-10] dangling one', '[B-10] dangling two']],
            ]],
        ];
    }
}
