<?php
/**
 * @author Joe Huss <detain@interserver.net>
 * @copyright 2026
 * @package MyAdmin
 * @category Testing
 */

namespace MyAdmin\Plugins\Testing\Contract;

use ReflectionMethod;

/**
 * Tier-B-11 — every route a plugin registers is well-formed.
 *
 * ---------------------------------------------------------------------------------
 * HOW ROUTES ARE ACTUALLY REGISTERED (verified, not assumed)
 * ---------------------------------------------------------------------------------
 * There is no `getRoutes()` anywhere in the fleet — zero of 70 `src/Plugin.php` files
 * declare one. Routes appear only as a **side effect** of the `function.requirements` hook:
 *
 * ```php
 * public static function getHooks()      { return ['function.requirements' => [__CLASS__, 'getRequirements']]; }
 * public static function getRequirements(GenericEvent $event)
 * {
 *     $loader = $event->getSubject();                       // a MyAdmin\Plugins\Loader
 *     $loader->add_page_requirement('abuse', '/../vendor/detain/myadmin-abuse-plugin/src/abuse.php');
 * }
 * ```
 *
 * Fleet census of `vendor/detain/myadmin-*\/src/Plugin.php`: 52 packages declare
 * `getRequirements()`, but only **27** register a route — 221 `add_page_requirement()` calls
 * plus a single `add_admin_page_requirement()`. The other 168 `$loader->` calls are
 * `add_requirement()`, which loads a file for a function name and registers no route at all.
 * `add_route_requirement()` is never called directly by a plugin. The remaining 42 packages
 * register nothing and are reported **not applicable**, not passed.
 *
 * ---------------------------------------------------------------------------------
 * NOT-APPLICABLE, NOT SKIPPED (R-4)
 * ---------------------------------------------------------------------------------
 * Those 42 used to be {@see Finding::skipped()}, and that was the single largest source of
 * the confusion R-4 fixed: `backups-module` registers neither routes nor requirement paths,
 * and the identical fact made B-10 green and B-11 grey. A grey dash claims *the check could
 * not run*, and this check ran perfectly well — it drove the handler, watched every
 * `$loader->` call, and observed that none of them registered a route. Nothing is missing
 * from the fleet's coverage because of these cells, so they must not be counted as if
 * something were.
 *
 * The two empty outcomes are therefore split by *why* they are empty, and the split follows
 * the observation, not the message:
 *
 *  - **not applicable** — the observation succeeded and saw no route registrations: a handler
 *    that ran and registered none, a source scan that found no call sites, or no
 *    `function.requirements` handler to begin with.
 *  - **skip** — the observation itself did not complete: the class will not load, the handler
 *    could neither be executed nor read from disk, or the scan recovered call sites whose
 *    arguments are not literals and so could not be replayed. A route may well be registered
 *    there; this inspector cannot say.
 *
 * ---------------------------------------------------------------------------------
 * TWO OBSERVATION MODES, ONE OBSERVATION POINT
 * ---------------------------------------------------------------------------------
 * Routes are observed on a real {@see TierB11RecordingLoader}, so path composition, the
 * `['GET', 'POST']` default and the `'/'.$function` default are production behaviour rather
 * than a model of it. How the loader gets driven depends on what is installed:
 *
 *  - **`execute`** — the handler is invoked with a `GenericEvent` wrapping the recorder.
 *    Used whenever `Symfony\Component\EventDispatcher\GenericEvent` is loadable, which is the
 *    case in every plugin's own CI, since each plugin requires `symfony/event-dispatcher`.
 *  - **`source-scan`** — {@see TierB11RouteCallScanner} recovers the call sites from tokens
 *    and replays them onto the recorder. Used when the handler cannot be invoked.
 *
 * The fallback is load-bearing rather than defensive: `symfony/event-dispatcher` is **not**
 * a dependency of this installer package, so inside this repo `GenericEvent` does not exist
 * and every real plugin's type-hinted handler raises a `TypeError`. Execute-only would
 * therefore skip 27 of 27 route-registering packages here — the same vacuous green the
 * `getRoutes()` design produced, just spelled "skipped". Every {@see Finding} carries the
 * mode it was observed in, so a triage matrix never has to guess.
 *
 * ---------------------------------------------------------------------------------
 * WHAT IS ASSERTED, AND WHY EACH ONE IS A REAL BREAK
 * ---------------------------------------------------------------------------------
 * Consumption is `public_html/route.php`: `$r->addRoute($route[2], $path, $route)`, then
 * `$handlerPerm = $handler[0]; $handlerFunc = $handler[1];`.
 *
 *  1. **path starts with `/`** — FastRoute matches against `urldecode($urlPath)`, which
 *     always begins with `/`. A path that does not is unreachable. FAILURE.
 *  2. **handler is non-empty** — `$route[1]` reaches `call_user_func()` in route.php. An
 *     empty string or a malformed callable array is a fatal on dispatch. FAILURE.
 *     The `[SomeClass::class, 'METHOD']` form is the documented multi-verb convention —
 *     route.php:242 rewrites the literal `'METHOD'` to the lowercased request method — and is
 *     explicitly accepted, not flagged.
 *  3. **methods are known verbs** — route.php dispatches on `$_SERVER['REQUEST_METHOD']`,
 *     which is always uppercase, so a lowercase or invented verb can never match. An empty
 *     list registers a route reachable by nothing. FAILURE.
 *     A bare string (`'GET'` rather than `['GET']`) is a NOTICE, not a failure:
 *     `FastRoute\RouteCollector::addRoute()` does `foreach ((array) $httpMethod ...)`, and
 *     core's own `include/config/router.php` uses the bare form on ~40 lines. Flagging it
 *     would be inventing a rule the router does not have.
 *  4. **route type is in the router's permission vocabulary** — route.php:283 gates both the
 *     session check and the admin check on exact string membership. A typo'd type is not a
 *     cosmetic problem: it silently drops out of both gates, leaving the route unauthenticated.
 *     FAILURE.
 *  5. **no duplicate path within one plugin** — the loader stores `$this->routes[$path] = ...`,
 *     so a repeat registration discards the earlier one with no diagnostic. FAILURE.
 *
 * Deliberately **not** asserted: whether the handler file exists, and whether the source path
 * resolves. That is Tier-B-9/B-10's subject; B-11 observes the registered route, they observe
 * what it points at.
 *
 * ---------------------------------------------------------------------------------
 * THE HANDLER RUNS UNDER A BUFFER, AND THIS INSPECTOR REPORTS WHAT IT PRINTS (R-8)
 * ---------------------------------------------------------------------------------
 * `execute` mode runs plugin code, and so does the `getHooks()` call in
 * {@see handlerMethod()}. Both now go through {@see TierB15NoOutput::capture()}. Unbuffered,
 * a `getRequirements()` carrying a stray `echo` escaped into the PHPUnit process, where
 * `beStrictAboutOutputDuringTests="true"` + `failOnRisky="true"` filed it as
 * `R  This test printed output: …` against **B-11** — the anonymous, mis-attributed report
 * {@see TierB15NoOutput} exists to replace.
 *
 * The `getRequirements()` bytes are **reported here**. B-15 executes `getSettings()` and
 * `getMenu()` and never touches this handler, so there is no owner to defer to and dropping
 * them would leave the defect reported nowhere. It is a real defect: `function.requirements`
 * fires from `tf::set_function_requirements()` during page setup, long before the theme has
 * emitted a byte, so anything printed there lands above `<!DOCTYPE html>`.
 *
 * The `getHooks()` bytes are **discarded**: that call only asks which handler to run, and
 * {@see TierA5HooksAreIdempotent} makes the identical call and reports what it prints.
 *
 * The finding is filed even when the handler registers no routes — the run is then reported
 * as not-applicable *and* a failure, which is honest on both counts: no routes were observed,
 * and bytes were. `Finding::notice()` is not used, because a notice never changes a cell's
 * colour, and printing above `<!DOCTYPE html>` is a defect that has to.
 *
 * `source-scan` mode executes nothing — it replays recovered call sites onto a recorder — so
 * it can print nothing and reports nothing.
 */
