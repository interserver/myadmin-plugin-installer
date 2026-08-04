<?php
/**
 * @author Joe Huss <detain@interserver.net>
 * @copyright 2026
 * @package MyAdmin
 * @category Testing
 */

namespace MyAdmin\Plugins\Testing\Fakes;

use ArrayAccess;
use ArrayIterator;
use Countable;
use IteratorAggregate;
use MyAdmin\Plugins\Testing\CallLog;
use MyAdmin\Plugins\Testing\Recorder;

/**
 * The object a service-lifecycle handler gets back from `$event->getSubject()`.
 *
 * ---------------------------------------------------------------------------------
 * WHY THIS IS PERMISSIVE RATHER THAN A FAITHFUL DOUBLE
 * ---------------------------------------------------------------------------------
 * There is no single class to be faithful to. The fleet's lifecycle handlers pass two
 * completely different things through the same `getSubject()` call, and which one arrives
 * depends on the hook, not on the plugin:
 *
 *  - an **ORM service object** — `$serviceClass->getId()`, `->getIp()`, `->getCustid()`,
 *    `->getUsername()`, `->setKey(...)->save()`, `->set_ip(...)`. Those classes live in
 *    MyAdmin core (`include/Orm/`), which no plugin's vendor tree contains;
 *  - a **plain service-info array row** — `$serviceInfo['action']`,
 *    `$serviceInfo[$settings['PREFIX'].'_id']`, which is what the `*.queue` hooks receive.
 *
 * `myadmin-quickservers-module::getQueue()` uses the array shape; `myadmin-kvm-vps
 * ::getDeactivate()` uses the object shape; both are reached from the same test case. A
 * double that committed to one shape would produce a `TypeError` on half the fleet and the
 * harness would be reporting its own narrowness as a plugin defect — the D7 H-bug pattern.
 *
 * So this answers **both** shapes and answers everything. That is a deliberate trade, and
 * the cost is real and worth stating: this double can never fail a handler for calling a
 * method that does not exist. It is not a contract check on the ORM surface, and it must
 * not be mistaken for one. Its whole job is to stay out of the way so that the *handler's*
 * behaviour — did it act, did it stop propagation — is what the assertion observes.
 *
 * ---------------------------------------------------------------------------------
 * WHY IT RECORDS
 * ---------------------------------------------------------------------------------
 * Reaching into the subject at all is the most common observable thing a lifecycle handler
 * does before it touches anything else: `myadmin-zonemta-mail::getReactivate()` calls
 * `getUsername()` twice and nothing else the harness can see. Without a record of those
 * calls, that handler looks completely inert and assertion A would report a defect that is
 * not there.
 *
 * It therefore uses the same {@see Recorder} trait every other fake does, so
 * `calls()` answers the same way and the "no side effects" sweep can treat it as one more
 * recorder rather than a special case.
 *
 * ---------------------------------------------------------------------------------
 * THE RETURN VALUES ARE TYPED, NOT UNIFORM
 * ---------------------------------------------------------------------------------
 * `__call()` does not return `$this` for everything. `getId()` returning a chainable object
 * would be concatenated into a log message and produce an "Object could not be converted to
 * string" error — the harness failing, attributed to the plugin. The getters that the fleet
 * actually interpolates into strings return scalars of the right shape; everything else
 * returns `$this` so that `->setKey(...)->save()` chains work.
 */
class FakeServiceClass implements ArrayAccess, Countable, IteratorAggregate
{
    use Recorder;

    /**
     * Scalar answers for the getters the fleet interpolates into strings or compares.
     *
     * Keyed by the lower-cased name with `get` stripped, so `getId()` and `get_id()` both
     * land on `id`.
     *
     * @var array<string,mixed>
     */
    const DEFAULT_SCALARS = [
        'id'       => 4242,
        'custid'   => 777,
        'ip'       => '10.0.0.1',
        'username' => 'stubuser',
        'password' => 'StubPass1!',
        'hostname' => 'stub.example.com',
        'domain'   => 'stub.example.com',
        'key'      => '',
        'extra'    => '',
        'status'   => 'active',
        'type'     => 0,
        'server'   => 'stub-server',
        'email'    => 'stub@example.com',
    ];

