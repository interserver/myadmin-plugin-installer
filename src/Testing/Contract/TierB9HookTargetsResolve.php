<?php
/**
 * @author Joe Huss <detain@interserver.net>
 * @copyright 2026
 * @package MyAdmin
 * @category Testing
 */

namespace MyAdmin\Plugins\Testing\Contract;

use ReflectionMethod;
use ReflectionNamedType;
use ReflectionType;
use Throwable;

/**
 * Tier-B-9 — **dangling hook target**.
 *
 * For every `[class, method]` returned by `getHooks()`, the target must
 *
 *  - exist,
 *  - be `public`,
 *  - be `static`,
 *  - take **exactly one** parameter, type-hinted `GenericEvent` (or a subclass).
 *
 * ---------------------------------------------------------------------------------
 * THE BUG CLASS — AND WHAT IT ACTUALLY DID (corrected)
 * ---------------------------------------------------------------------------------
 * A renamed or deleted handler leaves the hook entry behind. An earlier revision of this
 * docblock asserted that this then fails "silently, in production, with no log line and no
 * 500", on the reasoning that Symfony stores the array as a lazy callable and nothing asserts
 * it is callable. **That was false when it was written, and believing it under-rated this
 * bug class in the Phase 4 risk assessment. It cost a production incident.**
 *
 * What really happens, measured: `EventDispatcher::optimizeListeners()` (symfony/event-
 * dispatcher 5.4) runs `\Closure::fromCallable($listener)` over **every** listener registered
 * on an event key, the first time that key is dispatched. A `[Class, 'goneMethod']` pair
 * throws there:
 *
 *     TypeError: Failed to create closure from callable:
 *     class Detain\MyAdminPowerDns\Plugin does not have a method "getSettings"
 *
 * Because the listeners for one key are optimised as a batch, that throw kills **all** of
 * them, not just the broken one. `myadmin-powerdns` deleted `getSettings()` while core's
 * generated `include/config/hooks.json` still mapped `system.settings` at it; the result was
 * a hard 500 on the admin System Configuration page, taking roughly twenty other plugins'
 * settings contributions down with it. It was live on mystage.
 *
 * Two properties made it undetectable from the plugin side, and both still hold:
 *
 *  - `hooks.json` is a **generated dispatch table committed to a different repository**
 *    (core). The plugin repo that removes the handler never touches it.
 *  - It does not self-heal. Core sets `allow-plugins: {detain/myadmin-plugin-installer:
 *    false}`, so {@see \MyAdmin\Plugins\PluginScanner} never runs on install and the stale
 *    entry survives every deploy.
 *
 * Core has since been hardened — `include/tf.php` now gates `addListener()` on
 * `is_callable()` (commit `6e06a28a7a`) — so on a current core a dangling target is skipped
 * at registration instead of fatal at dispatch. That makes the *old* sentence become true
 * going forward: the listener never fires, no log line, no 500, the feature just stops. The
 * assertion matters under both regimes, and the difference is only how loudly it hurts:
 *
 *  - against a core without the guard, one stale entry is a shared-event 500;
 *  - against a core with it, the same entry is an invisible dead handler, which is exactly
 *    the class of defect a green test suite is supposed to stop shipping.
 *
 * The same is true of a handler that was accidentally made non-static, or whose signature
 * drifted — `getSettings($event)` with the type hint dropped still *runs*, so it hides the
 * day the dispatcher starts passing something else.
 *
 * The fleet's existing reflection tests do not catch any of this. They assert things like
 * "the first parameter is named `$event`" — a check that passes happily on a handler that no
 * longer exists at the name the hook points to, because those tests reflect the method they
 * already know about rather than the one the hook names. Tier-B-9 goes the other way round:
 * it starts from the hook table, which is the thing production actually dispatches through.
 *
 * ---------------------------------------------------------------------------------
 * WHY THE TYPE CHECK IS A STRING COMPARISON FIRST
 * ---------------------------------------------------------------------------------
 * `symfony/event-dispatcher` is not a dependency of this package — the installer never
 * dispatches anything itself, it only ships the harness. A check written as
 * `is_a($hinted, GenericEvent::class, true)` would therefore quietly answer "no" for
 * *every* plugin in an environment where the component is absent, turning a real check into
 * 69 false failures. Reflection reports a parameter's declared type as a plain string whether
 * or not that class can be loaded, so the exact-match arm below needs no autoloading at all,
 * and the subclass arm degrades to "not a subclass" only when it genuinely cannot tell.
 *
 * ---------------------------------------------------------------------------------
 * SCOPE BOUNDARY WITH TIER-A-8
 * ---------------------------------------------------------------------------------
 * "Every hook value is a 2-element array `[class-string, string]`" is Tier-A-8's assertion.
 * When a value is not that shape, B-9 emits a {@see Finding::skipped()} naming A-8 rather
 * than a second failure: double-reporting one defect in two matrix columns makes the day-one
 * triage read as worse than it is, while returning `[]` would read as a pass.
 *
 * Side-effect free apart from autoloading the classes the hooks name, which is unavoidable —
 * a target cannot be reflected without loading its class.
 */
