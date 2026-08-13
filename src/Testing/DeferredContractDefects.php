<?php
/**
 * @author Joe Huss <detain@interserver.net>
 * @copyright 2026
 * @package MyAdmin
 * @category Testing
 */

namespace MyAdmin\Plugins\Testing;

use MyAdmin\Plugins\Testing\Contract\Finding;
use Throwable;

/**
 * Turns a catalogue assertion that a package is knowingly **not fixing yet** into a
 * *recorded, time-boxed, fingerprinted* skip instead of a red build or — far worse — a
 * locally weakened assertion.
 *
 * A repo opts in with one line in its {@see PluginContractTestCase} subclass:
 *
 *     use \MyAdmin\Plugins\Testing\DeferredContractDefects;
 *
 * and declares the deferral itself as data in its own `composer.json`; see
 * {@see DeferralRegister} for the shape and for why the declaration lives there rather than
 * in PHP. Nothing else is per-repo. This file is the mechanism, once, for all 69 packages.
 *
 * ---------------------------------------------------------------------------------
 * THIS IS NOT A MUTE BUTTON, AND IT CANNOT BECOME ONE
 * ---------------------------------------------------------------------------------
 * Five guards, all of which have been mutation-verified — each is checked by a test that
 * fails when the guard is removed. They are listed in the order the test body applies them:
 *
 *  0. **Well-formedness.** {@see testDeferralRegisterIsWellFormed()} fails on a register
 *     naming an assertion the catalogue does not have, a date that does not parse, an empty
 *     fingerprint list, or a stray key. An unreadable register defers nothing while looking
 *     like it defers something, which is the worst of the available states.
 *  1. **Expiry.** Past `until`, the test fails outright rather than skipping. A time-boxed
 *     skip that never expires is an unrecorded permanent exemption.
 *  2. **Staleness.** Zero failures means the deferral has outlived its defect and the entry
 *     must be deleted. *However that came about* — including when the assertion stopped
 *     failing by becoming a skip or a not-applicable rather than a pass, which is precisely
 *     what deleting the offending handler outright would do. The version this replaced
 *     reached its staleness check only when the parent returned normally, so both of those
 *     bypassed it and left a green suite with a dead deferral behind.
 *  3. **Exact failure count.** More failures than fingerprints means something else is broken
 *     and the deferral does not cover it.
 *  4. **No non-failure finding alongside.** A fifth defect that surfaces as a *skip* or a
 *     *notice* would leave the failure count at four and ride along inside a green skip still
 *     claiming "4 known defects". This is not hypothetical: the package-relative arm of
 *     {@see Contract\TierB10RequirementPathsResolve} is the **default** for a plugin repo —
 *     0 of 71 ship an `include/` directory — and it answers a cross-package requirement path
 *     with a skip.
 *  5. **Every fingerprint still present.** A defect that changes shape is no longer the
 *     defect that was agreed, so the deferral stops describing it and stops covering it.
 *
 * ---------------------------------------------------------------------------------
 * FINDINGS ARE READ AS OBJECTS
 * ---------------------------------------------------------------------------------
 * The inspector is run directly and its {@see Finding} objects are examined. Nothing here
 * parses rendered failure text. An earlier text-scraping version could only see failures —
 * because the renderer only renders failures — which is what let guard 4 be bypassed, and it
 * turned a repo red with a wrong diagnosis the first time the harness reworded a bullet.
 * {@see PluginContractTestCase} documents against exactly that.
 *
 * ---------------------------------------------------------------------------------
 * DISCLOSURE
 * ---------------------------------------------------------------------------------
 * A deferral is an exemption from a shared gate, so it is disclosed twice and neither
 * disclosure depends on the run failing:
 *
 *  - in the skipped test's own message, which names the assertion, the deadline, the issue
 *    and every fingerprint;
 *  - in the fleet triage matrix's **Deferrals** section, rendered from the same
 *    `composer.json` block by {@see FleetMatrix::collectDeferrals()}, and cross-checked there
 *    against the cell the fleet run actually produced. A deferral never changes a matrix
 *    cell: `docs/phase2-triage-matrix.md` keeps reporting the P-bug as a failure, and the
 *    Deferrals section says who has agreed not to fix it yet and by when.
 *
 * That second one is why the declaration is data rather than a method — see
 * {@see DeferralRegister}.
 */
