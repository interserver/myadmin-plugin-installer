<?php
/**
 * @author Joe Huss <detain@interserver.net>
 * @copyright 2026
 * @package MyAdmin
 * @category Testing
 */

namespace MyAdmin\Plugins\Testing;

use MyAdmin\Plugins\Testing\Contract\Finding;

/**
 * Fleet-selection, tabulation and rendering for the Phase 2 triage matrix (gate G2).
 *
 * ---------------------------------------------------------------------------------
 * WHY THIS CLASS EXISTS
 * ---------------------------------------------------------------------------------
 * `docs/phase2-triage-matrix.md` was a hand-committed snapshot: no committed program
 * produced it, so nothing could reproduce it, nothing could detect it going stale, and
 * its evidence lines had been trimmed to fit. A matrix whose provenance is "someone ran
 * something once" is an assertion about the fleet, not a measurement of it.
 *
 * Everything here is a pure function over already-collected data. The process
 * management, the filesystem walk and the `docs/` write live in `tools/fleet-matrix.php`,
 * which is a shim — the same split the parent project applies to `scripts/`: policy in a
 * testable class, I/O in a CLI wrapper it can be exercised without.
 *
 * ---------------------------------------------------------------------------------
 * THE ROW SHAPE
 * ---------------------------------------------------------------------------------
 * Every entry point below consumes or produces this one structure:
 *
 *     [
 *       'detain/myadmin-foo' => [
 *         'class' => 'Detain\\MyAdminFoo\\Plugin',
 *         'cells' => [
 *           'A-1' => ['verdict' => 'pass', 'messages' => []],
 *           'B-10' => ['verdict' => 'fail', 'messages' => ['[B-10] ...']],
 *         ],
 *       ],
 *     ]
 *
 * It is deliberately made of arrays and strings rather than {@see Finding} objects: the
 * collection step runs one OS process per package (see the shim's docblock for why), so
 * the data has to survive a JSON round-trip anyway. Taking the serialisable form as the
 * input type means the tests exercise exactly what the tool exercises.
 *
 * ---------------------------------------------------------------------------------
 * WHY `MISSING` IS A VERDICT AND NOT A ZERO
 * ---------------------------------------------------------------------------------
 * If a child process dies, the honest report is "this cell did not run". Summing only the
 * verdicts that came back would silently shrink the denominator and leave the matrix
 * reading as complete — the same defect as a skip that renders as a pass, one level up.
 * {@see census()} therefore counts holes explicitly and {@see renderMarkdown()} puts them
 * in the headline, so a truncated run cannot be mistaken for a clean one.
 *
 * ---------------------------------------------------------------------------------
 * WHERE THE VOCABULARY LIVES
 * ---------------------------------------------------------------------------------
 * {@see verdictFor()} is the single site that turns severities into a cell state, and
 * {@see GLYPHS} the single site that turns a cell state into a grid character. Both were
 * written as single sites so that the open question about this vocabulary — the fourth state
 * separating "ran and passed" from "passed without observing anything" — could be answered
 * with a one-function change rather than a sweep through the renderer. R-4 answered it, and
 * it was.
 *
 * ---------------------------------------------------------------------------------
 * FIVE CELL STATES
 * ---------------------------------------------------------------------------------
 *   `.`  {@see PASS}           — the check ran, observed something, and it was clean
 *   `o`  {@see NOT_APPLICABLE} — the check ran; this plugin has nothing of this kind
 *   `-`  {@see SKIP}           — the check **could not run**
 *   `F`  {@see FAIL}           — a violation
 *   `?`  {@see MISSING}        — no verdict was collected (a broken run, not a result)
 *
 * The fourth state does not originate here. It cannot: by the time a set of severities
 * reaches {@see verdictFor()}, "found nothing of this kind" and "declined to look" are the
 * same string unless the inspector distinguished them. It originates in
 * {@see Finding::NOT_APPLICABLE}; this class only tabulates it.
 *
 * Why it was worth adding: 155 of the fleet's cells were grey, and classifying every one of
 * them found that all 155 meant "nothing of this kind here" and none meant "could not run".
 * The state that hides bugs was therefore perfectly camouflaged by 155 cells that were merely
 * inapplicable. Splitting them makes the residual skip count small enough to be a worklist.
 */
