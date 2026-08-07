<?php
/**
 * @author Joe Huss <detain@interserver.net>
 * @copyright 2026
 * @package MyAdmin
 * @category Testing
 */

namespace MyAdmin\Plugins\Testing;

use MyAdmin\Plugins\Testing\Bootstrap;
use MyAdmin\Plugins\Testing\Contract\Finding;
use MyAdmin\Plugins\Testing\Contract\InspectorRegistry;
use MyAdmin\Plugins\Testing\Contract\PluginSubject;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * Base test case asserting that a plugin honours the MyAdmin plugin contract.
 *
 * A plugin repo subclasses this and names its class. Everything else is optional:
 *
 *     class PluginContractTest extends PluginContractTestCase
 *     {
 *         protected function pluginClass()
 *         {
 *             return \Detain\MyAdminCpanel\Plugin::class;
 *         }
 *     }
 *
 * That yields one test per catalogue assertion. One test per assertion, rather than one
 * test that checks everything, is deliberate: PHPUnit stops a test at its first failed
 * assertion, so a combined test would report the first broken thing and hide the rest.
 * The whole point of the catalogue is to see every violation at once.
 *
 * ---------------------------------------------------------------------------------
 * H-BUG vs P-BUG (decision D7)
 * ---------------------------------------------------------------------------------
 * The two failure streams must never be mixed, because they are fixed by different
 * people on different branches. This class encodes the distinction mechanically:
 *
 *  - An inspector that RETURNS a failure is reporting a **plugin** defect (P-bug).
 *  - An inspector that THROWS has violated its own contract — {@see Contract\PluginInspector}
 *    forbids throwing for a defect it is meant to detect — so that is a **harness**
 *    defect (H-bug), and the failure message says so in as many words.
 *
 * Without that split, a broken inspector reads as 69 broken plugins and the effort dies
 * in a triage swamp.
 *
 * ---------------------------------------------------------------------------------
 * ESCAPE HATCHES, AND WHY DISCLOSURE CANNOT LIVE ONLY ON THE FAILURE PATH
 * ---------------------------------------------------------------------------------
 * The override hooks below exist so a repo with a legitimate deviation does not have to
 * weaken a shared assertion for everyone. Gate G2 requires that every hatch in use is
 * *reviewable*, and states it as "each is logged when used".
 *
 * An earlier revision disclosed them from {@see describeOverrides()} at exactly one call
 * site: `$this->fail()`. That log therefore fired precisely when the hatch had **failed** to
 * hide anything. The case worth auditing is the opposite one — a hatch that successfully
 * silences a real defect — and it produced a green run and no record at all. Disclosure now
 * happens on every outcome:
 *
 *  - **failure** and **harness bug** — named in the message, as before.
 *  - **skip** — named in the `markTestSkipped()` message.
 *  - **incomplete** (a notice was reported) — named in that message.
 *  - **pass** — recorded in {@see overrideLedger()}. There is no PHPUnit channel for
 *    "green, but say something", and turning every hatched repo yellow would push repos
 *    away from the documented hatches and back towards locally weakening shared assertions,
 *    which is the outcome this whole mechanism exists to prevent. So the passing path logs
 *    rather than shouts.
 *
 * The ledger is deliberately **structured**, not a formatted string: the fleet matrix
 * generator has to aggregate hatch use across 69 repos, and a generator that recovers its
 * data by scraping human-readable failure text breaks the first time a message is reworded.
 * Entries hold the plugin, the source, the assertion, the outcome, and the override values.
 * Only runs with at least one active hatch are recorded — "logged when used" means the
 * ledger is the hatch record, not a 1278-row transcript of the fleet.
 *
 * The sentinel matters: returning `null` from {@see requirementRoot()} is a deliberate,
 * logged opt-out of the B-10 path check, which is NOT the same as never having
 * overridden it. {@see NOT_SET} keeps those two apart.
 *
 * ---------------------------------------------------------------------------------
 * HOW A NOTICE RENDERS
 * ---------------------------------------------------------------------------------
 * {@see Finding::NOTICE} means "the inspector ran and observed something that is not a
 * contract violation". It used to be collected by nobody here, so an inspector returning
 * one notice and nothing else produced a green run identical to one that found nothing.
 *
 * A run with any notice is now reported as PHPUnit **incomplete**. That bucket was chosen
 * over the alternatives on purpose:
 *
 *  - *pass* is what was wrong; a green cell must not hide an observation.
 *  - *skipped* would be a false statement — emitting a notice proves the check ran — and
 *    understating coverage is still misreporting it.
 *  - *warning* and *risky* both fail the build under the `failOnWarning="true"` /
 *    `failOnRisky="true"` settings every fleet `phpunit.xml.dist` carries, and a notice must
 *    never fail a build.
 *  - printing is not available at all: `beStrictAboutOutputDuringTests="true"` turns output
 *    from a test into a risky failure.
 *
 * Incomplete is the only remaining bucket that is visible in the default report, distinct
 * from pass, fail and skip, and non-fatal.
 *
 * ---------------------------------------------------------------------------------
 * HOW NOT-APPLICABLE RENDERS — AND THE ONE LOSSY DIRECTION (R-4)
 * ---------------------------------------------------------------------------------
 * {@see Finding::NOT_APPLICABLE} means "the inspector ran and this plugin has nothing of this
 * kind": no routes, no `getMenu()`, no queue templates. It is a fourth *matrix* state. It is
 * **not** a fourth PHPUnit outcome, because PHPUnit 9 has only four and notices already took
 * `incomplete`. It lands in **`skipped`**, alongside genuine could-not-runs.
 *
 * So the matrix can tell those two apart and a PHPUnit reader cannot. That is worth stating
 * plainly rather than leaving for someone to discover from a confusing `S`:
 *
 *  - The message says which it is. A not-applicable run reads "X is not applicable to Y",
 *    a skipped one reads "X could not run against Y", and a run mixing the two says both.
 *    The distinction survives into the text even where the bucket cannot carry it.
 *  - **The collapse is acceptable in one direction only.** Reporting not-applicable as
 *    `skipped` understates coverage: a reader believes a check did not run when it did, and
 *    the correction is to look. Reporting a genuine skip as not-applicable would do the
 *    opposite — tell the reader there is nothing to look at — and that is the misreport this
 *    whole fourth state was introduced to remove. Nothing here may ever collapse that way.
 *  - The G2 artefact is the fleet matrix, not a PHPUnit summary, and it keeps the states
 *    apart. A repo's own run is a pass/fail gate; the matrix is the coverage record.
 *
 * The skip branch's unanimity rule extends to cover both severities together: a case is
 * bucketed `skipped` when *every* finding is a skip or a not-applicable. It is deliberately
 * **not** the matrix rule, which puts a `[skip, not-applicable]` cell in `skip` and requires
 * unanimity for `o`. The two answer different questions — "which of four buckets is this
 * whole test in?" versus "what does this one cell claim?" — and were always allowed to
 * differ; see {@see \MyAdmin\Plugins\Testing\FleetMatrix::verdictFor()}.
 *
 * The contract assertion is recorded *before* the test is marked incomplete. PHPUnit 9
 * routes an incomplete test through the same branch of `TestResult::run()` that suppresses
 * the "did not perform any assertions" check, so today that ordering is belt-and-braces
 * against `failOnRisky="true"` rather than load-bearing. It is kept, and pinned by
 * `PluginContractTestCaseTest::testTheContractAssertionIsRecordedEvenWhenTheRunIsIncomplete`,
 * for the property that does matter: an incomplete run carries exactly the same recorded
 * assertion a green one does, so "this cell was checked" stays true of it.
 *
 * This changes only the PHPUnit bucket. The fleet matrix keeps deriving its cell as
 * `fail > skip > pass`, so no notice moves a cell's colour.
 *
 * ---------------------------------------------------------------------------------
 * PRIMING IS IRREVERSIBLE — WHAT THAT COSTS A REPO
 * ---------------------------------------------------------------------------------
 * {@see primeConstants()} defines real PHP constants, and PHP cannot undefine one. So the
 * first contract test in a repo's process permanently primes it. Two consequences a repo
 * author should know before this class surprises them:
 *
 *  - A test that wants to observe the plugin *unprimed* cannot run in the same process as
 *    these. Put it in a separate test file with `@runInSeparateProcess`, or a separate suite.
 *  - {@see constantOverrides()} is order-sensitive against anything else that primes first:
 *    whoever defines a constant first wins, and this class is usually first. If an override
 *    appears to be ignored, that is why.
 *
 * This is a property of constants, not a defect to be worked around — and it is exactly why
 * the fleet self-check runs one process per plugin rather than sharing one.
 */
