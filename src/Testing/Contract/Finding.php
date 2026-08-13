<?php
/**
 * @author Joe Huss <detain@interserver.net>
 * @copyright 2026
 * @package MyAdmin
 * @category Testing
 */

namespace MyAdmin\Plugins\Testing\Contract;

/**
 * One thing an inspector observed about a plugin.
 *
 * ---------------------------------------------------------------------------------
 * WHY FINDINGS AND NOT ASSERTIONS
 * ---------------------------------------------------------------------------------
 * The obvious shape for Phase 2 is for each check to call `$this->assertX()` directly.
 * That was rejected because the phase has two consumers, not one:
 *
 *  1. `PluginContractTestCase`, running inside PHPUnit for a single plugin.
 *  2. The Phase 2 self-check, which must run every check against **all 69 plugins** and
 *     emit an assertion x plugin x pass/fail/skip triage matrix (gate G2).
 *
 * PHPUnit assertions throw on the first failure, which makes them useless for the second
 * consumer: one dangling hook in the first plugin would end the run. Inspectors therefore
 * *return* what they observed and let the caller decide whether to throw. The same code
 * backs both consumers, so the matrix cannot drift from what the test case enforces.
 *
 * ---------------------------------------------------------------------------------
 * WHAT `NOTICE` IS, AND WHERE IT SURFACES
 * ---------------------------------------------------------------------------------
 * `NOTICE` is the severity for *"the inspector ran, observed something worth saying, and it
 * is not a contract violation."* Two inspectors emit one today:
 *
 *  - Tier-B-11, for route methods given as the bare string `'GET'` rather than `['GET']`.
 *    FastRoute casts it and core's own router uses that form, so failing it would invent a
 *    rule the router does not have.
 *  - Tier-B-14, for a `*.sh.tpl` that is present but that no literal queue action selects.
 *
 * An earlier revision of this docblock claimed a single call site and claimed notices were
 * "reported". Both were false: there were two call sites, and no consumer looked at the
 * severity at all — a lone notice produced a green PHPUnit run indistinguishable from an
 * inspector that found nothing. That mattered beyond tidiness, because the vocabulary is
 * where an inspector puts *"I saw a problem but another inspector owns the verdict"*: routed
 * through a severity nobody reads, that observation becomes silence, which is strictly worse
 * than the false failure it was meant to replace.
 *
 * Both consumers now read it:
 *
 *  - {@see \MyAdmin\Plugins\Testing\PluginContractTestCase} renders a run whose findings
 *    include a notice as PHPUnit **incomplete** — visible in the default report, its own
 *    outcome bucket, and not a failure. Incomplete rather than skipped because the check did
 *    run; incomplete rather than passed because a green cell must not hide an observation.
 *  - The fleet matrix keeps deriving its cell as `fail > skip > pass`, so a notice never
 *    changes a cell's colour; it is carried alongside as an annotation.
 *
 * ---------------------------------------------------------------------------------
 * WHY `NOT_APPLICABLE` EXISTS, AND WHY IT HAD TO BE A SEVERITY (R-4)
 * ---------------------------------------------------------------------------------
 * Nobody ever wrote down what a *pass* means when there was nothing to check, so each
 * inspector's author decided independently, and they disagreed. `backups-module` registers
 * neither routes nor requirement paths; the identical situation produced two different cells:
 *
 *  - **B-10** returned `[]` and went green — *"vacuously satisfied: the check ran, found no
 *    paths, and all zero of them resolve."*
 *  - **B-11** returned {@see SKIPPED} and went grey — *"plugin registers no routes."*
 *
 * Thirty-two packages were green in one column and grey in the other for the same reason.
 *
 * Both readings are defensible and both are wrong, because they answer a question the
 * vocabulary could not ask. Classifying every skip in the fleet settled it: all 155 of them
 * said *"there is nothing of this kind here"*, and **not one** said *"the check could not
 * run"* — yet those are the two things a grey dash claimed, indistinguishably. "Could not
 * run" is the state that hides bugs, and it was camouflaged by 155 cells that were merely
 * inapplicable.
 *
 * So there is a fourth severity, and it lives **here** rather than in
 * {@see \MyAdmin\Plugins\Testing\FleetMatrix}. Only the inspector knows which of the two it
 * meant. A matrix handed `[SKIPPED]` cannot recover whether the check declined to look or
 * looked and found nothing of the kind — the information is destroyed at the point the
 * finding is constructed, so that is the only place it can be preserved.
 *
 * {@see notApplicable()} and {@see isNotApplicable()} ship together, deliberately. The
 * mechanical reason every consumer used to drop notices on the floor was that
 * {@see isNotice()} did not exist: each consumer asked "failure?", then "skipped?", and let
 * the remainder fall through as nothing at all. A severity without a predicate is a severity
 * that will be forgotten by the next consumer someone writes.
 *
 * ---------------------------------------------------------------------------------
 * SKIP vs NOT-APPLICABLE — THE TEST TO APPLY
 * ---------------------------------------------------------------------------------
 * Ask *"did the inspector reach a verdict about this plugin?"*
 *
 *  - **No** — it wanted to look and could not: an unloadable class, a handler it could not
 *    invoke, a source scan that desynchronised, a question another inspector owns. That is
 *    {@see SKIPPED}, and it is a hole in coverage that somebody should eventually close.
 *  - **Yes, and the answer is "this does not arise here"** — no routes are registered, no
 *    `getMenu()` is declared, no queue templates are rendered. That is {@see NOT_APPLICABLE}.
 *    Nothing is missing; there is simply nothing of this kind in this package.
 *
 * One crisp corollary, worth stating because it is the case most likely to be got wrong: a
 * finding carrying a `blockedBy` context key is **always** a skip. Deferring to another
 * inspector means this one did not reach a verdict, whatever it managed to observe first.
 *
 * Severity stays deliberately coarse: four values, each with one consumer-visible meaning.
 * A follow-up may turn them into a backed enum; that is a type-system migration and is kept
 * out of this change so the vocabulary diff stays reviewable.
 */
