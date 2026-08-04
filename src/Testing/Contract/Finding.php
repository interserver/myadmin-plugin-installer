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
 * Severity stays deliberately coarse: three values, each with one consumer-visible meaning.
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
     */
    const SKIPPED = 'skipped';

    /** @var string assertion id from the catalogue, e.g. "A-7" or "B-9" */
    private $assertion;

    /** @var string one of FAILURE / NOTICE / SKIPPED */
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
