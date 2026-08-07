<?php
/**
 * @author Joe Huss <detain@interserver.net>
 * @copyright 2026
 * @package MyAdmin
 * @category Testing
 */

namespace MyAdmin\Plugins\Testing\Contract;

use MyAdmin\Plugins\Testing\ConstantStub;

/**
 * A-7 — a hook key is either one of the handful of global event names, or it is scoped to
 * the module the plugin declares.
 *
 * ---------------------------------------------------------------------------------
 * THE BUG CLASS THIS CATCHES
 * ---------------------------------------------------------------------------------
 * A new `type=service` or `type=module` plugin defines `vps.activate` and either forgets to
 * declare `public static $module`, or declares one — say `'vpsaddon'` — that does not match
 * its own hook prefixes. Both variants install cleanly, both write a valid `hooks.json`
 * entry, and neither produces a warning anywhere. The hook simply registers under a prefix
 * that nothing dispatches to, and the plugin is silently inert. Every existing fleet plugin
 * builds its keys as `self::$module.'.activate'`, which makes the mismatch impossible by
 * construction; the moment someone writes the string literally, nothing catches it.
 *
 * "Silently inert" is accurate **here** and is not the story {@see TierB9HookTargetsResolve}
 * tells. A mis-scoped key names a handler that exists: the callable resolves, the listener
 * registers, and the only thing missing is a dispatch. A *dangling* target is louder and
 * worse — it throws out of Symfony's listener optimisation and takes every other listener on
 * that key with it. The two are checked separately because they break separately, and the
 * failure narratives must not be copied between them.
 *
 * ---------------------------------------------------------------------------------
 * THE RULE, ASSERTED IN BOTH DIRECTIONS
 * ---------------------------------------------------------------------------------
 * Checking only "module-scoped keys match `$module`" would let a plugin ship `foo.bar` with
 * no `$module` at all and stay green, so the rule is stated as a total function over keys:
 *
 *  - A key whose **full** name is in {@see GLOBAL_HOOK_KEYS} is always allowed. These are
 *    the cross-cutting events every plugin may listen to; they are matched whole rather
 *    than by prefix, so a plugin cannot claim `ui.anything` by pointing at `ui.menu`.
 *  - Any other key **must** be module-scoped: the plugin must declare a non-empty `$module`,
 *    **and** that `$module` must equal the key's prefix — the text before the first dot.
 *
 * The consequence is deliberate and worth stating out loud: a plugin with no `$module` may
 * use global keys only.
 *
 * ---------------------------------------------------------------------------------
 * WHEN `$module` CANNOT BE READ, THIS SKIPS — IT DOES NOT FAIL
 * ---------------------------------------------------------------------------------
 * {@see PluginSubject::staticProperty()} returns null both for "not declared" and for
 * "declared, but the initializer could not be evaluated *and* could not be recovered from
 * source" — a typed static (`public static string $module = 'vps';` is invisible to the
 * source fallback's modifier scan) in a class whose *other* static initializer throws, or a
 * `$module = SOME_CONSTANT;` whose constant is not defined yet. Reading that null as "no
 * module" produced the worst message this harness has ever emitted:
 *
 *     … declares no $module. … Add "public static $module = 'vps';"
 *
 * against a class whose source reads exactly `public static string $module = 'vps';`. The
 * message contained the correct value it was telling the maintainer to add. A-2 (`:137`),
 * A-3 (`:102`), A-4 (`:97`) and A-9 (`:141`) all guard the `value === null && error !== null`
 * case and downgrade to a skip; this now does the same, and for the same reason: "unevaluable"
 * is not "absent", and inventing a failure out of a harness limitation is strictly worse than
 * reporting that the check could not run.
 */
