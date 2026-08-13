<?php
/**
 * @author Joe Huss <detain@interserver.net>
 * @copyright 2026
 * @package MyAdmin
 * @category Testing
 */

namespace MyAdmin\Plugins\Testing;

use MyAdmin\Plugins\Testing\Fakes\FakeAccounts;
use MyAdmin\Plugins\Testing\Fakes\FakeApi;
use MyAdmin\Plugins\Testing\Fakes\FakeApp;
use MyAdmin\Plugins\Testing\Fakes\FakeDb;
use MyAdmin\Plugins\Testing\Fakes\FakeEvents;
use MyAdmin\Plugins\Testing\Fakes\FakeHistory;
use MyAdmin\Plugins\Testing\Fakes\FakeMenu;
use MyAdmin\Plugins\Testing\Fakes\FakeOutput;
use MyAdmin\Plugins\Testing\Fakes\FakeSession;
use MyAdmin\Plugins\Testing\Fakes\FakeSettings;
use MyAdmin\Plugins\Testing\Fakes\FakeSmarty;
use MyAdmin\Plugins\Testing\Fakes\FakeTable;
use MyAdmin\Plugins\Testing\Fakes\FakeVariables;

/**
 * The handle `Bootstrap::init()` returns: every fake, in one place, plus the
 * state the global stubs in `stubs.php` read.
 *
 * Static rather than instance-based because the global function stubs have no
 * object to reach through — `has_acl()` is a bare function and must answer
 * from *somewhere*. Keeping that state here rather than in loose `$GLOBALS`
 * keys means it is typed, discoverable and resettable in one call.
 */
class Harness
{
    /** @var \MyAdmin\Plugins\Testing\Fakes\FakeSettings|null */
    private static $settings;

    /** @var \MyAdmin\Plugins\Testing\Fakes\FakeMenu|null */
    private static $menu;

    /** @var \MyAdmin\Plugins\Testing\Fakes\FakeDb|null */
    private static $db;

    /** @var \MyAdmin\Plugins\Testing\Fakes\FakeHistory|null */
    private static $history;

    /** @var \MyAdmin\Plugins\Testing\Fakes\FakeSession|null */
    private static $session;

    /** @var \MyAdmin\Plugins\Testing\Fakes\FakeAccounts|null */
    private static $accounts;

    /** @var \MyAdmin\Plugins\Testing\Fakes\FakeVariables|null */
    private static $variables;

    /** @var \MyAdmin\Plugins\Testing\Fakes\FakeSmarty|null */
    private static $smarty;

    /** @var \MyAdmin\Plugins\Testing\Fakes\FakeTable|null */
    private static $table;

    /** @var \MyAdmin\Plugins\Testing\Fakes\FakeEvents|null */
    private static $events;

    /** @var \MyAdmin\Plugins\Testing\Fakes\FakeOutput|null */
    private static $output;

    /** @var \MyAdmin\Plugins\Testing\Fakes\FakeApi|null */
    private static $api;

    /**
     * Permissions `has_acl()` answers true for.
     *
     * @var array<int,string>
     */
    private static $acl = [];

    /**
     * When true, `has_acl()` grants everything regardless of the allowlist.
     *
     * @var bool
     */
    private static $aclGrantAll = false;

    /**
     * Rows `get_service()` hands back, keyed "module:id".
     *
     * @var array<string,array<string,mixed>>
     */
    private static $services = [];

    /**
     * Recorded `dialog()` calls.
     *
     * @var array<int,array<string,mixed>>
     */
    private static $dialogs = [];

    /**
     * Recorded `make_insert_query()` calls.
     *
     * @var array<int,array<string,mixed>>
     */
    private static $insertQueries = [];

    /**
     * The module `Bootstrap::init()` was configured with.
     *
     * @var string
     */
    private static $module = 'default';

    // -----------------------------------------------------------------------
    // Fake accessors — each lazily constructed so a test can reach any of them
    // -----------------------------------------------------------------------

    /**
     * @return \MyAdmin\Plugins\Testing\Fakes\FakeSettings
     */
    public static function settings()
    {
        if (self::$settings === null) {
            self::$settings = new FakeSettings();
        }
        return self::$settings;
    }