abstract class PluginContractTestCase extends TestCase
{
    /**
     * Marker for "this repo did not override the hook", so that an explicit `null`
     * stays distinguishable from a default.
     */
    const NOT_SET = '__myadmin_plugin_contract_not_set__';

    /**
     * Recorded as an override's value when {@see PluginSubject} grew a hatch that
     * {@see overrideValues()} has not been taught to read.
     *
     * The *name* is still logged, because G2 asks for the hatch to be visible and a name is
     * enough for that. The sentinel keeps the gap honest instead of logging a plausible
     * `null`. `PluginContractTestCaseTest::testEveryDeclaredHatchHasAReadableValue()` fails
     * the moment this would be emitted.
     */
    const OVERRIDE_VALUE_UNKNOWN = '__myadmin_plugin_contract_override_value_unknown__';

    /** A ledger entry written by a repo's own PHPUnit run. */
    const SOURCE_PHPUNIT = 'phpunit';

    /** A ledger entry written by the fleet sweep, {@see inspectAll()}. */
    const SOURCE_FLEET = 'fleet';

    /**
     * Every run that had at least one escape hatch active — the G2 hatch record.
     *
     * Static because it has to survive across the eighteen independent PHPUnit test
     * instances of one repo's run, and across the sixty-nine {@see inspectAll()} calls of a
     * fleet sweep, neither of which shares an object.
     *
     * @var array<int,array<string,mixed>>
     */
    private static $overrideLedger = [];

