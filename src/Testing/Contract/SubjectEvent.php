<?php
/**
 * @author Joe Huss <detain@interserver.net>
 * @copyright 2026
 * @package MyAdmin
 * @category Testing
 */

namespace MyAdmin\Plugins\Testing\Contract;

use MyAdmin\Plugins\Testing\Fakes\FakeApp;
use MyAdmin\Plugins\Testing\Harness;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use Throwable;

/**
 * The one place the contract inspectors get an event object to hand a plugin handler, and
 * the one place they put the harness back afterwards.
 *
 * ---------------------------------------------------------------------------------
 * WHY THIS IS NOT NAMED GenericEvent, AND NOT IN SYMFONY'S NAMESPACE
 * ---------------------------------------------------------------------------------
 * Every fleet handler is declared `getSettings(GenericEvent $event)` and does one thing with
 * it: `$event->getSubject()`. But `symfony/event-dispatcher` is **not** a dependency of this
 * installer package, and the Phase 2 self-check runs outside any plugin's vendor tree, so
 * `Symfony\Component\EventDispatcher\GenericEvent` may simply not exist at the moment an
 * inspector needs one.
 *
 * Declaring a stand-in *under Symfony's name* would fix that and is forbidden: it is the D2
 * failure mode — a test double occupying a production class name — and it would shadow the
 * real class in every environment where the real one is installed, silently changing the
 * behaviour of every inspector that branches on `class_exists()`. A previous pass tried it
 * and reverted it. Do not try it again.
 *
 * The cost of refusing is that a handler type-hinted `GenericEvent` raises a `TypeError`
 * where the component is absent. That is caught by the caller and answered with a skip
 * naming the missing component — an honest "could not run" rather than a fabricated pass.
 *
 * ---------------------------------------------------------------------------------
 * TWO WAYS OF PICKING AN EVENT, AND WHY BOTH SURVIVE
 * ---------------------------------------------------------------------------------
 * This class serves two selection strategies that are deliberately *not* merged, because
 * they answer different questions:
 *
 *  - **B-10 / B-11** construct an event before they know anything about the handler, so they
 *    prefer the real `GenericEvent` when loadable and instantiate *this class* otherwise.
 *    That is the instance side: `getSubject()` plus `setSubject()`, because a
 *    `function.requirements` handler is allowed to replace the subject and B-10 reads it
 *    back.
 *  - **B-12 / B-13 / B-15** take the event type from the **handler's own signature** by
 *    reflection ({@see argumentsFor()}), and fall back to {@see duck()} only for an untyped
 *    parameter. That is why the duck carries `hasArgument()`/`getArgument()`/`setArgument()`
 *    but **no** `setSubject()`: the real `GenericEvent` has no `setSubject()` either, and
 *    adding one would let a handler get away here with a call that fatals in production.
 *
 * Collapsing the two shapes into a single permissive object would widen what each inspector
 * silently accepts. The duplication that was removed here was in the *bodies* — five
 * byte-identical copies of the same code, an artefact of the agents that wrote them being
 * confined to disjoint file globs — not in the two shapes, which are kept apart on purpose.
 */
class SubjectEvent
{
    /** @var mixed */
    private $subject;

    /**
     * @param mixed $subject
     */
    public function __construct($subject = null)
    {
        $this->subject = $subject;
    }

    /**
     * @return mixed
     */
    public function getSubject()
    {
        return $this->subject;
    }

    /**
     * @param mixed $subject
     * @return $this
     */
    public function setSubject($subject)
    {
        $this->subject = $subject;
        return $this;
    }