class TierB11RoutesWellFormed implements PluginInspector
{
    /**
     * HTTP verbs the router can ever dispatch.
     *
     * @var array<int,string>
     */
    const VALID_METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'];

    /**
     * Route types the router recognises.
     *
     * Union of the types `Loader`'s own helpers emit (`client`, `admin`, `public`,
     * `public_file`, `client_ajax`, `admin_api`, `client_api`), the two more its
     * `add_route_requirement()` docblock documents (`public_ajax`, `admin_ajax`), and
     * `public_api`, which core's `include/config/router.php` uses and route.php:198/246
     * branches on.
     *
     * @var array<int,string>
     */
    const VALID_TYPES = [
        'client', 'admin', 'public', 'public_file',
        'client_ajax', 'public_ajax', 'admin_ajax',
        'client_api', 'admin_api', 'public_api',
    ];

    /**
     * Hook key the `Loader` is dispatched on.
     *
     * @var string
     */
    const REQUIREMENTS_HOOK = 'function.requirements';

    /**
     * Handler name used when `getHooks()` cannot be read.
     *
     * @var string
     */
    const DEFAULT_HANDLER = 'getRequirements';

    /**
     * The reason shared by every observation that completed and saw no routes.
     *
     * A constant rather than three string literals so that the three producers of it in
     * {@see observe()} and {@see scanSource()} cannot drift into three slightly different
     * sentences about the same fact — which, once the severity beside them differs, is how a
     * reader stops being able to tell the two empty outcomes apart.
     *
     * @var string
     */
    const NO_ROUTES = 'plugin registers no routes';