trait DeferredContractDefects
{
    /**
     * Catalogue id => defect record, as declared by the package under test.
     *
     * Not intended as an override point: the fleet matrix reads the same declaration through
     * {@see DeferralRegister}, and a repo that answered here instead would be exempting
     * itself in its own suite while the fleet document reported nothing. Overridden only by
     * this package's own tests, which drive the register from a scratch package directory.
     *
     * @return array<string,array<string,mixed>>
     */
    protected function deferredContractDefects()
    {
        return DeferralRegister::forSubject($this->contractSubject());
    }

    /**
     * Guard 0. A register that cannot be read defers nothing, and looks like it does.
     *
     * Its own test rather than a check folded into every data row: it is one fact about the
     * package, it is worth reporting once with the whole list of problems, and a repo that
     * declares no deferrals still runs it — so the day it declares one, the validation is
     * already in place.
     *
     * @return void
     */
    public function testDeferralRegisterIsWellFormed()
    {
        $problems = DeferralRegister::problemsForSubject($this->contractSubject());

        $this->assertSame(
            [],
            $problems,
            'The deferred-contract-defect register in this package\'s composer.json '
            .'(extra.'.DeferralRegister::MANIFEST_KEY.') is malformed:'."\n  - "
            .implode("\n  - ", $problems)."\n"
            .'A malformed register silently defers nothing while reading as though it defers '
            .'something, so it is a build failure rather than an ignored block.'
        );
    }

    /**
     * @dataProvider contractAssertions
     * @param class-string $inspectorClass
     * @return void
     */
    public function testPluginSatisfiesContractAssertion($inspectorClass)
    {
        $inspector = new $inspectorClass();
        $id = $inspector->id();
        $deferred = $this->deferredContractDefects();

        if (!isset($deferred[$id])) {
            parent::testPluginSatisfiesContractAssertion($inspectorClass);
            return;
        }

        $entry = $deferred[$id];

        // Guard 0, applied locally as well as by testDeferralRegisterIsWellFormed(): the
        // guards below index into `until`, `issue` and `findings`, and a malformed entry must
        // fail here rather than reach them and be judged on whatever it happened to contain.
        $entryProblems = DeferralRegister::problems([$id => $entry]);
        $this->assertSame(
            [],
            $entryProblems,
            $id.' is deferred, but its record is malformed:'."\n  - ".implode("\n  - ", $entryProblems)
        );

        $this->assertDeferralHasNotExpired($id, $entry);

        // Same order the parent uses: build the subject, then prime, then inspect. Priming
        // defines the plugin's bare constants, and an inspector run against an unprimed
        // process reports a different set of findings from the one the deferral was agreed
        // against — which would make guards 3 to 5 fire on a harness artefact.
        $subject = $this->contractSubject();
        $this->primeConstants();

        try {
            $findings = $inspector->inspect($subject);
        } catch (Throwable $e) {
            // D7: an inspector that throws has violated its own contract. Reporting that as a
            // stale deferral would blame the package for a harness defect, and reporting it as
            // a skip would hide it entirely.
            $this->fail(
                'HARNESS BUG (H-bug), not a plugin defect: inspector '.$id.' threw '
                .get_class($e).' — "'.$e->getMessage().'" while '.$id.' was deferred for '
                .$subject->pluginClass().'. Fix the inspector; the deferral is not the problem.'
            );
        }

        $failures = [];
        $others = [];
        foreach ($findings as $finding) {
            if ($finding->isFailure()) {
                $failures[] = $finding->message();
            } else {
                $others[] = $finding->severity().': '.$finding->message();
            }
        }

        $this->assertDeferralIsNotStale($id, $entry, $failures, $others);
        $this->assertDeferralStillDescribes($id, $entry, $failures, $others);

        $this->markTestSkipped($this->describeDeferral($id, $entry, $subject));
    }

    // -----------------------------------------------------------------------
    // Guards
    // -----------------------------------------------------------------------