class TierA7HookKeyScoping implements PluginInspector
{
    /**
     * Hook names that are not owned by any module.
     *
     * Matched against the **full** key, never as a prefix.
     *
     * ---------------------------------------------------------------------------------
     * WHY THIS IS AN ALIAS AND NOT A LIST
     * ---------------------------------------------------------------------------------
     * This was a hand-typed list of six, while {@see TierB9bHookKeysDispatched::LITERAL_KEYS}
     * held nine derived from the same grep of core plus every vendor plugin. Both are the
     * *same set* — not by coincidence, by definition:
     *
     *   - a key dispatched from a **literal** string (`dispatch($e, 'licenses.change_ip')`)
     *     fires no matter which plugin is listening, so any plugin may register it whatever
     *     its `$module` is. That is precisely "global";
     *   - a key dispatched as `self::$module.'.<suffix>'` fires only for that one module, so
     *     a listener must declare it. That is precisely "module-scoped".
     *
     * So "not owned by any module" and "dispatched verbatim" are two names for one list, and
     * two copies of one list drift. They had: the three keys core dispatches literally from
     * `include/licenses/{deactivate_license_by_key,deactivate_license_by_ip}.php` and
     * `include/licenses/license.functions.inc.php` were in B-9b's copy and missing from this
     * one, so a plugin registering `licenses.deactivate_key` under a different `$module` was
     * failed here — with the words "a prefix nothing dispatches to" — while B-9b, in the same
     * run, reported that exact key as dispatched.
     *
     * B-9b owns the data because B-9b is the inspector whose entire subject *is* the dispatch
     * vocabulary; its docblock already commits to maintaining it as reviewed data and names
     * the trap (plugin-to-plugin dispatch) that a re-derivation falls into. It is deliberately
     * **not** parked next to {@see TierA5HooksAreIdempotent::hookTable()}: that helper is the
     * single source of truth for a different predicate — whether a plugin's `getHooks()` can
     * be called at all — and folding fleet-wide dispatch-site data into an idempotency
     * inspector would be a third home for the concept, not a shared one.
     *
     * Held as an alias rather than a re-export so drift is not merely discouraged but
     * impossible; {@see \Tests\MyAdmin\Plugins\Testing\Contract\HookKeyVocabularyTest} fails
     * if anyone expands it back into a literal array that disagrees.
     *
     * @var array<int,string>
     */
    const GLOBAL_HOOK_KEYS = TierB9bHookKeysDispatched::LITERAL_KEYS;

    /**
     * Whether a key is global — allowed under any `$module`, or under none.
     *
     * Public so the vocabulary drift guard can assert this predicate against B-9b's
     * {@see TierB9bHookKeysDispatched::isDispatched()} instead of comparing two arrays and
     * hoping both are actually consulted.
     *
     * Strict comparison, so the predicate is total: a non-string key — which A-6 owns — is
     * simply not global.
     *
     * @param string $key
     * @return bool
     */
    public static function isGlobalKey($key)
    {
        return in_array($key, self::GLOBAL_HOOK_KEYS, true);
    }

    /**
     * Whether a recovered value is one of {@see ConstantStub}'s `__STUB_<NAME>__` sentinels
     * rather than anything the plugin author wrote.
     *
     * `public static $module = SOME_CONSTANT;` evaluates cleanly once `ConstantStub` has
     * primed `SOME_CONSTANT` — to a placeholder that stands for "this harness could not
     * obtain the real value". There is no Throwable to catch and so no
     * {@see PluginSubject::staticPropertyError()} to consult, but the value is no more
     * usable than the null the class docblock describes, and comparing a hook prefix against
     * it manufactures a mismatch out of a harness artefact. Same response — skip, never fail
     * — because it is the same fact: `$module` could not be recovered.
     *
     * The shape is derived from {@see ConstantStub::SENTINEL_FORMAT} rather than spelled
     * out, so changing the sentinel changes this too.
     *
     * @param string $value
     * @return bool
     */
    public static function isStubSentinel($value)
    {
        $parts = explode('%s', ConstantStub::SENTINEL_FORMAT, 2);
        if (count($parts) !== 2) {
            return false;
        }
        $head = $parts[0];
        $tail = $parts[1];
        if (strlen($value) <= strlen($head) + strlen($tail)) {
            return false;
        }
        return strpos($value, $head) === 0
            && ($tail === '' || substr($value, -strlen($tail)) === $tail);
    }

    /**
     * @return string
     */
    public function id()
    {
        return 'A-7';
    }

    /**
     * @return string
     */
    public function title()
    {
        return 'Hook keys are global names or scoped to the plugin\'s declared $module';
    }