    /**
     * @return string
     */
    public function id()
    {
        return 'B-11';
    }

    /**
     * @return string
     */
    public function title()
    {
        return 'Route registrations are well-formed';
    }

    /**
     * @param PluginSubject $subject
     * @return array<int,Finding>
     */
    public function inspect(PluginSubject $subject)
    {
        if (!$subject->isLoadable()) {
            return [Finding::skipped($this->id(), 'plugin class does not load', [
                'class' => $subject->pluginClass(),
            ])];
        }
        $handler = $this->handlerMethod($subject);
        if ($handler === null) {
            // Not a skip: there is no handler, therefore no route registration, therefore
            // nothing of this assertion's kind in this package. The check reached a verdict.
            return [Finding::notApplicable(
                $this->id(),
                'plugin declares no function.requirements handler, so it registers no routes',
                ['class' => $subject->pluginClass()]
            )];
        }
        $observed = $this->observe($subject, $handler);
        $findings = $this->outputFindings($subject, $handler, $observed['output']);
        if ($observed['registrations'] === []) {
            $context = [
                'class' => $subject->pluginClass(),
                'handler' => $handler,
                'mode' => $observed['mode'],
            ];
            $findings[] = $observed['observed']
                ? Finding::notApplicable($this->id(), $observed['emptyReason'], $context)
                : Finding::skipped($this->id(), $observed['emptyReason'], $context);
            return $findings;
        }
        return array_merge($findings, $this->validate($observed['registrations'], $observed['mode']));
    }

    /**
     * The failure for a requirements handler that printed, or nothing when it stayed silent.
     *
     * Separate from {@see validate()} because it is not a statement about a route: it is the
     * one thing this inspector observes that belongs to B-15's subject and has no B-15 owner.
     * See the class docblock.
     *
     * @param PluginSubject $subject
     * @param string        $handler
     * @param string        $output captured bytes, '' when the handler stayed silent
     * @return array<int,Finding>
     */
    private function outputFindings(PluginSubject $subject, $handler, $output)
    {
        if ($output === '') {
            return [];
        }
        return [Finding::failure(
            $this->id(),
            TierB15NoOutput::describeOutput($subject->pluginClass(), $handler.'()', $output)
                .' The "'.self::REQUIREMENTS_HOOK.'" hook fires during page setup, before the theme'
                .' has emitted anything, so this lands above <!DOCTYPE html> in a real request.'
                .' Reported here rather than under B-15 because B-15 executes the settings and menu'
                .' handlers, never this one, so nothing else in the catalogue would ever see these'
                .' bytes.',
            [
                'class' => $subject->pluginClass(),
                'handler' => $handler,
                'mode' => 'execute',
                'bytes' => strlen($output),
                'output' => TierB15NoOutput::excerpt($output),
            ]
        )];
    }

