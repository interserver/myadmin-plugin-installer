<?php
/**
 * Plugins Management
 * @author Joe Huss <detain@interserver.net>
 * @copyright 2025
 * @package MyAdmin
 * @category Plugins
 */

namespace MyAdmin\Plugins;

/**
 * Detects uncommitted work in source-installed vendor packages before Composer overwrites it.
 *
 * ---------------------------------------------------------------------------------
 * THE PROBLEM THIS SOLVES
 * ---------------------------------------------------------------------------------
 * MyAdmin installs its own packages from source:
 *
 *     "preferred-install": { "detain/*": "source", ... }
 *     "discard-changes":   "stash"
 *
 * That makes roughly a hundred directories under vendor/ real git working copies that
 * developers edit in place, and it tells Composer to STASH any local modifications it finds
 * rather than refusing to proceed. The stash is silent. Work disappears from the working
 * tree with no prompt and no summary, recoverable only by someone who knows to run
 * `git stash list` inside that specific vendor directory.
 *
 * The existing workflow depends on remembering to run scripts/plugins/plugins_diff.sh by
 * hand first. This class makes it automatic.
 *
 * ---------------------------------------------------------------------------------
 * POLICY
 * ---------------------------------------------------------------------------------
 * WARN on install, ABORT on update.
 *
 * `composer update` is the destructive, developer-initiated case and is where stashing
 * actually happens, so a dirty tree stops it. `composer install` runs on deploy targets and
 * in CI where an abort would be more harmful than the thing it prevents, so it only warns.
 *
 * Escape hatch for a deliberate update over dirty state:
 *
 *     MYADMIN_ALLOW_DIRTY_VENDOR=1 composer update
 *
 * CI is unaffected: a fresh runner has no vendor/ directory, so nothing is ever dirty.
 *
 * @see \MyAdmin\Plugins\Plugin::onPreUpdateCmd()
 * @see \MyAdmin\Plugins\Plugin::onPreInstallCmd()
 */
class VendorGuard
{
    /**
     * Environment variable that downgrades the update-time abort to a warning.
     */
    public const OVERRIDE_ENV = 'MYADMIN_ALLOW_DIRTY_VENDOR';

    /**
     * Absolute path to the vendor directory being inspected.
     *
     * @var string
     */
    protected $vendorDir;

    /**
     * @param string $vendorDir absolute path to vendor/
     */
    public function __construct($vendorDir)
    {
        $this->vendorDir = rtrim(self::normalizeSeparators($vendorDir), '/');
    }

    /**
     * Rewrites backslashes to forward slashes.
     *
     * Composer package names are canonically "vendor/name" with a forward slash on every
     * platform, and git accepts forward slashes in paths on Windows too. Normalising once
     * on the way in means the rest of this class never has to care which separator the
     * caller or `glob()` happened to produce.
     *
     * @param string $path
     * @return string
     */
    public static function normalizeSeparators($path)
    {
        return str_replace('\\', '/', $path);
    }

    /**
     * Derives the composer package name from one `glob()` hit.
     *
     * INPUT:   $vendorDir — the vendor root; $gitDir — a matched ".../vendor/acme/one/.git".
     * RETURNS: string — "acme/one".
     *
     * Split out of {@see findWorkingCopies()} and made static so the separator handling is
     * reachable from a test on a machine whose `glob()` never emits backslashes. PHP's glob
     * echoes back the separators of the pattern it was given, and on Windows the vendor root
     * typically arrives from `sys_get_temp_dir()` or Composer with backslashes, so both sides
     * are normalised before the prefix is stripped.
     *
     * @param string $vendorDir
     * @param string $gitDir
     * @return string
     */
    public static function packageNameFor($vendorDir, $gitDir)
    {
        $vendorDir = rtrim(self::normalizeSeparators($vendorDir), '/');
        return str_replace($vendorDir.'/', '', dirname(self::normalizeSeparators($gitDir)));
    }

