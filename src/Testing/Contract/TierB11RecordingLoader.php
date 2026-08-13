<?php
/**
 * @author Joe Huss <detain@interserver.net>
 * @copyright 2026
 * @package MyAdmin
 * @category Testing
 */

namespace MyAdmin\Plugins\Testing\Contract;

use MyAdmin\Plugins\Loader;

/**
 * A real {@see Loader} that also keeps every route registration in call order.
 *
 * ---------------------------------------------------------------------------------
 * WHY THIS EXISTS RATHER THAN READING `get_routes()`
 * ---------------------------------------------------------------------------------
 * `Loader::add_route_requirement()` stores routes as `$this->routes[$path] = ...`. A second
 * registration of the same path therefore **silently overwrites the first** — no warning, no
 * error, and nothing left in `get_routes()` to show it ever happened. That is not
 * hypothetical: `Loader::add_apmin_api_page_requirement()`'s own docblock records exactly
 * this bug, where an admin_api registration quietly replaced a client_ajax one.
 *
 * Reading `get_routes()` after the fact can therefore never detect a duplicate path, which
 * is one of the three things Tier-B-11 exists to detect. This subclass records each call as
 * it happens, so collisions survive in the log.
 *
 * ---------------------------------------------------------------------------------
 * WHY A PROBE LOADER RATHER THAN REIMPLEMENTING THE PATH RULES
 * ---------------------------------------------------------------------------------
 * `add_route_requirement()` applies two defaults of its own: `$path === false` becomes
 * `'/'.$function`, and `$methods === false` becomes `['GET', 'POST']`. Copying those two
 * lines into the override would be a model of the Loader that could drift from it silently.
 * Instead each call is replayed onto a *throwaway* `Loader`, whose `get_routes()` then holds
 * exactly one entry: the production-normalised path, type, handler and methods. The
 * normalisation is therefore always the real one.
 */
class TierB11RecordingLoader extends Loader
{
    /**
     * Every registration in call order, including ones a later call overwrote.
     *
     * @var array<int,array<string,mixed>>
     */
    private $registrations = [];

    /**
     * Label attached to registrations made by the next call — the source line in
     * source-scan mode, null when the plugin's handler was executed directly.
     *
     * @var int|null
     */
    private $currentLine = null;

    /**
     * Records, then delegates.
     *
     * @param string       $type
     * @param string|array $function
     * @param string       $source
     * @param string|false $path
     * @param mixed        $methods
     * @return void
     */
    public function add_route_requirement($type, $function, $source = '', $path = false, $methods = false)
    {
        // `$path === false` makes the parent build `'/'.$function`. When `$function` is a
        // `[class, 'METHOD']` array that is an array-to-string conversion, which under
        // `failOnWarning` would turn a *detected* defect into a suite error. Record the
        // malformed registration and do not let the parent evaluate it.
        if ($path === false && !is_string($function)) {
            $this->registrations[] = [
                'path' => null,
                'type' => $type,
                'function' => $function,
                'methods' => $methods,
                'line' => $this->currentLine,
            ];
            return;
        }
        $probe = new Loader();
        $probe->add_route_requirement($type, $function, $source, $path, $methods);
        foreach ($probe->get_routes() as $probePath => $route) {
            $this->registrations[] = [
                'path' => $probePath,
                'type' => $route[0],
                'function' => $route[1],
                'methods' => $route[2],
                'line' => $this->currentLine,
            ];
        }
        parent::add_route_requirement($type, $function, $source, $path, $methods);
    }

    /**
     * Labels every registration made while running $callback with a source line.
     *
     * @param int|null $line
     * @return void
     */
    public function setCurrentLine($line)
    {
        $this->currentLine = $line === null ? null : (int)$line;
    }

    /**
     * Every route registration observed, in call order.
     *
     * @return array<int,array<string,mixed>>
     */
    public function registrations()
    {
        return $this->registrations;
    }
}
