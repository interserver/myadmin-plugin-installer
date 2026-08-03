<?php
/**
 * @author Joe Huss <detain@interserver.net>
 * @copyright 2026
 * @package MyAdmin
 * @category Testing
 */

namespace MyAdmin\Plugins\Testing;

use ReflectionClass;

/**
 * Defines the bare global constants a plugin's source references, so its
 * handlers can actually execute under test.
 *
 * Why this exists: 206 distinct bare constants are referenced directly in
 * plugin `Plugin.php` bodies across the fleet — `PRORATE_BILLING`,
 * `FANTASTICO_USERNAME`, `MAXMIND_ENABLE`, … In PHP 8 an undefined constant is
 * a fatal `Error`, not a notice, so *any* attempt to call `getSettings()`
 * without defining them first dies at the first reference. This is measured,
 * not theoretical: `myadmin-mail-module` currently fails 39 tests with
 * `Undefined constant "Detain\MyAdminMail\PRORATE_BILLING"`.
 *
 * Strategy (plan D4):
 *   1. Apply explicit `$overrides` first, each guarded by `defined()`.
 *   2. Token-scan the class's own file for bare constant references.
 *   3. Skip anything already `defined()`, anything that is a loadable class,
 *      and a small denylist of PHP built-ins that slip the regex.
 *   4. `define($name, '__STUB_<NAME>__')` for the remainder.
 *
 * The sentinel is a **string** on purpose. It is self-describing in failure
 * output (`'__STUB_MAXMIND_ENABLE__'` instantly explains itself) and it is
 * truthy, so `if (MAXMIND_ENABLE)` takes the enabled branch — which is the
 * branch worth covering.
 *
 * ## Documented limitation — read this before writing a test
 *
 * **PHP constants are process-global and immutable.** Once `PRORATE_BILLING`
 * is defined in a process it cannot be redefined. A test that needs a
 * *different* value must either use `@runInSeparateProcess`, or pass the value
 * through `Bootstrap::init(['constants' => ...])` **before** anything else
 * defines it, or be redesigned not to depend on the value at all. The third
 * option is almost always the right one. This will otherwise be rediscovered
 * painfully, once per repo.
 */
class ConstantStub
{
    /**
     * Prefix and suffix of the sentinel value.
     *
     * @var string
     */
    const SENTINEL_FORMAT = '__STUB_%s__';

    /**
     * All-caps tokens that are PHP language constructs or built-ins rather than
     * application constants.
     *
     * Most built-ins (`TRUE`, `FALSE`, `NULL`, `PHP_EOL`, `M_PI`,
     * `JSON_PRETTY_PRINT`) are already rejected by the `defined()` guard —
     * verified by running `defined()` over each. `COMMAND` and `DEBUG` are the
     * two that are **not** defined in a bare PHP process and so genuinely need
     * listing; they were surfaced as false positives by the fleet-wide scan.
     * The rest are kept as defence in depth against a host with unusual
     * extensions loaded.
     *
     * @var array<int,string>
     */
    const DENYLIST = [
        'TRUE', 'FALSE', 'NULL',
        'COMMAND', 'DEBUG',
        'PHP_EOL', 'PHP_INT_MAX', 'PHP_INT_MIN', 'PHP_VERSION', 'PHP_OS',
        'M_PI', 'M_E',
        'JSON_PRETTY_PRINT', 'JSON_UNESCAPED_SLASHES', 'JSON_THROW_ON_ERROR',
        'E_ALL', 'E_ERROR', 'E_WARNING', 'E_NOTICE', 'E_STRICT', 'E_DEPRECATED',
        'STDIN', 'STDOUT', 'STDERR',
        'SORT_REGULAR', 'SORT_NUMERIC', 'SORT_STRING',
        'COUNT_RECURSIVE', 'ENT_QUOTES', 'ARRAY_FILTER_USE_KEY',
        'PREG_PATTERN_ORDER', 'PREG_SET_ORDER',
    ];

    /**
     * Tokens which, when they immediately precede an all-caps T_STRING, mean it
     * is a declaration or a name — not a constant *reference*.
     *
     * @var array<int,int>
     */
    private static $precedingDisqualifiers = [
        T_OBJECT_OPERATOR,   // $obj->CONST_LIKE_PROPERTY
        T_DOUBLE_COLON,      // Foo::BAR
        T_NS_SEPARATOR,      // Some\NAMESPACE_SEGMENT
        T_FUNCTION,          // function NAME()
        T_CLASS,             // class NAME
        T_NEW,               // new NAME
        T_CONST,             // const NAME = ...   (a declaration, not a use)
        T_USE,               // use Some\NAME;
        T_EXTENDS,
        T_IMPLEMENTS,
        T_INSTANCEOF,
        T_INTERFACE,
        T_TRAIT,
        T_NAMESPACE,
        T_GOTO,              // goto LABEL;
    ];

    /**
     * Per-file scan cache, keyed by "path@mtime".
     *
     * Token-scanning is not free and `Bootstrap::init()` is idempotent and may
     * be called from every `setUp()`. R8 in the plan flags suite runtime as a
     * risk; this is the mitigation.
     *
     * @var array<string,array<int,string>>
     */
    private static $scanCache = [];

    /**
     * Every constant this class has defined, in order, for assertions and for
     * failure output.
     *
     * @var array<int,string>
     */
    private static $defined = [];

    /**
     * Defines the constants referenced by a plugin class.
     *
     * @param string              $pluginClass fully-qualified class name
     * @param array<string,mixed> $overrides   explicit values, applied first
     * @return array<int,string> the constant names this call defined
     */
    public static function defineFrom($pluginClass, array $overrides = [])
    {
        $newlyDefined = self::defineOverrides($overrides);

        $file = self::classFile($pluginClass);
        if ($file === null) {
            return $newlyDefined;
        }
        foreach (self::scanFile($file) as $name) {
            if (self::define($name, sprintf(self::SENTINEL_FORMAT, $name))) {
                $newlyDefined[] = $name;
            }
        }
        return $newlyDefined;
    }

