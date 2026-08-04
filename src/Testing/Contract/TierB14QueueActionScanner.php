<?php
/**
 * @author Joe Huss <detain@interserver.net>
 * @copyright 2026
 * @package MyAdmin
 * @category Testing
 */

namespace MyAdmin\Plugins\Testing\Contract;

/**
 * Token scan that recovers, from a `getQueue()` body, where it renders templates from and
 * which action names it names literally.
 *
 * ---------------------------------------------------------------------------------
 * WHAT A QUEUE HANDLER ACTUALLY LOOKS LIKE (verified across the 8 that exist)
 * ---------------------------------------------------------------------------------
 * Six of the eight dispatch on a template path built at runtime:
 *
 * ```php
 * if (!file_exists(__DIR__.'/../templates/'.$serviceInfo['action'].'.sh.tpl')) {
 *     myadmin_log(self::$module, 'error', 'Call '.$serviceInfo['action'].' ... Does not Exist ...');
 * } else {
 *     $output = $smarty->fetch(__DIR__.'/../templates/'.$serviceInfo['action'].'.sh.tpl');
 * }
 * ```
 *
 * The consequence for B-14 is the single most important thing to understand about this
 * check: **the action name is data, not code.** It arrives from the queue row, so the set of
 * actions a handler can reach is not enumerable from its source. Only two things are:
 *
 *  - the **template directory**, which is a literal — and not always the plugin's own.
 *    `myadmin-quickservers-module` renders from `__DIR__.'/../../myadmin-kvm-vps/templates/'`,
 *    reaching into a sibling package it does not declare a dependency on.
 *  - the handful of action names the handler mentions **literally**, which it does only when
 *    it special-cases them.
 *
 * ## Extraction rules, and what each one misses
 *
 * An action name is harvested only when it is a direct operand of a test against an
 * `['action']` subscript. Three forms are recognised:
 *
 *  - **R1** `in_array($serviceInfo['action'] ?? '', ['create', 'reinstall_os'])`
 *  - **R2** `$serviceInfo['action'] === 'create'` (and `==`, `!=`, `!==`, either order)
 *  - **R3** `switch ($serviceInfo['action']) { case 'create': ... }`
 *
 * The deliberate narrowness is what makes the result usable. An earlier and more obvious
 * rule — "harvest string literals near an `['action']` subscript" — was rejected after it
 * pulled `'error'` out of
 * `myadmin_log(self::$module, 'error', 'Call '.$serviceInfo['action'].' ...')`
 * and would have reported a missing `error.sh.tpl` on six packages. False failures are the
 * expensive kind; these rules produce none on the fleet.
 *
 * **Known false negatives**, all reported rather than hidden, because {@see TierB14TemplateCompleteness}
 * turns "nothing to cross-check" into a skip rather than a pass:
 *
 *  - actions reached only through data (`$queueCalls[$serviceInfo['action']]`) or through a
 *    computed method name (`'queue'.ucwords($action, '_')`, which `myadmin-hyperv-vps` uses);
 *  - actions named in a sibling package or in core rather than in the handler. `myadmin-xen-vps`
 *    ships no `create.sh.tpl` while every other hypervisor does; nothing in its own source
 *    says `create`, so no anchored check can see it.
 */
class TierB14QueueActionScanner
{
    /**
     * Suffix that marks a queue template.
     *
     * @var string
     */
    const TEMPLATE_SUFFIX = '.sh.tpl';

    /**
     * Array key a queue handler reads the action from.
     *
     * @var string
     */
    const ACTION_KEY = 'action';