    /**
     * @return \MyAdmin\Plugins\Testing\Fakes\FakeMenu
     */
    public static function menu()
    {
        if (self::$menu === null) {
            self::$menu = new FakeMenu();
        }
        return self::$menu;
    }

    /**
     * @return \MyAdmin\Plugins\Testing\Fakes\FakeDb
     */
    public static function db()
    {
        if (self::$db === null) {
            self::$db = new FakeDb();
        }
        return self::$db;
    }

    /**
     * @return \MyAdmin\Plugins\Testing\Fakes\FakeHistory
     */
    public static function history()
    {
        if (self::$history === null) {
            self::$history = new FakeHistory();
        }
        return self::$history;
    }

    /**
     * @return \MyAdmin\Plugins\Testing\Fakes\FakeSession
     */
    public static function session()
    {
        if (self::$session === null) {
            self::$session = new FakeSession();
        }
        return self::$session;
    }

    /**
     * @return \MyAdmin\Plugins\Testing\Fakes\FakeAccounts
     */
    public static function accounts()
    {
        if (self::$accounts === null) {
            self::$accounts = new FakeAccounts();
        }
        return self::$accounts;
    }

    /**
     * @return \MyAdmin\Plugins\Testing\Fakes\FakeVariables
     */
    public static function variables()
    {
        if (self::$variables === null) {
            self::$variables = new FakeVariables();
        }
        return self::$variables;
    }

    /**
     * @return \MyAdmin\Plugins\Testing\Fakes\FakeSmarty
     */
    public static function smarty()
    {
        if (self::$smarty === null) {
            self::$smarty = new FakeSmarty();
        }
        return self::$smarty;
    }

    /**
     * @return \MyAdmin\Plugins\Testing\Fakes\FakeTable
     */
    public static function table()
    {
        if (self::$table === null) {
            self::$table = new FakeTable();
        }
        return self::$table;
    }

    /**
     * @return \MyAdmin\Plugins\Testing\Fakes\FakeEvents
     */
    public static function events()
    {
        if (self::$events === null) {
            self::$events = new FakeEvents();
        }
        return self::$events;
    }

    /**
     * @return \MyAdmin\Plugins\Testing\Fakes\FakeOutput
     */
    public static function output()
    {
        if (self::$output === null) {
            self::$output = new FakeOutput();
        }
        return self::$output;
    }

    /**
     * The sink the `api_register*()` stubs write into.
     *
     * @return \MyAdmin\Plugins\Testing\Fakes\FakeApi
     */
    public static function api()
    {
        if (self::$api === null) {
            self::$api = new FakeApi();
        }
        return self::$api;
    }

    /**
     * The log sink. Static class, returned as a name for symmetry with the
     * other accessors.
     *
     * @return string the Log class name, for `Harness::log()::entries()`-style use
     */
    public static function logClass()
    {
        return Log::class;
    }

    /**
     * Convenience: recorded log entries.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function logEntries()
    {
        return Log::entries();
    }

    // -----------------------------------------------------------------------
    // State the global stubs read
    // -----------------------------------------------------------------------

    /**
     * @param string $permission
     * @return bool
     */
    public static function hasAcl($permission)
    {
        if (self::$aclGrantAll) {
            return true;
        }
        return in_array($permission, self::$acl, true);
    }

    /**
     * Replaces the `has_acl()` allowlist.
     *
     * @param array<int,string>|bool $acl an allowlist, or true to grant everything
     * @return void
     */
    public static function setAcl($acl)
    {
        if ($acl === true) {
            self::$aclGrantAll = true;
            self::$acl = [];
            return;
        }
        self::$aclGrantAll = false;
        self::$acl = is_array($acl) ? array_values($acl) : [];
    }

    /**
     * Seeds the row `get_service()` returns.
     *
     * @param int|string          $id
     * @param string              $module
     * @param array<string,mixed> $row
     * @return void
     */
    public static function setService($id, $module, array $row)
    {
        self::$services[$module . ':' . $id] = $row;
    }