    /**
     * Fully-qualified plugin class under test.
     *
     * @return class-string
     */
    abstract protected function pluginClass();

    /**
     * Constrain `$type` to one exact value, or {@see NOT_SET} to accept any known type.
     *
     * @return string|mixed
     */
    protected function expectedType()
    {
        return self::NOT_SET;
    }

    /**
     * Filesystem root that `getRequirements()` sources must resolve under.
     *
     * Return `null` to opt this repo out of the B-10 path check — a logged escape hatch,
     * not a silent one.
     *
     * @return string|null|mixed
     */
    protected function requirementRoot()
    {
        return self::NOT_SET;
    }

    /**
     * Service-id constants to seed before plugin code runs.
     *
     * @return array<string,int>|mixed
     */
    protected function serviceDefines()
    {
        return self::NOT_SET;
    }

    /**
     * Constants to force before the plugin class is touched, e.g. `PRORATE_BILLING`.
     *
     * @return array<string,mixed>|mixed
     */
    protected function constantOverrides()
    {
        return self::NOT_SET;
    }

    /**
     * One case per catalogue assertion.
     *
     * Yields class names, not inspector instances: providers run before the test body,
     * and constructing 18 inspectors during collection would turn a broken inspector
     * into a collection-time error with no useful attribution.
     *
     * @return array<string,array{0:class-string}>
     */
    public static function contractAssertions()
    {
        $ordered = [];
        foreach (InspectorRegistry::classes() as $class) {
            $inspector = new $class();
            $ordered[] = [$inspector->id(), $inspector->title(), $class];
        }
        // Sorted by catalogue id, not by class name. `classes()` is sorted by class name,
        // which prints "B-10" before "B-9" — the unreadable ordering InspectorRegistry
        // ::compareIds() exists to prevent, in the one place a plugin maintainer reads it.
        usort($ordered, function ($a, $b) {
            return InspectorRegistry::compareIds($a[0], $b[0]);
        });
        $cases = [];
        foreach ($ordered as $row) {
            $cases[$row[0].' '.$row[1]] = [$row[2]];
        }
        return $cases;
    }