class TierB9HookTargetsResolve implements PluginInspector
{
    /** Catalogue id. */
    const ID = 'B-9';

    /**
     * Compared as a **string** before any attempt to load it. See the class docblock.
     */
    const EVENT_CLASS = 'Symfony\Component\EventDispatcher\GenericEvent';

    /**
     * The base class a handler's one parameter must accept.
     *
     * Protected rather than inlined so the *subclass* arm of {@see satisfiesEventContract()}
     * can be exercised at all. Testing "a subclass of GenericEvent is accepted" needs a real
     * class extending a real `GenericEvent`, which cannot exist in a repo that does not
     * depend on symfony/event-dispatcher — and declaring one into Symfony's namespace to make
     * the test possible is the D2 failure mode (a double shadowing a production class), which
     * this package refuses on principle. A test subclass retargets this to a local base class
     * instead. Production always returns the constant; nothing else overrides it.
     *
     * @return string
     */
    protected function eventClass()
    {
        return self::EVENT_CLASS;
    }

    /**
     * @return string
     */
    public function id()
    {
        return self::ID;
    }

    /**
     * @return string
     */
    public function title()
    {
        return 'Dangling hook target';
    }

    /**
     * @param PluginSubject $subject
     * @return array<int,Finding>
     */
    public function inspect(PluginSubject $subject)
    {
        if (!$subject->isLoadable()) {
            return [Finding::skipped(
                self::ID,
                'plugin class does not load, so its hook targets cannot be resolved',
                ['plugin' => $subject->pluginClass()]
            )];
        }

        $hooks = $this->readHooks($subject);
        if ($hooks instanceof Finding) {
            return [$hooks];
        }

        $findings = [];
        foreach ($hooks as $key => $target) {
            foreach ($this->inspectTarget((string)$key, $target, $subject) as $finding) {
                $findings[] = $finding;
            }
        }
        return $findings;
    }

    /**
     * The hook table, or the Finding explaining why it could not be read.
     *
     * Reading it through reflection rather than `$class::getHooks()` keeps a non-static or
     * non-public `getHooks()` from raising an error inside an inspector: that deviation is
     * Tier-A-5's to report, and an inspector that fatals takes the whole 69-plugin sweep with
     * it.
     *
     * @param PluginSubject $subject
     * @return array<string,mixed>|Finding
     */
    private function readHooks(PluginSubject $subject)
    {
        $reflection = $subject->reflection();
        if (!$reflection->hasMethod('getHooks')) {
            return Finding::skipped(
                self::ID,
                'plugin declares no getHooks(), so there are no hook targets to resolve',
                ['plugin' => $subject->pluginClass()]
            );
        }

        $method = $reflection->getMethod('getHooks');
        if (!$method->isStatic() || !$method->isPublic()) {
            return Finding::skipped(
                self::ID,
                'getHooks() is not public static, so it cannot be called (Tier-A-5 reports this)',
                ['plugin' => $subject->pluginClass()]
            );
        }

        try {
            $hooks = $method->invoke(null);
        } catch (Throwable $e) {
            return Finding::skipped(
                self::ID,
                'getHooks() threw '.get_class($e).': '.$e->getMessage(),
                ['plugin' => $subject->pluginClass()]
            );
        }

        if (!is_array($hooks)) {
            return Finding::skipped(
                self::ID,
                'getHooks() did not return an array, so there is no hook table to walk (Tier-A-5 reports this)',
                ['plugin' => $subject->pluginClass(), 'returned' => gettype($hooks)]
            );
        }

        return $hooks;
    }

