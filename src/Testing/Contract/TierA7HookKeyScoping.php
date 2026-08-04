<?php
/**
 * @author Joe Huss <detain@interserver.net>
 * @copyright 2026
 * @package MyAdmin
 * @category Testing
 */

namespace MyAdmin\Plugins\Testing\Contract;

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
 */
class TierA7HookKeyScoping implements PluginInspector
{
    /**
     * Hook names that are not owned by any module.
     *
     * Matched against the **full** key, never as a prefix. Exposed as a const so the
     * triage matrix, the docs and any future inspector reference one list instead of
     * re-typing it and drifting.
     *
     * @var array<int,string>
     */
    const GLOBAL_HOOK_KEYS = [
        'system.settings',
        'function.requirements',
        'ui.menu',
        'api.register',
        'account.activated',
        'mailinglist.subscribe',
    ];

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

        $module = $subject->module();
        $module = is_string($module) ? $module : '';
        $findings = [];

        foreach ($hooks as $key => $unusedTarget) {
            if (!is_string($key)) {
                // Non-string keys have no prefix to scope. A-6 reports them.
                continue;
            }

            if (in_array($key, self::GLOBAL_HOOK_KEYS, true)) {
                continue;
            }

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
                $findings[] = Finding::failure(
                    'A-7',
                    $class.' registers hook key "'.$key.'", whose prefix is "'.$prefix.'", but'
                        .' the plugin declares $module = "'.$module.'". The hook registers under'
                        .' a prefix nothing dispatches to. Expected the key to start with "'
                        .$module.'." or to be one of the global hooks.',
                    [
                        'class' => $class,
                        'key' => $key,
                        'prefix' => $prefix,
                        'module' => $module,
                        'problem' => 'prefix-mismatch',
                    ]
                );
            }
        }

        return $findings;
    }
}
