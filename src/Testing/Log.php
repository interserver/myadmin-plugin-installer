<?php
/**
 * @author Joe Huss <detain@interserver.net>
 * @copyright 2026
 * @package MyAdmin
 * @category Testing
 */

namespace MyAdmin\Plugins\Testing;

/**
 * Sink and query API for `myadmin_log()`, the single most-called symbol in the
 * fleet (807 calls across plugin sources).
 *
 * Static because `myadmin_log()` is a global function with no object to hang
 * state on. `Bootstrap::reset()` clears it between tests.
 */
class Log
{
    /**
     * @var array<int,array{section:string,level:string,text:string,line:mixed,file:mixed,module:mixed,service:mixed,custid:mixed}>
     */
    private static $entries = [];

    /**
     * Records one log line. Mirrors the core `myadmin_log()` signature.
     *
     * @param string $section
     * @param string $level
     * @param string $text
     * @param mixed  $line
     * @param mixed  $file
     * @param mixed  $module
     * @param mixed  $service
     * @param mixed  $custid
     * @return void
     */
    public static function add($section, $level, $text, $line = '', $file = '', $module = false, $service = false, $custid = false)
    {
        self::$entries[] = [
            'section' => $section,
            'level'   => $level,
            'text'    => $text,
            'line'    => $line,
            'file'    => $file,
            'module'  => $module,
            'service' => $service,
            'custid'  => $custid,
        ];
    }

    /**
     * Every entry, in order.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function entries()
    {
        return self::$entries;
    }

    /**
     * Entries at one log level (`info`, `warning`, `error`, …).
     *
     * @param string $level
     * @return array<int,array<string,mixed>>
     */
    public static function entriesAtLevel($level)
    {
        $matches = array_filter(self::$entries, static function (array $entry) use ($level) {
            return $entry['level'] === $level;
        });
        return array_values($matches);
    }

    /**
     * Entries for one module/section.
     *
     * @param string $section
     * @return array<int,array<string,mixed>>
     */
    public static function entriesForSection($section)
    {
        $matches = array_filter(self::$entries, static function (array $entry) use ($section) {
            return $entry['section'] === $section;
        });
        return array_values($matches);
    }

    /**
     * Whether any entry's text contains the given substring.
     *
     * @param string $needle
     * @return bool
     */
    public static function hasEntryContaining($needle)
    {
        foreach (self::$entries as $entry) {
            if (strpos((string)$entry['text'], $needle) !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * @return int
     */
    public static function count()
    {
        return count(self::$entries);
    }

    /**
     * @return void
     */
    public static function reset()
    {
        self::$entries = [];
    }
}