class Finding
{
    /** A contract violation. Fails the test and the matrix cell. */
    const FAILURE = 'failure';

    /**
     * An observation that is not a violation. Never fails a build; never silent either —
     * see the class docblock for where it surfaces.
     */
    const NOTICE = 'notice';

    /**
     * The check could not be performed. Distinct from a pass — a skip that reads as a
     * pass is how a matrix quietly overstates coverage.
     *
     * Distinct from {@see NOT_APPLICABLE} too, and that is the more important boundary: a
     * skip is a coverage hole, so the number of them is a number somebody has to work down.
     * See the class docblock for the test that separates the two.
     */
    const SKIPPED = 'skipped';

    /**
     * The check ran and this plugin has nothing of this kind — no routes, no `getMenu()`,
     * no queue templates.
     *
     * Not a pass: a pass claims something was observed and found clean, and a matrix that
     * counts vacuous cells as passes overstates what it has verified. Not a skip either: the
     * inspector did reach a verdict, and calling that "could not run" understates coverage
     * *and* buries the genuine could-not-runs among it.
     */
    const NOT_APPLICABLE = 'not-applicable';

    /** @var string assertion id from the catalogue, e.g. "A-7" or "B-9" */
    private $assertion;

    /** @var string one of FAILURE / NOTICE / SKIPPED / NOT_APPLICABLE */
    private $severity;

    /** @var string human-readable, actionable message */
    private $message;

    /**
     * Structured detail for the matrix (hook key, method name, resolved path, ...).
     *
     * @var array<string,mixed>
     */
    private $context;