    /**
     * @param PluginSubject $subject
     * @return array<int,Finding>
     */
    public function inspect(PluginSubject $subject)
    {
        $class = $subject->pluginClass();

        if (!$subject->isLoadable()) {
            return [Finding::skipped(
                'A-7',
                'Plugin class '.$class.' could not be loaded, so its hook scoping cannot be'
                    .' inspected (see A-1).',
                ['class' => $class]
            )];
        }

        $hooks = TierA5HooksAreIdempotent::hookTable($subject);

        if ($hooks === null) {
            return [Finding::skipped(
                'A-7',
                $class.'::getHooks() could not be evaluated, so its hook scoping cannot be'
                    .' inspected (see A-5).',
                ['class' => $class]
            )];
        }

        // Which keys actually need a `$module` to judge. A table made entirely of global
        // keys is decidable without one, so an unreadable `$module` must not drag it into a
        // skip — that would turn a real pass into an absence of information.
        $scoped = [];
        foreach ($hooks as $key => $unusedTarget) {
            if (!is_string($key)) {
                // Non-string keys have no prefix to scope. A-6 reports them.
                continue;
            }
            if (self::isGlobalKey($key)) {
                continue;
            }
            $scoped[] = $key;
        }

        if ($scoped === []) {
            return [];
        }

        $module = $subject->module();
        $moduleError = $subject->staticPropertyError('module');

        if ($subject->hasStaticProperty('module') && $module === null && $moduleError !== null) {
            return [Finding::skipped(
                'A-7',
                $class.' declares $module, but its value could not be determined: evaluating it'
                    .' threw ('.$moduleError.') and the declaration is not a scalar literal the'
                    .' source fallback can recover, so its hook prefixes cannot be compared'
                    .' against it. This is "declared but unevaluable", not "absent" — reading it'
                    .' as "absent" would demand the maintainer add a property the class already'
                    .' has (see A-2).',
                [
                    'class' => $class,
                    'problem' => 'unevaluable-module',
                    'error' => $moduleError,
                    'keys' => $scoped,
                ]
            )];
        }

        $module = is_string($module) ? $module : '';

        if (self::isStubSentinel($module)) {
            return [Finding::skipped(
                'A-7',
                $class.'::$module resolved to '.$module.', a ConstantStub placeholder rather'
                    .' than a declared module name: the initializer names a constant the harness'
                    .' had to invent a value for. Comparing hook prefixes against a placeholder'
                    .' would report a mismatch that exists only under test.',
                [
                    'class' => $class,
                    'problem' => 'stubbed-module',
                    'module' => $module,
                    'keys' => $scoped,
                ]
            )];
        }

        $findings = [];
        // Consulted only to word the mismatch rationale. A-7 said "a prefix nothing
        // dispatches to" unconditionally, which is a claim about the fleet's dispatch sites
        // that A-7 was in no position to make — and that B-9b sometimes contradicts in the
        // same run. Asked rather than assumed, it stays true for both answers.
        $dispatch = new TierB9bHookKeysDispatched();

        foreach ($scoped as $key) {
            $dot = strpos($key, '.');
            $prefix = $dot === false ? $key : substr($key, 0, $dot);

            if ($module === '') {
                $findings[] = Finding::failure(
                    'A-7',
                    $class.' registers hook key "'.$key.'" but declares no $module. Only the'
                        .' global hooks ('.implode(', ', self::GLOBAL_HOOK_KEYS).') may be used'
                        .' without one; every other key must be scoped. Add'
                        .' "public static $module = \''.$prefix.'\';" or use a global hook name.',
                    [
                        'class' => $class,
                        'key' => $key,
                        'prefix' => $prefix,
                        'module' => '',
                        'problem' => 'no-module',
                    ]
                );
                continue;
            }

            if ($module !== $prefix) {
                $isDispatched = $dispatch->isDispatched($key);
                $findings[] = Finding::failure(
                    'A-7',
                    $class.' registers hook key "'.$key.'", whose prefix is "'.$prefix.'", but'
                        .' the plugin declares $module = "'.$module.'". '
                        .($isDispatched
                            ? 'That key is dispatched, but as "'.$prefix.'.<event>" — for the "'
                                .$prefix.'" module, not for this one, so this plugin never sees'
                                .' it.'
                            : 'The hook registers under a prefix nothing dispatches to.')
                        .' Expected the key to start with "'.$module.'." or to be one of the'
                        .' global hooks.',
                    [
                        'class' => $class,
                        'key' => $key,
                        'prefix' => $prefix,
                        'module' => $module,
                        'problem' => 'prefix-mismatch',
                        'dispatched' => $isDispatched,
                    ]
                );
            }
        }

        return $findings;
    }
}