    /**
     * Defines explicit overrides without scanning anything.
     *
     * @param array<string,mixed> $overrides
     * @return array<int,string> names actually defined by this call
     */
    public static function defineOverrides(array $overrides)
    {
        $newlyDefined = [];
        foreach ($overrides as $name => $value) {
            if (self::define($name, $value)) {
                $newlyDefined[] = $name;
            }
        }
        return $newlyDefined;
    }

    /**
     * Scans a PHP file and returns the bare constant names it references.
     *
     * Public so the scan can be unit-tested and reviewed independently of the
     * side effect of defining anything.
     *
     * @param string $file absolute path to a PHP file
     * @return array<int,string> distinct candidate constant names, in first-seen order
     */
    public static function scanFile($file)
    {
        if (!is_file($file) || !is_readable($file)) {
            return [];
        }
        $key = $file . '@' . (string)filemtime($file);
        if (isset(self::$scanCache[$key])) {
            return self::$scanCache[$key];
        }
        $found = self::scanSource((string)file_get_contents($file));
        self::$scanCache[$key] = $found;
        return $found;
    }

    /**
     * The token scan itself, over a source string.
     *
     * @param string $source PHP source, including the opening tag
     * @return array<int,string> distinct candidate constant names, in first-seen order
     */
    public static function scanSource($source)
    {
        $tokens = token_get_all($source);
        $significant = [];
        foreach ($tokens as $token) {
            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $significant[] = $token;
        }

        $found = [];
        $count = count($significant);
        for ($i = 0; $i < $count; $i++) {
            $token = $significant[$i];
            if (!is_array($token) || $token[0] !== T_STRING) {
                continue;
            }
            $name = $token[1];
            if (!preg_match('/^[A-Z][A-Z0-9_]{2,}$/', $name)) {
                continue;
            }
            $previous = $i > 0 ? $significant[$i - 1] : null;
            if (self::disqualifiedByPrevious($previous)) {
                continue;
            }
            $next = $i + 1 < $count ? $significant[$i + 1] : null;
            if (self::disqualifiedByNext($next)) {
                continue;
            }
            if (!in_array($name, $found, true)) {
                $found[] = $name;
            }
        }
        return $found;
    }

    /**
     * @param array{0:int,1:string}|string|null $previous
     * @return bool
     */
    private static function disqualifiedByPrevious($previous)
    {
        if ($previous === null) {
            return false;
        }
        if (is_array($previous)) {
            return in_array($previous[0], self::$precedingDisqualifiers, true);
        }
        // `#[` opens an attribute; the name inside it is a class, not a constant.
        return $previous === '#[';
    }

    /**
     * @param array{0:int,1:string}|string|null $next
     * @return bool
     */
    private static function disqualifiedByNext($next)
    {
        if ($next === null) {
            return false;
        }
        if (is_array($next)) {
            $disqualifying = [T_VARIABLE, T_DOUBLE_COLON, T_NS_SEPARATOR, T_ELLIPSIS];
            // PHP 8.1 split the `&` token: in `TYPE &$ref` the ampersand is no
            // longer the plain string '&' but T_AMPERSAND_FOLLOWED_BY_VAR_OR_VARARG.
            // Resolved at runtime because the installer still supports 7.4,
            // where the constant does not exist.
            if (defined('T_AMPERSAND_FOLLOWED_BY_VAR_OR_VARARG')) {
                $disqualifying[] = constant('T_AMPERSAND_FOLLOWED_BY_VAR_OR_VARARG');
            }
            // `SOMETYPE $var` is a parameter type hint; `SOME\THING` is a name.
            return in_array($next[0], $disqualifying, true);
        }
        // `SOME_FUNC(` is a call; `TYPE &$ref` is a by-reference type hint on
        // PHP < 8.1, where `&` is still tokenised as a plain string.
        return $next === '(' || $next === '&';
    }

    /**
     * Defines one constant if it is safe and necessary to do so.
     *
     * @param string $name
     * @param mixed  $value
     * @return bool whether this call defined it
     */
    private static function define($name, $value)
    {
        if (in_array($name, self::DENYLIST, true)) {
            return false;
        }
        if (defined($name)) {
            return false;
        }
        // An all-caps class name (rare, but `class DB {}` exists in the wild)
        // must never be shadowed by a constant of the same name.
        if (class_exists($name) || interface_exists($name) || trait_exists($name)) {
            return false;
        }
        define($name, $value);
        self::$defined[] = $name;
        return true;
    }

    /**
     * Resolves the file a class is declared in, without requiring it to be a
     * plugin or to have any particular parent.
     *
     * @param string $class
     * @return string|null
     */
    private static function classFile($class)
    {
        if (!class_exists($class)) {
            return null;
        }
        $reflection = new ReflectionClass($class);
        $file = $reflection->getFileName();
        return $file === false ? null : $file;
    }

    /**
     * Every constant defined by this class so far, in order.
     *
     * @return array<int,string>
     */
    public static function definedConstants()
    {
        return self::$defined;
    }

    /**
     * Forgets the record of what was defined and the scan cache.
     *
     * This does **not** undefine anything: PHP has no mechanism for that. It
     * exists so a test can assert on what a *particular* `defineFrom()` call
     * did. See the class docblock on immutability.
     *
     * @return void
     */
    public static function reset()
    {
        self::$defined = [];
        self::$scanCache = [];
    }
}