    /**
     * The shell token that discards a stream.
     *
     * `/dev/null` is a Unix path. cmd.exe does not resolve it, and the redirection failing
     * aborts the whole command line *before* git runs — so on Windows every git invocation
     * here used to die with "The system cannot find the path specified." and a non-zero
     * status, which {@see statusLines()} then read as "clean". The guard that exists to stop
     * Composer silently stashing work was itself silently inert on Windows.
     *
     * INPUT:   $isWindows — forced platform, or null to detect. The parameter exists so both
     *          branches are reachable from a test on either platform; production always
     *          passes null.
     *
     * @param bool|null $isWindows
     * @return string
     */
    public static function nullDevice($isWindows = null)
    {
        if ($isWindows === null) {
            $isWindows = DIRECTORY_SEPARATOR === '\\';
        }
        return $isWindows ? 'NUL' : '/dev/null';
    }

    /**
     * Whether the abort has been explicitly overridden for this run.
     *
     * RETURNS: bool — true when MYADMIN_ALLOW_DIRTY_VENDOR is set to anything other than
     *          '', '0' or 'false'.
     *
     * @return bool
     */
    public static function isOverridden()
    {
        $value = getenv(self::OVERRIDE_ENV);
        if ($value === false) {
            return false;
        }
        $value = strtolower(trim($value));
        return $value !== '' && $value !== '0' && $value !== 'false';
    }

    /**
     * Vendor packages that are git working copies.
     *
     * RETURNS: array<int, string> sorted "vendor/name" strings.
     *
     * @return array<int, string>
     */
    public function findWorkingCopies()
    {
        $found = [];
        $matches = glob($this->vendorDir.'/*/*/.git', GLOB_ONLYDIR);
        if ($matches === false) {
            return [];
        }
        foreach ($matches as $gitDir) {
            $found[] = self::packageNameFor($this->vendorDir, $gitDir);
        }
        sort($found);
        return $found;
    }

    /**
     * Vendor working copies with uncommitted changes.
     *
     * RETURNS: array<string, array<int, string>> package name => porcelain status lines,
     *          capped at 20 lines per package so the report stays readable.
     *
     * Uses `git status --porcelain`, which lists modified, staged, deleted and untracked
     * paths, and is empty for a clean tree. Packages whose git invocation fails (not a repo,
     * git missing, permissions) are treated as clean — this guard must never itself be the
     * reason a build breaks.
     *
     * @return array<string, array<int, string>>
     */
    public function findDirty()
    {
        $dirty = [];
        foreach ($this->findWorkingCopies() as $package) {
            $lines = $this->statusLines($package);
            if ($lines !== []) {
                $dirty[$package] = array_slice($lines, 0, 20);
            }
        }
        return $dirty;
    }

    /**
     * Porcelain status lines for one package.
     *
     * INPUT:   $package — "vendor/name".
     * RETURNS: array<int, string> — empty for a clean tree or on any git failure.
     *
     * @param string $package
     * @return array<int, string>
     */
    protected function statusLines($package)
    {
        $dir = $this->vendorDir.'/'.$package;
        if (!is_dir($dir.'/.git')) {
            return [];
        }
        $output = [];
        $return = 0;
        exec('git -C '.escapeshellarg($dir).' status --porcelain 2>'.self::nullDevice(), $output, $return);
        if ($return !== 0) {
            return [];
        }
        return array_values(array_filter(array_map('trim', $output), function ($line) {
            return $line !== '';
        }));
    }

    /**
     * Formats a dirty-package report for console output.
     *
     * INPUT:   $dirty — output of findDirty().
     * RETURNS: array<int, string> lines ready to hand to IOInterface::writeError().
     *
     * @param array<string, array<int, string>> $dirty
     * @return array<int, string>
     */
    public static function formatReport(array $dirty)
    {
        $lines = [];
        foreach ($dirty as $package => $changes) {
            $lines[] = sprintf('  <comment>%s</comment> (%d change%s)', $package, count($changes), count($changes) === 1 ? '' : 's');
            foreach (array_slice($changes, 0, 5) as $change) {
                $lines[] = '      '.$change;
            }
            if (count($changes) > 5) {
                $lines[] = sprintf('      ... and %d more', count($changes) - 5);
            }
        }
        return $lines;
    }
}
