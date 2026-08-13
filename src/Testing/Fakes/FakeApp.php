<?php
/**
 * @author Joe Huss <detain@interserver.net>
 * @copyright 2026
 * @package MyAdmin
 * @category Testing
 */

namespace MyAdmin\Plugins\Testing\Fakes;

use MyAdmin\Plugins\Testing\CallLog;
use MyAdmin\Plugins\Testing\Log;

/**
 * Stand-in for the `\MyAdmin\App` facade, installed under that exact name by
 * `Bootstrap::init()` via `class_alias()`.
 *
 * ## Why this class is the keystone
 *
 * 1,001 `\MyAdmin\App::` references sit in plugin sources, and `App` lives in
 * MyAdmin core, which a plugin's own vendor tree does not contain. That single
 * fact is why `myadmin-virtuozzo-vps` fails **all 52 of its tests** with
 * `Error: Class "MyAdmin\App" not found`.
 *
 * It also solves a problem the plan flagged as unsolvable by a global stub
 * file. The installer's `autoload.files` already defines `get_module_db()`,
 * `get_service_define()`, `function_requirements()` and
 * `get_module_settings()` **into production autoload**, so any
 * `function_exists()`-guarded stub of those names is dead code. But three of
 * those four real functions are *pure delegations to `\MyAdmin\App`*:
 *
 *     function function_requirements($f) { return \MyAdmin\App::functionRequirements($f); }
 *     function get_service_define($s)    { return \MyAdmin\App::getServiceDefine($s); }
 *     function get_module_db($m)         { ... \MyAdmin\App::has() / ::db() ... }
 *
 * Aliasing this class into `\MyAdmin\App` therefore makes the **real,
 * unmodified installer functions work correctly against the fakes**. No
 * shadowing, no guard fight. (The fourth, `get_module_settings()`, needs no
 * fake at all — it reads `$GLOBALS['modules']`, which `Bootstrap` populates
 * through the installer's own `register_module()`.)
 *
 * Verified end-to-end by spike, not assumed. See `docs/testing-harness.md`.
 *
 * ## Calls are recorded, not discarded
 *
 * Per D5, every accessor records. `App::history()` hands back a
 * {@see FakeHistory} whose `add()` calls are assertable, which is the single
 * most useful observable effect a service handler produces.
 */
class FakeApp
{
    /**
     * Shared log for the facade's own static calls.
     *
     * @var \MyAdmin\Plugins\Testing\CallLog|null
     */
    private static $callLog;

    /** @var \MyAdmin\Plugins\Testing\Fakes\FakeHistory|null */
    private static $history;

    /** @var \MyAdmin\Plugins\Testing\Fakes\FakeAccounts|null */
    private static $accounts;

    /** @var \MyAdmin\Plugins\Testing\Fakes\FakeSession|null */
    private static $session;

    /** @var \MyAdmin\Plugins\Testing\Fakes\FakeVariables|null */
    private static $variables;

    /** @var \MyAdmin\Plugins\Testing\Fakes\FakeDb|null */
    private static $db;

    /** @var \MyAdmin\Plugins\Testing\Fakes\FakeEvents|null */
    private static $events;

    /** @var \MyAdmin\Plugins\Testing\Fakes\FakeOutput|null */
    private static $output;

    /**
     * Whatever a test bound via setContainer(). The harness never reads it —
     * it exists so `setContainer()`/`container()` round-trip as core's do.
     *
     * @var object|null
     */
    private static $container;

    /**
     * Value returned by ima(): the panel the request is rendering.
     *
     * @var string
     */
    private static $ima = 'client';

    /**
     * Service-name => int map backing getServiceDefine().
     *
     * @var array<string,mixed>
     */
    private static $serviceDefines = [];

    /**
     * Function names function_requirements() should report as unavailable.
     *
     * @var array<int,string>
     */
    private static $missingRequirements = [];

    // -----------------------------------------------------------------------
    // Container-shaped surface
    // -----------------------------------------------------------------------

    /**
     * Core returns whether the container has a binding. The harness binds
     * everything it fakes, so this is true by default.
     *
     * Load-bearing: the installer's real `get_module_db()` branches on
     * `App::has(\MyAdmin\tf::class)` and falls through to `clone $default_dbh`
     * — cloning null — when this returns false.
     *
     * @param string $id
     * @return bool
     */
    public static function has($id)
    {
        self::log(__FUNCTION__, [$id]);
        // Always true: the harness backs every id the fleet asks for, whether
        // or not a test bound an explicit container. Returning the container's
        // answer would make `get_module_db()` fall to `clone $default_dbh` for
        // an unbound id — which the harness also seeds, so both paths work,
        // but "true" is the simpler and more predictable contract.
        return true;
    }