class FleetMatrix
{
    /** @var string composer `type` that puts a package in the fleet */
    const SCOPE_TYPE = 'myadmin-plugin';

    /** @var string every inspector reported a pass */
    const PASS = 'pass';

    /** @var string at least one inspector reported a failure */
    const FAIL = 'fail';

    /** @var string no failures, but at least one inspector could not run */
    const SKIP = 'skip';

    /**
     * @var string the check ran and found nothing of its kind in this package
     *
     * Counted separately from {@see PASS} because a vacuous cell verifies nothing, and
     * separately from {@see SKIP} because the check did run. See the class docblock.
     */
    const NOT_APPLICABLE = 'not-applicable';

    /** @var string the cell was never collected — a hole, not a result */
    const MISSING = 'missing';

    /**
     * @var array<string,string> grid characters, one per verdict
     *
     * `o` for not-applicable: visually quiet, because an inapplicable cell is not a problem,
     * but plainly not `.` and plainly not `-`. The grid is scanned by eye for the loud
     * glyphs, and neither `F` nor `?` may lose contrast to a state that means "nothing here".
     */
    const GLYPHS = [
        self::PASS => '.',
        self::FAIL => '**F**',
        self::SKIP => '-',
        self::NOT_APPLICABLE => 'o',
        self::MISSING => '**?**',
    ];

    /**
     * @var array<int,string> verdicts in report order, everywhere they are listed
     *
     * One list rather than four literal arrays: {@see census()}, {@see totals()} and the two
     * renderers each used to spell it out, and a fifth state added to three of the four would
     * have produced a document whose columns and whose headline disagreed.
     */
    const VERDICTS = [self::PASS, self::FAIL, self::SKIP, self::NOT_APPLICABLE, self::MISSING];

    /**
     * Whether a package belongs to the fleet, judged only by its composer `type`.
     *
     * Membership deliberately does not depend on the package being *inspectable*. A
     * `myadmin-plugin` with no resolvable plugin class is a finding about that package,
     * reported by {@see renderMarkdown()} under "Excluded packages" — not a reason to
     * quietly drop it and report a smaller, greener fleet.
     *
     * @param array<string,mixed> $composerJson decoded composer.json
     * @return bool
     */
    public static function isInScope(array $composerJson)
    {
        return isset($composerJson['type']) && $composerJson['type'] === self::SCOPE_TYPE;
    }

    /**
     * The plugin class a package declares, read from its PSR-4 map.
     *
     * Resolved from the autoload map and never guessed from the package name: several
     * packages have a namespace that no transformation of their directory name produces
     * (`myadmin-powerdns` is `Detain\MyAdminPowerDns`, note the lowercase `ns`), and a
     * guess that misses reports a construction failure against the wrong subject.
     *
     * @param array<string,mixed> $composerJson decoded composer.json
     * @return string|null fully-qualified class name, or null if no prefix maps to `src`
     */
    public static function pluginClassFor(array $composerJson)
    {
        if (!isset($composerJson['autoload']['psr-4']) || !is_array($composerJson['autoload']['psr-4'])) {
            return null;
        }
        foreach ($composerJson['autoload']['psr-4'] as $prefix => $paths) {
            foreach ((array)$paths as $path) {
                if (rtrim((string)$path, '/') === 'src') {
                    return rtrim((string)$prefix, '\\').'\\Plugin';
                }
            }
        }
        return null;
    }

