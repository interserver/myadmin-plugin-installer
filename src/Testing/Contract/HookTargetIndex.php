<?php
/**
 * @author Joe Huss <detain@interserver.net>
 * @copyright 2026
 * @package MyAdmin
 * @category Testing
 */

namespace MyAdmin\Plugins\Testing\Contract;

/**
 * Answers one structural question for any inspector that executes a plugin handler:
 * **does anything in `getHooks()` actually register this handler?**
 *
 * ---------------------------------------------------------------------------------
 * WHY THIS IS SHARED AND NOT COPIED
 * ---------------------------------------------------------------------------------
 * {@see TierB12SettingsExecute} needs it to decide whether "registered nothing" is a defect
 * or a vacuous complaint about a body core never runs;
 * {@see TierB16ApiRegisterExecute} needs the identical decision for `apiRegister()`. The rule
 * is small but subtly wrong in three easy ways, and each way is a wrong verdict rather than a
 * crash:
 *
 *  - **Keying on a fixed hook name.** For `getSettings` that is wrong by a factor of three —
 *    only 14 of the 56 packages that register the handler use `system.settings`; the other 42
 *    use the per-module `<module>.settings` form. The question to ask is "does any hook name
 *    this class's method?", never "is key X present?".
 *  - **Comparing class names naively.** PHP class names are case-insensitive, and a hook may
 *    legitimately name a parent class whose method is inherited. Both must count.
 *  - **Confusing "no hook targets this" with "no hook value is parseable".** A hook table in
 *    which nothing is a `[class, method]` pair cannot answer the question at all; saying "not
 *    registered" there is a guess, and A-8 owns the malformed values.
 *
 * Three copies of that would be three chances to get one of them wrong, and a wrong answer
 * here silently flips a matrix cell between `fail` and `o`.
 *
 * ---------------------------------------------------------------------------------
 * WHAT IT DOES NOT DO
 * ---------------------------------------------------------------------------------
 * It returns **facts**, not {@see Finding}s. Every caller words its own findings, because the
 * sentence a reader needs is about that caller's assertion ("an empty settings page core can
 * never render", "an API surface nothing dispatches to") and not about hook tables. Sharing
 * the words as well as the rule is how one defect ends up described in two columns.
 *
 * The table is read through {@see TierA5HooksAreIdempotent::hookTable()} rather than by
 * calling `getHooks()` here, for the reason that helper exists: two inspectors that
 * separately decide "can getHooks() be called?" are two inspectors that can disagree.
 */
class HookTargetIndex
{
    /**
     * Which hook keys register `$method` on this plugin, and how much of the table was
     * readable at all.
     *
     * The three interesting shapes of the return value:
     *
     *  - `hooks === null` — `getHooks()` could not be evaluated (missing, non-static,
     *    throwing, non-array). **A-5** reports the root cause; a caller must not restate it as
     *    a defect of its own.
     *  - `hooks !== [] && pairs === 0` — there is a table, but no value in it is a
     *    `[class, method]` pair or a `Class::method` string, so "is the handler registered?"
     *    is unanswerable rather than false. **A-8** owns hook value shape.
     *  - `keys !== []` — core has a dispatch path to the handler.
     *
     * Anything else means the table was readable and nothing in it names the handler: the
     * handler is orphaned.
     *
     * @param PluginSubject $subject
     * @param string        $method handler name, compared case-insensitively as PHP does
     * @return array{keys:array<int,string>,pairs:int,hooks:array<mixed,mixed>|null}
     */
    public static function keysTargeting(PluginSubject $subject, $method)
    {
        $hooks = TierA5HooksAreIdempotent::hookTable($subject);
        if ($hooks === null) {
            return ['keys' => [], 'pairs' => 0, 'hooks' => null];
        }

        $names = self::classNamesOf($subject, $method);
        $keys = [];
        $pairs = 0;
        foreach ($hooks as $key => $value) {
            $target = self::targetOf($value);
            if ($target === null) {
                continue;
            }
            $pairs++;
            if (strcasecmp($target['method'], (string)$method) !== 0) {
                continue;
            }
            if (!in_array(strtolower(ltrim($target['class'], '\\')), $names, true)) {
                continue;
            }
            $keys[] = (string)$key;
        }

        return ['keys' => $keys, 'pairs' => $pairs, 'hooks' => $hooks];
    }

    /**
     * Every class name a hook may legitimately use to name this plugin's handler, lowercased
     * and without a leading separator.
     *
     * Covers the declaring class and the whole parent chain, so an inherited handler
     * registered as `[ParentPlugin::class, 'getSettings']` still counts as reachable. Built
     * from reflection metadata already in hand, so it can never autoload — resolving a hook's
     * named class is {@see TierB9HookTargetsResolve}'s job, not this one's.
     *
     * @param PluginSubject $subject
     * @param string        $method
     * @return array<int,string>
     */
    public static function classNamesOf(PluginSubject $subject, $method)
    {
        $reflection = $subject->reflection();
        $names = [strtolower(ltrim($reflection->getName(), '\\'))];
        if ($reflection->hasMethod($method)) {
            $names[] = strtolower(ltrim(
                $reflection->getMethod($method)->getDeclaringClass()->getName(),
                '\\'
            ));
        }
        for ($parent = $reflection->getParentClass(); $parent !== false; $parent = $parent->getParentClass()) {
            $names[] = strtolower(ltrim($parent->getName(), '\\'));
        }
        return array_values(array_unique($names));
    }

    /**
     * The `[class, method]` a hook value names, or null when it names neither.
     *
     * Accepts the `[__CLASS__, 'handler']` form every fleet package uses and the
     * `'Class::method'` string form `call_user_func()` also honours. Anything else returns
     * null and is counted as unparseable rather than as "not this handler", which is what lets
     * a caller tell "this hook targets something else" apart from "this hook table is
     * malformed and A-8 should say so".
     *
     * @param mixed $value
     * @return array{class:string,method:string}|null
     */
    public static function targetOf($value)
    {
        if (is_array($value) && count($value) === 2 && isset($value[0], $value[1]) && is_string($value[1])) {
            if (is_object($value[0])) {
                return ['class' => get_class($value[0]), 'method' => $value[1]];
            }
            if (is_string($value[0])) {
                return ['class' => $value[0], 'method' => $value[1]];
            }
            return null;
        }
        if (is_string($value) && strpos($value, '::') !== false) {
            $parts = explode('::', $value, 2);
            return ['class' => $parts[0], 'method' => $parts[1]];
        }
        return null;
    }
}