    /**
     * Name of the method bound to `function.requirements`.
     *
     * Read from `getHooks()` where possible so a plugin binding a differently named handler
     * is still followed. `getHooks()` is allowed to throw — nine repos initialise statics
     * from constants such as `PRORATE_BILLING` that do not exist outside a MyAdmin request —
     * so failure falls back to the fleet-wide conventional name.
     *
     * @param PluginSubject $subject
     * @return string|null
     */
    private function handlerMethod(PluginSubject $subject)
    {
        $reflection = $subject->reflection();
        $name = null;
        if ($reflection->hasMethod('getHooks')) {
            // Buffered and dropped: this call is only asking which handler to run, and A-5
            // makes the identical call and reports anything it prints. See the class docblock.
            $method = $reflection->getMethod('getHooks');
            $hooks = null;
            $run = TierB15NoOutput::capture(function () use ($method, &$hooks) {
                $hooks = $method->invoke(null);
            });
            if ($run['error'] === null && is_array($hooks)) {
                $name = $this->handlerFromHooks($hooks);
            }
        }
        if ($name === null) {
            $name = self::DEFAULT_HANDLER;
        }
        if (!$reflection->hasMethod($name)) {
            return null;
        }
        $method = $reflection->getMethod($name);
        return $method->isStatic() && $method->isPublic() ? $method->getName() : null;
    }

    /**
     * @param array<mixed,mixed> $hooks
     * @return string|null
     */
    private function handlerFromHooks(array $hooks)
    {
        foreach ($hooks as $key => $target) {
            if ($key !== self::REQUIREMENTS_HOOK) {
                continue;
            }
            if (is_array($target) && isset($target[1]) && is_string($target[1])) {
                return $target[1];
            }
        }
        return null;
    }

    /**
     * Drives a recording loader, by execution where possible and by source scan otherwise.
     *
     * `observed` is the R-4 severity switch for an empty result, and it is set by whichever
     * branch produced the emptiness rather than derived from `emptyReason` afterwards. True
     * means "the observation completed and there was nothing to see" — a not-applicable cell;
     * false means "the observation did not complete" — a skip. Deriving it from the message
     * would make a reworded sentence silently change a verdict.
     *
     * @param PluginSubject $subject
     * @param string        $handler
     * @return array{registrations:array<int,array<string,mixed>>,mode:string,emptyReason:string,observed:bool,output:string}
     */
    private function observe(PluginSubject $subject, $handler)
    {
        $executed = $this->executeHandler($subject, $handler);
        if ($executed['loader'] !== null) {
            return [
                'registrations' => $executed['loader']->registrations(),
                'mode' => 'execute',
                'emptyReason' => self::NO_ROUTES,
                'observed' => true,
                'output' => $executed['output'],
            ];
        }
        // A handler that printed and *then* threw still printed. The mode falls back to
        // source-scan for the route observation, but the bytes were really written and are
        // carried out rather than dropped with the failed execution.
        return $this->scanSource($subject, $handler) + ['output' => $executed['output']];
    }

    /**
     * Invokes the handler against a recording loader, under an output buffer.
     *
     * Returns the loader *and* whatever escaped, because the two answer different questions
     * and a handler can produce both — or produce bytes and then throw, in which case the
     * loader is null and the bytes are still real.
     *
     * @param PluginSubject $subject
     * @param string        $handler
     * @return array{loader:TierB11RecordingLoader|null,output:string} loader null when the handler could not be invoked
     */
    private function executeHandler(PluginSubject $subject, $handler)
    {
        $eventClass = 'Symfony\\Component\\EventDispatcher\\GenericEvent';
        $loader = new TierB11RecordingLoader();
        $event = class_exists($eventClass) ? new $eventClass($loader) : new SubjectEvent($loader);
        $class = $subject->pluginClass();
        $run = TierB15NoOutput::capture(function () use ($class, $handler, $event) {
            $method = new ReflectionMethod($class, $handler);
            $method->invoke(null, $event);
        });
        return [
            'loader' => $run['error'] === null ? $loader : null,
            'output' => $run['output'],
        ];
    }

