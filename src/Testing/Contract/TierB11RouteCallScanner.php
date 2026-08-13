<?php
/**
 * @author Joe Huss <detain@interserver.net>
 * @copyright 2026
 * @package MyAdmin
 * @category Testing
 */

namespace MyAdmin\Plugins\Testing\Contract;

use MyAdmin\Plugins\Loader;
use ReflectionClass;
use ReflectionMethod;

/**
 * Token scan that recovers a plugin's route registrations from its source.
 *
 * ---------------------------------------------------------------------------------
 * WHY SOURCE SCANNING AND NOT EXECUTION
 * ---------------------------------------------------------------------------------
 * Route registration in this fleet happens inside a hook handler:
 *
 * ```php
 * public static function getRequirements(GenericEvent $event)
 * {
 *     $loader = $event->getSubject();
 *     $loader->add_page_requirement('abuse', '/../vendor/detain/myadmin-abuse-plugin/src/abuse.php');
 * }
 * ```
 *
 * The handler is **typed** on `Symfony\Component\EventDispatcher\GenericEvent`, and
 * `symfony/event-dispatcher` is not a dependency of this installer package — it is a
 * dependency of each *plugin*. Executing the handler from here would therefore require
 * either declaring a class in Symfony's namespace (a D2 violation: a test double shadowing
 * a real production class) or being silently unrunnable in this repo's own suite. Both were
 * rejected, so the call sites are recovered from tokens instead and then **replayed against
 * a real {@see Loader}** ({@see TierB11RecordingLoader}) so that path composition and
 * defaulting are the production ones rather than a model of them.
 *
 * `token_get_all()` rather than a regex, matching {@see \MyAdmin\Plugins\Testing\ConstantStub}:
 * commented-out call sites arrive as a single `T_COMMENT` and are skipped for free, and
 * argument boundaries are recovered by bracket depth rather than guessed.
 *
 * ## Known limits
 *
 * - Only the plugin class's own file is scanned. A plugin registering routes from a helper
 *   class is invisible here (false negative). No fleet plugin does this today.
 * - Arguments that are not literals — variables, constants, concatenations involving either,
 *   function calls — cannot be evaluated. Such a call is reported with `resolved => false`
 *   so the caller can count it rather than silently pass it.
 */
class TierB11RouteCallScanner
{
    /**
     * Public `add_*` methods of {@see Loader} that do **not** register a route.
     *
     * Everything else matching `add_*` funnels through `add_route_requirement()`, so the
     * helper list is derived from the class rather than hardcoded: a new helper added to
     * `Loader` is picked up without editing this file.
     *
     * @var array<int,string>
     */
    const NON_ROUTE_HELPERS = ['add_requirement'];

    /**
     * Per-file scan cache, keyed by "path@mtime".
     *
     * @var array<string,array<int,array<string,mixed>>>
     */
    private static $scanCache = [];