    /**
     * @param string              $assertion
     * @param string              $severity
     * @param string              $message
     * @param array<string,mixed> $context
     */
    public function __construct($assertion, $severity, $message, array $context = [])
    {
        $this->assertion = (string)$assertion;
        $this->severity = (string)$severity;
        $this->message = (string)$message;
        $this->context = $context;
    }

    /**
     * @param string              $assertion
     * @param string              $message
     * @param array<string,mixed> $context
     * @return self
     */
    public static function failure($assertion, $message, array $context = [])
    {
        return new self($assertion, self::FAILURE, $message, $context);
    }

    /**
     * @param string              $assertion
     * @param string              $message
     * @param array<string,mixed> $context
     * @return self
     */
    public static function notice($assertion, $message, array $context = [])
    {
        return new self($assertion, self::NOTICE, $message, $context);
    }

    /**
     * @param string              $assertion
     * @param string              $reason
     * @param array<string,mixed> $context
     * @return self
     */
    public static function skipped($assertion, $reason, array $context = [])
    {
        return new self($assertion, self::SKIPPED, $reason, $context);
    }

    /**
     * "The check ran; this plugin has nothing of this kind."
     *
     * The `$reason` is not optional and is not decoration. A cell rendered `o` in the fleet
     * grid carries its message through to the reader exactly as a skip does, and *why*
     * nothing arose is the whole content of the cell — "plugin registers no routes" and
     * "plugin declares no function.requirements handler" are different facts about a package
     * even though they produce the same glyph.
     *
     * @param string              $assertion
     * @param string              $reason  why nothing of this kind arises here
     * @param array<string,mixed> $context
     * @return self
     */
    public static function notApplicable($assertion, $reason, array $context = [])
    {
        return new self($assertion, self::NOT_APPLICABLE, $reason, $context);
    }

    /**
     * @return string
     */
    public function assertion()
    {
        return $this->assertion;
    }

    /**
     * @return string
     */
    public function severity()
    {
        return $this->severity;
    }

    /**
     * @return string
     */
    public function message()
    {
        return $this->message;
    }

    /**
     * @return array<string,mixed>
     */
    public function context()
    {
        return $this->context;
    }

    /**
     * @return bool
     */
    public function isFailure()
    {
        return $this->severity === self::FAILURE;
    }

    /**
     * @return bool
     */
    public function isSkipped()
    {
        return $this->severity === self::SKIPPED;
    }

    /**
     * Companion to {@see isFailure()} and {@see isSkipped()}.
     *
     * Its absence is why notices went unreported: every consumer asked "failure?" then
     * "skipped?" and treated the remaining case as nothing at all. A predicate per severity
     * makes the third case as easy to handle as the other two.
     *
     * @return bool
     */
    public function isNotice()
    {
        return $this->severity === self::NOTICE;
    }

    /**
     * Whether this finding says "there is nothing of this kind here".
     *
     * Shipped in the same commit as {@see NOT_APPLICABLE} and {@see notApplicable()}, for the
     * reason {@see isNotice()} records: a severity with no predicate is a severity every
     * consumer silently drops, because the natural shape of a consumer is a chain of
     * `if ($finding->isX())` and a missing `isX()` puts the value in the `else`.
     *
     * @return bool
     */
    public function isNotApplicable()
    {
        return $this->severity === self::NOT_APPLICABLE;
    }

    /**
     * Single-line form used in PHPUnit failure output and in the matrix.
     *
     * @return string
     */
    public function describe()
    {
        $line = '['.$this->assertion.'] '.$this->message;
        if ($this->context === []) {
            return $line;
        }
        $pairs = [];
        foreach ($this->context as $key => $value) {
            $pairs[] = $key.'='.(is_scalar($value) || $value === null ? var_export($value, true) : gettype($value));
        }
        return $line.' ('.implode(', ', $pairs).')';
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray()
    {
        return [
            'assertion' => $this->assertion,
            'severity' => $this->severity,
            'message' => $this->message,
            'context' => $this->context,
        ];
    }
}
