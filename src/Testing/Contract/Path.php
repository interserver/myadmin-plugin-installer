<?php
/**
 * Plugins Management
 * @author Joe Huss <detain@interserver.net>
 * @copyright 2026
 * @package MyAdmin
 * @category Testing
 */

namespace MyAdmin\Plugins\Testing\Contract;

/**
 * Lexical path handling that does not assume the separator is `/`.
 *
 * ---------------------------------------------------------------------------------
 * WHY THIS EXISTS
 * ---------------------------------------------------------------------------------
 * The inspectors resolve paths that may not exist — the whole point of B-10 and B-14 is to
 * name a `require()` target or a template directory that is *missing* — so `realpath()` is
 * unusable, and every resolution is lexical. Two inspectors wrote that logic themselves,
 * and one of them defined "absolute" as "starts with `/`".
 *
 * On Windows `dirname(__FILE__)` returns `D:\a\pkg\src`, which fails that test, so the
 * whole path was treated as relative, `explode('/')` returned it as a single segment, and
 * the drive letter was dropped. B-14 then resolved every plugin's template directory to
 * the bare string `templates` and reported all 66 packages as having no templates at all.
 * That is a false accusation of exactly the kind the harness must not make — it just
 * happened to only fire on an OS nobody deploys this on, so it surfaced as 16 red tests on
 * one CI leg rather than as a wrong finding in front of an operator.
 *
 * ---------------------------------------------------------------------------------
 * LEXICAL, AND DELIBERATELY SO
 * ---------------------------------------------------------------------------------
 * Nothing here touches the filesystem. `..` is collapsed textually, which is not the same
 * answer `realpath()` would give through a symlink — and that is the correct trade here,
 * because a path that resolves to nothing still has to be printable in a finding.
 *
 * Output always uses `/`, including on Windows, where PHP accepts it everywhere. A finding
 * that reads the same on every platform is worth more than one that matches the local
 * shell's preferred slash.
 */
final class Path
{
    /**
     * Whether a path is rooted, on any platform.
     *
     * Recognises POSIX `/foo`, Windows `C:\foo` and `C:/foo`, a Windows drive-relative
     * `\foo`, and a UNC `\\server\share`.
     *
     * @param string $path
     * @return bool
     */
    public static function isAbsolute($path)
    {
        $path = (string)$path;
        if ($path === '') {
            return false;
        }
        if ($path[0] === '/' || $path[0] === '\\') {
            return true;
        }
        return preg_match('#^[A-Za-z]:[\\\\/]#', $path) === 1;
    }

    /**
     * The rooted prefix of a path — what must survive segment collapsing.
     *
     * RETURNS: string — `''` for a relative path, otherwise one of `/`, `//`, `C:/`.
     *
     * @param string $path
     * @return string
     */
    public static function prefix($path)
    {
        $path = str_replace('\\', '/', (string)$path);
        if (preg_match('#^([A-Za-z]:)/#', $path, $found) === 1) {
            return $found[1].'/';
        }
        if (strpos($path, '//') === 0) {
            return '//';
        }
        if (strpos($path, '/') === 0) {
            return '/';
        }
        return '';
    }

    /**
     * Collapses `.` and `..` lexically, keeping whatever root the path had.
     *
     * A `..` that would climb past the root of an absolute path is dropped rather than
     * kept, because `/..` is `/`. On a relative path it is kept, because there is no root
     * to be above and dropping it would silently change which directory is meant.
     *
     * @param string $path
     * @return string
     */
    public static function normalise($path)
    {
        $path = str_replace('\\', '/', (string)$path);
        $prefix = self::prefix($path);
        $rest = $prefix === '' ? $path : substr($path, strlen($prefix));

        $segments = [];
        foreach (explode('/', (string)$rest) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                if ($segments !== [] && end($segments) !== '..') {
                    array_pop($segments);
                    continue;
                }
                if ($prefix !== '') {
                    continue;
                }
            }
            $segments[] = $segment;
        }

        return $prefix.implode('/', $segments);
    }

    /**
     * Joins a fragment onto a base, unless the fragment is already rooted.
     *
     * @param string $base
     * @param string $fragment
     * @return string
     */
    public static function join($base, $fragment)
    {
        if (self::isAbsolute($fragment)) {
            return self::normalise($fragment);
        }
        return self::normalise(rtrim(str_replace('\\', '/', (string)$base), '/').'/'.ltrim(str_replace('\\', '/', (string)$fragment), '/'));
    }

    /**
     * Anchors a fragment under a base **unconditionally**, root or not.
     *
     * Distinct from {@see join()}, and the distinction is load-bearing. B-14 decides from
     * the source whether a template directory was anchored on `__DIR__`, on the package,
     * or on nothing — and once it has decided "under the package", a fragment that happens
     * to start with a separator is still a fragment. Handing that case to `join()` would
     * silently promote `'/templates'` to the filesystem root.
     *
     * @param string $base
     * @param string $fragment
     * @return string
     */
    public static function under($base, $fragment)
    {
        $base = rtrim(str_replace('\\', '/', (string)$base), '/');
        $fragment = ltrim(str_replace('\\', '/', (string)$fragment), '/');

        return self::normalise($base.'/'.$fragment);
    }

    /**
     * Whether two paths name the same location, ignoring separator style.
     *
     * Case is significant even on Windows. Treating `C:/Temp` and `c:/temp` as equal would
     * make a finding's path match one that was never written, and the inspectors only ever
     * compare paths they themselves derived from the same source.
     *
     * @param string $a
     * @param string $b
     * @return bool
     */
    public static function equals($a, $b)
    {
        return self::normalise($a) === self::normalise($b);
    }
}
