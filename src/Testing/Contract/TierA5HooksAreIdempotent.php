<?php
/**
 * @author Joe Huss <detain@interserver.net>
 * @copyright 2026
 * @package MyAdmin
 * @category Testing
 */

namespace MyAdmin\Plugins\Testing\Contract;

/**
 * A-5 — `getHooks()` is statically invokable, returns an array, and returns the same array
 * twice in a row.
 *
 * This is the gateway inspector for A-6, A-7 and A-8: all three need the hook table, and
 * all three skip when it cannot be obtained. A-5 is the one that turns that condition red,
 * so a plugin whose `getHooks()` is missing, non-static or explosive still shows exactly one
 * failing cell rather than four skips that read as "not applicable".
 *
 * `PluginScanner::scan()` obtains the table with `call_user_func([$class, 'getHooks'])` and
 * writes the result into `hooks.json`, which MyAdmin's `include/tf.php` decodes at boot.
 * The invocation shape checked here — public, static, no required arguments — is therefore
 * the literal call the scanner makes, not a stylistic preference.
 *
 * **Idempotence** is checked because the scanner reads the table once and MyAdmin dispatches
 * against the cached copy forever after. A `getHooks()` that consults request state, a
 * mutable static or the clock registers whatever happened to be true during the install and
 * then diverges from what the plugin believes it registered. Two consecutive calls are
 * compared with `==`, i.e. same keys mapped to the same values; declaration order is not
 * part of the contract.
 *
 * A throwing `getHooks()` is reported as a failure and not a skip. It did not return an
 * array, which is precisely what this assertion says it must do, and the scanner's
 * documented response — keep whatever hooks the package already had — is exactly the silent
 * staleness the harness exists to surface.
 */
class TierA5HooksAreIdempotent implements PluginInspector
{
    /**
     * @return string
     */
    public function id()
    {
        return 'A-5';
    }

    /**
     * @return string
     */
    public function title()
    {
        return 'getHooks() returns an array and is idempotent';
    }

    /**
     * The plugin's hook table, or null when it cannot be obtained.
     *
     * A-6, A-7 and A-8 all need this and none of them should re-derive "can getHooks() even
     * be called?" — two inspectors disagreeing about that is exactly the drift
     * {@see PluginSubject} exists to prevent. They call this and skip on null; the failure
     * itself is A-5's to report.
     *
     * @param PluginSubject $subject
     * @return array<mixed,mixed>|null
     */
    public static function hookTable(PluginSubject $subject)
    {
        if (!$subject->isLoadable()) {
            return null;
        }
        $reflection = $subject->reflection();
        if (!$reflection->hasMethod('getHooks')) {
            return null;
        }
        $method = $reflection->getMethod('getHooks');
        if (!$method->isPublic() || !$method->isStatic() || $method->getNumberOfRequiredParameters() > 0) {
            return null;
        }
        try {
            $hooks = $method->invoke(null);
        } catch (\Throwable $e) {
            return null;
        }
        return is_array($hooks) ? $hooks : null;
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
                'A-5',
                'Plugin class '.$class.' could not be loaded, so getHooks() cannot be'
                    .' invoked (see A-1).',
                ['class' => $class]
            )];
        }

        $reflection = $subject->reflection();

        if (!$reflection->hasMethod('getHooks')) {
            return [Finding::failure(
                'A-5',
                $class.' does not declare getHooks(). Without it the plugin is never added to'
                    .' hooks.json and no event ever reaches it.',
                ['class' => $class, 'problem' => 'missing']
            )];
        }

        $method = $reflection->getMethod('getHooks');

        if (!$method->isPublic()) {
            return [Finding::failure(
                'A-5',
                $class.'::getHooks() is '.($method->isPrivate() ? 'private' : 'protected')
                    .'. PluginScanner calls it as a public static, so it must be public.',
                ['class' => $class, 'problem' => 'not-public']
            )];
        }

        if (!$method->isStatic()) {
            return [Finding::failure(
                'A-5',
                $class.'::getHooks() is not static. PluginScanner invokes'
                    .' call_user_func(['.$class.'::class, \'getHooks\']), which needs a static method.',
                ['class' => $class, 'problem' => 'not-static']
            )];
        }

        if ($method->getNumberOfRequiredParameters() > 0) {
            return [Finding::failure(
                'A-5',
                $class.'::getHooks() requires '.$method->getNumberOfRequiredParameters()
                    .' argument(s); it is invoked with none. Give every parameter a default.',
                ['class' => $class, 'problem' => 'requires-arguments']
            )];
        }

        try {
            $first = $method->invoke(null);
        } catch (\Throwable $e) {
            return [Finding::failure(
                'A-5',
                $class.'::getHooks() threw '.get_class($e).': '.$e->getMessage()
                    .' — the plugin registers no hooks and PluginScanner keeps whatever stale'
                    .' entry it already had.',
                ['class' => $class, 'problem' => 'threw', 'exception' => get_class($e)]
            )];
        }

        if (!is_array($first)) {
            return [Finding::failure(
                'A-5',
                $class.'::getHooks() returned '.gettype($first).'; it must return an array of'
                    .' "module.event" => [class, method] entries.',
                ['class' => $class, 'problem' => 'not-array', 'found' => gettype($first)]
            )];
        }

        // An empty hook table is NOT a defect. Ten fleet packages — drbl-backups,
        // gluster-backups, google-analytics, hotjar-analytics, kayako-chat, novnc-plugin,
        // payum-payments, piwik-analytics, raid-backups, slack-chat — return [] because
        // their registrations are deliberately commented out. Failing on it would redden
        // 10 of 69 for a decision their authors made on purpose, and gate G2 forbids
        // adding an escape hatch to walk that back. Idempotence still applies: two empty
        // arrays are equal, so such a plugin passes.
        $findings = [];

        try {
            $second = $method->invoke(null);
        } catch (\Throwable $e) {
            $findings[] = Finding::failure(
                'A-5',
                'The second consecutive '.$class.'::getHooks() call threw '.get_class($e).': '
                    .$e->getMessage().' — the method is not idempotent.',
                ['class' => $class, 'problem' => 'second-call-threw', 'exception' => get_class($e)]
            );
            return $findings;
        }

        if (!is_array($second) || $second != $first) {
            $findings[] = Finding::failure(
                'A-5',
                $class.'::getHooks() is not idempotent: two consecutive calls returned'
                    .' different results. PluginScanner reads the table once, so whatever the'
                    .' first call produced is what MyAdmin dispatches against forever.',
                [
                    'class' => $class,
                    'problem' => 'not-idempotent',
                    'first' => is_array($first) ? implode(',', array_keys($first)) : gettype($first),
                    'second' => is_array($second) ? implode(',', array_keys($second)) : gettype($second),
                ]
            );
        }

        return $findings;
    }
}