    /**
     * @param int|string $id
     * @param string     $module
     * @param bool       $acl
     * @return array<string,mixed>|false
     */
    public static function service($id, $module, $acl = false)
    {
        $key = $module . ':' . $id;
        return array_key_exists($key, self::$services) ? self::$services[$key] : false;
    }

    /**
     * @param string $title
     * @param string $text
     * @param bool   $return
     * @param string $options
     * @return string the markup a real dialog() would have produced
     */
    public static function recordDialog($title, $text, $return, $options)
    {
        self::$dialogs[] = [
            'title'   => $title,
            'text'    => $text,
            'return'  => $return,
            'options' => $options,
        ];
        return '<div class="dialog"><h3>' . $title . '</h3><p>' . $text . '</p></div>';
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public static function dialogs()
    {
        return self::$dialogs;
    }

    /**
     * @param string              $table
     * @param array<string,mixed> $args
     * @param string              $query
     * @return void
     */
    public static function recordInsertQuery($table, array $args, $query)
    {
        self::$insertQueries[] = ['table' => $table, 'args' => $args, 'query' => $query];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public static function insertQueries()
    {
        return self::$insertQueries;
    }

    /**
     * @param string $module
     * @return void
     */
    public static function setModule($module)
    {
        self::$module = (string)$module;
    }

    /**
     * @return string
     */
    public static function module()
    {
        return self::$module;
    }

    // -----------------------------------------------------------------------
    // Lifecycle
    // -----------------------------------------------------------------------

    /**
     * Installs a fake, replacing whatever is there. Used by `Bootstrap::init()`
     * and by a test that wants a pre-seeded double.
     *
     * @param string $name settings|menu|db|history|session|accounts|variables|smarty|table|events|output|api
     * @param object $fake
     * @return void
     */
    public static function set($name, $fake)
    {
        switch ($name) {
            case 'settings':  self::$settings = $fake; break;
            case 'menu':      self::$menu = $fake; break;
            case 'db':        self::$db = $fake; break;
            case 'history':   self::$history = $fake; break;
            case 'session':   self::$session = $fake; break;
            case 'accounts':  self::$accounts = $fake; break;
            case 'variables': self::$variables = $fake; break;
            case 'smarty':    self::$smarty = $fake; break;
            case 'table':     self::$table = $fake; break;
            case 'events':    self::$events = $fake; break;
            case 'output':    self::$output = $fake; break;
            case 'api':       self::$api = $fake; break;
            default:
                throw new \InvalidArgumentException('Unknown harness fake: ' . $name);
        }
    }

    /**
     * Clears recorded state on every fake **without** discarding the fakes and
     * **without** touching constants.
     *
     * Constants are deliberately untouched: PHP constants are immutable once
     * defined, so a `reset()` that tried to redefine them would either fatal
     * or silently do nothing. See {@see ConstantStub} on the consequences.
     *
     * Safe to call from `setUp()`.
     *
     * @return void
     */
    public static function reset()
    {
        foreach ([
            self::$settings, self::$menu, self::$db, self::$history, self::$session,
            self::$accounts, self::$variables, self::$smarty, self::$table,
            self::$events, self::$output, self::$api,
        ] as $fake) {
            if ($fake !== null && method_exists($fake, 'reset')) {
                $fake->reset();
            }
        }
        self::$dialogs = [];
        self::$insertQueries = [];
        self::$services = [];
        Log::reset();
        if (class_exists(FakeApp::class, false)) {
            FakeApp::reset();
        }
    }

    /**
     * Drops every fake. For use between test *classes*; `reset()` is what a
     * `setUp()` wants.
     *
     * @return void
     */
    public static function tearDownAll()
    {
        self::$settings = null;
        self::$menu = null;
        self::$db = null;
        self::$history = null;
        self::$session = null;
        self::$accounts = null;
        self::$variables = null;
        self::$smarty = null;
        self::$table = null;
        self::$events = null;
        self::$output = null;
        self::$api = null;
        self::$acl = [];
        self::$aclGrantAll = false;
        self::$services = [];
        self::$dialogs = [];
        self::$insertQueries = [];
        self::$module = 'default';
        Log::reset();
        FakeApp::tearDownAll();
    }
}
