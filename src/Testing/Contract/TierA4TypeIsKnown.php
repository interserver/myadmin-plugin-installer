<?php
/**
 * @author Joe Huss <detain@interserver.net>
 * @copyright 2026
 * @package MyAdmin
 * @category Testing
 */

namespace MyAdmin\Plugins\Testing\Contract;

/**
 * A-4 — `$type` is one of the four values MyAdmin actually branches on, and matches what
 * the repo expects.
 *
 * `$type` decides how a package is grouped and presented, and a typo produces no error
 * anywhere: the plugin simply never appears under any heading. Because there is no runtime
 * complaint, an allowlist check here is the only thing standing between `'srevice'` and a
 * silently invisible module.
 *
 * The second half of the check is per-repo. `PluginSubject::expectedType()` lets a repo say
 * "this package is a `service`" so a later refactor cannot quietly turn it into a `plugin`
 * and stay green on the vocabulary check alone. When no expectation is set, any of the four
 * is accepted.
 *
 * An unknown type that also disagrees with the expectation yields two findings, because
 * they are two separate things to fix: correct the spelling, and confirm the intended
 * category.
 *
 * A `$type` that is absent or not a string is a {@see Finding::skipped()}, never a failure:
 * A-2 already owns those root causes, and one defect reported twice makes the G2 matrix
 * overstate how much is wrong.
 *
 * The value is read through {@see PluginSubject::staticProperty()} rather than
 * `ReflectionProperty::getValue()`. Ten fleet packages poison every one of their statics with
 * a `$settings` initializer referencing a bare billing constant — PHP evaluates all of a
 * class's initializers on first access to any of them — so `getValue()` throws even though
 * `$type` is a plain string literal. The subject's source-text fallback recovers it, so those
 * ten are actually vetted against the vocabulary instead of leaving ten "never ran" cells.
 *
 * A `$type` that truly cannot be recovered — an array initializer, a constant expression —
 * is still a skip, but one carrying {@see PluginSubject::staticPropertyError()} so the matrix
 * records why, and saying plainly that the property is declared rather than absent.
 */
class TierA4TypeIsKnown implements PluginInspector
{
    /**
     * The complete `$type` vocabulary.
     *
     * @var array<int,string>
     */
    const KNOWN_TYPES = ['service', 'plugin', 'module', 'addon'];

    /**
     * @return string
     */
    public function id()
    {
        return 'A-4';
    }

    /**
     * @return string
     */
    public function title()
    {
        return 'Declared $type is a known plugin type and matches the expected one';
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
                'A-4',
                'Plugin class '.$class.' could not be loaded, so its $type cannot be'
                    .' inspected (see A-1).',
                ['class' => $class]
            )];
        }

        if (!$subject->hasStaticProperty('type')) {
            return [Finding::skipped(
                'A-4',
                $class.' declares no public static $type, so there is no value to check'
                    .' against the known types (see A-2).',
                ['class' => $class]
            )];
        }

        // `staticPropertyError()` is consulted only when the value came back null, so the
        // 59 healthy fleet packages pay for one read rather than two.
        $type = $subject->staticProperty('type');
        $error = $type === null ? $subject->staticPropertyError('type') : null;

        if ($error !== null) {
            return [Finding::skipped(
                'A-4',
                $class.' declares $type, but its value could not be determined: evaluating it'
                    .' threw ('.$error.') and the declaration is not a scalar literal the source'
                    .' fallback can recover, so it cannot be matched against the known types.'
                    .' This is "declared but unevaluable", not "absent".',
                ['class' => $class, 'problem' => 'unevaluable', 'error' => $error]
            )];
        }

        if (!is_string($type)) {
            return [Finding::skipped(
                'A-4',
                $class.'::$type holds '.gettype($type).', not a string, so it cannot be'
                    .' matched against the known types (see A-2).',
                ['class' => $class, 'found' => gettype($type)]
            )];
        }

        $findings = [];

        if (!in_array($type, self::KNOWN_TYPES, true)) {
            $findings[] = Finding::failure(
                'A-4',
                $class.'::$type is "'.$type.'", which is not a recognised plugin type.'
                    .' Use one of: '.implode(', ', self::KNOWN_TYPES).'.',
                ['class' => $class, 'found' => $type, 'known' => implode(',', self::KNOWN_TYPES)]
            );
        }

        $expected = $subject->expectedType();

        if ($expected !== null && $type !== $expected) {
            $findings[] = Finding::failure(
                'A-4',
                $class.'::$type is "'.$type.'" but this package expects "'.$expected.'".'
                    .' Either fix $type or update the expectedType option for this subject.',
                ['class' => $class, 'found' => $type, 'expected' => $expected]
            );
        }

        return $findings;
    }
}
