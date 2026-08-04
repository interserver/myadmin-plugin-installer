<?php
/**
 * @author Joe Huss <detain@interserver.net>
 * @copyright 2026
 * @package MyAdmin
 * @category Testing
 */

namespace MyAdmin\Plugins\Testing\Contract;

/**
 * A-6 — every hook key is a lowercase `prefix.event` string.
 *
 * `run_event('vps.activate', …)` dispatches on an exact string match, so a key that differs
 * from the dispatched name by a capital letter, a hyphen, a stray space or a second dot
 * registers cleanly, survives the `hooks.json` write, and is then never called. Nothing
 * anywhere reports it. The format is the only place that can be mechanically checked.
 *
 * PHP array semantics add one shape this cannot express: a numeric-looking key such as
 * `'0'` is silently converted to the integer `0`, which is not a string at all. That is
 * reported separately, with its type, rather than being coerced and pattern-matched.
 *
 * Whether the prefix is one a dispatcher actually uses is A-7's question; this inspector
 * only judges the shape. One finding per offending key.
 */
class TierA6HookKeyFormat implements PluginInspector
{
    /**
     * `prefix.event`, lowercase letters and underscores on each side of a single dot.
     *
     * @var string
     */
    const KEY_PATTERN = '/^[a-z_]+\.[a-z_]+$/';

    /**
     * @return string
     */
    public function id()
    {
        return 'A-6';
    }

    /**
     * @return string
     */
    public function title()
    {
        return 'Hook keys are lowercase prefix.event strings';
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
                'A-6',
                'Plugin class '.$class.' could not be loaded, so its hook keys cannot be'
                    .' inspected (see A-1).',
                ['class' => $class]
            )];
        }

        $hooks = TierA5HooksAreIdempotent::hookTable($subject);

        if ($hooks === null) {
            return [Finding::skipped(
                'A-6',
                $class.'::getHooks() could not be evaluated, so its hook keys cannot be'
                    .' inspected (see A-5).',
                ['class' => $class]
            )];
        }

        $findings = [];

        foreach ($hooks as $key => $unusedTarget) {
            if (!is_string($key)) {
                $findings[] = Finding::failure(
                    'A-6',
                    $class.' registers hook key '.var_export($key, true).', which is a '
                        .gettype($key).'. Hook keys must be strings of the form'
                        .' "prefix.event" — note PHP converts numeric string keys to integers.',
                    ['class' => $class, 'key' => $key, 'found' => gettype($key)]
                );
                continue;
            }

            if (preg_match(self::KEY_PATTERN, $key) !== 1) {
                $findings[] = Finding::failure(
                    'A-6',
                    $class.' registers hook key "'.$key.'", which does not match'
                        .' "prefix.event" (lowercase letters and underscores, exactly one dot).'
                        .' run_event() matches names exactly, so this hook is never dispatched.',
                    ['class' => $class, 'key' => $key, 'pattern' => self::KEY_PATTERN]
                );
            }
        }

        return $findings;
    }
}