    /**
     * Recovers the call sites from tokens and replays them onto a recording loader.
     *
     * @param PluginSubject $subject
     * @param string        $handler
     * @return array{registrations:array<int,array<string,mixed>>,mode:string,emptyReason:string,observed:bool}
     */
    private function scanSource(PluginSubject $subject, $handler)
    {
        $file = $subject->reflection()->getFileName();
        if ($file === false || !is_file($file)) {
            // Neither observation mode was available. This is the real "could not run".
            return [
                'registrations' => [],
                'mode' => 'source-scan',
                'emptyReason' => 'handler could not be executed and the plugin has no readable source file',
                'observed' => false,
            ];
        }
        $calls = TierB11RouteCallScanner::scanFile($file);
        if ($calls === []) {
            // The scan is an observation mode in its own right, not a degraded one — see the
            // class docblock — so a clean scan that found no call sites is a verdict: this
            // plugin registers no routes.
            return [
                'registrations' => [],
                'mode' => 'source-scan',
                'emptyReason' => self::NO_ROUTES,
                'observed' => true,
            ];
        }
        $loader = new TierB11RecordingLoader();
        $unresolved = 0;
        foreach ($calls as $call) {
            if (!$call['resolved'] || !$this->callable($loader, $call)) {
                $unresolved++;
                continue;
            }
            $loader->setCurrentLine($call['line']);
            call_user_func_array([$loader, $call['helper']], $call['args']);
        }
        $loader->setCurrentLine(null);
        $registrations = $loader->registrations();
        return [
            'registrations' => $registrations,
            'mode' => 'source-scan',
            // Unresolved call sites are routes this inspector knows exist and cannot read.
            // That is a coverage hole, so it stays a skip even though the scan itself
            // completed; "nothing of this kind here" would be a false statement about a
            // package that demonstrably registers something.
            'emptyReason' => $unresolved > 0
                ? $unresolved.' route registration(s) use non-literal arguments and could not be evaluated statically'
                : self::NO_ROUTES,
            'observed' => $unresolved === 0,
        ];
    }

    /**
     * Whether a scanned call can be replayed without an ArgumentCountError.
     *
     * A call with too few arguments is a genuine defect, but it is one the plugin's own
     * suite raises the moment the handler runs; replaying it here would throw out of the
     * inspector, which is exactly what an inspector must never do.
     *
     * @param TierB11RecordingLoader $loader
     * @param array<string,mixed>    $call
     * @return bool
     */
    private function callable(TierB11RecordingLoader $loader, array $call)
    {
        $method = new ReflectionMethod($loader, $call['helper']);
        return $call['argCount'] >= $method->getNumberOfRequiredParameters();
    }

    /**
     * @param array<int,array<string,mixed>> $registrations
     * @param string                         $mode
     * @return array<int,Finding>
     */
    private function validate(array $registrations, $mode)
    {
        $findings = [];
        $seen = [];
        foreach ($registrations as $registration) {
            $context = ['mode' => $mode, 'path' => $registration['path']];
            if ($registration['line'] !== null) {
                $context['line'] = $registration['line'];
            }
            $findings = array_merge(
                $findings,
                $this->checkPath($registration, $context),
                $this->checkHandler($registration, $context),
                $this->checkMethods($registration, $context),
                $this->checkType($registration, $context)
            );
            if (!is_string($registration['path'])) {
                continue;
            }
            // array_key_exists, not isset: in execute mode there is no source line, so the
            // stored value is null and isset() would report every duplicate as unseen.
            if (array_key_exists($registration['path'], $seen)) {
                $findings[] = Finding::failure(
                    $this->id(),
                    'route path "'.$registration['path'].'" is registered more than once; the loader keys routes by '
                        .'path, so the earlier registration is silently discarded',
                    $context + ['firstLine' => $seen[$registration['path']]]
                );
                continue;
            }
            $seen[$registration['path']] = $registration['line'];
        }
        return $findings;
    }