    /**
     * The `Loader` methods that register a route, with their arities.
     *
     * @return array<string,array<string,mixed>> keyed by lowercased method name
     */
    public static function routeHelpers()
    {
        $helpers = [];
        $reflection = new ReflectionClass(Loader::class);
        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            $name = $method->getName();
            if (strpos($name, 'add_') !== 0) {
                continue;
            }
            if (in_array($name, self::NON_ROUTE_HELPERS, true)) {
                continue;
            }
            $helpers[strtolower($name)] = [
                'name' => $name,
                'required' => $method->getNumberOfRequiredParameters(),
                'total' => $method->getNumberOfParameters(),
            ];
        }
        return $helpers;
    }

    /**
     * @param string $file absolute path to a PHP file
     * @return array<int,array<string,mixed>>
     */
    public static function scanFile($file)
    {
        if (!is_file($file) || !is_readable($file)) {
            return [];
        }
        $key = $file.'@'.(string)filemtime($file);
        if (isset(self::$scanCache[$key])) {
            return self::$scanCache[$key];
        }
        $calls = self::scanSource((string)file_get_contents($file));
        self::$scanCache[$key] = $calls;
        return $calls;
    }

    /**
     * Recovers every `$something->add_*_requirement(...)` call in a source string.
     *
     * Each entry is `helper` (the real method name), `line`, `argCount`, `resolved`
     * (whether every argument evaluated to a literal) and `args` (the evaluated values,
     * empty when `resolved` is false).
     *
     * @param string $source PHP source including the opening tag
     * @return array<int,array<string,mixed>>
     */
    public static function scanSource($source)
    {
        $helpers = self::routeHelpers();
        $tokens = self::significant(token_get_all($source));
        $calls = [];
        $count = count($tokens);
        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            if (!is_array($token) || $token[0] !== T_STRING) {
                continue;
            }
            if (!isset($helpers[strtolower($token[1])])) {
                continue;
            }
            if (!self::precededByObjectOperator($tokens, $i)) {
                continue;
            }
            if (!isset($tokens[$i + 1]) || $tokens[$i + 1] !== '(') {
                continue;
            }
            $close = self::matchingBracket($tokens, $i + 1);
            if ($close === null) {
                continue;
            }
            $slices = self::splitArguments(array_slice($tokens, $i + 2, $close - $i - 2));
            $values = [];
            $resolved = true;
            foreach ($slices as $slice) {
                $evaluated = self::evaluate($slice);
                if ($evaluated === null) {
                    $resolved = false;
                    break;
                }
                $values[] = $evaluated['value'];
            }
            $calls[] = [
                'helper' => $helpers[strtolower($token[1])]['name'],
                'line' => $token[2],
                'argCount' => count($slices),
                'resolved' => $resolved,
                'args' => $resolved ? $values : [],
            ];
            $i = $close;
        }
        return $calls;
    }

    /**
     * Drops whitespace and comments, keeping the `[id, text, line]` shape of the rest.
     *
     * @param array<int,array{0:int,1:string,2:int}|string> $tokens
     * @return array<int,array{0:int,1:string,2:int}|string>
     */
    private static function significant(array $tokens)
    {
        $kept = [];
        foreach ($tokens as $token) {
            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $kept[] = $token;
        }
        return $kept;
    }

    /**
     * @param array<int,array{0:int,1:string,2:int}|string> $tokens
     * @param int                                           $index
     * @return bool
     */
    private static function precededByObjectOperator(array $tokens, $index)
    {
        if ($index === 0) {
            return false;
        }
        $previous = $tokens[$index - 1];
        if (!is_array($previous)) {
            return false;
        }
        if ($previous[0] === T_OBJECT_OPERATOR) {
            return true;
        }
        // PHP 8.0's `?->`. Resolved at runtime because 7.4 has no such constant.
        return defined('T_NULLSAFE_OBJECT_OPERATOR') && $previous[0] === constant('T_NULLSAFE_OBJECT_OPERATOR');
    }

    /**
     * Index of the bracket closing the one at $open, or null when unbalanced.
     *
     * @param array<int,array{0:int,1:string,2:int}|string> $tokens
     * @param int                                           $open
     * @return int|null
     */
    private static function matchingBracket(array $tokens, $open)
    {
        $depth = 0;
        $count = count($tokens);
        for ($i = $open; $i < $count; $i++) {
            $token = $tokens[$i];
            if ($token === '(' || $token === '[' || $token === '{') {
                $depth++;
            } elseif ($token === ')' || $token === ']' || $token === '}') {
                $depth--;
                if ($depth === 0) {
                    return $i;
                }
            } elseif (is_array($token) && self::opensCurly($token)) {
                $depth++;
            }
        }
        return null;
    }

    /**
     * `${`, `{$` and `#[` all open a brace that must be counted.
     *
     * @param array{0:int,1:string,2:int} $token
     * @return bool
     */
    private static function opensCurly(array $token)
    {
        $openers = [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES];
        if (defined('T_ATTRIBUTE')) {
            $openers[] = constant('T_ATTRIBUTE');
        }
        return in_array($token[0], $openers, true);
    }

    /**
     * Splits an argument-list token slice on top-level commas.
     *
     * @param array<int,array{0:int,1:string,2:int}|string> $slice
     * @return array<int,array<int,array{0:int,1:string,2:int}|string>>
     */
    private static function splitArguments(array $slice)
    {
        if ($slice === []) {
            return [];
        }
        $arguments = [];
        $current = [];
        $depth = 0;
        foreach ($slice as $token) {
            if ($token === '(' || $token === '[' || $token === '{') {
                $depth++;
            } elseif ($token === ')' || $token === ']' || $token === '}') {
                $depth--;
            } elseif (is_array($token) && self::opensCurly($token)) {
                $depth++;
            }
            if ($token === ',' && $depth === 0) {
                $arguments[] = $current;
                $current = [];
                continue;
            }
            $current[] = $token;
        }
        $arguments[] = $current;
        return $arguments;
    }

    /**
     * Evaluates a token slice to a PHP value, or null when it is not a literal.
     *
     * Handles: single- and double-quoted strings without interpolation, `.` concatenation
     * of those, list-style array literals of the same, integers, floats, and the bare words
     * `true`/`false`/`null`. Everything else — variables, constants, calls — is a miss.
     *
     * @param array<int,array{0:int,1:string,2:int}|string> $slice
     * @return array{value:mixed}|null
     */
    private static function evaluate(array $slice)
    {
        if ($slice === []) {
            return null;
        }
        // Array literal: `[ ... ]` or `array( ... )`.
        $first = $slice[0];
        if ($first === '[' || (is_array($first) && $first[0] === T_ARRAY)) {
            $open = $first === '[' ? 0 : 1;
            if (!isset($slice[$open]) || ($slice[$open] !== '[' && $slice[$open] !== '(')) {
                return null;
            }
            $close = self::matchingBracket($slice, $open);
            if ($close === null || $close !== count($slice) - 1) {
                return null;
            }
            $values = [];
            foreach (self::splitArguments(array_slice($slice, $open + 1, $close - $open - 1)) as $element) {
                if ($element === []) {
                    continue;
                }
                $evaluated = self::evaluate($element);
                if ($evaluated === null) {
                    return null;
                }
                $values[] = $evaluated['value'];
            }
            return ['value' => $values];
        }
        // Concatenation chain of scalars.
        $parts = [];
        $expectOperand = true;
        foreach ($slice as $token) {
            if ($expectOperand) {
                $scalar = self::scalar($token);
                if ($scalar === null) {
                    return null;
                }
                $parts[] = $scalar['value'];
                $expectOperand = false;
                continue;
            }
            if ($token !== '.') {
                return null;
            }
            $expectOperand = true;
        }
        if ($expectOperand) {
            return null;
        }
        if (count($parts) === 1) {
            return ['value' => $parts[0]];
        }
        $joined = '';
        foreach ($parts as $part) {
            if (!is_scalar($part)) {
                return null;
            }
            $joined .= (string)$part;
        }
        return ['value' => $joined];
    }

    /**
     * @param array{0:int,1:string,2:int}|string $token
     * @return array{value:mixed}|null
     */
    private static function scalar($token)
    {
        if (!is_array($token)) {
            return null;
        }
        if ($token[0] === T_CONSTANT_ENCAPSED_STRING) {
            return ['value' => self::unquote($token[1])];
        }
        if ($token[0] === T_LNUMBER) {
            return ['value' => (int)$token[1]];
        }
        if ($token[0] === T_DNUMBER) {
            return ['value' => (float)$token[1]];
        }
        if ($token[0] === T_STRING) {
            $word = strtolower($token[1]);
            if ($word === 'true') {
                return ['value' => true];
            }
            if ($word === 'false') {
                return ['value' => false];
            }
            if ($word === 'null') {
                return ['value' => null];
            }
        }
        return null;
    }

    /**
     * Unquotes a `T_CONSTANT_ENCAPSED_STRING`.
     *
     * Double-quoted forms are run through `stripcslashes()`; the token type guarantees there
     * is no interpolation, so the only difference from PHP's own unescaping is in exotic
     * octal/hex forms that do not appear in route literals.
     *
     * @param string $text
     * @return string
     */
    private static function unquote($text)
    {
        $body = substr($text, 1, -1);
        if ($body === false) {
            return '';
        }
        if ($text[0] === "'") {
            return str_replace(['\\\\', "\\'"], ['\\', "'"], $body);
        }
        return stripcslashes($body);
    }

    /**
     * Forgets the scan cache.
     *
     * @return void
     */
    public static function reset()
    {
        self::$scanCache = [];
    }
}