    /**
     * Collapse one inspector's findings into a single cell verdict.
     *
     * ---------------------------------------------------------------------------------
     * PRECEDENCE: fail > skip > not-applicable > pass
     * ---------------------------------------------------------------------------------
     * Failure dominates skip dominates pass: a cell that both failed and skipped is a
     * failure, because the skip is then a detail of how far the inspector got, not a
     * statement that nothing was learned.
     *
     * **Not-applicable sits below skip and requires unanimity.** Two decisions there, and
     * both go the other way from a naive precedence chain:
     *
     *  - *Below skip*, because `[SKIPPED, NOT_APPLICABLE]` is a cell with a coverage hole in
     *    it. One half of the check found nothing of its kind and the other half could not
     *    run; the reader who needs to act is the one chasing the half that could not run, and
     *    `o` would hide it behind the state that means "no action needed". Skip is the loud
     *    state and it wins any tie it is in.
     *  - *Unanimous*, because {@see NOT_APPLICABLE} is a claim about the **whole cell**: this
     *    plugin has nothing of this kind. An inspector that filed four clean observations and
     *    one "no queue templates here" has observed plenty; rendering that cell as vacuous
     *    would understate coverage, which is the mirror image of the overstatement the state
     *    was introduced to fix. Mixed with anything else that is not a skip or a failure, the
     *    cell is a pass, and the not-applicable finding is carried as an annotation exactly
     *    as a notice is.
     *
     * Note that `[]` — the inspectors' pass signal — stays a {@see PASS} and does not become
     * not-applicable. An empty result means "I checked and found nothing wrong"; an inspector
     * that means "there was nothing to check" now has {@see Finding::notApplicable()} and must
     * say so. Silently reinterpreting `[]` here would move the decision back out of the
     * inspector, which is precisely what R-4 exists to stop.
     *
     * {@see Finding::NOTICE} is intentionally not a verdict of its own. A notice is
     * additional detail about a cell that otherwise passed, and promoting it would make
     * the matrix's headline count disagree with the suite's.
     *
     * ---------------------------------------------------------------------------------
     * THIS IS NOT THE PHPUNIT RULE, AND IS NOT MEANT TO BE
     * ---------------------------------------------------------------------------------
     * {@see \MyAdmin\Plugins\Testing\PluginContractTestCase} lands the same findings in one of
     * PHPUnit 9's four outcomes; this method lands them in one of five cell states. The two
     * rules are deliberately allowed to differ, and they do: PHPUnit puts not-applicable in
     * the *skipped* bucket, because it has no fifth bucket to put it in. That collapse is
     * acceptable in one direction only — see that class's docblock for why.
     *
     * @param array<int,string> $severities one {@see Finding} severity per finding
     * @return string one of the verdict constants (never MISSING — that is the absence of a call)
     */
    public static function verdictFor(array $severities)
    {
        if (in_array(Finding::FAILURE, $severities, true)) {
            return self::FAIL;
        }
        if (in_array(Finding::SKIPPED, $severities, true)) {
            return self::SKIP;
        }
        if ($severities !== [] && array_values(array_unique($severities)) === [Finding::NOT_APPLICABLE]) {
            return self::NOT_APPLICABLE;
        }
        return self::PASS;
    }

    /**
     * Per-assertion tallies across the whole fleet.
     *
     * @param array<string,array<string,mixed>> $rows see the class docblock
     * @param array<int,string> $ids catalogue ids, in report order
     * @return array<string,array<string,int>> id => one count per {@see VERDICTS} entry
     */
    public static function census(array $rows, array $ids)
    {
        $census = [];
        foreach ($ids as $id) {
            $census[$id] = array_fill_keys(self::VERDICTS, 0);
            foreach ($rows as $row) {
                $verdict = self::verdictAt($row, $id);
                $census[$id][$verdict]++;
            }
        }
        return $census;
    }

    /**
     * Fleet-wide totals, plus the cell count the run should have produced.
     *
     * `cells` is derived from the fleet size times the catalogue size rather than from
     * the results, so it stays the denominator even when the run is incomplete.
     *
     * @param array<string,array<string,mixed>> $rows see the class docblock
     * @param array<int,string> $ids catalogue ids
     * @return array<string,int>
     */
    public static function totals(array $rows, array $ids)
    {
        $totals = ['cells' => count($rows) * count($ids)] + array_fill_keys(self::VERDICTS, 0);
        foreach (self::census($rows, $ids) as $counts) {
            foreach (self::VERDICTS as $verdict) {
                $totals[$verdict] += $counts[$verdict];
            }
        }
        return $totals;
    }