    /**
     * Defines the constants the plugin's own code references, before that code is run.
     *
     * Load-bearing, and easy to leave out — the fleet self-check was written without it and
     * produced 20 false failures plus 70 false skips. Inspectors that only READ static
     * properties survive unprimed, because {@see PluginSubject} recovers literals from source;
     * inspectors that EXECUTE plugin code (A-1 constructs it, A-5 calls `getHooks()`) do not,
     * and throw `Error: Undefined constant` on every plugin whose class body references one.
     *
     * Best-effort by design. A failure here is NOT raised directly: if the class cannot be
     * loaded or primed, the inspectors are the right reporters of that — A-1 says
     * "constructing threw" and attributes it correctly. Aborting here would replace an
     * accurate per-assertion verdict with one opaque setup error. The message is returned so
     * it can still be surfaced alongside a failure rather than silently dropped.
     *
     * @return string|null the failure message, or null when priming succeeded
     */
    protected function primeConstants()
    {
        try {
            $options = ['plugin' => $this->pluginClass()];
            $overrides = $this->constantOverrides();
            if ($overrides !== self::NOT_SET && is_array($overrides)) {
                $options['constants'] = $overrides;
            }
            Bootstrap::init($options);
            return null;
        } catch (Throwable $e) {
            return get_class($e).': '.$e->getMessage();
        }
    }

