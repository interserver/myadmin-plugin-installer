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
 *
 * ---------------------------------------------------------------------------------
 * getHooks() RUNS UNDER A BUFFER, AND THIS INSPECTOR REPORTS WHAT IT PRINTS (R-8)
 * ---------------------------------------------------------------------------------
 * Every invocation of `getHooks()` in this class — both of `inspect()`'s and the one in
 * {@see hookTable()} — goes through {@see TierB15NoOutput::capture()}. Unbuffered, a
 * `getHooks()` with a leftover `var_dump()` escaped into the PHPUnit process and
 * `beStrictAboutOutputDuringTests="true"` + `failOnRisky="true"` reported it as
 * `R  This test printed output: …` against whichever of the eight inspectors that reach
 * `getHooks()` happened to be running — A-5, A-6, A-7, A-8, B-9, B-9b, B-10 or B-12, plus
 * B-11 by its own route. Attribution by coincidence is exactly what
 * {@see TierB15NoOutput} was written to abolish.
 *
 * The bytes are **reported here and discarded in `hookTable()`**, which is the same division
 * of labour this class already applies to failures. `hookTable()` gates on loadable class,
 * declared method, `public static` and no required parameters, then invokes with no
 * arguments; `inspect()` checks the identical four conditions and makes the identical call.
 * So whenever a consumer's `hookTable()` call prints, this inspector's own call prints too,
 * and turns red for it — the same "A-5 is the one that turns that condition red" contract
 * that makes the consumers' `null` skips honest.
 *
 * There is nobody else to defer to: B-15 executes `getSettings()` and `getMenu()` and never
 * calls `getHooks()`, so bytes dropped here would be reported nowhere. `Finding::notice()` is
 * not an alternative — {@see \MyAdmin\Plugins\Testing\PluginContractTestCase} reads failures
 * and skips only, so a notice is dropped by the consumer.
 *
 * Only A-6, A-7, A-8 and B-12 actually come through `hookTable()`. {@see TierB9HookTargetsResolve},
 * {@see TierB9bHookKeysDispatched} and {@see TierB10RequirementPathsResolve} each invoke
 * `getHooks()` directly and **unbuffered**, so R-8's guarantee does not yet cover them; they
 * were outside the file scope this pass was made under. Routing them through `hookTable()`
 * closes it, and is what those three should have been doing anyway.
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
     * Buffered, and the captured bytes dropped: a caller of this helper is asking for the
     * hook table, not conducting an output check, and it must not have a `getHooks()` that
     * prints attributed to *its* assertion — nor, once the buffer is here, allowed to escape
     * and be attributed to whichever test happened to be running. The bytes are not lost,
     * because {@see inspect()} makes the identical call under the identical preconditions
     * and reports them; see the class docblock for why that premise holds rather than being
     * assumed.
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
        $hooks = null;
        $run = TierB15NoOutput::capture(function () use ($method, &$hooks) {
            $hooks = $method->invoke(null);
        });
        if ($run['error'] !== null) {
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

        $first = null;
        $firstRun = TierB15NoOutput::capture(function () use ($method, &$first) {
            $first = $method->invoke(null);
        });
        $printed = $firstRun['output'];

        if ($firstRun['error'] !== null) {
            $e = $firstRun['error'];
            return $this->withOutput([Finding::failure(
                'A-5',
                $class.'::getHooks() threw '.get_class($e).': '.$e->getMessage()
                    .' — the plugin registers no hooks and PluginScanner keeps whatever stale'
                    .' entry it already had.',
                ['class' => $class, 'problem' => 'threw', 'exception' => get_class($e)]
            )], $printed, $class);
        }

        if (!is_array($first)) {
            return $this->withOutput([Finding::failure(
                'A-5',
                $class.'::getHooks() returned '.gettype($first).'; it must return an array of'
                    .' "module.event" => [class, method] entries.',
                ['class' => $class, 'problem' => 'not-array', 'found' => gettype($first)]
            )], $printed, $class);
        }

        // An empty hook table is NOT a defect. Ten fleet packages — drbl-backups,
        // gluster-backups, google-analytics, hotjar-analytics, kayako-chat, novnc-plugin,
        // payum-payments, piwik-analytics, raid-backups, slack-chat — return [] because
        // their registrations are deliberately commented out. Failing on it would redden
        // 10 of 69 for a decision their authors made on purpose, and gate G2 forbids
        // adding an escape hatch to walk that back. Idempotence still applies: two empty
        // arrays are equal, so such a plugin passes.
        $findings = [];

        $second = null;
        $secondRun = TierB15NoOutput::capture(function () use ($method, &$second) {
            $second = $method->invoke(null);
        });
        $printed .= $secondRun['output'];

        if ($secondRun['error'] !== null) {
            $e = $secondRun['error'];
            $findings[] = Finding::failure(
                'A-5',
                'The second consecutive '.$class.'::getHooks() call threw '.get_class($e).': '
                    .$e->getMessage().' — the method is not idempotent.',
                ['class' => $class, 'problem' => 'second-call-threw', 'exception' => get_class($e)]
            );
            return $this->withOutput($findings, $printed, $class);
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

        return $this->withOutput($findings, $printed, $class);
    }

    /**
     * Appends the "getHooks() printed" failure, if there is anything to append.
     *
     * Every return path below the first `invoke()` goes through here, because a `getHooks()`
     * that also returns the wrong thing must not have its printed bytes dropped on the way
     * out. Adding rather than replacing: the two are separate defects with separate fixes,
     * and collapsing them would leave whichever one lost unreported.
     *
     * @param array<int,Finding> $findings what the assertion decided on its own terms
     * @param string             $printed  everything both invocations wrote to output
     * @param string             $class    plugin class
     * @return array<int,Finding>
     */
    private function withOutput(array $findings, $printed, $class)
    {
        if ($printed === '') {
            return $findings;
        }
        $findings[] = Finding::failure(
            'A-5',
            TierB15NoOutput::describeOutput($class, 'getHooks()', $printed)
                .' PluginScanner calls getHooks() during `composer install`, where there is no page'
                .' to print to, and MyAdmin calls it at boot, where printing lands above'
                .' <!DOCTYPE html>. Reported here rather than under B-15 because B-15 executes the'
                .' settings and menu handlers, never getHooks(), so nothing else in the catalogue'
                .' would ever see these bytes.',
            [
                'class' => $class,
                'problem' => 'printed',
                'site' => 'getHooks',
                'bytes' => strlen($printed),
                'output' => TierB15NoOutput::excerpt($printed),
            ]
        );
        return $findings;
    }
}