    /**
     * Failing cells grouped by assertion, with their full evidence.
     *
     * @param array<string,array<string,mixed>> $rows see the class docblock
     * @param array<int,string> $ids catalogue ids
     * @return array<string,array<string,array<int,string>>> id => package => messages
     */
    public static function failuresBy(array $rows, array $ids)
    {
        $out = [];
        foreach ($ids as $id) {
            foreach ($rows as $package => $row) {
                if (self::verdictAt($row, $id) !== self::FAIL) {
                    continue;
                }
                $out[$id][$package] = self::messagesAt($row, $id);
            }
        }
        return $out;
    }

    /**
     * Escape-hatch ledgers, keyed by package, from the raw per-package records.
     *
     * Lives here rather than in the shim's collection loop for one reason: a loop in the shim
     * is unreachable from a unit test, and deleting it would leave the audit reporting "no
     * package overrides a contract default" over a fleet that does. That is the one sentence in
     * the document nobody would think to double-check.
     *
     * @param array<int,array<string,mixed>> $records one decoded child record per package
     * @return array<string,array<int,array<string,mixed>>> package => ledger entries
     */
    public static function collectHatches(array $records)
    {
        $hatches = [];
        foreach ($records as $record) {
            if (!isset($record['package']) || !isset($record['hatches']) || !is_array($record['hatches'])) {
                continue;
            }
            if ($record['hatches'] === []) {
                continue;
            }
            $hatches[(string)$record['package']] = $record['hatches'];
        }
        return $hatches;
    }

    /**
     * Deferral registers, keyed by package, from the raw per-package records.
     *
     * The sibling of {@see collectHatches()}, and here for the same reason: a deferral is an
     * exemption from a shared gate, and an exemption nobody can see is the failure mode the
     * whole deferral mechanism exists to prevent. An escape hatch changes what an assertion
     * asks; a deferral leaves the assertion alone and excuses the package from its answer
     * until a date. Both have to be readable in the artefact gate G2 is reviewed against.
     *
     * The register is read from each package's `composer.json` by the child process — see
     * {@see DeferralRegister} for why the declaration lives there and not in a PHPUnit class
     * this generator can never load. Packages with neither a register nor a complaint about
     * one are dropped, so this stays a record of exemptions rather than a 71-row transcript.
     *
     * @param array<int,array<string,mixed>> $records one decoded child record per package
     * @return array<string,array{deferrals:array<string,array<string,mixed>>,problems:array<int,string>}>
     */
    public static function collectDeferrals(array $records)
    {
        $out = [];
        foreach ($records as $record) {
            if (!isset($record['package'])) {
                continue;
            }
            $deferrals = isset($record['deferrals']) && is_array($record['deferrals'])
                ? $record['deferrals']
                : [];
            $problems = isset($record['deferralProblems']) && is_array($record['deferralProblems'])
                ? array_values(array_map('strval', $record['deferralProblems']))
                : [];
            if ($deferrals === [] && $problems === []) {
                continue;
            }
            $out[(string)$record['package']] = ['deferrals' => $deferrals, 'problems' => $problems];
        }
        return $out;
    }

    /**
     * Package name as the grid column shows it: vendor prefix and the `myadmin-` marker
     * dropped, because 69 rows of `detain/myadmin-` is 16 characters of nothing.
     *
     * @param string $package full composer package name
     * @return string
     */
    public static function shortName($package)
    {
        $short = $package;
        $slash = strrpos($short, '/');
        if ($slash !== false) {
            $short = substr($short, $slash + 1);
        }
        if (strpos($short, 'myadmin-') === 0) {
            $short = substr($short, strlen('myadmin-'));
        }
        return $short;
    }