    /**
     * Builds the argument list for a handler whose event type is read from its own signature.
     *
     * When the declared type cannot be produced here — not autoloadable in this environment,
     * abstract, or unwilling to accept the subject — that is a genuine "could not run" and
     * returns a skip. Passing something the handler did not ask for would be worse: the
     * handler would run against an object of the wrong shape and its result would be
     * reported as if it meant something.
     *
     * @param ReflectionMethod    $method
     * @param object              $eventSubject what getSubject() must hand back
     * @param PluginSubject       $subject
     * @param string              $id           catalogue id of the calling inspector
     * @param array<string,mixed> $extraContext appended to every skip's context (B-13 adds
     *                                          the ACL/ima combination it is on)
     * @return array{args:array<int,mixed>,skip:Finding|null}
     */
    public static function argumentsFor(
        ReflectionMethod $method,
        $eventSubject,
        PluginSubject $subject,
        $id,
        array $extraContext = []
    ) {
        $parameters = $method->getParameters();
        if ($parameters === []) {
            return ['args' => [], 'skip' => null];
        }
        if ($method->getNumberOfRequiredParameters() > 1) {
            return ['args' => [], 'skip' => Finding::skipped(
                $id,
                sprintf(
                    '%s::%s() requires %d arguments; the harness can only supply the event',
                    $subject->pluginClass(),
                    $method->getName(),
                    $method->getNumberOfRequiredParameters()
                ),
                array_merge(
                    ['class' => $subject->pluginClass(), 'method' => $method->getName()],
                    $extraContext
                )
            )];
        }

        $type = $parameters[0]->getType();
        if (!$type instanceof ReflectionNamedType || $type->isBuiltin()) {
            return ['args' => [self::duck($eventSubject)], 'skip' => null];
        }

        $name = $type->getName();
        if (!class_exists($name)) {
            return ['args' => [], 'skip' => Finding::skipped(
                $id,
                sprintf('event class %s is not loadable in this environment', $name),
                array_merge(
                    ['class' => $subject->pluginClass(), 'method' => $method->getName(), 'event' => $name],
                    $extraContext
                )
            )];
        }

        try {
            $eventReflection = new ReflectionClass($name);
            if ($eventReflection->isAbstract()) {
                throw new \RuntimeException('event class is abstract');
            }
            $event = $eventReflection->newInstance($eventSubject);
            if (!method_exists($event, 'getSubject') || $event->getSubject() !== $eventSubject) {
                throw new \RuntimeException('event did not accept the harness subject');
            }
        } catch (Throwable $e) {
            return ['args' => [], 'skip' => Finding::skipped(
                $id,
                sprintf('could not build a %s to pass to the handler: %s', $name, $e->getMessage()),
                array_merge(
                    ['class' => $subject->pluginClass(), 'method' => $method->getName(), 'event' => $name],
                    $extraContext
                )
            )];
        }

        return ['args' => [$event], 'skip' => null];
    }

    /**
     * The fallback event for a handler that declares no parameter type.
     *
     * Anonymous on purpose: a named class would be autoloadable, and an autoloadable
     * duck-typed event is one `use` statement away from being passed somewhere it was never
     * meant to go. See the class docblock for why it has no `setSubject()`.
     *
     * @param object $eventSubject
     * @return object
     */
    public static function duck($eventSubject)
    {
        return new class ($eventSubject) {
            /** @var object */
            private $subject;

            /**
             * @param object $subject
             */
            public function __construct($subject)
            {
                $this->subject = $subject;
            }

            /**
             * @return object
             */
            public function getSubject()
            {
                return $this->subject;
            }

            /**
             * @param string $key
             * @return bool
             */
            public function hasArgument($key)
            {
                return false;
            }

            /**
             * @param string $key
             * @return mixed
             */
            public function getArgument($key)
            {
                return null;
            }

            /**
             * @param string $key
             * @param mixed  $value
             * @return $this
             */
            public function setArgument($key, $value)
            {
                return $this;
            }

            /**
             * @return array<string,mixed>
             */
            public function getArguments()
            {
                return [];
            }
        };
    }

    /**
     * Puts the harness back the way the next inspector expects to find it.
     *
     * `Harness::reset()` alone is not enough: it deliberately leaves the `has_acl()`
     * allowlist and `App::ima()` alone, and every inspector that executes a handler changes
     * both. Sixty-nine plugins run back-to-back in one process during the self-check, so a
     * grant left behind does not fail here — it changes the verdict on some later plugin,
     * which is far harder to trace back.
     *
     * Lives beside the event builders because it is the other half of the same call: nothing
     * that invokes a handler is finished until it has run.
     *
     * @return void
     */
    public static function releaseHarness()
    {
        Harness::reset();
        Harness::setAcl([]);
        FakeApp::setIma('client');
    }
}
