<?php
/**
 * @author Joe Huss <detain@interserver.net>
 * @copyright 2026
 * @package MyAdmin
 * @category Testing
 */

namespace MyAdmin\Plugins\Testing;

use MyAdmin\Plugins\Testing\Fakes\FakeApp;

/**
 * One call sets up everything a plugin needs to have its handlers actually
 * execute under test.
 *
 * A plugin's whole `tests/bootstrap.php` becomes:
 *
 *     <?php
 *     require __DIR__ . '/../vendor/autoload.php';
 *     \MyAdmin\Plugins\Testing\Bootstrap::init(['module' => 'vps']);
 *
 * Five lines, replacing the 341 in `myadmin-vps-module` and the 4,366 spread
 * across 30 repos in four competing conventions.
 *
 * ## Order of operations, and why it is this order
 *
 * 1. **Constant overrides**, then the scanned stubs. Constants are immutable,
 *    so an explicit value must be defined before anything else claims the name.
 * 2. **Install `FakeApp` as `\MyAdmin\App`.** Must happen before any plugin
 *    code runs, and before step 4, because the installer's real
 *    `get_module_db()` delegates to `App::has()` / `App::db()`.
 * 3. **Load `stubs.php`.** Must happen before step 4: the installer's real
 *    `get_module_db()` calls `myadmin_log()` on its fallback path, which
 *    fatals if the stub is not yet defined. (Found by spike, not by reading.)
 * 4. **`register_module()` + `$GLOBALS['<module>_dbh']`.** This is what makes
 *    the installer's *real* `get_module_settings()` and `get_module_db()`
 *    return useful values instead of needing to be shadowed.
 *
 * ## Idempotent
 *
 * Safe to call from both `tests/bootstrap.php` and every `setUp()`. Repeat
 * calls re-apply configuration but never re-alias `\MyAdmin\App` — a second
 * `class_alias()` to an existing name emits a warning and returns false, and
 * PHPUnit turns warnings into test errors, so the guard is load-bearing rather
 * than tidy.
 */
class Bootstrap
{
    /**
     * @var bool whether stubs.php has been required
     */
    private static $stubsLoaded = false;

    /**
     * @var bool whether \MyAdmin\App has been aliased by us
     */
    private static $appInstalled = false;

    /**
     * Namespaces that have had function stubs injected, to keep injection
     * idempotent. See {@see Bootstrap::stubNamespace()}.
     *
     * @var array<string,array<int,string>>
     */
    private static $stubbedNamespaces = [];

    /**
     * Implementations behind {@see Bootstrap::stubNamespace()}.
     *
     * Held here rather than closed over inside the eval'd function so the
     * behaviour can be swapped per test without redeclaring anything — PHP
     * cannot redeclare a function, so this indirection is what makes the
     * mechanism reusable at all.
     *
     * @var array<string,callable>
     */
    private static $namespaceStubs = [];

    /**
     * Sets up the harness.
     *
     * Options, all optional:
     *
     *   module     string  drives register_module() and the module db handle
     *   settings   array   PREFIX/TABLE/TBLNAME/… for get_module_settings()
     *   constants  array   explicit constant values, applied before scanning
     *   plugin     string  a plugin class to scan for bare constants (D4)
     *   ima        string  'admin' or 'client' — App::ima()
     *   acl        array|true  has_acl() allowlist, or true to grant everything
     *   defines    array   name => int map for get_service_define()
     *   request    array   seeds App::variables()
     *   rows       array   rows the module FakeDb hands out via next_record()
     *
     * @param array<string,mixed> $options
     * @return string the Harness class name, so callers can chain assertions
     */
    public static function init(array $options = [])
    {
        $module = isset($options['module']) ? (string)$options['module'] : 'default';

        // 1. Constants first — they are immutable, so explicit values must win.
        if (isset($options['constants']) && is_array($options['constants'])) {
            ConstantStub::defineOverrides($options['constants']);
        }
        self::defineBaseConstants();
        if (isset($options['plugin']) && is_string($options['plugin'])) {
            ConstantStub::defineFrom($options['plugin']);
        }

        // 2. FakeApp must exist before any plugin code or module wiring runs.
        self::installApp();

        // 3. Global stubs — get_module_db()'s fallback path calls myadmin_log().
        self::loadStubs();

        // 4. Wire the module through the installer's *real* functions.
        Harness::setModule($module);
        self::registerModule($module, isset($options['settings']) && is_array($options['settings']) ? $options['settings'] : []);
        self::installModuleDb($module, isset($options['rows']) && is_array($options['rows']) ? $options['rows'] : []);

        // 5. Remaining configuration.
        FakeApp::install([
            'history'   => Harness::history(),
            'accounts'  => Harness::accounts(),
            'session'   => Harness::session(),
            'variables' => Harness::variables(),
            'db'        => Harness::db(),
            'events'    => Harness::events(),
            'output'    => Harness::output(),
        ]);
        FakeApp::setIma(isset($options['ima']) ? (string)$options['ima'] : 'client');
        if (isset($options['defines']) && is_array($options['defines'])) {
            FakeApp::setServiceDefines($options['defines']);
        }
        Harness::setAcl(isset($options['acl']) ? $options['acl'] : []);
        if (isset($options['request']) && is_array($options['request'])) {
            Harness::variables()->setRequest($options['request']);
        }

        return Harness::class;
    }