    /**
     * The whole document.
     *
     * Evidence is emitted in full. The previous snapshot cut each message at a fixed
     * width, which removed the resolved path from every B-10 line — the one part of a
     * dangling-requirement finding that tells you what to fix.
     *
     * @param array<string,array<string,mixed>> $rows see the class docblock
     * @param array<int,string> $ids catalogue ids, in report order
     * @param array<string,mixed> $options 'notes' => array<string,string> id => census note,
     *                                     'excluded' => array<string,string> package => why it is not in the fleet,
     *                                     'hatches' => array<string,array<int,array<string,mixed>>> package => ledger entries,
     *                                     'deferrals' => array<string,array<string,mixed>> package => register + problems,
     *                                     'generator' => string command that reproduces this file
     * @return string markdown
     */
    public static function renderMarkdown(array $rows, array $ids, array $options = [])
    {
        $notes = isset($options['notes']) && is_array($options['notes']) ? $options['notes'] : [];
        $excluded = isset($options['excluded']) && is_array($options['excluded']) ? $options['excluded'] : [];
        $hatches = isset($options['hatches']) && is_array($options['hatches']) ? $options['hatches'] : [];
        $deferrals = isset($options['deferrals']) && is_array($options['deferrals']) ? $options['deferrals'] : [];
        $generator = isset($options['generator']) ? (string)$options['generator'] : 'tools/fleet-matrix.php';

        $out = self::renderHeader($rows, $ids, $generator);
        $out .= self::renderCensus($rows, $ids, $notes);
        $out .= self::renderHatches($hatches);
        $out .= self::renderDeferrals($deferrals, $rows);
        $out .= self::renderExcluded($excluded);
        $out .= self::renderFailures($rows, $ids);
        $out .= self::renderGrid($rows, $ids);
        return $out;
    }

    // -----------------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------------

    /**
     * @param array<string,mixed> $row one package's entry
     * @param string $id catalogue id
     * @return string verdict constant
     */
    private static function verdictAt(array $row, $id)
    {
        if (!isset($row['cells'][$id]['verdict'])) {
            return self::MISSING;
        }
        $verdict = (string)$row['cells'][$id]['verdict'];
        return isset(self::GLYPHS[$verdict]) ? $verdict : self::MISSING;
    }

    /**
     * @param array<string,mixed> $row one package's entry
     * @param string $id catalogue id
     * @return array<int,string>
     */
    private static function messagesAt(array $row, $id)
    {
        if (!isset($row['cells'][$id]['messages']) || !is_array($row['cells'][$id]['messages'])) {
            return [];
        }
        return array_values(array_map('strval', $row['cells'][$id]['messages']));
    }

    /**
     * @param array<string,array<string,mixed>> $rows
     * @param array<int,string> $ids
     * @param string $generator
     * @return string
     */
    private static function renderHeader(array $rows, array $ids, $generator)
    {
        $totals = self::totals($rows, $ids);
        $headline = sprintf(
            '**%d assertions x %d packages = %d cells** — %d pass, %d fail, %d skip, %d not applicable',
            count($ids),
            count($rows),
            $totals['cells'],
            $totals[self::PASS],
            $totals[self::FAIL],
            $totals[self::SKIP],
            $totals[self::NOT_APPLICABLE]
        );
        if ($totals[self::MISSING] > 0) {
            $headline .= sprintf(
                ", **%d NOT RUN — this matrix is incomplete and must not be read as a gate result**",
                $totals[self::MISSING]
            );
        }

        return "# Phase 2 — fleet triage matrix (gate G2)\n"
            ."\n"
            .$headline.".\n"
            ."\n"
            ."Generated — do not hand-edit. Reproduce with:\n"
            ."\n"
            ."```bash\n"
            .$generator."\n"
            ."```\n"
            ."\n"
            ."Every inspector runs over every in-scope plugin, **one process per package**:\n"
            ."constants are immutable and `register_module()` has no inverse, so a shared process\n"
            ."would let plugin *n* contaminate plugin *n+1*. Plugin classes are resolved from each\n"
            ."package's composer PSR-4 map, never guessed from the package name. The fleet is every\n"
            ."package whose composer `type` is `".self::SCOPE_TYPE."`.\n"
            ."\n"
            ."A cell is `pass` when the check ran, observed something and found it clean; `not\n"
            ."applicable` when the check ran and this package has nothing of that kind — no routes,\n"
            ."no `getMenu()`, no queue templates; and `skip` when the check **could not run**. Those\n"
            ."last two were one grey dash until R-4, and all 155 of the dashes meant the harmless\n"
            ."one, so the state that hides bugs was invisible inside the state that does not. A cell\n"
            ."is `missing` when its process produced no verdict at all; that is a broken run, not a\n"
            ."result, and it is counted separately above rather than folded into the denominator.\n"
            ."\n";
    }