    /**
     * Guard 1. The date is already known to parse — guard 0 ran first.
     *
     * @param string              $id
     * @param array<string,mixed> $entry
     * @return void
     */
    private function assertDeferralHasNotExpired($id, array $entry)
    {
        $expiry = (int)DeferralRegister::expiresAt($entry);
        $this->assertLessThanOrEqual(
            $expiry,
            time(),
            $id.' was deferred until '.$entry['until'].' pending '.$entry['issue']
            .'. That date has passed: fix the plugin defect or re-agree the deferral. A'
            .' time-boxed skip that never expires is an unrecorded permanent exemption.'
        );
    }

    /**
     * Guard 2 — the one the earlier revision could reach only on the parent's happy path.
     *
     * `$others` is quoted in the message rather than merely counted because the interesting
     * stale case is not "the plugin was fixed" but "the assertion stopped failing for some
     * other reason": deleting the handler makes B-10 report *not applicable*, and a reader
     * told only "no failures" would go looking for a fix that never happened.
     *
     * @param string              $id
     * @param array<string,mixed> $entry
     * @param array<int,string>   $failures
     * @param array<int,string>   $others   non-failure findings
     * @return void
     */
    private function assertDeferralIsNotStale($id, array $entry, array $failures, array $others)
    {
        $this->assertNotSame(
            [],
            $failures,
            $id.' is deferred in this package\'s composer.json but reports no failures at all.'
            .' The deferral is stale — delete the entry. This fires however the failures went'
            .' away: the defect being fixed, the assertion becoming a skip, or the handler being'
            .' deleted so the assertion no longer applies. A register that outlives its defect'
            .' stops being a record and starts being a blind spot.'
            .($others === [] ? '' : "\nWhat the assertion reports instead:\n  - ".implode("\n  - ", $others))
        );
    }

    /**
     * Guards 3, 4 and 5.
     *
     * All three matter, and they fail on different mutations. The count catches a fifth
     * failure; the `$others` check catches a fifth finding that is *not* a failure, which the
     * count alone cannot see because the parent renders failures only; the fingerprint loop
     * catches a defect that changed shape and is therefore no longer the one that was agreed.
     *
     * @param string              $id
     * @param array<string,mixed> $entry
     * @param array<int,string>   $failures
     * @param array<int,string>   $others   non-failure findings
     * @return void
     */
    private function assertDeferralStillDescribes($id, array $entry, array $failures, array $others)
    {
        $fingerprints = array_values($entry['findings']);
        $report = "\nfailures:\n  ".implode("\n  ", $failures)
            .($others === [] ? '' : "\nother findings:\n  ".implode("\n  ", $others));

        $this->assertCount(
            count($fingerprints),
            $failures,
            $id.' reported '.count($failures).' failure(s) but '.count($fingerprints)
            .' are deferred. The deferral covers only the defects it names; something else is'
            .' broken here.'.$report
        );
        $this->assertSame(
            [],
            $others,
            $id.' reported '.count($others).' finding(s) that are not failures alongside the'
            .' deferred ones. A skip, a notice or a not-applicable is not covered by this'
            .' deferral and must not be hidden by it — the count check alone would not have'
            .' seen it, because a skip is not a failure and the failure count stays right.'
            .$report
        );
        foreach ($fingerprints as $needle) {
            $matched = false;
            foreach ($failures as $message) {
                if (strpos($message, (string)$needle) !== false) {
                    $matched = true;
                    break;
                }
            }
            $this->assertTrue(
                $matched,
                $id.' no longer reports the deferred finding "'.$needle.'". The defect changed'
                .' shape, so the deferral no longer describes it.'.$report
            );
        }
    }

    // -----------------------------------------------------------------------
    // Disclosure
    // -----------------------------------------------------------------------

    /**
     * The skipped run's message: what was deferred, until when, why, and exactly which
     * failures it covers.
     *
     * @param string                                          $id
     * @param array<string,mixed>                             $entry
     * @param \MyAdmin\Plugins\Testing\Contract\PluginSubject $subject
     * @return string
     */
    private function describeDeferral($id, array $entry, $subject)
    {
        return DeferralRegister::describe($id, $entry).' for '.$subject->pluginClass().".\n"
            .'This is a P-bug in the plugin, not a harness defect. It is recorded rather than'
            .' fixed here, is time-boxed, and is disclosed in the fleet triage matrix'
            ." (Deferrals) as well as here.\nDeferred findings:\n  - "
            .implode("\n  - ", array_values($entry['findings']))."\n"
            .$this->describeOverrides($subject);
    }
}
