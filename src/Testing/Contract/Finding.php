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
 * Severity is deliberately coarse. `NOTICE` exists for exactly one documented case in the
 * catalogue — Tier-B-14's "template present but unreachable" direction, which is
 * informational rather than a defect — so that reporting it never fails a build.
 */
class Finding
{
    /** A contract violation. Fails the test and the matrix cell. */
    const FAILURE = 'failure';

    /** Informational. Reported, never fails. */
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