    /**
     * Every distinct problem with one hook entry, as its own Finding.
     *
     * Deliberately does **not** stop at the first problem: a handler can be both non-static
     * and wrongly hinted, and a reader fixing one and re-running to discover the other is how
     * a 69-plugin sweep turns into a week.
     *
     * @param string        $key
     * @param mixed         $target
     * @param PluginSubject $subject
     * @return array<int,Finding>
     */
    private function inspectTarget($key, $target, PluginSubject $subject)
    {
        $base = ['hook' => $key, 'plugin' => $subject->pluginClass()];

        if (!$this->isClassMethodPair($target)) {
            return [Finding::skipped(
                self::ID,
                'hook "'.$key.'" target is not a [class, method] pair, so it cannot be resolved'
                    .' (shape is Tier-A-8\'s assertion)',
                $base + ['target' => $this->describeTarget($target)]
            )];
        }

        list($class, $method) = [(string)$target[0], (string)$target[1]];
        $base += ['class' => $class, 'method' => $method];

        if (!class_exists($class)) {
            return [Finding::failure(
                self::ID,
                'hook "'.$key.'" points at '.$class.'::'.$method.'(), but class '.$class
                    .' does not exist — the listener will never fire',
                $base
            )];
        }

        if (!method_exists($class, $method)) {
            return [Finding::failure(
                self::ID,
                'hook "'.$key.'" points at '.$class.'::'.$method.'(), which does not exist'
                    .' — the listener will never fire (renamed or deleted handler)',
                $base
            )];
        }

        $reflection = new ReflectionMethod($class, $method);
        $findings = [];

        if (!$reflection->isPublic()) {
            $findings[] = Finding::failure(
                self::ID,
                'hook "'.$key.'" target '.$class.'::'.$method.'() is not public (found '
                    .$this->describeVisibility($reflection).')',
                $base + ['found' => $this->describeVisibility($reflection)]
            );
        }

        if (!$reflection->isStatic()) {
            $findings[] = Finding::failure(
                self::ID,
                'hook "'.$key.'" target '.$class.'::'.$method.'() is not static — the dispatcher'
                    .' calls it statically, so it will never fire',
                $base + ['found' => 'instance method']
            );
        }

        $parameters = $reflection->getParameters();
        $count = count($parameters);
        if ($count !== 1) {
            $findings[] = Finding::failure(
                self::ID,
                'hook "'.$key.'" target '.$class.'::'.$method.'() takes '.$count.' parameters,'
                    .' expected exactly 1 (the GenericEvent)',
                $base + ['found' => $count]
            );
        }

        if ($count >= 1) {
            $typeFinding = $this->inspectEventParameter($key, $class, $method, $parameters[0], $base);
            if ($typeFinding !== null) {
                $findings[] = $typeFinding;
            }
        }

        return $findings;
    }

    /**
     * The type-hint half of the check.
     *
     * An **untyped** parameter gets its own message rather than being folded into
     * "wrong type": the two are different mistakes with different fixes, and the untyped case
     * is the one that goes unnoticed for years because the handler still runs.
     *
     * `?GenericEvent` is accepted. The dispatcher never passes null, so the nullability is
     * merely redundant rather than a defect, and no fleet plugin writes it — failing it would
     * be a style rule wearing a bug check's clothes.
     *
     * @param string               $key
     * @param string               $class
     * @param string               $method
     * @param \ReflectionParameter $parameter
     * @param array<string,mixed>  $base
     * @return Finding|null
     */
    private function inspectEventParameter($key, $class, $method, $parameter, array $base)
    {
        $where = 'hook "'.$key.'" target '.$class.'::'.$method.'() parameter $'.$parameter->getName();

        if (!$parameter->hasType()) {
            return Finding::failure(
                self::ID,
                $where.' has no type declaration; expected '.$this->eventClass()
                    .' — an untyped parameter accepts anything, so a drifting event shape'
                    .' never fails loudly',
                $base + ['found' => 'no type declaration']
            );
        }

        $type = $parameter->getType();
        $described = $this->describeType($type);

        // A builtin (`array`, `string`, …) needs no case of its own: it can be neither the
        // event class nor a subclass of one, so it falls through to the failure below.
        if ($type instanceof ReflectionNamedType && $this->satisfiesEventContract($type->getName())) {
            return null;
        }

        return Finding::failure(
            self::ID,
            $where.' is type-hinted '.$described.'; expected '.$this->eventClass().' or a subclass',
            $base + ['found' => $described]
        );
    }

    /**
     * Exact match first (no autoloading), subclass second.
     *
     * @param string $name
     * @return bool
     */
    private function satisfiesEventContract($name)
    {
        $name = ltrim($name, '\\');
        if ($name === $this->eventClass()) {
            return true;
        }
        return class_exists($name) && is_subclass_of($name, $this->eventClass());
    }

    /**
     * @param mixed $target
     * @return bool
     */
    private function isClassMethodPair($target)
    {
        return is_array($target)
            && count($target) === 2
            && isset($target[0], $target[1])
            && is_string($target[0])
            && is_string($target[1])
            && $target[0] !== ''
            && $target[1] !== '';
    }

    /**
     * @param mixed $target
     * @return string
     */
    private function describeTarget($target)
    {
        if (is_array($target)) {
            return 'array('.count($target).')';
        }
        if (is_object($target)) {
            return get_class($target);
        }
        if (is_scalar($target) || $target === null) {
            return var_export($target, true);
        }
        return gettype($target);
    }

    /**
     * @param ReflectionMethod $method
     * @return string
     */
    private function describeVisibility(ReflectionMethod $method)
    {
        if ($method->isPrivate()) {
            return 'private';
        }
        if ($method->isProtected()) {
            return 'protected';
        }
        return 'public';
    }

    /**
     * Union and intersection types stringify usefully; named types are printed with their
     * nullability so `?GenericEvent` is not silently reported as `GenericEvent`.
     *
     * @param ReflectionType|null $type
     * @return string
     */
    private function describeType($type)
    {
        if ($type === null) {
            return 'no type declaration';
        }
        if ($type instanceof ReflectionNamedType) {
            return ($type->allowsNull() && $type->getName() !== 'null' && $type->getName() !== 'mixed' ? '?' : '')
                .$type->getName();
        }
        return (string)$type;
    }
}