    /**
     * @dataProvider contractAssertions
     * @param class-string $inspectorClass
     * @return void
     */
    public function testPluginSatisfiesContractAssertion($inspectorClass)
    {
        $inspector = new $inspectorClass();
        $subject = $this->contractSubject();
        $primingError = $this->primeConstants();

        try {
            $findings = $inspector->inspect($subject);
        } catch (Throwable $e) {
            self::recordOverrideUse($subject, self::SOURCE_PHPUNIT, $inspector->id(), 'harness-bug');
            $this->fail(
                'HARNESS BUG (H-bug), not a plugin defect: inspector '.$inspector->id().' threw '
                .get_class($e).' — "'.$e->getMessage().'". '
                .'PluginInspector forbids throwing to report a defect; a detected problem must be '
                .'returned as a Finding. Fix the inspector, not '.$subject->pluginClass().'.'
                ."\n".$this->describeOverrides($subject)
            );
        }

        $failures = [];
        $skips = [];
        $notices = [];
        $inapplicable = [];
        foreach ($findings as $finding) {
            if ($finding->isFailure()) {
                $failures[] = $finding->describe();
            } elseif ($finding->isSkipped()) {
                $skips[] = $finding->describe();
            } elseif ($finding->isNotice()) {
                $notices[] = $finding->describe();
            } elseif ($finding->isNotApplicable()) {
                $inapplicable[] = $finding->describe();
            }
        }

        // Recorded before any branch below returns or throws, so the hatch record is
        // complete on the paths that produce no visible message at all — which are exactly
        // the ones a hatch abuse hides behind.
        self::recordOverrideUse($subject, self::SOURCE_PHPUNIT, $inspector->id(), self::outcomeOf($findings));

        if ($failures !== []) {
            $this->fail(
                $inspector->id().' — '.$inspector->title()."\n"
                .$subject->pluginClass()." violates the plugin contract:\n  - "
                .implode("\n  - ", $failures)."\n"
                .$this->describeOverrides($subject)
                .($primingError === null
                    ? ''
                    : "\nNote: constant priming failed (".$primingError.'), so this failure may be a '
                      .'setup problem rather than a plugin defect.')
            );
        }

        // A check that could not run is not a check that passed, and neither is a check that
        // had nothing to look at. Both are bucketed `skipped`, because PHPUnit 9 has four
        // outcomes and `incomplete` is already spoken for; the message says which one this is,
        // and the class docblock records why the collapse is only ever allowed this way round.
        //
        // DELIBERATE: the test is "EVERY finding is a skip or a not-applicable", not "...or a
        // notice". A skip alongside a NOTICE therefore does NOT skip the test. That looks
        // like it buries the skip, and it was changed once on that reasoning and changed back:
        //   - A notice is an OBSERVATION. Emitting one proves the inspector ran and had
        //     something to report, so "could not run" would be a false statement about it.
        //     Understating coverage is still misreporting it.
        //   - Nothing is actually lost. The fleet triage matrix derives each cell straight
        //     from the findings, so a skip is rendered as a skip in the G2 artefact whatever
        //     this method decides. Only the PHPUnit bucket differs. The notice now carries the
        //     skip into the incomplete message below, so the PHPUnit reader does not lose it
        //     either.
        // A not-applicable is admitted to this branch where a notice is not, because it is not
        // an observation about the plugin's behaviour — it is the statement that there was no
        // behaviour of this kind to observe. Grouped with a skip it makes the same claim a
        // skip does: nothing here was verified.
        // Pinned by PluginContractTestCaseTest::testASkipAlongsideANoticeDoesNotSkipTheTest
        // and ::testAnInapplicableRunIsBucketedWithSkipsButSaysSoInItsMessage.
        if (($skips !== [] || $inapplicable !== [])
            && count($skips) + count($inapplicable) === count($findings)) {
            $this->markTestSkipped(
                $this->describeUnverified($inspector, $subject, $skips, $inapplicable)
                ."\n".$this->describeOverrides($subject)
            );
            return;
        }

        // Recorded before markTestIncomplete() below, never after, so an incomplete run
        // carries the same recorded assertion a green one does — "this cell was checked"
        // has to stay true of it. See the class docblock for why that is not merely tidiness.
        $this->assertSame([], $failures, $inspector->id().' reported no contract violations');

        if ($notices !== []) {
            $this->markTestIncomplete(
                $this->describeNotices($inspector, $subject, $notices, $skips, $inapplicable)
            );
        }
    }

    /**
     * The skipped-run message, for a case in which nothing was verified.
     *
     * Two reasons produce that outcome and they are not the same reason, so the sentence says
     * which one applies. PHPUnit's bucket cannot: `S` is `S`. The text is the only channel the
     * distinction has on this side, which is why it is built here rather than inlined at the
     * call site where the next edit would flatten it back into one wording.
     *
     * @param \MyAdmin\Plugins\Testing\Contract\PluginInspector $inspector
     * @param \MyAdmin\Plugins\Testing\Contract\PluginSubject   $subject
     * @param array<int,string>                                 $skips        could-not-run reasons
     * @param array<int,string>                                 $inapplicable nothing-of-this-kind reasons
     * @return string
     */
    protected function describeUnverified($inspector, PluginSubject $subject, array $skips, array $inapplicable)
    {
        if ($inapplicable === []) {
            return $inspector->id().' could not run against '.$subject->pluginClass().': '
                .implode('; ', $skips);
        }
        if ($skips === []) {
            return $inspector->id().' is not applicable to '.$subject->pluginClass().': '
                .implode('; ', $inapplicable)
                .' — the check ran and this plugin has nothing of this kind, which is not the same'
                .' as the check being unable to run. PHPUnit has no bucket of its own for that, so'
                .' it is reported here as skipped; the fleet triage matrix renders it `o`, not `-`.';
        }
        return $inspector->id().' could not run against '.$subject->pluginClass().': '
            .implode('; ', $skips)
            ."\nSeparately, part of this assertion does not apply to this plugin at all: "
            .implode('; ', $inapplicable);
    }

