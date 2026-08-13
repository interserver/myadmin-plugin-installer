<?php
/**
 * @author Joe Huss <detain@interserver.net>
 * @copyright 2026
 * @package MyAdmin
 * @category Testing
 */

namespace MyAdmin\Plugins\Testing;

/**
 * The shared, mutable record of everything a fake was asked to do.
 *
 * This is a separate object rather than a plain array property for one
 * load-bearing reason: **the core `get_module_db()` hands callers a `clone` of
 * the module database handle**, and PHP's shallow clone copies object property
 * *references*. A fake that recorded into `$this->queries = []` would therefore
 * write into the clone and the test would assert against an empty original —
 * a silent false pass, which is the exact failure class this harness exists to
 * eliminate.
 *
 * Holding the log in an object means every clone keeps writing to the same
 * CallLog, so the recording survives. `Fakes\FakeDbTest::testCloneSharesCallLog`
 * pins this and was verified to fail when the CallLog is swapped for an array.
 *
 * @see \MyAdmin\Plugins\Testing\Recorder
 */
class CallLog
{
    /**
     * Every recorded call, in order.
     *
     * @var array<int,array{method:string,args:array<int,mixed>}>
     */
    private $calls = [];

    /**
     * Records one call.
     *
     * @param string           $method the method or function name
     * @param array<int,mixed> $args   its positional arguments
     * @return int the 1-based sequence number of this call
     */
    public function record($method, array $args = [])
    {
        $this->calls[] = ['method' => $method, 'args' => $args];
        return count($this->calls);
    }

    /**
     * Every call, optionally filtered to one method name.
     *
     * @param string|null $method
     * @return array<int,array{method:string,args:array<int,mixed>}>
     */
    public function calls($method = null)
    {
        if ($method === null) {
            return $this->calls;
        }
        $matches = array_filter($this->calls, static function (array $call) use ($method) {
            return $call['method'] === $method;
        });
        return array_values($matches);
    }

    /**
     * The most recent call, optionally for one method name.
     *
     * @param string|null $method
     * @return array{method:string,args:array<int,mixed>}|null null when there is none
     */
    public function lastCall($method = null)
    {
        $calls = $this->calls($method);
        return $calls === [] ? null : $calls[count($calls) - 1];
    }

    /**
     * How many calls were recorded, optionally for one method name.
     *
     * @param string|null $method
     * @return int
     */
    public function callCount($method = null)
    {
        return count($this->calls($method));
    }

    /**
     * Whether a method was called at least once.
     *
     * @param string $method
     * @return bool
     */
    public function wasCalled($method)
    {
        return $this->callCount($method) > 0;
    }

    /**
     * The argument lists passed to one method, in order.
     *
     * @param string $method
     * @return array<int,array<int,mixed>>
     */
    public function argsFor($method)
    {
        return array_map(static function (array $call) {
            return $call['args'];
        }, $this->calls($method));
    }

    /**
     * Drops every recorded call, leaving the object identity intact so clones
     * that already hold a reference keep sharing it.
     *
     * @return void
     */
    public function reset()
    {
        $this->calls = [];
    }
}
