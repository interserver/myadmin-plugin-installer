<?php
/**
 * @author Joe Huss <detain@interserver.net>
 * @copyright 2026
 * @package MyAdmin
 * @category Testing
 */

namespace MyAdmin\Plugins\Testing;

use ArrayAccess;

/**
 * The event handed to a lifecycle handler whose parameter carries no type hint.
 *
 * ---------------------------------------------------------------------------------
 * WHY THIS EXISTS WHEN SubjectEvent::duck() ALREADY DOES
 * ---------------------------------------------------------------------------------
 * {@see Contract\SubjectEvent::duck()} is the fallback for `getSettings()`, `getMenu()` and
 * `getRequirements()`, and it is shaped for them: `getSubject()`, plus
 * `hasArgument()`/`getArgument()`/`setArgument()` that answer nothing. That is correct for
 * those three, which never read an argument and never stop propagation.
 *
 * A lifecycle handler does both, and neither is optional:
 *
 *  - it reads its gate as **`$event['type']` / `$event['category']`** — array access, which
 *    the duck does not implement at all;
 *  - it calls **`$event->stopPropagation()`**, and the whole of assertion B is a claim about
 *    `isPropagationStopped()`, which the duck cannot answer.
 *
 * Widening the duck to cover this was rejected. Its docblock records that the two event
 * shapes in that class are kept apart on purpose so that neither inspector silently accepts
 * more than it should, and adding propagation to the shape used by `getSettings()` would let
 * a settings handler get away with a call that has no meaning there.
 *
 * ---------------------------------------------------------------------------------
 * WHAT IT IS NOT
 * ---------------------------------------------------------------------------------
 * It is **not** a stand-in for `Symfony\Component\EventDispatcher\GenericEvent`, is not
 * declared under Symfony's namespace, and is never substituted for it. A handler that
 * type-hints `GenericEvent` in an environment without the component gets a skip, not this.
 * See {@see ServiceHandlerProbe::buildEvent()} and the D2 reasoning in
 * {@see Contract\SubjectEvent}.
 *
 * Where it *is* used — an untyped parameter — it copies `GenericEvent`'s semantics closely
 * enough that a handler cannot tell the difference, including the one that catches people
 * out: **reading an argument that was never set throws**, exactly as
 * `GenericEvent::getArgument()` does. A stand-in that returned null there would let a
 * handler run further under test than it can in production, and a lenient double is how a
 * harness manufactures green cells.
 */
class ServiceLifecycleEvent implements ArrayAccess
{
    /** @var mixed */
    private $subject;

    /** @var array<string,mixed> */
    private $arguments;

    /** @var bool */
    private $propagationStopped = false;

    /**
     * @param mixed               $subject
     * @param array<string,mixed> $arguments
     */
    public function __construct($subject = null, array $arguments = [])
    {
        $this->subject = $subject;
        $this->arguments = $arguments;
    }

    /**
     * @return mixed
     */
    public function getSubject()
    {
        return $this->subject;
    }

    /**
     * @param string $key
     * @return mixed
     * @throws \InvalidArgumentException when the argument was never set, as GenericEvent does
     */
    public function getArgument($key)
    {
        if (!array_key_exists($key, $this->arguments)) {
            throw new \InvalidArgumentException(sprintf('Argument "%s" not found.', $key));
        }
        return $this->arguments[$key];
    }

    /**
     * @param string $key
     * @param mixed  $value
     * @return $this
     */
    public function setArgument($key, $value)
    {
        $this->arguments[$key] = $value;
        return $this;
    }

    /**
     * @param string $key
     * @return bool
     */
    public function hasArgument($key)
    {
        return array_key_exists($key, $this->arguments);
    }

    /**
     * @return array<string,mixed>
     */
    public function getArguments()
    {
        return $this->arguments;
    }

    /**
     * @param array<string,mixed> $arguments
     * @return $this
     */
    public function setArguments(array $arguments = [])
    {
        $this->arguments = $arguments;
        return $this;
    }

    /**
     * @param mixed $offset
     * @return bool
     */
    #[\ReturnTypeWillChange]
    public function offsetExists($offset)
    {
        return $this->hasArgument($offset);
    }

    /**
     * @param mixed $offset
     * @return mixed
     */
    #[\ReturnTypeWillChange]
    public function offsetGet($offset)
    {
        return $this->getArgument($offset);
    }

    /**
     * @param mixed $offset
     * @param mixed $value
     * @return void
     */
    #[\ReturnTypeWillChange]
    public function offsetSet($offset, $value)
    {
        $this->setArgument($offset, $value);
    }

    /**
     * @param mixed $offset
     * @return void
     */
    #[\ReturnTypeWillChange]
    public function offsetUnset($offset)
    {
        unset($this->arguments[$offset]);
    }

    /**
     * @return bool
     */
    public function isPropagationStopped()
    {
        return $this->propagationStopped;
    }

    /**
     * @return void
     */
    public function stopPropagation()
    {
        $this->propagationStopped = true;
    }
}
