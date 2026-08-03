<?php
/**
 * @author Joe Huss <detain@interserver.net>
 * @copyright 2026
 * @package MyAdmin
 * @category Testing
 */

namespace MyAdmin\Plugins\Testing\Fakes;

use MyAdmin\Plugins\Testing\CallLog;
use MyAdmin\Plugins\Testing\Recorder;

/**
 * Stand-in for the event dispatcher behind `App::events()` / `App::dispatch()`
 * and the global `run_event()` — 56 fleet references to the latter.
 *
 * Records dispatches rather than routing them. A plugin under test is the
 * subject, not the audience: actually dispatching would pull in whatever other
 * listeners happen to be registered and make the test non-deterministic.
 */
class FakeEvents
{
    use Recorder;

    /**
     * @param \MyAdmin\Plugins\Testing\CallLog|null $log
     */
    public function __construct(?CallLog $log = null)
    {
        $this->initRecorder($log);
    }

    /**
     * Symfony's dispatcher signature is `dispatch(object $event, string $eventName = null)`.
     *
     * @param object|string $event
     * @param string|null   $eventName
     * @return object|string the event, unchanged, as Symfony does
     */
    public function dispatch($event, $eventName = null)
    {
        $this->record(__FUNCTION__, [$event, $eventName]);
        return $event;
    }

    /**
     * Mirrors the global `run_event()` shape.
     *
     * @param string $event
     * @param mixed  $args
     * @param string $module
     * @param mixed  $section
     * @return mixed
     */
    public function runEvent($event, $args = false, $module = 'default', $section = false)
    {
        $this->record(__FUNCTION__, [$event, $args, $module, $section]);
        return $args;
    }

    /**
     * @param string   $eventName
     * @param callable $listener
     * @param int      $priority
     * @return void
     */
    public function addListener($eventName, $listener, $priority = 0)
    {
        $this->record(__FUNCTION__, [$eventName, $listener, $priority]);
    }

    /**
     * Names of every event dispatched, in order.
     *
     * @return array<int,string>
     */
    public function dispatched()
    {
        $names = [];
        foreach ($this->calls() as $call) {
            if ($call['method'] === 'dispatch') {
                $names[] = (string)(isset($call['args'][1]) && $call['args'][1] !== null
                    ? $call['args'][1]
                    : (is_object($call['args'][0]) ? get_class($call['args'][0]) : $call['args'][0]));
            } elseif ($call['method'] === 'runEvent') {
                $names[] = (string)$call['args'][0];
            }
        }
        return $names;
    }

    /**
     * @param string $eventName
     * @return bool
     */
    public function wasDispatched($eventName)
    {
        return in_array($eventName, $this->dispatched(), true);
    }

    /**
     * @return void
     */
    public function reset()
    {
        $this->resetCalls();
    }
}