    /**
     * The `tf` a test bound through {@see \MyAdmin\Plugins\Testing\TestContainerBuilder},
     * if any.
     *
     * @return object|null
     */
    private static function boundTf()
    {
        if (self::$container === null || !method_exists(self::$container, 'get')) {
            return null;
        }
        $tf = self::$container->get('MyAdmin\tf');
        return is_object($tf) ? $tf : null;
    }

    /**
     * @param string $id
     * @return mixed
     */
    public static function get($id)
    {
        self::log(__FUNCTION__, [$id]);
        return null;
    }

    /**
     * Core's container setter. Deliberately **not** type-hinted against
     * `Psr\Container\ContainerInterface`: a plugin's vendor tree may not have
     * psr/container, and a type hint against an unloadable interface fatals at
     * call time rather than failing gracefully.
     *
     * @param object|null $container
     * @return void
     */
    public static function setContainer($container = null)
    {
        self::log(__FUNCTION__, [$container]);
        self::$container = $container;
    }

    /**
     * Core's container getter.
     *
     * @return object|null
     */
    public static function container()
    {
        self::log(__FUNCTION__, []);
        return self::$container;
    }

    /**
     * Core drops the bound container so the next request rebuilds it. Real
     * plugin test suites call this from `setUp()` — `myadmin-virtuozzo-vps`
     * does, in all 52 of its tests — so the fake has to provide it or the
     * alias is useless.
     *
     * The fake clears **recorded state** rather than discarding the fakes
     * themselves, which is what a `setUp()` calling it actually wants: a clean
     * slate that still has working doubles behind it.
     *
     * @return void
     */
    public static function resetContainer()
    {
        self::log(__FUNCTION__, []);
        self::$container = null;
        self::reset();
    }

    // -----------------------------------------------------------------------
    // Service accessors, ordered by measured fleet usage
    // -----------------------------------------------------------------------

    /**
     * 429 fleet references — the most used member of the whole surface.
     *
     * @return \MyAdmin\Plugins\Testing\Fakes\FakeVariables
     */
    public static function variables()
    {
        self::log(__FUNCTION__, []);
        if (self::$variables === null) {
            self::$variables = new FakeVariables();
        }
        return self::$variables;
    }

    /**
     * 130 fleet references.
     *
     * @return \MyAdmin\Plugins\Testing\Fakes\FakeHistory
     */
    public static function history()
    {
        self::log(__FUNCTION__, []);
        if (self::$history === null) {
            self::$history = new FakeHistory();
        }
        return self::$history;
    }

    /**
     * 127 fleet references.
     *
     * @return \MyAdmin\Plugins\Testing\Fakes\FakeAccounts
     */
    public static function accounts()
    {
        self::log(__FUNCTION__, []);
        if (self::$accounts === null) {
            self::$accounts = new FakeAccounts();
        }
        return self::$accounts;
    }

    /**
     * 117 fleet references. The panel currently being rendered — **not** the
     * account's role, which is `accounts()->data['ima']`.
     *
     * @return string
     */
    public static function ima()
    {
        self::log(__FUNCTION__, []);
        return self::$ima;
    }

    /**
     * @return bool
     */
    public static function isAdmin()
    {
        self::log(__FUNCTION__, []);
        return self::$ima === 'admin';
    }

    /**
     * 88 fleet references.
     *
     * @return \MyAdmin\Plugins\Testing\Fakes\FakeSession
     */
    public static function session()
    {
        self::log(__FUNCTION__, []);
        if (self::$session === null) {
            self::$session = new FakeSession();
        }
        return self::$session;
    }

    /**
     * 34 fleet references, plus every call the installer's real
     * `get_module_db()` fallback makes.
     *
     * @return \MyAdmin\Plugins\Testing\Fakes\FakeDb
     */
    public static function db()
    {
        self::log(__FUNCTION__, []);
        if (self::$db === null) {
            self::$db = new FakeDb();
        }
        return self::$db;
    }

    /**
     * 24 fleet references.
     *
     * @return \MyAdmin\Plugins\Testing\Fakes\FakeOutput
     */
    public static function output()
    {
        self::log(__FUNCTION__, []);
        if (self::$output === null) {
            self::$output = new FakeOutput();
        }
        return self::$output;
    }

