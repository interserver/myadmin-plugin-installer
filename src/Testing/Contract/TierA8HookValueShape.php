<?php
/**
 * @author Joe Huss <detain@interserver.net>
 * @copyright 2026
 * @package MyAdmin
 * @category Testing
 */

namespace MyAdmin\Plugins\Testing\Contract;

/**
 * A-8 — every hook value is a two-element `[class-string, method-string]` array.
 *
 * The dispatcher turns each `hooks.json` entry into an EventDispatcher listener, and the
 * only form it understands is the two-element callable array every fleet plugin writes as
 * `[__CLASS__, 'getSettings']`. A closure survives `getHooks()` but not the JSON round-trip
 * through `hooks.json`; a bare `'Class::method'` string, a three-element array, or an array
 * with string keys all pass `is_array()` and then fail — or worse, silently do nothing — at
 * dispatch time, far from the plugin that caused it.
 *
 * Shape only. Whether the named class and method actually exist is a Tier B question; this
 * inspector is what makes that later check well-defined, since it cannot resolve a target it
 * cannot destructure.
 *
 * One finding per problem: a value that is neither an array nor the right length yields one,
 * and an entry whose class *and* method slots are both wrong yields two, because they are
 * two separate edits.
 */
class TierA8HookValueShape implements PluginInspector
{
    /**
     * @return string
     */
    public function id()
    {
        return 'A-8';
    }

    /**
     * @return string
     */
    public function title()
    {
        return 'Hook values are 2-element [class-string, method] arrays';
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
                'A-8',
                'Plugin class '.$class.' could not be loaded, so its hook values cannot be'
                    .' inspected (see A-1).',
                ['class' => $class]
            )];
        }

        $hooks = TierA5HooksAreIdempotent::hookTable($subject);

        if ($hooks === null) {
            return [Finding::skipped(
                'A-8',
                $class.'::getHooks() could not be evaluated, so its hook values cannot be'
                    .' inspected (see A-5).',
                ['class' => $class]
            )];
        }

        $findings = [];

        foreach ($hooks as $key => $target) {
            $label = is_string($key) ? $key : var_export($key, true);

            if (!is_array($target)) {
                $findings[] = Finding::failure(
                    'A-8',
                    $class.' maps hook "'.$label.'" to '.gettype($target).'. Every hook value'
                        .' must be a 2-element array [class-string, method], e.g.'
                        .' [__CLASS__, \'getSettings\'].',
                    ['class' => $class, 'key' => $label, 'found' => gettype($target), 'problem' => 'not-array']
                );
                continue;
            }

            if (array_keys($target) !== [0, 1]) {
                $findings[] = Finding::failure(
                    'A-8',
                    $class.' maps hook "'.$label.'" to an array with keys ['
                        .implode(', ', array_map(function ($k) {
                            return var_export($k, true);
                        }, array_keys($target)))
                        .']; expected exactly a list of 2 elements [class-string, method].',
                    [
                        'class' => $class,
                        'key' => $label,
                        'count' => count($target),
                        'problem' => 'wrong-arity',
                    ]
                );
                continue;
            }

            if (!is_string($target[0]) || $target[0] === '') {
                $findings[] = Finding::failure(
                    'A-8',
                    $class.' maps hook "'.$label.'" to a target whose first element is '
                        .(is_string($target[0]) ? 'an empty string' : gettype($target[0]))
                        .'; it must be a non-empty class-string.',
                    [
                        'class' => $class,
                        'key' => $label,
                        'found' => gettype($target[0]),
                        'problem' => 'bad-class',
                    ]
                );
            }

            if (!is_string($target[1]) || $target[1] === '') {
                $findings[] = Finding::failure(
                    'A-8',
                    $class.' maps hook "'.$label.'" to a target whose second element is '
                        .(is_string($target[1]) ? 'an empty string' : gettype($target[1]))
                        .'; it must be a non-empty method name.',
                    [
                        'class' => $class,
                        'key' => $label,
                        'found' => gettype($target[1]),
                        'problem' => 'bad-method',
                    ]
                );
            }
        }

        return $findings;
    }
}