    /**
     * The incomplete-run message: what was observed, what could not run, and why this is
     * not a failure.
     *
     * @param \MyAdmin\Plugins\Testing\Contract\PluginInspector $inspector
     * @param \MyAdmin\Plugins\Testing\Contract\PluginSubject   $subject
     * @param array<int,string>                                 $notices
     * @param array<int,string>                                 $skips
     * @param array<int,string>                                 $inapplicable defaulted so the
     *        signature stays compatible with a repo that overrode this hook before R-4
     * @return string
     */
    protected function describeNotices(
        $inspector,
        PluginSubject $subject,
        array $notices,
        array $skips,
        array $inapplicable = []
    ) {
        $message = $inspector->id().' — '.$inspector->title()."\n"
            .$subject->pluginClass().' satisfies this assertion. Reported as incomplete rather than'
            ." passed so these observations are not lost in a green run — none of them is a contract"
            ." violation and none of them fails the build:\n  - "
            .implode("\n  - ", $notices)."\n";
        if ($skips !== []) {
            $message .= "Part of the check could not run:\n  - ".implode("\n  - ", $skips)."\n";
        }
        // Carried for the same reason the skips are: this branch is the one that runs when a
        // notice keeps the case out of the skipped bucket, and anything not repeated here is
        // reported nowhere a PHPUnit reader will see it.
        if ($inapplicable !== []) {
            $message .= "Part of the check does not apply to this plugin:\n  - "
                .implode("\n  - ", $inapplicable)."\n";
        }
        return $message.$this->describeOverrides($subject);
    }

    /**
     * Builds the subject, passing through only the hooks this repo actually overrode.
     *
     * @return \MyAdmin\Plugins\Testing\Contract\PluginSubject
     */
    protected function contractSubject()
    {
        $options = [];
        foreach ([
            'expectedType' => $this->expectedType(),
            'requirementRoot' => $this->requirementRoot(),
            'serviceDefines' => $this->serviceDefines(),
            'constantOverrides' => $this->constantOverrides(),
        ] as $name => $value) {
            if ($value !== self::NOT_SET) {
                $options[$name] = $value;
            }
        }
        return new PluginSubject($this->pluginClass(), $options);
    }

    /**
     * Names the escape hatches in play **and what they were set to**, so any message shows
     * whether the result was reached under a relaxed contract.
     *
     * The values are not decoration. The B-10 abuse case is entirely about *which* directory
     * `requirementRoot()` names — one value manufactures dangling-path failures for every
     * registered path, another silences real ones — and "requirementRoot is in effect" tells
     * a maintainer nothing they can act on. A reader who has never seen the plan has to be
     * able to judge the hatch from the message alone.
     *
     * @param \MyAdmin\Plugins\Testing\Contract\PluginSubject $subject
     * @return string
     */
    protected function describeOverrides(PluginSubject $subject)
    {
        $used = $subject->overridesInUse();
        if ($used === []) {
            return 'No per-repo overrides are in effect.';
        }
        $values = self::overrideValues($subject);
        $pairs = [];
        foreach ($used as $name) {
            $rendered = self::renderOverrideValue($values[$name]);
            // A null root is not "no root": it is the one hatch that removes an assertion
            // outright, and the reader has to be told which one without going and reading
            // PluginSubject::skipsRequirementCheck().
            if ($name === 'requirementRoot' && $subject->skipsRequirementCheck()) {
                $rendered .= ' (opts out of the B-10 requirement-path check)';
            }
            $pairs[] = $name.'='.$rendered;
        }
        return 'Per-repo overrides in effect (review under gate G2): '.implode(', ', $pairs).'.';
    }