    /**
     * Aliases {@see FakeApp} to `\MyAdmin\App`.
     *
     * Returns false without complaint when `\MyAdmin\App` already exists —
     * which happens when the harness is run inside a real core bootstrap, or
     * when a repo's own legacy doubles already declared one. That is the
     * documented R9 fallback: the harness stands down rather than fighting for
     * the name, and everything else still works.
     *
     * @return bool whether this call installed the alias
     */
    public static function installApp()
    {
        if (self::$appInstalled) {
            return false;
        }
        if (class_exists('MyAdmin\App', false)) {
            // Someone else owns the name. Record that so reset() does not
            // assume our fake is behind it.
            self::$appInstalled = false;
            return false;
        }
        class_alias(FakeApp::class, 'MyAdmin\App');
        self::installTestContainerBuilder();
        self::$appInstalled = true;
        return true;
    }

    /**
     * Aliases the harness {@see TestContainerBuilder} into core's name.
     *
     * Repos already call `\MyAdmin\App\Testing\TestContainerBuilder::make()`
     * unguarded — `myadmin-virtuozzo-vps` does, and `myadmin-vps-module` guards
     * it with a `class_exists()` that has always been false. That class lives
     * in the core tree and in no package, so those call sites have never once
     * executed. Providing the name here is what turns them on.
     *
     * @return bool whether this call installed the alias
     */
    public static function installTestContainerBuilder()
    {
        if (class_exists('MyAdmin\App\Testing\TestContainerBuilder', false)) {
            return false;
        }
        class_alias(TestContainerBuilder::class, 'MyAdmin\App\Testing\TestContainerBuilder');
        return true;
    }

    /**
     * Requires `src/stubs.php` exactly once.
     *
     * D2: this is the **only** place that file is ever loaded. It is not, and
     * must never be, in `composer.json`'s `autoload.files`.
     *
     * @return bool whether this call loaded it
     */
    public static function loadStubs()
    {
        if (self::$stubsLoaded) {
            return false;
        }
        require_once __DIR__ . '/stubs.php';
        self::$stubsLoaded = true;
        return true;
    }

    /**
     * Registers the module with the installer's **real** `register_module()`,
     * which is what makes its **real** `get_module_settings()` work.
     *
     * Defaults are derived from the module name in the same shape core uses,
     * so `$settings['PREFIX'] . '_id'` resolves for a plugin that never
     * declared them.
     *
     * @param string              $module
     * @param array<string,mixed> $settings
     * @return array<string,mixed> the settings actually registered
     */
    public static function registerModule($module, array $settings = [])
    {
        $defaults = [
            'PREFIX'  => $module,
            'TABLE'   => $module,
            'TBLNAME' => strtoupper($module),
            'TITLE'   => ucfirst($module),
        ];
        $merged = array_merge($defaults, $settings);
        if (function_exists('register_module')) {
            register_module($module, $merged);
        } else {
            // The installer's autoload.files should always have provided this;
            // fall back to the same global it writes so the harness still works
            // if a consumer loaded us without the installer's files entry.
            $modules = isset($GLOBALS['modules']) && is_array($GLOBALS['modules']) ? $GLOBALS['modules'] : [];
            $modules[$module] = $merged;
            $GLOBALS['modules'] = $modules;
        }
        return $merged;
    }

    /**
     * Points `$GLOBALS['<module>_dbh']` at the harness FakeDb.
     *
     * This is the whole trick for `get_module_db()`. The installer's real
     * implementation returns `clone $GLOBALS['<module>_dbh']` when that key is
     * set — including for the hardcoded `powerdns` and `zonemta` branches,
     * which otherwise construct a real `\MyDb\Mdb2\Db` against undefined
     * connection constants and fatal. Presetting the global skips that
     * construction entirely, so those two modules need no special casing.
     *
     * The returned clone shares the fake's {@see CallLog}, so queries issued
     * through the clone are still visible to the test. See {@see Fakes\FakeDb}.
     *
     * @param string                         $module
     * @param array<int,array<string,mixed>> $rows
     * @return \MyAdmin\Plugins\Testing\Fakes\FakeDb
     */
    public static function installModuleDb($module, array $rows = [])
    {
        $db = Harness::db();
        if ($rows !== []) {
            $db->setRows($rows);
        }
        $GLOBALS[$module . '_dbh'] = $db;
        $GLOBALS['default_dbh'] = $db;
        return $db;
    }

