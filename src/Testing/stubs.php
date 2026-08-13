<?php
/**
 * Global function stubs for the MyAdmin core surface that plugin handlers call.
 *
 * ## D2 — THIS FILE MUST NEVER APPEAR IN composer.json's `autoload.files`
 *
 * The installer is a `composer-plugin`, so everything in its `autoload.files`
 * is loaded into **every production install**. If this file joined
 * `src/function_requirements.php` and `src/modules.php` there, it would shadow
 * the real `myadmin_log()`, `has_acl()` and `dialog()` in production —
 * catastrophic and silent: logging would vanish and `has_acl()` would return a
 * fixed answer for every permission check.
 *
 * It is therefore pulled in **only** by an explicit `require` from
 * {@see \MyAdmin\Plugins\Testing\Bootstrap::init()}, and
 * `Tests\MyAdmin\Plugins\Testing\AutoloadTripwireTest` fails the build if any
 * path under `src/Testing/` is ever added to `autoload.files`. That test is a
 * tripwire, not a comment — it was verified to go red when the path is added.
 *
 * ## Why these names and not the other four
 *
 * `get_module_settings()`, `get_module_db()`, `get_service_define()` and
 * `function_requirements()` are deliberately **absent** from this file. The
 * installer already defines all four at autoload time, so a
 * `function_exists()`-guarded redefinition here would be dead code — the guard
 * is always false. Those four are handled instead by making the installer's
 * *real* implementations work:
 *
 *   - `get_module_settings()` reads `$GLOBALS['modules']`, which `Bootstrap`
 *     populates via the installer's own `register_module()`. No fake needed.
 *   - `get_module_db()` returns `clone $GLOBALS['<module>_dbh']` when that is
 *     set, which `Bootstrap` sets to a {@see \MyAdmin\Plugins\Testing\Fakes\FakeDb}.
 *   - `get_service_define()` and `function_requirements()` are pure
 *     delegations to `\MyAdmin\App`, which `Bootstrap` aliases to
 *     {@see \MyAdmin\Plugins\Testing\Fakes\FakeApp}.
 *
 * See `docs/testing-harness.md` for the full reasoning and the spike that
 * verified it.
 *
 * Every signature below was diffed against core at build time; three of them
 * differ from an earlier revision of the plan and follow **core**, not the
 * plan: `has_acl($permission)`, `get_service(..., $acl = false)` (not `null`),
 * and `generate_password($length = 8, $available_sets = 'luds')` (not
 * `$len = 12`).
 *
 * @author Joe Huss <detain@interserver.net>
 * @copyright 2026
 * @package MyAdmin
 * @category Testing
 */

use MyAdmin\Plugins\Testing\Harness;
use MyAdmin\Plugins\Testing\Log;

if (!function_exists('myadmin_log')) {
    /**
     * The most-called symbol in the fleet: 807 calls across plugin sources.
     *
     * @param string $section
     * @param string $level
     * @param string $text
     * @param mixed  $line
     * @param mixed  $file
     * @param mixed  $module
     * @param mixed  $service
     * @param bool   $tf
     * @param bool   $calltrace
     * @param mixed  $custid
     * @return void
     */
    function myadmin_log($section, $level, $text, $line = '', $file = '', $module = false, $service = false, $tf = true, $calltrace = false, $custid = false)
    {
        Log::add($section, $level, $text, $line, $file, $module, $service, $custid);
    }
}

if (!function_exists('add_output')) {
    /**
     * 297 fleet calls. Buffers rather than echoing — see FakeOutput on why
     * echoing would trip `beStrictAboutOutputDuringTests`.
     *
     * @param mixed $value
     * @return void
     */
    function add_output($value)
    {
        Harness::output()->add($value);
    }
}

if (!function_exists('run_event')) {
    /**
     * 56 fleet calls.
     *
     * @param string $event
     * @param mixed  $args
     * @param string $module
     * @param mixed  $section
     * @return mixed the args, unchanged, as core does when no listener alters them
     */
    function run_event($event, $args = false, $module = 'default', $section = false)
    {
        return Harness::events()->runEvent($event, $args, $module, $section);
    }
}

if (!function_exists('has_acl')) {
    /**
     * 48 fleet calls. Answers from the allowlist `Bootstrap::init(['acl' => …])`
     * seeded, so a test can drive both the granted and denied branches of a
     * menu or handler.
     *
     * Note the parameter name is `$permission`, matching core
     * (`include/admin/configuration/admin_rbac.php:49`) rather than the `$acl`
     * an earlier plan revision listed.
     *
     * @param string $permission
     * @return bool
     */
    function has_acl($permission)
    {
        return Harness::hasAcl($permission);
    }
}

if (!function_exists('dialog')) {
    /**
     * 54 fleet calls.
     *
     * @param string $title
     * @param string $text
     * @param bool   $return
     * @param string $options
     * @return string|void the markup when $return is true, as core does
     */
    function dialog($title, $text, $return = false, $options = '')
    {
        $markup = Harness::recordDialog($title, $text, $return, $options);
        if ($return) {
            return $markup;
        }
        Harness::output()->add($markup);
    }
}