    /**
     * Array-shaped service info, for the handlers that treat the subject as a row.
     *
     * @var array<string,mixed>
     */
    private $data = [];

    /**
     * Scalar getter answers, {@see DEFAULT_SCALARS} merged with per-test values.
     *
     * @var array<string,mixed>
     */
    private $scalars = [];

    /**
     * @param array<string,mixed>                   $data    row-shaped fields
     * @param array<string,mixed>                   $scalars getter overrides
     * @param \MyAdmin\Plugins\Testing\CallLog|null  $log
     */
    public function __construct(array $data = [], array $scalars = [], ?CallLog $log = null)
    {
        $this->initRecorder($log);
        $this->data = $data;
        $this->scalars = array_merge(self::DEFAULT_SCALARS, $scalars);
    }

    /**
     * Answers every method a handler calls on the subject.
     *
     * @param string           $name
     * @param array<int,mixed> $args
     * @return mixed a scalar for a known getter, `$this` for everything else
     */
    public function __call($name, array $args)
    {
        $this->record($name, $args);

        $normalised = strtolower(str_replace('_', '', $name));
        if (strpos($normalised, 'get') === 0) {
            $field = substr($normalised, 3);
            if (array_key_exists($field, $this->scalars)) {
                return $this->scalars[$field];
            }
            // An unmodelled getter still has to return something a handler can
            // concatenate. A string naming itself is the most debuggable choice: it shows
            // up verbatim in a log assertion and says where it came from.
            return '__stub_' . $field . '__';
        }
        return $this;
    }

    /**
     * @param string $name
     * @return mixed
     */
    public function __get($name)
    {
        $this->record('__get', [$name]);
        return array_key_exists($name, $this->data) ? $this->data[$name] : '__stub_' . $name . '__';
    }

    /**
     * @param string $name
     * @param mixed  $value
     * @return void
     */
    public function __set($name, $value)
    {
        $this->record('__set', [$name, $value]);
        $this->data[$name] = $value;
    }

    /**
     * Deliberately always true. A handler guarding with `isset($service->foo)` should take
     * the populated branch, which is the branch worth exercising.
     *
     * @param string $name
     * @return bool
     */
    public function __isset($name)
    {
        return true;
    }

    /**
     * @param mixed $offset
     * @return bool
     */
    #[\ReturnTypeWillChange]
    public function offsetExists($offset)
    {
        return true;
    }

    /**
     * @param mixed $offset
     * @return mixed
     */
    #[\ReturnTypeWillChange]
    public function offsetGet($offset)
    {
        $this->record('offsetGet', [$offset]);
        return array_key_exists($offset, $this->data) ? $this->data[$offset] : '__stub_' . $offset . '__';
    }

    /**
     * @param mixed $offset
     * @param mixed $value
     * @return void
     */
    #[\ReturnTypeWillChange]
    public function offsetSet($offset, $value)
    {
        $this->record('offsetSet', [$offset, $value]);
        if ($offset === null) {
            $this->data[] = $value;
            return;
        }
        $this->data[$offset] = $value;
    }

    /**
     * @param mixed $offset
     * @return void
     */
    #[\ReturnTypeWillChange]
    public function offsetUnset($offset)
    {
        $this->record('offsetUnset', [$offset]);
        unset($this->data[$offset]);
    }

    /**
     * @return int
     */
    #[\ReturnTypeWillChange]
    public function count()
    {
        return count($this->data);
    }

    /**
     * @return \ArrayIterator<string,mixed>
     */
    #[\ReturnTypeWillChange]
    public function getIterator()
    {
        return new ArrayIterator($this->data);
    }

    /**
     * The row-shaped fields, including anything a handler wrote.
     *
     * @return array<string,mixed>
     */
    public function data()
    {
        return $this->data;
    }

    /**
     * Clears recorded calls and restores the seeded row.
     *
     * @param array<string,mixed> $data
     * @return void
     */
    public function reset(array $data = [])
    {
        $this->resetCalls();
        $this->data = $data;
    }
}