    /**
     * Injects namespace-scoped function stubs.
     *
     * ## When you need this
     *
     * Plugin code calls core functions **unqualified** (`get_module_db(...)`,
     * not `\get_module_db(...)`), and PHP resolves an unqualified call against
     * the current namespace before falling back to global. A function declared
     * as `Detain\MyAdminHyperv\get_module_db` therefore wins over the
     * installer's global one without any `function_exists()` guard fight.
     *
     * **You should not normally need this.** The four functions the installer
     * defines globally are all reachable through `FakeApp` and the module
     * wiring above, which is cheaper and needs no per-repo code. This exists
     * for the genuine remainder:
     *
     *   - a plugin-specific core helper the harness cannot know about
     *     (`vps_get_password()`, `ipcalc()`, `get_service_master()`);
     *   - a test that needs one function to behave differently from the
     *     harness-wide default, for one namespace only.
     *
     * ## Mechanism
     *
     * `eval()` with a namespace declaration, verified to work on PHP 8.2–8.4.
     * A generated-and-committed forwarder file is the alternative and is the
     * recommended shape for permanent per-repo stubs — see
     * `docs/testing-harness.md`, which records this as an open decision for
     * the owner rather than settling it here.
     *
     * @param string                $namespace  e.g. 'Detain\MyAdminHyperv'
     * @param array<string,callable> $functions  name => implementation
     * @return array<int,string> the fully-qualified names actually declared
     */
    public static function stubNamespace($namespace, array $functions)
    {
        $namespace = trim((string)$namespace, '\\');
        $declared = [];
        foreach ($functions as $name => $implementation) {
            $fqn = $namespace . '\\' . $name;
            if (function_exists($fqn)) {
                continue;
            }
            self::$stubbedNamespaces[$namespace][$name] = $fqn;
            $key = var_export($fqn, true);
            $code = 'namespace ' . $namespace . ' { '
                . 'function ' . $name . '(...$args) { '
                . 'return \\MyAdmin\\Plugins\\Testing\\Bootstrap::callNamespaceStub(' . $key . ', $args); '
                . '} }';
            eval($code);
            self::$namespaceStubs[$fqn] = $implementation;
            $declared[] = $fqn;
        }
        return $declared;
    }

    /**
     * Dispatch target for every namespace-scoped stub.
     *
     * @param string           $fqn
     * @param array<int,mixed> $args
     * @return mixed
     * @throws \RuntimeException when no implementation is registered
     */
    public static function callNamespaceStub($fqn, array $args)
    {
        if (!isset(self::$namespaceStubs[$fqn])) {
            throw new \RuntimeException('No namespace stub registered for ' . $fqn);
        }
        return call_user_func_array(self::$namespaceStubs[$fqn], $args);
    }

    /**
     * Swaps the implementation behind an already-declared namespace stub.
     *
     * @param string   $fqn
     * @param callable $implementation
     * @return void
     */
    public static function setNamespaceStub($fqn, $implementation)
    {
        self::$namespaceStubs[trim((string)$fqn, '\\')] = $implementation;
    }

    /**
     * Constants the harness itself needs, independent of any plugin.
     *
     * `MYSQL_ASSOC` is the one that matters: it is passed to
     * `next_record(MYSQL_ASSOC)` by 6 plugins and is a core `define()`, not a
     * PHP built-in — `MYSQLI_ASSOC` is the built-in and has value 1.
     *
     * @return void
     */
    private static function defineBaseConstants()
    {
        if (!defined('MYSQL_ASSOC')) {
            define('MYSQL_ASSOC', defined('MYSQLI_ASSOC') ? MYSQLI_ASSOC : 1);
        }
        if (!defined('MYSQL_NUM')) {
            define('MYSQL_NUM', defined('MYSQLI_NUM') ? MYSQLI_NUM : 2);
        }
        if (!defined('MYSQL_BOTH')) {
            define('MYSQL_BOTH', defined('MYSQLI_BOTH') ? MYSQLI_BOTH : 3);
        }
    }

    /**
     * Whether `\MyAdmin\App` is this harness's fake rather than the real core
     * class or a repo's own double.
     *
     * @return bool
     */
    public static function ownsApp()
    {
        return self::$appInstalled;
    }

    /**
     * Resets recorded state on every fake. Delegates to {@see Harness::reset()};
     * provided here so a `setUp()` only has to know one class.
     *
     * @return void
     */
    public static function reset()
    {
        Harness::reset();
    }
}