if (!function_exists('get_service')) {
    /**
     * 11 fleet calls. Returns whatever row the test seeded for that id and
     * module, or false — mirroring core, which returns false for an unknown or
     * unauthorised service.
     *
     * Core's third parameter defaults to `false`, not `null`.
     *
     * @param int|string $id
     * @param string     $module
     * @param bool       $acl
     * @return array<string,mixed>|false
     */
    function get_service($id, $module, $acl = false)
    {
        return Harness::service($id, $module, $acl);
    }
}

if (!function_exists('make_insert_query')) {
    /**
     * 30 fleet calls. Builds a real INSERT string, byte-compatible with core's
     * output for the simple cases, so a test asserting on the generated SQL is
     * asserting on something true.
     *
     * @param string              $table
     * @param array<string,mixed> $args
     * @param array<string,mixed>|false $duplicate_args
     * @return string
     */
    function make_insert_query($table, $args, $duplicate_args = false)
    {
        if (!is_array($args) || count($args) === 0) {
            return '';
        }
        $quote = static function ($value) {
            if ($value === null) {
                return 'NULL';
            }
            if (is_int($value) || is_float($value)) {
                return (string)$value;
            }
            return "'" . addslashes((string)$value) . "'";
        };
        $fields = array_keys($args);
        $values = array_map($quote, array_values($args));
        $query = 'insert into ' . $table . ' (' . implode(', ', $fields) . ') values (' . implode(', ', $values) . ')';
        if (is_array($duplicate_args) && count($duplicate_args) > 0) {
            $pairs = [];
            foreach ($duplicate_args as $field => $value) {
                $pairs[] = $field . '=' . $quote($value);
            }
            $query .= ' on duplicate key update ' . implode(', ', $pairs);
        }
        Harness::recordInsertQuery($table, $args, $query);
        return $query;
    }
}

if (!function_exists('generate_password')) {
    /**
     * Deterministic on purpose: a random password makes an assertion on the
     * generated value impossible, and the *value* is never the thing under
     * test — its presence and placement are.
     *
     * Core's signature is `($length = 8, $available_sets = 'luds')`, not the
     * `($len = 12)` an earlier plan revision listed.
     *
     * @param int    $length
     * @param string $available_sets
     * @return string
     */
    function generate_password($length = 8, $available_sets = 'luds')
    {
        return substr(str_repeat('Stub1Pass!', (int)ceil(max(1, $length) / 10)), 0, max(1, (int)$length));
    }
}

if (!function_exists('api_register')) {
    /**
     * The `api.register` surface: three bare functions in
     * `include/Api/api.functions.inc.php` that write straight into globals, dispatched
     * by `api_register_init()`. Nine fleet packages implement the handler and 63 fleet
     * `api_register*()` calls pass through here.
     *
     * The `function_exists()` guard is not decorative for these three. Core's
     * `api.functions.inc.php` is `require_once`d by the API entry points, so a process
     * that has bootstrapped enough of core to reach one already owns these names, and
     * redeclaring would be a fatal.
     *
     * Signature verbatim from `api.functions.inc.php:176` — note `$logged_in` and
     * `$wrap` default to **true**, which is what makes an omitted argument mean
     * "session-checked and prefixed with api_".
     *
     * @param string $function
     * @param mixed  $input
     * @param mixed  $output
     * @param string $label
     * @param bool   $logged_in
     * @param bool   $wrap
     * @return void
     */
    function api_register($function, $input, $output, $label = '', $logged_in = true, $wrap = true)
    {
        Harness::api()->api_register($function, $input, $output, $label, $logged_in, $wrap);
    }
}

if (!function_exists('api_register_array')) {
    /**
     * Registers a complex type. Signature verbatim from `api.functions.inc.php:201`.
     *
     * @param string $function
     * @param mixed  $data
     * @return void
     */
    function api_register_array($function, $data)
    {
        Harness::api()->api_register_array($function, $data);
    }
}

if (!function_exists('api_register_array_array')) {
    /**
     * Registers an array-of-a-type. Signature verbatim from `api.functions.inc.php:156`.
     *
     * @param string $arraysName
     * @param string $targetArray
     * @return void
     */
    function api_register_array_array($arraysName, $targetArray)
    {
        Harness::api()->api_register_array_array($arraysName, $targetArray);
    }
}

if (!function_exists('slugify')) {
    /**
     * Core's `Settings` uses this to key sections and categories. Defined here
     * so a plugin that calls it directly does not fatal.
     *
     * @param string $text
     * @return string
     */
    function slugify($text)
    {
        $slug = strtolower((string)$text);
        $slug = preg_replace('/[^a-z0-9]+/', '_', $slug);
        return trim((string)$slug, '_');
    }
}

if (!function_exists('_')) {
    /**
     * gettext passthrough.
     *
     * The guard is **mandatory, not stylistic**: CI may or may not load
     * ext-gettext, which provides `_()` natively. Redeclaring it when the
     * extension is present is a fatal error.
     *
     * @param string $message
     * @return string
     */
    function _($message)
    {
        return $message;
    }
}