    /**
     * Each active hatch's name mapped to the value the subject actually holds.
     *
     * Read through {@see PluginSubject}'s public accessors rather than from the subclass
     * hooks, so the ledger records what the inspectors were handed — not what the repo meant
     * to hand them. Those two differ whenever a hatch fails to reach the subject, and that
     * difference is the one worth catching.
     *
     * @param \MyAdmin\Plugins\Testing\Contract\PluginSubject $subject
     * @return array<string,mixed>
     */
    protected static function overrideValues(PluginSubject $subject)
    {
        $values = [];
        foreach ($subject->overridesInUse() as $name) {
            if ($name === 'expectedType') {
                $values[$name] = $subject->expectedType();
            } elseif ($name === 'requirementRoot') {
                $values[$name] = $subject->requirementRoot();
            } elseif ($name === 'serviceDefines') {
                $values[$name] = $subject->serviceDefines();
            } elseif ($name === 'constantOverrides') {
                $values[$name] = $subject->constantOverrides();
            } else {
                $values[$name] = self::OVERRIDE_VALUE_UNKNOWN;
            }
        }
        return $values;
    }

    /**
     * One override value as a short, readable string.
     *
     * Arrays are rendered one level deep — `serviceDefines` and `constantOverrides` are flat
     * name/value maps, and printing `array` for them would lose the only part a reviewer
     * cares about.
     *
     * @param mixed $value
     * @return string
     */
    private static function renderOverrideValue($value)
    {
        if ($value === self::OVERRIDE_VALUE_UNKNOWN) {
            return '(value not exposed to the override log)';
        }
        if ($value === null) {
            return 'null';
        }
        if (is_scalar($value)) {
            return var_export($value, true);
        }
        if (!is_array($value)) {
            return gettype($value);
        }
        if ($value === []) {
            return '[]';
        }
        $pairs = [];
        foreach ($value as $key => $item) {
            $pairs[] = $key.'='.(is_scalar($item) || $item === null ? var_export($item, true) : gettype($item));
        }
        return '['.implode(', ', $pairs).']';
    }

    /**
     * Every recorded use of an escape hatch, oldest first.
     *
     * This is the G2 hatch record, and the reason it is an array of arrays rather than a
     * block of text: the fleet matrix generator has to group hatch use by package and by
     * assertion, and a generator that parsed it out of a failure message would break on the
     * next reword. Each entry holds:
     *
     *  - `plugin`    — the class under inspection;
     *  - `source`    — {@see SOURCE_PHPUNIT} for a repo's own run, {@see SOURCE_FLEET} for
     *                  {@see inspectAll()};
     *  - `assertion` — the catalogue id whose run this was;
     *  - `outcome`   — `pass`, `notice`, `skip`, `not-applicable`, `fail` or `harness-bug`.
     *                  `pass` alongside a hatch is the entry a G2 reviewer is looking for: a
     *                  green cell reached under a relaxed contract. `not-applicable` is the
     *                  next most interesting: a hatch that made an assertion stop applying;
     *  - `overrides` — hatch name to the value the subject held.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function overrideLedger()
    {
        return self::$overrideLedger;
    }

    /**
     * Empties the hatch record.
     *
     * For a fleet sweep that wants one package's entries at a time, and for tests. A repo's
     * own PHPUnit run never needs it.
     *
     * @return void
     */
    public static function clearOverrideLedger()
    {
        self::$overrideLedger = [];
    }

    /**
     * Appends one entry, if and only if a hatch was actually in use.
     *
     * @param \MyAdmin\Plugins\Testing\Contract\PluginSubject $subject
     * @param string                                          $source
     * @param string                                          $assertion
     * @param string                                          $outcome
     * @return void
     */
    protected static function recordOverrideUse(PluginSubject $subject, $source, $assertion, $outcome)
    {
        $overrides = self::overrideValues($subject);
        if ($overrides === []) {
            return;
        }
        self::$overrideLedger[] = [
            'plugin' => $subject->pluginClass(),
            'source' => (string)$source,
            'assertion' => (string)$assertion,
            'outcome' => (string)$outcome,
            'overrides' => $overrides,
        ];
    }