    /**
     * The per-assertion tally table.
     *
     * The `n/a` column is unconditional, unlike `not run`. The two absences say opposite
     * things: a table with no `not run` column is reporting the normal case, whereas a table
     * with no `n/a` column would be indistinguishable from one produced by a generator that
     * has never heard of the state — and "no cell was inapplicable" and "inapplicability was
     * never measured" are exactly the pair this document exists to keep apart. Same argument
     * as {@see renderHatches()} makes for printing its section when it is empty.
     *
     * @param array<string,array<string,mixed>> $rows
     * @param array<int,string> $ids
     * @param array<string,string> $notes
     * @return string
     */
    private static function renderCensus(array $rows, array $ids, array $notes)
    {
        $census = self::census($rows, $ids);
        $anyMissing = false;
        foreach ($census as $counts) {
            if ($counts[self::MISSING] > 0) {
                $anyMissing = true;
                break;
            }
        }

        $out = "## Census\n\n";
        $out .= $anyMissing
            ? "| id | pass | fail | skip | n/a | not run | note |\n|---|---|---|---|---|---|---|\n"
            : "| id | pass | fail | skip | n/a | note |\n|---|---|---|---|---|---|\n";
        foreach ($ids as $id) {
            $note = isset($notes[$id]) ? $notes[$id] : '';
            $cols = [
                $id,
                $census[$id][self::PASS],
                $census[$id][self::FAIL],
                $census[$id][self::SKIP],
                $census[$id][self::NOT_APPLICABLE],
            ];
            if ($anyMissing) {
                $cols[] = $census[$id][self::MISSING];
            }
            $cols[] = $note;
            $out .= '| '.implode(' | ', $cols)." |\n";
        }
        return $out."\n";
    }

    /**
     * Every cell reached under a relaxed contract, from
     * {@see \MyAdmin\Plugins\Testing\PluginContractTestCase::overrideLedger()}.
     *
     * This section is rendered even when it is empty, unlike "Excluded packages". Escape-hatch
     * auditability is a G2 checklist item in its own right, so an absent section would be
     * indistinguishable from a run that never looked — and "no evidence of abuse" and "no
     * search for abuse" are the two readings this whole document exists to keep apart.
     *
     * A `pass` row is the one worth reading. A failure under a hatch is still a failure; a pass
     * under a hatch is an assertion that held only because the package changed what it was asked.
     *
     * @param array<string,array<int,array<string,mixed>>> $hatches package => ledger entries
     * @return string
     */
    private static function renderHatches(array $hatches)
    {
        $out = "## Escape hatches\n\n";
        if ($hatches === []) {
            return $out."No package overrides a contract default. Every cell above was measured\n"
                ."against the assertion as written.\n\n";
        }
        ksort($hatches);
        $out .= "Cells reached under a relaxed contract. A `pass` row is an assertion that held\n"
            ."only because the package changed what it was asked.\n\n"
            ."| package | assertion | outcome | override | value |\n|---|---|---|---|---|\n";
        foreach ($hatches as $package => $entries) {
            foreach ($entries as $entry) {
                $overrides = isset($entry['overrides']) && is_array($entry['overrides']) ? $entry['overrides'] : [];
                foreach ($overrides as $name => $value) {
                    $out .= '| '.self::shortName($package)
                        .' | '.(isset($entry['assertion']) ? $entry['assertion'] : '?')
                        .' | '.(isset($entry['outcome']) ? $entry['outcome'] : '?')
                        .' | '.$name
                        .' | `'.self::describeValue($value)."` |\n";
                }
            }
        }
        return $out."\n";
    }