    /**
     * 7 fleet references.
     *
     * @return \MyAdmin\Plugins\Testing\Fakes\FakeEvents
     */
    public static function events()
    {
        self::log(__FUNCTION__, []);
        if (self::$events === null) {
            self::$events = new FakeEvents();
        }
        return self::$events;
    }

    /**
     * Core aliases dispatcher() to the same object as events().
     *
     * @return \MyAdmin\Plugins\Testing\Fakes\FakeEvents
     */
    public static function dispatcher()
    {
        return self::events();
    }

    /**
     * 6 fleet references. Core returns the legacy `\MyAdmin\tf` instance;
     * plugin code reaches `->db`, `->history`, `->session`, `->variables`
     * through it, so the fake exposes those as properties.
     *
     * @return object
     */
    public static function tf()
    {
        self::log(__FUNCTION__, []);
        $bound = self::boundTf();
        if ($bound !== null) {
            return $bound;
        }
        $tf = new \stdClass();
        $tf->db = self::db();
        $tf->history = self::history();
        $tf->session = self::session();
        $tf->variables = self::variables();
        $tf->accounts = self::accounts();
        $tf->ima = self::$ima;
        return $tf;
    }

    // -----------------------------------------------------------------------
    // Delegation targets used by the installer's real autoload.files functions
    // -----------------------------------------------------------------------

    /**
     * Backs the installer's real global `function_requirements()`.
     *
     * @param string $function
     * @return bool
     */
    public static function functionRequirements($function)
    {
        self::log(__FUNCTION__, [$function]);
        $tf = self::boundTf();
        if ($tf !== null && method_exists($tf, 'function_requirements')) {
            return (bool)$tf->function_requirements($function);
        }
        return !in_array($function, self::$missingRequirements, true);
    }

    /**
     * Backs the installer's real global `get_service_define()`.
     *
     * Unknown names return a stable synthetic int rather than throwing, so a
     * handler comparing `$event['type']` against an unmapped define still runs
     * and the test sees the whole code path. Collisions with real ids are
     * avoided by starting well above the real range.
     *
     * @param string $service
     * @return mixed
     */
    public static function getServiceDefine($service)
    {
        self::log(__FUNCTION__, [$service]);
        // Core implements this as `self::tf()->get_service_define($service)`,
        // and real plugin suites exploit that: `myadmin-virtuozzo-vps` binds a
        // tf whose get_service_define() returns a sentinel so that *no* type
        // matches, then asserts the handler stays inert. Honouring a bound tf
        // first is what makes those already-written tests run.
        $tf = self::boundTf();
        if ($tf !== null && method_exists($tf, 'get_service_define')) {
            return $tf->get_service_define($service);
        }
        if (array_key_exists($service, self::$serviceDefines)) {
            return self::$serviceDefines[$service];
        }
        return self::syntheticDefine($service);
    }

    /**
     * A deterministic, collision-resistant id for an unmapped service name.
     *
     * @param string $service
     * @return int
     */
    public static function syntheticDefine($service)
    {
        return 900000 + (int)(crc32((string)$service) % 90000);
    }

    // -----------------------------------------------------------------------
    // Remaining core surface
    // -----------------------------------------------------------------------

    /**
     * Reversible by construction: `decrypt(encrypt($x)) === $x`. Never real
     * crypto — a fake that actually encrypted would make assertions opaque.
     *
     * @param mixed $plain
     * @return string
     */
    public static function encrypt($plain)
    {
        self::log(__FUNCTION__, [$plain]);
        return '__ENC__' . base64_encode((string)$plain);
    }

    /**
     * @param mixed $cipher
     * @return string
     */
    public static function decrypt($cipher)
    {
        self::log(__FUNCTION__, [$cipher]);
        $cipher = (string)$cipher;
        if (strpos($cipher, '__ENC__') === 0) {
            return (string)base64_decode(substr($cipher, 7));
        }
        return $cipher;
    }

    /**
     * @param mixed $cipher
     * @return string
     */
    public static function decryptOld($cipher)
    {
        return self::decrypt($cipher);
    }

    /**
     * 43 fleet references.
     *
     * @param string $url
     * @param string $extravars
     * @return string
     */
    public static function link($url, $extravars = '')
    {
        self::log(__FUNCTION__, [$url, $extravars]);
        return $extravars === '' ? (string)$url : $url . '?' . $extravars;
    }

