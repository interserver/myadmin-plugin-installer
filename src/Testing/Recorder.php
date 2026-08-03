<?php
/**
 * @author Joe Huss <detain@interserver.net>
 * @copyright 2026
 * @package MyAdmin
 * @category Testing
 */

namespace MyAdmin\Plugins\Testing;

/**
 * Gives a fake the "record every call, expose a reader" behaviour that D5 of
 * the harness plan requires. A no-op stub makes a handler *run*; a recording
 * fake makes it *assertable*, which is the whole point.
 *
 * Implementers MUST call {@see Recorder::initRecorder()} from their constructor.
 * The CallLog is deliberately **not** lazily created on first use: a fake that
 * is cloned before its first recorded call would otherwise have a null log in
 * both copies, each would create its own, and the two would silently diverge.
 * Eager construction means the clone always shares the original's log.
 */
trait Recorder
{
    /**
     * Shared with every clone of this fake. See {@see CallLog}.
     *
     * @var \MyAdmin\Plugins\Testing\CallLog|null
     */
    protected $callLog;

    /**
     * Installs the call log. Call this from the constructor of every fake.
     *
     * @param \MyAdmin\Plugins\Testing\CallLog|null $log an existing log to share, or null for a fresh one
     * @return void
     */
    protected function initRecorder(?CallLog $log = null)
    {
        $this->callLog = $log ?: new CallLog();
    }

    /**
     * The underlying log. Falls back to creating one so a fake that forgot to
     * call initRecorder() still works — but see the trait docblock for why
     * that fallback must never be relied on.
     *
     * @return \MyAdmin\Plugins\Testing\CallLog
     */
    public function callLog()
    {
        if ($this->callLog === null) {
            $this->callLog = new CallLog();
        }
        return $this->callLog;
    }

    /**
     * @param string           $method
     * @param array<int,mixed> $args
     * @return int
     */
    protected function record($method, array $args = [])
    {
        return $this->callLog()->record($method, $args);
    }

    /**
     * @param string|null $method
     * @return array<int,array{method:string,args:array<int,mixed>}>
     */
    public function calls($method = null)
    {
        return $this->callLog()->calls($method);
    }

    /**
     * @param string|null $method
     * @return array{method:string,args:array<int,mixed>}|null
     */
    public function lastCall($method = null)
    {
        return $this->callLog()->lastCall($method);
    }

    /**
     * @param string|null $method
     * @return int
     */
    public function callCount($method = null)
    {
        return $this->callLog()->callCount($method);
    }

    /**
     * @param string $method
     * @return bool
     */
    public function wasCalled($method)
    {
        return $this->callLog()->wasCalled($method);
    }

    /**
     * @param string $method
     * @return array<int,array<int,mixed>>
     */
    public function argsFor($method)
    {
        return $this->callLog()->argsFor($method);
    }

    /**
     * Clears recorded calls without breaking log sharing with existing clones.
     *
     * @return void
     */
    public function resetCalls()
    {
        $this->callLog()->reset();
    }
}