    /**
     * Every assertion a package has declared it is knowingly not fixing yet.
     *
     * Rendered even when empty, for the reason {@see renderHatches()} gives: "no package
     * defers an assertion" and "deferrals were never looked for" are the two readings this
     * document exists to keep apart.
     *
     * **A deferral never moves a cell.** The census and the grid above report the P-bug as the
     * failure it is; this section records who has agreed not to fix it yet, and by when. That
     * separation is deliberate — a mechanism that let a package turn a red cell green would be
     * the silent exemption the mechanism was built to replace.
     *
     * The `state` column is the cross-check, and it is the reason this method takes `$rows`.
     * The repo's own suite enforces expiry and staleness against its own run; this enforces
     * staleness against the *fleet* run, which is the one a reviewer reads. Expiry is
     * deliberately not reported here — see {@see deferralState()} for why a generated,
     * `--check`ed document must not contain a clock-dependent cell:
     *
     *  - `stale`     — the cell is not a failure, so there is nothing left to defer. This is
     *                  the case that matters most, because a deferral whose defect has gone
     *                  away is an assertion nobody is watching any more.
     *  - `malformed` — the register could not be read; it defers nothing while looking as
     *                  though it defers something.
     *  - `active`    — a real, current, time-boxed exemption.
     *
     * @param array<string,array<string,mixed>> $deferrals package => register + problems
     * @param array<string,array<string,mixed>> $rows      see the class docblock
     * @return string
     */
    private static function renderDeferrals(array $deferrals, array $rows)
    {
        $out = "## Deferrals\n\n";
        if ($deferrals === []) {
            return $out."No package defers a catalogue assertion. Every failing cell above is an\n"
                ."open P-bug with nobody's agreement to leave it open.\n\n";
        }

        ksort($deferrals);
        $out .= "Assertions a package has declared it is knowingly not fixing yet, from\n"
            ."`extra.".DeferralRegister::MANIFEST_KEY."` in its own `composer.json`. A deferral does\n"
            ."**not** change a cell above — the P-bug is still counted as a failure. This is the\n"
            ."record of who agreed to leave it open, and until when.\n\n"
            ."| package | assertion | until | cell | state | issue | findings |\n"
            ."|---|---|---|---|---|---|---|\n";
        $problems = [];
        foreach ($deferrals as $package => $entry) {
            $register = isset($entry['deferrals']) && is_array($entry['deferrals']) ? $entry['deferrals'] : [];
            $declaredProblems = isset($entry['problems']) && is_array($entry['problems']) ? $entry['problems'] : [];
            foreach ($declaredProblems as $problem) {
                $problems[] = self::shortName($package).' — '.$problem;
            }
            foreach ($register as $id => $record) {
                $record = is_array($record) ? $record : [];
                $verdict = isset($rows[$package]) ? self::verdictAt($rows[$package], (string)$id) : self::MISSING;
                $out .= '| '.self::shortName($package)
                    .' | '.$id
                    .' | '.(isset($record['until']) && is_scalar($record['until']) ? (string)$record['until'] : '?')
                    .' | '.$verdict
                    .' | '.self::deferralState($record, $verdict, $declaredProblems)
                    .' | '.(isset($record['issue']) && is_scalar($record['issue']) ? (string)$record['issue'] : '?')
                    .' | '.(isset($record['findings']) && is_array($record['findings']) ? count($record['findings']) : 0)
                    ." |\n";
            }
        }
        if ($problems !== []) {
            $out .= "\nMalformed registers — these defer nothing and must be fixed or deleted:\n\n";
            foreach ($problems as $problem) {
                $out .= '- '.$problem."\n";
            }
        }
        return $out."\n";
    }