    /**
     * @param string $choice
     * @param bool   $lifetime
     * @param bool   $width
     * @return string
     */
    public static function tooltip($choice, $lifetime = true, $width = false)
    {
        self::log(__FUNCTION__, [$choice, $lifetime, $width]);
        return '';
    }

    /**
     * @return string
     */
    public static function language()
    {
        self::log(__FUNCTION__, []);
        return 'en_US';
    }

    /**
     * @return string
     */
    public static function locale()
    {
        self::log(__FUNCTION__, []);
        return 'en_US';
    }

    /**
     * @return string
     */
    public static function defaultTheme()
    {
        self::log(__FUNCTION__, []);
        return 'adminlte';
    }

    /**
     * @param string $module
     * @return void
     */
    public static function load($module)
    {
        self::log(__FUNCTION__, [$module]);
    }

    /**
     * @param string $server
     * @return array<string,mixed>
     */
    public static function getServer($server)
    {
        self::log(__FUNCTION__, [$server]);
        return [];
    }

    /**
     * @param string $server
     * @return void
     */
    public static function removeServer($server)
    {
        self::log(__FUNCTION__, [$server]);
    }

    /**
     * @return \MyAdmin\Plugins\Loader|null
     */
    public static function loader()
    {
        self::log(__FUNCTION__, []);
        return null;
    }

    // -----------------------------------------------------------------------
    // Harness control surface
    // -----------------------------------------------------------------------

    /**
     * Installs the fakes the harness owns. Called by `Bootstrap::init()`.
     *
     * @param array<string,mixed> $fakes keyed by accessor name
     * @return void
     */
    public static function install(array $fakes)
    {
        foreach ($fakes as $name => $fake) {
            switch ($name) {
                case 'history':   self::$history = $fake; break;
                case 'accounts':  self::$accounts = $fake; break;
                case 'session':   self::$session = $fake; break;
                case 'variables': self::$variables = $fake; break;
                case 'db':        self::$db = $fake; break;
                case 'events':    self::$events = $fake; break;
                case 'output':    self::$output = $fake; break;
            }
        }
    }

    /**
     * @param string $ima admin|client
     * @return void
     */
    public static function setIma($ima)
    {
        self::$ima = (string)$ima;
    }

    /**
     * @param array<string,mixed> $defines
     * @return void
     */
    public static function setServiceDefines(array $defines)
    {
        self::$serviceDefines = $defines;
    }

    /**
     * @return array<string,mixed>
     */
    public static function serviceDefines()
    {
        return self::$serviceDefines;
    }

    /**
     * Makes `function_requirements()` report these names as unavailable, so a
     * handler's failure branch can be covered.
     *
     * @param array<int,string> $functions
     * @return void
     */
    public static function setMissingRequirements(array $functions)
    {
        self::$missingRequirements = $functions;
    }

    /**
     * The facade's own recorded static calls.
     *
     * @return \MyAdmin\Plugins\Testing\CallLog
     */
    public static function callLog()
    {
        if (self::$callLog === null) {
            self::$callLog = new CallLog();
        }
        return self::$callLog;
    }

    /**
     * @param string|null $method
     * @return array<int,array{method:string,args:array<int,mixed>}>
     */
    public static function calls($method = null)
    {
        return self::callLog()->calls($method);
    }

    /**
     * Clears recorded calls on the facade and every fake it holds, without
     * discarding the fakes themselves — a test that grabbed a reference in
     * `setUp()` keeps a working handle.
     *
     * @return void
     */
    public static function reset()
    {
        self::callLog()->reset();
        foreach ([self::$history, self::$accounts, self::$session, self::$variables, self::$db, self::$events, self::$output] as $fake) {
            if ($fake !== null && method_exists($fake, 'reset')) {
                $fake->reset();
            }
        }
        Log::reset();
    }

    /**
     * Drops every fake and every setting. Used between test *classes*, not
     * between tests.
     *
     * @return void
     */
    public static function tearDownAll()
    {
        self::$callLog = null;
        self::$history = null;
        self::$accounts = null;
        self::$session = null;
        self::$variables = null;
        self::$db = null;
        self::$events = null;
        self::$output = null;
        self::$ima = 'client';
        self::$serviceDefines = [];
        self::$missingRequirements = [];
    }

    /**
     * @param string           $method
     * @param array<int,mixed> $args
     * @return void
     */
    private static function log($method, array $args)
    {
        self::callLog()->record($method, $args);
    }
}
