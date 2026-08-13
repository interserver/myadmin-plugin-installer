<?php
/**
 * @author Joe Huss <detain@interserver.net>
 * @copyright 2026
 * @package MyAdmin
 * @category Testing
 */

namespace MyAdmin\Plugins\Testing\Contract;

/**
 * A-3 — `$name` and `$description` carry actual text.
 *
 * `$name` is what the admin plugin listing shows and what appears in every
 * `myadmin_log()` line a plugin writes; `$description` is the only in-product explanation of
 * what a plugin sells or does. An empty one is not a cosmetic problem — it produces a row
 * in the admin UI that identifies nothing.
 *
 * **`$help` is explicitly exempt.** Most fleet plugins ship `public static $help = '';` and
 * that is legitimate: help text is optional. Failing on it would mean either 60-odd
 * false positives or an escape hatch per repo, and gate G2 forbids weakening an assertion to
 * make plugins pass — so the assertion is written correctly the first time instead.
 *
 * Declaration shape is A-2's job. When a property is absent, unreadable, or not a string
 * this inspector returns a {@see Finding::skipped()} rather than a second failure for the
 * same root cause: A-2 already reddens that cell, and duplicating it would inflate the
 * defect count without adding information. That rule is load-bearing for the G2 matrix —
 * one defect must yield one red cell, not three — so do not "improve" any of these skips
 * into failures.
 *
 * The value is read through {@see PluginSubject::staticProperty()} rather than
 * `ReflectionProperty::getValue()`. Ten fleet packages declare a `$settings` initializer
 * referencing a bare billing constant, and PHP evaluates every static initializer of a class
 * on first access to any of them — so asking those packages for a `$name` that is a plain
 * string literal throws. The subject's source-text fallback recovers the literal, which is
 * what lets emptiness actually be judged on those ten instead of recorded as "never ran".
 *
 * A value that truly cannot be recovered — an array initializer, a constant expression —
 * remains a skip, but one that carries {@see PluginSubject::staticPropertyError()} so the
 * matrix records why the check could not run, and that says explicitly that the property is
 * declared. Collapsing "unevaluable" into "absent" would let a harness limitation read as a
 * clean bill of health.
 */
class TierA3NonEmptyMetadata implements PluginInspector
{
    /**
     * Properties that must carry text. `help` is deliberately absent — see the class
     * docblock.
     *
     * @var array<int,string>
     */
    const NON_EMPTY_PROPERTIES = ['name', 'description'];

    /**
     * @return string
     */
    public function id()
    {
        return 'A-3';
    }

    /**
     * @return string
     */
    public function title()
    {
        return 'Name and description are non-empty ($help may legitimately be empty)';
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
                'A-3',
                'Plugin class '.$class.' could not be loaded, so its metadata cannot be'
                    .' inspected (see A-1).',
                ['class' => $class]
            )];
        }

        $findings = [];

        foreach (self::NON_EMPTY_PROPERTIES as $name) {
            if (!$subject->hasStaticProperty($name)) {
                $findings[] = Finding::skipped(
                    'A-3',
                    $class.' declares no public static $'.$name.', so there is no value to'
                        .' test for emptiness (see A-2).',
                    ['class' => $class, 'property' => $name]
                );
                continue;
            }

            // `staticPropertyError()` is consulted only when the value came back null, so the
            // 59 healthy fleet packages pay for one read rather than two.
            $value = $subject->staticProperty($name);
            $error = $value === null ? $subject->staticPropertyError($name) : null;

            if ($error !== null) {
                $findings[] = Finding::skipped(
                    'A-3',
                    $class.' declares $'.$name.', but its value could not be determined:'
                        .' evaluating it threw ('.$error.') and the declaration is not a scalar'
                        .' literal the source fallback can recover, so emptiness cannot be'
                        .' judged. This is "declared but unevaluable", not "absent".',
                    [
                        'class' => $class,
                        'property' => $name,
                        'problem' => 'unevaluable',
                        'error' => $error,
                    ]
                );
                continue;
            }

            if (!is_string($value)) {
                $findings[] = Finding::skipped(
                    'A-3',
                    $class.'::$'.$name.' holds '.gettype($value).', not a string, so emptiness'
                        .' is not meaningful here (see A-2).',
                    ['class' => $class, 'property' => $name, 'found' => gettype($value)]
                );
                continue;
            }

            if (trim($value) === '') {
                $findings[] = Finding::failure(
                    'A-3',
                    $class.'::$'.$name.' is empty. It is shown in the admin plugin listing and'
                        .' in log lines, so it must carry text identifying this plugin.',
                    ['class' => $class, 'property' => $name, 'value' => $value]
                );
            }
        }

        return $findings;
    }
}