    /**
     * One deferral's state: a malformed register is not a deferral at all, and a deferral with
     * no failure left to cover is the quiet one worth chasing.
     *
     * Deliberately does NOT report expiry, even though the data is right here. This document is
     * generated and `--check`ed in CI, so any clock-dependent cell would make a deterministic
     * artefact go stale on a date rather than on a change — turning a build red for a reason
     * that has nothing to do with the commit under test. Expiry is enforced where enforcement
     * belongs, in {@see DeferredContractDefects}, which fails the owning package's own suite
     * past `until`. The `until` column is printed next to this one, so a reader can see for
     * themselves that a date has passed; what is dropped is only the *derived* claim, not the
     * fact.
     *
     * @param array<string,mixed> $record
     * @param string              $verdict the cell the fleet run produced
     * @param array<int,string>   $problems the whole package's register problems
     * @return string
     */
    private static function deferralState(array $record, $verdict, array $problems)
    {
        if ($problems !== []) {
            return '**malformed**';
        }
        if (DeferralRegister::expiresAt($record) === null) {
            return '**malformed**';
        }
        return $verdict === self::FAIL ? 'active' : '**stale**';
    }

    /**
     * A hatch value as one table cell. The whole abuse case is *which* directory, so the value
     * is printed rather than summarised — but a constant map has to collapse to something that
     * fits a row.
     *
     * @param mixed $value what the subject held
     * @return string
     */
    private static function describeValue($value)
    {
        if (is_array($value)) {
            $parts = [];
            foreach ($value as $key => $item) {
                $parts[] = $key.'='.(is_scalar($item) ? (string)$item : gettype($item));
            }
            return implode(', ', $parts);
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        return is_scalar($value) ? (string)$value : gettype($value);
    }

    /**
     * @param array<string,string> $excluded
     * @return string
     */
    private static function renderExcluded(array $excluded)
    {
        if ($excluded === []) {
            return '';
        }
        ksort($excluded);
        $out = "## Excluded packages\n\n"
            ."In scope by composer `type`, but not inspectable. Each line is a defect in that\n"
            ."package, not a shrinking of the fleet.\n\n";
        foreach ($excluded as $package => $reason) {
            $out .= '- **'.$package.'** — '.$reason."\n";
        }
        return $out."\n";
    }

    /**
     * @param array<string,array<string,mixed>> $rows
     * @param array<int,string> $ids
     * @return string
     */
    private static function renderFailures(array $rows, array $ids)
    {
        $failures = self::failuresBy($rows, $ids);
        $out = "## Failing cells, classified (all P-bugs — report only, per D7)\n\n";
        if ($failures === []) {
            return $out."No failing cells.\n\n";
        }
        foreach ($failures as $id => $packages) {
            $out .= sprintf("### %s — %d package(s)\n\n", $id, count($packages));
            foreach ($packages as $package => $messages) {
                $out .= '- **'.$package."**\n";
                foreach ($messages as $message) {
                    $out .= '  - '.$message."\n";
                }
            }
            $out .= "\n";
        }
        return $out;
    }

    /**
     * @param array<string,array<string,mixed>> $rows
     * @param array<int,string> $ids
     * @return string
     */
    private static function renderGrid(array $rows, array $ids)
    {
        $out = "## Grid\n\n"
            .'`'.self::GLYPHS[self::PASS].'` pass · `'.self::GLYPHS[self::NOT_APPLICABLE].'` not applicable'
            .' (ran; nothing of this kind here) · `F` fail · `'.self::GLYPHS[self::SKIP].'` skip'
            .' (could not run) · `?` not run'."\n\n"
            .'| package | '.implode(' | ', $ids)." |\n"
            .'|---'.str_repeat('|---', count($ids))."|\n";
        foreach ($rows as $package => $row) {
            $cells = [];
            foreach ($ids as $id) {
                $cells[] = self::GLYPHS[self::verdictAt($row, $id)];
            }
            $out .= '| '.self::shortName($package).' | '.implode(' | ', $cells)." |\n";
        }
        return $out;
    }
}