    /**
     * The verdict a set of findings produces, in the same precedence the test body uses.
     *
     * Shared so the ledger cannot drift from the PHPUnit bucket it claims to describe.
     * Note this is **not** the matrix cell rule: the matrix collapses `notice` into `pass`,
     * reports `[skip, notice]` as a skip and requires unanimity before it will call a cell
     * not-applicable, all deliberately, because a cell has five states and a PHPUnit run has
     * four outcomes.
     *
     * `not-applicable` is reported here as its own outcome even though the PHPUnit bucket it
     * lands in is `skipped`. The ledger is read by a G2 reviewer asking "what did this escape
     * hatch buy the package?", and "the assertion did not apply" and "the assertion could not
     * be evaluated" are different answers to that question. Flattening them into `skip` here
     * would throw away, in the audit record, exactly the distinction R-4 added.
     *
     * @param array<int,\MyAdmin\Plugins\Testing\Contract\Finding> $findings
     * @return string one of pass / notice / skip / not-applicable / fail
     */
    protected static function outcomeOf(array $findings)
    {
        $skips = 0;
        $notices = 0;
        $inapplicable = 0;
        foreach ($findings as $finding) {
            if ($finding->isFailure()) {
                return 'fail';
            }
            if ($finding->isSkipped()) {
                $skips++;
            } elseif ($finding->isNotice()) {
                $notices++;
            } elseif ($finding->isNotApplicable()) {
                $inapplicable++;
            }
        }
        if ($skips + $inapplicable > 0 && $skips + $inapplicable === count($findings)) {
            return $skips > 0 ? 'skip' : 'not-applicable';
        }
        return $notices > 0 ? 'notice' : 'pass';
    }

    /**
     * Every finding for this plugin, keyed by assertion id — the row this plugin
     * contributes to the fleet triage matrix.
     *
     * Exposed so the Phase 2 self-check and the PHPUnit path share one code path. If the
     * matrix were built by re-implementing the loop above, the two could disagree, and the
     * matrix is the artefact gate G2 is reviewed against.
     *
     * Also writes the G2 hatch record for this subject into {@see overrideLedger()} — one
     * entry per assertion, and only when a hatch is actually in use. Gate G2 asks for every
     * escape hatch to be logged when used, and this is the path that produces the artefact
     * the gate is reviewed against; before it recorded anything, the fleet sweep was
     * structurally incapable of satisfying the requirement it was meant to evidence.
     *
     * The keys of the returned array stay exactly the catalogue ids. The hatch record is a
     * separate channel on purpose: an extra row here would become an extra matrix cell and
     * quietly change the 18 x 71 census the gate is read against.
     *
     * @param \MyAdmin\Plugins\Testing\Contract\PluginSubject $subject
     * @return array<string,array<int,\MyAdmin\Plugins\Testing\Contract\Finding>>
     */
    public static function inspectAll(PluginSubject $subject)
    {
        $rows = [];
        foreach (InspectorRegistry::all() as $inspector) {
            $outcome = null;
            try {
                // Primed inside the per-inspector try, not once before the loop: Bootstrap::init()
                // throwing outside this catch would abort the whole 69-plugin matrix and pin the
                // blame on whichever plugin happened to be in flight — the exact failure this
                // catch exists to prevent. Defining a constant twice is a no-op, so repeating it
                // per inspector costs nothing.
                Bootstrap::init(['plugin' => $subject->pluginClass()] + (
                    $subject->constantOverrides() === []
                        ? []
                        : ['constants' => $subject->constantOverrides()]
                ));
                $rows[$inspector->id()] = $inspector->inspect($subject);
                $outcome = self::outcomeOf($rows[$inspector->id()]);
            } catch (Throwable $e) {
                $rows[$inspector->id()] = [
                    Finding::failure(
                        $inspector->id(),
                        'HARNESS BUG (H-bug): inspector threw '.get_class($e).' — '.$e->getMessage(),
                        ['harnessBug' => true, 'exception' => get_class($e)]
                    ),
                ];
                $outcome = 'harness-bug';
            }
            self::recordOverrideUse($subject, self::SOURCE_FLEET, $inspector->id(), $outcome);
        }
        return $rows;
    }
}
