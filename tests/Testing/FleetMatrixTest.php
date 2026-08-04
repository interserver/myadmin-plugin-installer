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

    // -----------------------------------------------------------------------
    // Arithmetic
    // -----------------------------------------------------------------------

    /**
     * @return void
     */
    public function testCensusCountsEachVerdictPerAssertion()
    {
        $census = FleetMatrix::census($this->rows(), self::IDS);
        $this->assertSame(
            [FleetMatrix::PASS => 2, FleetMatrix::FAIL => 0, FleetMatrix::SKIP => 0, FleetMatrix::MISSING => 0],
            $census['A-1']
        );
        $this->assertSame(
            [FleetMatrix::PASS => 1, FleetMatrix::FAIL => 0, FleetMatrix::SKIP => 1, FleetMatrix::MISSING => 0],
            $census['B-9']
        );
        $this->assertSame(
            [FleetMatrix::PASS => 1, FleetMatrix::FAIL => 1, FleetMatrix::SKIP => 0, FleetMatrix::MISSING => 0],
            $census['B-10']
        );
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
        $this->assertSame(4, $totals[FleetMatrix::PASS]);
        $this->assertSame(1, $totals[FleetMatrix::FAIL]);
        $this->assertSame(1, $totals[FleetMatrix::SKIP]);
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
            '**3 assertions x 2 packages = 6 cells** — 4 pass, 1 fail, 1 skip.',
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
        $this->assertStringContainsString('| id | pass | fail | skip | note |', $clean);

        $rows = $this->rows();
        $rows['c/three'] = ['class' => 'C', 'cells' => []];
        $this->assertStringContainsString(
            '| id | pass | fail | skip | not run | note |',
            FleetMatrix::renderMarkdown($rows, self::IDS)
        );
    }

    /**
     * @return void
     */
    public function testEditorialNotesAppearAgainstTheirAssertion()
    {
        $markdown = FleetMatrix::renderMarkdown($this->rows(), self::IDS, [
            'notes' => ['B-10' => 'dangling requirement paths'],
        ]);
        $this->assertStringContainsString('| B-10 | 1 | 1 | 0 | dangling requirement paths |', $markdown);
        $this->assertStringContainsString('| A-1 | 2 | 0 | 0 |  |', $markdown);
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
     * @return void
     */
    public function testGridRendersOneGlyphPerVerdict()
    {
        $rows = $this->rows();
        $rows['c/three'] = ['class' => 'C', 'cells' => []];
        $markdown = FleetMatrix::renderMarkdown($rows, self::IDS);

        $this->assertStringContainsString('| one | . | . | . |', $markdown);
        $this->assertStringContainsString('| two | . | - | **F** |', $markdown);
        $this->assertStringContainsString('| three | **?** | **?** | **?** |', $markdown);
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

    // -----------------------------------------------------------------------
    // Fixtures
    // -----------------------------------------------------------------------

    /**
     * Two packages: one clean, one with a skip and a two-finding failure.
     *
     * @return array<string,array<string,mixed>>
     */
    private function rows()
    {
        return [
            'a/one' => ['class' => 'A', 'cells' => [
                'A-1' => ['verdict' => 'pass', 'messages' => []],
                'B-9' => ['verdict' => 'pass', 'messages' => []],
                'B-10' => ['verdict' => 'pass', 'messages' => []],
            ]],
            'b/two' => ['class' => 'B', 'cells' => [
                'A-1' => ['verdict' => 'pass', 'messages' => []],
                'B-9' => ['verdict' => 'skip', 'messages' => ['[B-9] nothing to check']],
                'B-10' => ['verdict' => 'fail', 'messages' => ['[B-10] dangling one', '[B-10] dangling two']],
            ]],
        ];
    }
}
