<?php
/**
 * @author Joe Huss <detain@interserver.net>
 * @copyright 2026
 * @package MyAdmin
 * @category Testing
 */

namespace MyAdmin\Plugins\Testing\Contract;

/**
 * A-2 — `$name`, `$description`, `$help` and `$type` exist, are `public static`, and hold
 * strings.
 *
 * These four are read by MyAdmin's plugin listing and by `PluginScanner`, always as
 * `Plugin::$name` style static access on the class. A property that is present but declared
 * as an *instance* property, or as `protected static`, is invisible there and produces a
 * fatal or an empty column rather than a diagnosable error — which is why declaration shape
 * is checked and not merely presence.
 *
 * Emptiness is deliberately **not** checked here; that is A-3, which knows `$help` is
 * allowed to be `''`. This inspector reports one finding per offending property so a plugin
 * missing three of the four does not hide two of them behind the first.
 *
 * ---------------------------------------------------------------------------------
 * WHY THE SHAPE AND THE VALUE COME FROM DIFFERENT PLACES
 * ---------------------------------------------------------------------------------
 * The three *shape* verdicts — missing, not-static, not-public — are read straight off
 * `ReflectionClass`, because reflection metadata never evaluates an initializer and so can
 * never throw. They also need a three-way answer that {@see PluginSubject::hasStaticProperty()}
 * deliberately collapses into one.
 *
 * The *value*, in contrast, is taken from {@see PluginSubject::staticProperty()} rather than
 * from `ReflectionProperty::getValue()`. PHP evaluates **every** static initializer of a
 * class on the first access to **any** of its statics, so ten fleet packages — the `*-module`
 * ones — throw `Error: Undefined constant` when asked for a `$type` that is a plain string
 * literal, purely because their unrelated `$settings` references `PRORATE_BILLING`. Reading
 * through the subject inherits its source-text fallback, so those ten get a real pass/fail
 * verdict on all four properties instead of thirty "never ran" cells in the G2 matrix.
 *
 * A value that genuinely cannot be recovered is still a {@see Finding::skipped()} — reporting
 * an unread value as a string violation would be a lie about what was observed — but the skip
 * now carries {@see PluginSubject::staticPropertyError()} so the matrix says *why*, and says
 * plainly that the property is declared. "Unevaluable" must never be allowed to read as
 * "absent"; that turns a harness limitation into a false clean bill of health.
 *
 * One ambiguity is accepted knowingly: a declared `$x = null;` on an otherwise poisoned class
 * is indistinguishable from an unrecoverable initializer, because both surface as a null value
 * with a non-null error. It is reported as unevaluable. That is the pre-existing verdict
 * (a skip either way), no fleet package has the shape, and resolving it would mean widening
 * `PluginSubject`'s API for a case that has never occurred.
 */
class TierA2RequiredStaticProperties implements PluginInspector
{
    /**
     * The four properties every MyAdmin plugin must publish.
     *
     * @var array<int,string>
     */
    const REQUIRED_PROPERTIES = ['name', 'description', 'help', 'type'];

    /**
     * @return string
     */
    public function id()
    {
        return 'A-2';
    }

    /**
     * @return string
     */
    public function title()
    {
        return 'Required metadata properties are declared public static and hold strings';
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
                'A-2',
                'Plugin class '.$class.' could not be loaded, so its metadata properties'
                    .' cannot be inspected (see A-1).',
                ['class' => $class]
            )];
        }

        $reflection = $subject->reflection();
        $findings = [];

        foreach (self::REQUIRED_PROPERTIES as $name) {
            if (!$reflection->hasProperty($name)) {
                $findings[] = Finding::failure(
                    'A-2',
                    $class.' does not declare $'.$name.'. Add "public static $'.$name.' = \'…\';".',
                    ['class' => $class, 'property' => $name, 'problem' => 'missing']
                );
                continue;
            }

            $property = $reflection->getProperty($name);

            if (!$property->isStatic()) {
                $findings[] = Finding::failure(
                    'A-2',
                    $class.'::$'.$name.' is an instance property. MyAdmin reads it as'
                        .' '.$class.'::$'.$name.', so it must be declared public static.',
                    ['class' => $class, 'property' => $name, 'problem' => 'not-static']
                );
                continue;
            }

            if (!$property->isPublic()) {
                $findings[] = Finding::failure(
                    'A-2',
                    $class.'::$'.$name.' is '.($property->isPrivate() ? 'private' : 'protected')
                        .' static; it must be public static so MyAdmin can read it.',
                    [
                        'class' => $class,
                        'property' => $name,
                        'problem' => 'not-public',
                        'visibility' => $property->isPrivate() ? 'private' : 'protected',
                    ]
                );
            }

            // Only the *value* goes through the subject; the shape verdicts above stay on
            // raw reflection. `staticPropertyError()` is asked for only when the value came
            // back null, so a healthy plugin pays for one read rather than two.
            $value = $subject->staticProperty($name);
            $error = $value === null ? $subject->staticPropertyError($name) : null;

            if ($error !== null) {
                $findings[] = Finding::skipped(
                    'A-2',
                    $class.' declares $'.$name.', but its value could not be determined:'
                        .' evaluating it threw ('.$error.') and the declaration is not a scalar'
                        .' literal the source fallback can recover. This is "declared but'
                        .' unevaluable", not "absent".',
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
                $findings[] = Finding::failure(
                    'A-2',
                    $class.'::$'.$name.' must hold a string; found '.gettype($value).'.',
                    [
                        'class' => $class,
                        'property' => $name,
                        'problem' => 'not-string',
                        'found' => gettype($value),
                    ]
                );
            }
        }

        return $findings;
    }
}
