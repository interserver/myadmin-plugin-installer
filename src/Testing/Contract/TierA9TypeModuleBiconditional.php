<?php
/**
 * @author Joe Huss <detain@interserver.net>
 * @copyright 2026
 * @package MyAdmin
 * @category Testing
 */

namespace MyAdmin\Plugins\Testing\Contract;

/**
 * A-9 — `$type` is `"plugin"` **if and only if** the package declares no `$module`.
 *
 * ---------------------------------------------------------------------------------
 * THE BUG CLASS THIS CATCHES
 * ---------------------------------------------------------------------------------
 * `$type` and `$module` are two independent declarations that answer the same question:
 * *does this package own a module namespace?* `type=plugin` says no; a non-empty `$module`
 * says yes. Nothing in MyAdmin cross-checks them, so a package can answer both ways at once
 * and install perfectly cleanly.
 *
 * Both halves of the contradiction are silent, and both are inert in the same undiagnosable
 * way:
 *
 *  - `type=plugin` **with** a `$module` — usually a service package downgraded to `plugin`
 *    during a copy-paste, or a plugin that grew module-scoped hooks and had `$module` added
 *    without the type being revisited. It is categorised as an unscoped plugin while
 *    declaring ownership of a namespace.
 *  - a scoped type (`service`, `module`, `addon`) **without** a `$module` — the mirror
 *    mistake. By A-7 this package may register only the six global hook names; every
 *    module-scoped key it declares lands under a prefix nothing dispatches to. A-7 catches
 *    that only once such a key exists, so a package that is *going* to register one is
 *    already broken and still green.
 *
 * ---------------------------------------------------------------------------------
 * THE RULE, ASSERTED IN BOTH DIRECTIONS
 * ---------------------------------------------------------------------------------
 * Checking only one direction would leave the other half of the fleet unguarded, so the rule
 * is stated as a biconditional:
 *
 *  - `$type === 'plugin'` **and** a non-empty `$module` → failure.
 *  - `$type !== 'plugin'` **and** no non-empty `$module` → failure.
 *
 * Verified across all 69 fleet packages in both directions: `plugin` 27, `service` 25,
 * `module` 10, `addon` 7; all 69 declare `$type`; exactly the 27 `plugin` packages declare no
 * `$module`, and every one of the other 42 declares a non-empty one. **The day-one yield is
 * therefore zero, and that is the correct result.** This is a regression guard on an
 * invariant the fleet already satisfies — it earns its keep on the seventieth package, not
 * on the sixty-nine that exist. Do not go looking for a way to make it report something.
 *
 * ---------------------------------------------------------------------------------
 * ON SKIPPING
 * ---------------------------------------------------------------------------------
 * A missing, non-string or unrecoverable `$type` is a {@see Finding::skipped()}, never a
 * failure: A-2, A-3 and A-4 already own those root causes, and one defect reported by four
 * inspectors makes the triage matrix overstate how much is wrong. The same applies to a
 * `$module` that is declared but whose value cannot be determined — inferring "no module"
 * from a harness limitation would manufacture a failure out of nothing.
 *
 * Ten fleet packages reach this inspector with their static initializers unevaluable; they
 * are inspected rather than skipped because {@see PluginSubject::staticProperty()} recovers
 * the literal from source. This inspector is the main consumer of that fallback.
 */
class TierA9TypeModuleBiconditional implements PluginInspector
{
    /**
     * @var string
     */
    const ID = 'A-9';

    /**
     * The one `$type` that declares the package owns no module namespace.
     *
     * Published as a const so the matrix and any future inspector reference one value
     * rather than re-typing the string.
     *
     * @var string
     */
    const UNSCOPED_TYPE = 'plugin';

    /**
     * @return string
     */
    public function id()
    {
        return self::ID;
    }

    /**
     * @return string
     */
    public function title()
    {
        return '$type is "plugin" exactly when the package declares no $module';
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
                self::ID,
                'Plugin class '.$class.' could not be loaded, so its $type and $module cannot'
                    .' be compared (see A-1).',
                ['class' => $class]
            )];
        }

        if (!$subject->hasStaticProperty('type')) {
            return [Finding::skipped(
                self::ID,
                $class.' declares no public static $type, so there is nothing to compare'
                    .' $module against (see A-2).',
                ['class' => $class]
            )];
        }

        $type = $subject->staticProperty('type');

        if (!is_string($type) || $type === '') {
            $error = $subject->staticPropertyError('type');
            return [Finding::skipped(
                self::ID,
                $class.'::$type could not be resolved to a non-empty string'
                    .($error === null ? ' (holds '.gettype($type).')' : ' ('.$error.')')
                    .', so it cannot be compared against $module (see A-2, A-3, A-4).',
                [
                    'class' => $class,
                    'found' => gettype($type),
                    'error' => $error,
                ]
            )];
        }

        $module = $subject->module();
        $moduleError = $subject->staticPropertyError('module');

        if ($subject->hasStaticProperty('module') && $module === null && $moduleError !== null) {
            return [Finding::skipped(
                self::ID,
                $class.' declares $module but its value could not be resolved ('.$moduleError
                    .'). Treating that as "no module" would invent a failure, so this is a skip.',
                ['class' => $class, 'type' => $type, 'error' => $moduleError]
            )];
        }

        $hasModule = is_string($module) && $module !== '';
        $isUnscoped = $type === self::UNSCOPED_TYPE;

        if ($isUnscoped && $hasModule) {
            return [Finding::failure(
                self::ID,
                $class.' declares $type = "'.self::UNSCOPED_TYPE.'" but also $module = "'.$module
                    .'". Those contradict each other: "'.self::UNSCOPED_TYPE.'" is the type for a'
                    .' package that owns no module namespace, while $module claims one. Either'
                    .' drop $module, or give $type a scoped value (service, module or addon).',
                [
                    'class' => $class,
                    'type' => $type,
                    'module' => $module,
                    'problem' => 'unscoped-type-with-module',
                ]
            )];
        }

        if (!$isUnscoped && !$hasModule) {
            return [Finding::failure(
                self::ID,
                $class.' declares $type = "'.$type.'", which is a module-owning type, but no'
                    .' non-empty $module. Without one the package can register only the global'
                    .' hooks (see A-7); every module-scoped key would land under a prefix nothing'
                    .' dispatches to. Either add "public static $module = \'…\';" or set $type to "'
                    .self::UNSCOPED_TYPE.'".',
                [
                    'class' => $class,
                    'type' => $type,
                    'module' => $module === null ? '' : $module,
                    'problem' => 'scoped-type-without-module',
                ]
            )];
        }

        return [];
    }
}