    /**
     * Every `*.sh.tpl` render target the source builds.
     *
     * Each entry is `directory` (the literal path fragment up to and including the last
     * separator), `anchor` (`dir` for a `__DIR__`-rooted chain, `absolute`, or `relative`),
     * `dynamic` (whether a non-literal sits between the directory and the suffix) and
     * `template` (the basename when the whole chain is literal, else null).
     *
     * @param string $source PHP source including the opening tag
     * @return array<int,array<string,mixed>>
     */
    public static function templateDispatches($source)
    {
        $tokens = self::significant(token_get_all($source));
        $found = [];
        $count = count($tokens);
        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            if (!is_array($token) || $token[0] !== T_CONSTANT_ENCAPSED_STRING) {
                continue;
            }
            $value = self::unquote($token[1]);
            if (substr($value, -strlen(self::TEMPLATE_SUFFIX)) !== self::TEMPLATE_SUFFIX) {
                continue;
            }
            $dispatch = self::describeChain($tokens, $i);
            if ($dispatch === null) {
                continue;
            }
            $key = $dispatch['anchor'].'|'.$dispatch['directory'].'|'.($dispatch['dynamic'] ? '1' : '0')
                .'|'.(string)$dispatch['template'];
            $found[$key] = $dispatch;
        }
        return array_values($found);
    }

    /**
     * Action names the source names literally, in first-seen order.
     *
     * @param string $source PHP source including the opening tag
     * @return array<int,string>
     */
    public static function actionLiterals($source)
    {
        $tokens = self::significant(token_get_all($source));
        $actions = [];
        foreach (self::membershipActions($tokens) as $action) {
            $actions[] = $action;
        }
        foreach (self::comparisonActions($tokens) as $action) {
            $actions[] = $action;
        }
        foreach (self::switchActions($tokens) as $action) {
            $actions[] = $action;
        }
        $unique = [];
        foreach ($actions as $action) {
            if (!self::plausibleAction($action) || in_array($action, $unique, true)) {
                continue;
            }
            $unique[] = $action;
        }
        return $unique;
    }

    /**
     * R1 — `in_array(<expr with ['action']>, [ ... ])`.
     *
     * @param array<int,array{0:int,1:string,2:int}|string> $tokens
     * @return array<int,string>
     */
    private static function membershipActions(array $tokens)
    {
        $actions = [];
        $count = count($tokens);
        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            if (!is_array($token) || $token[0] !== T_STRING || strtolower($token[1]) !== 'in_array') {
                continue;
            }
            if (!isset($tokens[$i + 1]) || $tokens[$i + 1] !== '(') {
                continue;
            }
            $close = self::matchingBracket($tokens, $i + 1);
            if ($close === null) {
                continue;
            }
            $arguments = self::splitTopLevel(array_slice($tokens, $i + 2, $close - $i - 2), ',');
            if (count($arguments) < 2 || !self::readsAction($arguments[0])) {
                continue;
            }
            foreach (self::arrayLiteralStrings($arguments[1]) as $action) {
                $actions[] = $action;
            }
        }
        return $actions;
    }

    /**
     * R2 — `<expr with ['action']> ==|===|!=|!== '<literal>'`, either order.
     *
     * @param array<int,array{0:int,1:string,2:int}|string> $tokens
     * @return array<int,string>
     */
    private static function comparisonActions(array $tokens)
    {
        $operators = [T_IS_EQUAL, T_IS_IDENTICAL, T_IS_NOT_EQUAL, T_IS_NOT_IDENTICAL];
        $actions = [];
        $count = count($tokens);
        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            if (!is_array($token) || !in_array($token[0], $operators, true)) {
                continue;
            }
            $left = self::operandBefore($tokens, $i);
            $right = self::operandAfter($tokens, $i);
            $literal = null;
            if (self::readsAction($left)) {
                $literal = self::loneString($right);
            } elseif (self::readsAction($right)) {
                $literal = self::loneString($left);
            }
            if ($literal !== null) {
                $actions[] = $literal;
            }
        }
        return $actions;
    }

    /**
     * R3 — `switch (<expr with ['action']>) { case '<literal>': }`.
     *
     * @param array<int,array{0:int,1:string,2:int}|string> $tokens
     * @return array<int,string>
     */
    private static function switchActions(array $tokens)
    {
        $actions = [];
        $count = count($tokens);
        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            if (!is_array($token) || $token[0] !== T_SWITCH) {
                continue;
            }
            if (!isset($tokens[$i + 1]) || $tokens[$i + 1] !== '(') {
                continue;
            }
            $closeParen = self::matchingBracket($tokens, $i + 1);
            if ($closeParen === null) {
                continue;
            }
            if (!self::readsAction(array_slice($tokens, $i + 2, $closeParen - $i - 2))) {
                continue;
            }
            $openBrace = $closeParen + 1;
            if (!isset($tokens[$openBrace]) || $tokens[$openBrace] !== '{') {
                continue;
            }
            $closeBrace = self::matchingBracket($tokens, $openBrace);
            if ($closeBrace === null) {
                continue;
            }
            $body = array_slice($tokens, $openBrace + 1, $closeBrace - $openBrace - 1);
            $bodyCount = count($body);
            for ($j = 0; $j < $bodyCount; $j++) {
                if (!is_array($body[$j]) || $body[$j][0] !== T_CASE) {
                    continue;
                }
                if (!isset($body[$j + 1]) || !is_array($body[$j + 1])
                    || $body[$j + 1][0] !== T_CONSTANT_ENCAPSED_STRING) {
                    continue;
                }
                $actions[] = self::unquote($body[$j + 1][1]);
            }
        }
        return $actions;
    }

    /**
     * Whether a token slice contains an `['action']` subscript.
     *
     * @param array<int,array{0:int,1:string,2:int}|string> $slice
     * @return bool
     */
    private static function readsAction(array $slice)
    {
        $count = count($slice);
        for ($i = 0; $i + 2 < $count; $i++) {
            if ($slice[$i] !== '[') {
                continue;
            }
            $key = $slice[$i + 1];
            if (!is_array($key) || $key[0] !== T_CONSTANT_ENCAPSED_STRING) {
                continue;
            }
            if ($slice[$i + 2] === ']' && self::unquote($key[1]) === self::ACTION_KEY) {
                return true;
            }
        }
        return false;
    }

    /**
     * String elements of a list-style array literal, or [] when it is not one.
     *
     * @param array<int,array{0:int,1:string,2:int}|string> $slice
     * @return array<int,string>
     */
    private static function arrayLiteralStrings(array $slice)
    {
        if ($slice === []) {
            return [];
        }
        $open = 0;
        if (is_array($slice[0]) && $slice[0][0] === T_ARRAY) {
            $open = 1;
        }
        if (!isset($slice[$open]) || ($slice[$open] !== '[' && $slice[$open] !== '(')) {
            return [];
        }
        $close = self::matchingBracket($slice, $open);
        if ($close === null || $close !== count($slice) - 1) {
            return [];
        }
        $strings = [];
        foreach (self::splitTopLevel(array_slice($slice, $open + 1, $close - $open - 1), ',') as $element) {
            $literal = self::loneString($element);
            if ($literal !== null) {
                $strings[] = $literal;
            }
        }
        return $strings;
    }

    /**
     * The value of a slice that is exactly one string literal, else null.
     *
     * @param array<int,array{0:int,1:string,2:int}|string> $slice
     * @return string|null
     */
    private static function loneString(array $slice)
    {
        if (count($slice) !== 1 || !is_array($slice[0]) || $slice[0][0] !== T_CONSTANT_ENCAPSED_STRING) {
            return null;
        }
        return self::unquote($slice[0][1]);
    }

    /**
     * Describes the concatenation chain ending at the `.sh.tpl` literal at $index.
     *
     * @param array<int,array{0:int,1:string,2:int}|string> $tokens
     * @param int                                           $index
     * @return array<string,mixed>|null
     */
    private static function describeChain(array $tokens, $index)
    {
        $slice = self::operandChainBefore($tokens, $index);
        $slice[] = $tokens[$index];
        $parts = [];
        foreach (self::splitTopLevel($slice, '.') as $piece) {
            if (count($piece) === 1 && is_array($piece[0]) && $piece[0][0] === T_DIR) {
                $parts[] = ['kind' => 'dir'];
                continue;
            }
            $literal = self::loneString($piece);
            $parts[] = $literal === null ? ['kind' => 'dyn'] : ['kind' => 'lit', 'value' => $literal];
        }
        if ($parts === []) {
            return null;
        }
        $anchor = 'relative';
        if ($parts[0]['kind'] === 'dir') {
            $anchor = 'dir';
            array_shift($parts);
        }
        if ($parts === []) {
            return null;
        }
        // A `__DIR__`-less chain whose first literal is rooted is an absolute path.
        if ($anchor === 'relative' && $parts[0]['kind'] === 'lit' && strpos($parts[0]['value'], '/') === 0) {
            $anchor = 'absolute';
        }
        $prefix = '';
        $dynamic = false;
        foreach ($parts as $part) {
            if ($part['kind'] !== 'lit') {
                $dynamic = true;
                break;
            }
            $prefix .= $part['value'];
        }
        if (!$dynamic) {
            // Whole chain literal: everything before the final separator is the directory.
            $separator = strrpos($prefix, '/');
            $directory = $separator === false ? '' : substr($prefix, 0, $separator + 1);
            $basename = $separator === false ? $prefix : substr($prefix, $separator + 1);
            return [
                'anchor' => $anchor,
                'directory' => $directory,
                'dynamic' => false,
                'template' => substr($basename, 0, -strlen(self::TEMPLATE_SUFFIX)),
            ];
        }
        $separator = strrpos($prefix, '/');
        return [
            'anchor' => $anchor,
            'directory' => $separator === false ? $prefix : substr($prefix, 0, $separator + 1),
            'dynamic' => true,
            'template' => null,
        ];
    }

    /**
     * Tokens of the concatenation chain immediately preceding $index, in source order.
     *
     * @param array<int,array{0:int,1:string,2:int}|string> $tokens
     * @param int                                           $index
     * @return array<int,array{0:int,1:string,2:int}|string>
     */
    private static function operandChainBefore(array $tokens, $index)
    {
        if (!isset($tokens[$index - 1]) || $tokens[$index - 1] !== '.') {
            return [];
        }
        $collected = self::operandBefore($tokens, $index - 1);
        $collected[] = '.';
        return $collected;
    }

    /**
     * The expression slice ending immediately before $index, in source order.
     *
     * @param array<int,array{0:int,1:string,2:int}|string> $tokens
     * @param int                                           $index
     * @return array<int,array{0:int,1:string,2:int}|string>
     */
    private static function operandBefore(array $tokens, $index)
    {
        $collected = [];
        $depth = 0;
        for ($i = $index - 1; $i >= 0; $i--) {
            $token = $tokens[$i];
            if ($token === ')' || $token === ']' || $token === '}') {
                $depth++;
            } elseif ($token === '(' || $token === '[' || $token === '{') {
                if ($depth === 0) {
                    break;
                }
                $depth--;
            } elseif ($depth === 0 && self::breaksExpression($token)) {
                break;
            }
            $collected[] = $token;
        }
        return array_reverse($collected);
    }

    /**
     * The expression slice starting immediately after $index, in source order.
     *
     * @param array<int,array{0:int,1:string,2:int}|string> $tokens
     * @param int                                           $index
     * @return array<int,array{0:int,1:string,2:int}|string>
     */
    private static function operandAfter(array $tokens, $index)
    {
        $collected = [];
        $depth = 0;
        $count = count($tokens);
        for ($i = $index + 1; $i < $count; $i++) {
            $token = $tokens[$i];
            if ($token === '(' || $token === '[' || $token === '{') {
                $depth++;
            } elseif ($token === ')' || $token === ']' || $token === '}') {
                if ($depth === 0) {
                    break;
                }
                $depth--;
            } elseif ($depth === 0 && self::breaksExpression($token)) {
                break;
            }
            $collected[] = $token;
        }
        return $collected;
    }

    /**
     * Tokens that cannot appear inside a single concatenation/comparison operand.
     *
     * @param array{0:int,1:string,2:int}|string $token
     * @return bool
     */
    private static function breaksExpression($token)
    {
        if (!is_array($token)) {
            return in_array($token, [',', ';', '=', '?', ':', '!', '<', '>', '&', '|'], true);
        }
        $breaking = [
            T_BOOLEAN_AND, T_BOOLEAN_OR, T_LOGICAL_AND, T_LOGICAL_OR, T_LOGICAL_XOR,
            T_IS_EQUAL, T_IS_IDENTICAL, T_IS_NOT_EQUAL, T_IS_NOT_IDENTICAL,
            T_IS_SMALLER_OR_EQUAL, T_IS_GREATER_OR_EQUAL, T_SPACESHIP, T_COALESCE,
            T_DOUBLE_ARROW, T_CONCAT_EQUAL,
            T_RETURN, T_ECHO, T_IF, T_ELSEIF, T_CASE, T_SWITCH,
        ];
        return in_array($token[0], $breaking, true);
    }

    /**
     * Whether a harvested literal could name a template file.
     *
     * @param string $action
     * @return bool
     */
    private static function plausibleAction($action)
    {
        return (bool)preg_match('/^[A-Za-z0-9_][A-Za-z0-9_.-]*$/', $action);
    }

    /**
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
            }
        }
        return null;
    }

    /**
     * Splits a slice on a top-level separator token.
     *
     * @param array<int,array{0:int,1:string,2:int}|string> $slice
     * @param string                                        $separator
     * @return array<int,array<int,array{0:int,1:string,2:int}|string>>
     */
    private static function splitTopLevel(array $slice, $separator)
    {
        if ($slice === []) {
            return [];
        }
        $parts = [];
        $current = [];
        $depth = 0;
        foreach ($slice as $token) {
            if ($token === '(' || $token === '[' || $token === '{') {
                $depth++;
            } elseif ($token === ')' || $token === ']' || $token === '}') {
                $depth--;
            }
            if ($token === $separator && $depth === 0) {
                $parts[] = $current;
                $current = [];
                continue;
            }
            $current[] = $token;
        }
        $parts[] = $current;
        return $parts;
    }

    /**
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
}
