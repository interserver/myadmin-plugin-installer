<?php
/**
 * @author Joe Huss <detain@interserver.net>
 * @copyright 2026
 * @package MyAdmin
 * @category Testing
 */

namespace MyAdmin\Plugins\Testing\Contract;

use ReflectionClass;

/**
 * The set of Phase 2 assertions, discovered rather than listed.
 *
 * ---------------------------------------------------------------------------------
 * WHY DISCOVERY AND NOT A HARDCODED LIST
 * ---------------------------------------------------------------------------------
 * A hand-maintained array is the obvious implementation and it rots silently: someone
 * adds an inspector, forgets the registry line, and every consumer keeps reporting
 * green over an assertion that never ran. That is the same failure mode
 * {@see Finding::SKIPPED} exists to prevent, one level up — a matrix that looks
 * complete because the missing column was never rendered.
 *
 * Discovery cannot drift, because the thing being discovered (implements
 * {@see PluginInspector}, concrete) is exactly the thing being asked for.
 *
 * The scan is anchored on `__DIR__`, never the current working directory: the fleet
 * self-check runs from an arbitrary cwd, and `include/tf.php` already demonstrates
 * how a bare relative path behaves when that assumption breaks.
 */
class InspectorRegistry
{
    /** @var array<int,class-string>|null memoised, keyed by nothing — the dir cannot change mid-process */
    private static $classes = null;

    /**
     * Every concrete inspector, ordered by catalogue id (A-1 ... A-9, B-9 ... B-15).
     *
     * @return array<int,\MyAdmin\Plugins\Testing\Contract\PluginInspector>
     */
    public static function all()
    {
        $inspectors = [];
        foreach (self::classes() as $class) {
            $inspectors[] = new $class();
        }
        usort($inspectors, function ($a, $b) {
            return self::compareIds($a->id(), $b->id());
        });
        return $inspectors;
    }

    /**
     * Inspector class names, unordered.
     *
     * Kept separate from {@see all()} so a PHPUnit data provider can enumerate the
     * assertions without constructing anything — providers run before the test, and
     * an inspector constructor is not the place to discover a broken plugin.
     *
     * @return array<int,class-string>
     */
    public static function classes()
    {
        if (self::$classes !== null) {
            return self::$classes;
        }
        $found = [];
        $files = glob(__DIR__.'/*.php');
        if ($files === false) {
            $files = [];
        }
        foreach ($files as $file) {
            $class = __NAMESPACE__.'\\'.basename($file, '.php');
            if (!class_exists($class)) {
                continue;
            }
            $reflection = new ReflectionClass($class);
            if ($reflection->isAbstract() || !$reflection->implementsInterface(__NAMESPACE__.'\\PluginInspector')) {
                continue;
            }
            $found[] = $class;
        }
        sort($found);
        self::$classes = $found;
        return self::$classes;
    }

    /**
     * Catalogue ids in display order.
     *
     * @return array<int,string>
     */
    public static function ids()
    {
        $ids = [];
        foreach (self::all() as $inspector) {
            $ids[] = $inspector->id();
        }
        return $ids;
    }

    /**
     * Orders "A-7" before "A-10" and "B-9" before "B-9b" — a plain string sort puts
     * "A-10" before "A-2", which makes the triage matrix columns unreadable.
     *
     * @param string $a
     * @param string $b
     * @return int
     */
    public static function compareIds($a, $b)
    {
        $left = self::idParts($a);
        $right = self::idParts($b);
        if ($left[0] !== $right[0]) {
            return strcmp($left[0], $right[0]);
        }
        if ($left[1] !== $right[1]) {
            return $left[1] < $right[1] ? -1 : 1;
        }
        return strcmp($left[2], $right[2]);
    }

    /**
     * Splits "B-9b" into ['B', 9, 'b'].
     *
     * @param string $id
     * @return array{0:string,1:int,2:string}
     */
    private static function idParts($id)
    {
        $matches = [];
        if (preg_match('/^([A-Za-z]+)-(\d+)(.*)$/', (string)$id, $matches) !== 1) {
            return [(string)$id, 0, ''];
        }
        return [$matches[1], (int)$matches[2], $matches[3]];
    }

    /**
     * Drops the memoised scan. Tests only.
     *
     * @return void
     */
    public static function reset()
    {
        self::$classes = null;
    }
}