    /**
     * @param array<string,mixed> $registration
     * @param array<string,mixed> $context
     * @return array<int,Finding>
     */
    private function checkPath(array $registration, array $context)
    {
        $path = $registration['path'];
        if (!is_string($path) || $path === '') {
            return [Finding::failure(
                $this->id(),
                'route path is not a non-empty string',
                $context + ['pathType' => gettype($path)]
            )];
        }
        if (strpos($path, '/') !== 0) {
            return [Finding::failure(
                $this->id(),
                'route path "'.$path.'" does not start with "/" and can never match a request URI',
                $context
            )];
        }
        return [];
    }

    /**
     * @param array<string,mixed> $registration
     * @param array<string,mixed> $context
     * @return array<int,Finding>
     */
    private function checkHandler(array $registration, array $context)
    {
        $function = $registration['function'];
        if (is_string($function)) {
            return $function === ''
                ? [Finding::failure($this->id(), 'route handler is an empty string', $context)]
                : [];
        }
        if (is_array($function)) {
            // `[SomeClass::class, 'METHOD']` is the documented multi-verb form; route.php
            // rewrites 'METHOD' to the lowercased request method before dispatching.
            $valid = count($function) === 2
                && isset($function[0], $function[1])
                && is_string($function[0]) && $function[0] !== ''
                && is_string($function[1]) && $function[1] !== '';
            return $valid ? [] : [Finding::failure(
                $this->id(),
                'route handler array is not a [class, method] pair',
                $context
            )];
        }
        return [Finding::failure(
            $this->id(),
            'route handler is neither a function name nor a [class, method] pair',
            $context + ['handlerType' => gettype($function)]
        )];
    }

    /**
     * @param array<string,mixed> $registration
     * @param array<string,mixed> $context
     * @return array<int,Finding>
     */
    private function checkMethods(array $registration, array $context)
    {
        $methods = $registration['methods'];
        if (is_string($methods)) {
            if (!in_array($methods, self::VALID_METHODS, true)) {
                return [Finding::failure(
                    $this->id(),
                    'route method "'.$methods.'" is not an HTTP verb the router can dispatch',
                    $context
                )];
            }
            return [Finding::notice(
                $this->id(),
                'route methods given as the bare string "'.$methods.'" rather than an array; FastRoute casts it, '
                    .'so this works, but the array form is the catalogue convention',
                $context
            )];
        }
        if (!is_array($methods)) {
            return [Finding::failure(
                $this->id(),
                'route methods is neither an array nor a string',
                $context + ['methodsType' => gettype($methods)]
            )];
        }
        if ($methods === []) {
            return [Finding::failure(
                $this->id(),
                'route registers an empty method list and is reachable by no request',
                $context
            )];
        }
        $invalid = [];
        foreach ($methods as $method) {
            if (!is_string($method) || !in_array($method, self::VALID_METHODS, true)) {
                $invalid[] = is_string($method) ? $method : gettype($method);
            }
        }
        if ($invalid !== []) {
            return [Finding::failure(
                $this->id(),
                'route methods contain '.implode(', ', array_map(function ($value) {
                    return '"'.$value.'"';
                }, $invalid)).', which the router can never dispatch',
                $context
            )];
        }
        return [];
    }

    /**
     * @param array<string,mixed> $registration
     * @param array<string,mixed> $context
     * @return array<int,Finding>
     */
    private function checkType(array $registration, array $context)
    {
        $type = $registration['type'];
        if (is_string($type) && in_array($type, self::VALID_TYPES, true)) {
            return [];
        }
        return [Finding::failure(
            $this->id(),
            'route type '.(is_string($type) ? '"'.$type.'"' : gettype($type)).' is not one of the router\'s '
                .'permission buckets, so the route drops out of the session and admin gates',
            $context
        )];
    }
}
