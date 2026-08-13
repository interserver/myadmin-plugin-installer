<?php
/**
 * @author Joe Huss <detain@interserver.net>
 * @copyright 2026
 * @package MyAdmin
 * @category Testing
 */

namespace MyAdmin\Plugins\Testing;

use MyAdmin\Plugins\Testing\Contract\InspectorRegistry;
use MyAdmin\Plugins\Testing\Contract\PluginSubject;

/**
 * Reads and validates a package's register of **deferred contract defects** (P-bugs it is
 * knowingly not fixing yet), and is the single place that knows where such a register lives.
 *
 * ---------------------------------------------------------------------------------
 * WHY THE DECLARATION IS DATA IN composer.json AND NOT A METHOD IN A TEST CLASS
 * ---------------------------------------------------------------------------------
 * The mechanism this backs — {@see DeferredContractDefects} — began life as a 143-line trait
 * copied into one plugin repo. Roughly 130 of those lines were repo-agnostic mechanism and 15
 * were data, so copying it into the remaining 68 repos would have copied the mechanism 68
 * more times. Hoisting it settles where the mechanism lives. It does **not**, on its own,
 * settle where the *data* lives, and that second question is the one with a correctness
 * consequence:
 *
 * A deferral is an exemption from a shared gate. The failure mode the whole mechanism exists
 * to prevent is a **silent** exemption, so the exemption has to be visible in the artefact
 * gate G2 is reviewed against — the fleet triage matrix. That matrix is produced by
 * `tools/fleet-matrix.php`, which runs one child process per package and inspects the plugin
 * class directly: it never loads the package's PHPUnit classes, never sees its `tests/`
 * autoload namespace, and could not call a `deferredContractDefects()` method on a test class
 * if it wanted to. A register declared in PHP inside a test class is therefore, by
 * construction, invisible to the only document that could disclose it.
 *
 * `composer.json` is visible to both. The matrix generator already decodes every package's
 * manifest to decide fleet membership ({@see FleetMatrix::isInScope()}) and to resolve the
 * plugin class ({@see FleetMatrix::pluginClassFor()}), and a repo's own test run reaches the
 * same file through {@see PluginSubject::packageDir()}. One declaration, two readers, no way
 * to defer an assertion in the suite without the fleet document saying so.
 *
 * ---------------------------------------------------------------------------------
 * THE SHAPE
 * ---------------------------------------------------------------------------------
 *     "extra": {
 *         "myadmin-deferred-contract-defects": {
 *             "B-10": {
 *                 "until": "2026-11-30",
 *                 "issue": "plugin_plan.md Phase 5, Bucket 1 (scaffold-copied abuse.inc.php)",
 *                 "findings": [
 *                     "requirement \"class.Novnc\" registers /../vendor/.../src/Novnc.php"
 *                 ]
 *             }
 *         }
 *     }
 *
 * `findings` holds **fingerprints**: substrings of the failure messages the deferral covers,
 * one per covered failure. They are matched with `strpos()` against
 * {@see \MyAdmin\Plugins\Testing\Contract\Finding::message()} — never against rendered PHPUnit
 * failure text, which is the anti-pattern
 * {@see PluginContractTestCase} documents against and which produced three separate holes in
 * the version this replaced.
 *
 * ---------------------------------------------------------------------------------
 * MALFORMED IS AN ERROR, NOT AN ABSENCE
 * ---------------------------------------------------------------------------------
 * {@see problems()} exists because every plausible mistake in this block — a typo'd
 * assertion id, a date that does not parse, an empty `findings` list — degrades in the
 * dangerous direction if it is merely ignored: an unreadable register defers nothing, which
 * *looks* like a stricter suite right up until someone assumes the deferral is in force. It
 * is reported by {@see DeferredContractDefects::testDeferralRegisterIsWellFormed()} as a
 * failure and by the fleet matrix as a line in its Deferrals section, on the same principle
 * that makes `tools/fleet-matrix.php` hard-error on a census note for an unknown id.
 */
class DeferralRegister
{
    /** The `extra` key a package declares its deferrals under. */
    const MANIFEST_KEY = 'myadmin-deferred-contract-defects';

    /** Every key an entry may carry; nothing else is accepted. */
    const ENTRY_KEYS = ['until', 'issue', 'findings'];

    /**
     * The register a package declares, or `[]` when it declares none.
     *
     * Returns `[]` for a malformed declaration as well as for an absent one — the two are
     * separated by {@see problems()}, not here, so that a caller which only wants to read the
     * register cannot accidentally act on half-parsed data.
     *
     * @param string|null $packageDir the package root, as {@see PluginSubject::packageDir()} reports it
     * @return array<string,array<string,mixed>>
     */
    public static function forPackageDir($packageDir)
    {
        $raw = self::rawForPackageDir($packageDir);
        if (!is_array($raw)) {
            return [];
        }
        $register = [];
        foreach ($raw as $id => $entry) {
            if (is_string($id) && is_array($entry)) {
                $register[$id] = $entry;
            }
        }
        return $register;
    }

    /**
     * @param PluginSubject $subject
     * @return array<string,array<string,mixed>>
     */
    public static function forSubject(PluginSubject $subject)
    {
        return self::forPackageDir(self::packageDirOf($subject));
    }

    /**
     * Everything wrong with a package's declaration, one sentence each. Empty means clean.
     *
     * @param PluginSubject $subject
     * @return array<int,string>
     */
    public static function problemsForSubject(PluginSubject $subject)
    {
        return self::problemsForPackageDir(self::packageDirOf($subject));
    }

    /**
     * @param string|null $packageDir
     * @return array<int,string>
     */
    public static function problemsForPackageDir($packageDir)
    {
        $raw = self::rawForPackageDir($packageDir);
        if ($raw === null) {
            return [];
        }
        if (!is_array($raw)) {
            return ['extra.'.self::MANIFEST_KEY.' is a '.gettype($raw).'; expected an object keyed by'
                .' catalogue assertion id'];
        }
        return self::problems($raw);
    }

    /**
     * Validates an already-read register.
     *
     * Exposed separately from the file reading so the rules can be exercised without a
     * package on disk, and so a caller holding a register from somewhere else (a test
     * fixture, a future second declaration site) is validated by the same code.
     *
     * @param array<mixed,mixed> $register
     * @return array<int,string>
     */
    public static function problems(array $register)
    {
        $known = InspectorRegistry::ids();
        $problems = [];
        foreach ($register as $id => $entry) {
            if (!is_string($id) || $id === '') {
                $problems[] = 'a deferral is keyed by '.gettype($id).'; keys must be catalogue'
                    .' assertion ids such as "B-10"';
                continue;
            }
            if (!in_array($id, $known, true)) {
                $problems[] = '"'.$id.'" is not a catalogue assertion id ('.implode(', ', $known).').'
                    .' A deferral naming an assertion that does not exist defers nothing and hides'
                    .' the fact that it defers nothing';
                continue;
            }
            foreach (self::entryProblems($entry) as $problem) {
                $problems[] = $id.': '.$problem;
            }
        }
        return $problems;
    }

    /**
     * The instant a deferral stops being in force — the end of its `until` day.
     *
     * End of day rather than midnight: `until` is written as a date, and a maintainer reading
     * "until 2026-11-30" reasonably expects the whole of the 30th.
     *
     * @param array<string,mixed> $entry
     * @return int|null unix timestamp, or null when `until` does not parse
     */
    public static function expiresAt(array $entry)
    {
        if (!isset($entry['until']) || !is_string($entry['until'])) {
            return null;
        }
        $at = strtotime($entry['until'].' 23:59:59');
        return $at === false ? null : $at;
    }

    /**
     * One line naming the deferral, its deadline and its size.
     *
     * Shared by the skipped-test message and the fleet matrix so a reader meets the same
     * sentence in both places, and so neither can be reworded without the other following.
     *
     * @param string              $id
     * @param array<string,mixed> $entry
     * @return string
     */
    public static function describe($id, array $entry)
    {
        $findings = isset($entry['findings']) && is_array($entry['findings']) ? $entry['findings'] : [];
        return $id.' deferred until '.(isset($entry['until']) ? (string)$entry['until'] : '(no date)')
            .' pending '.(isset($entry['issue']) ? (string)$entry['issue'] : '(no issue)')
            .' — '.count($findings).' recorded finding(s)';
    }

    // -----------------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------------

    /**
     * The raw `extra.<MANIFEST_KEY>` value, or null when the manifest does not declare one.
     *
     * Null and `[]` are kept apart deliberately: "no register" and "an empty register" are
     * both fine, but "a register that is a string" is not, and only a reader that can see the
     * key was present can say so.
     *
     * @param string|null $packageDir
     * @return mixed
     */
    private static function rawForPackageDir($packageDir)
    {
        if (!is_string($packageDir) || $packageDir === '') {
            return null;
        }
        $manifest = $packageDir.'/composer.json';
        if (!is_file($manifest) || !is_readable($manifest)) {
            return null;
        }
        $json = json_decode((string)file_get_contents($manifest), true);
        if (!is_array($json) || !isset($json['extra']) || !is_array($json['extra'])) {
            return null;
        }
        return array_key_exists(self::MANIFEST_KEY, $json['extra']) ? $json['extra'][self::MANIFEST_KEY] : null;
    }

    /**
     * `packageDir()` reflects the plugin class, which throws for a class that does not load.
     * A deferral register must be readable regardless — a repo whose plugin has stopped
     * loading is exactly the repo whose exemptions want auditing.
     *
     * @param PluginSubject $subject
     * @return string|null
     */
    private static function packageDirOf(PluginSubject $subject)
    {
        try {
            return $subject->packageDir();
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * @param mixed $entry
     * @return array<int,string>
     */
    private static function entryProblems($entry)
    {
        if (!is_array($entry)) {
            return ['entry is a '.gettype($entry).'; expected an object with '
                .implode('/', self::ENTRY_KEYS)];
        }

        $problems = [];
        foreach (array_keys($entry) as $key) {
            if (!in_array($key, self::ENTRY_KEYS, true)) {
                $problems[] = 'unknown key "'.$key.'" (expected only '
                    .implode(', ', self::ENTRY_KEYS).')';
            }
        }

        if (!isset($entry['until']) || !is_string($entry['until']) || trim($entry['until']) === '') {
            $problems[] = '"until" is missing or empty; a deferral with no deadline is a permanent'
                .' exemption wearing a temporary one\'s clothes';
        } elseif (self::expiresAt($entry) === null) {
            $problems[] = '"until" ('.$entry['until'].') is not a date strtotime() can read';
        }

        if (!isset($entry['issue']) || !is_string($entry['issue']) || trim($entry['issue']) === '') {
            $problems[] = '"issue" is missing or empty; the record has to say what is going to fix this';
        }

        if (!isset($entry['findings']) || !is_array($entry['findings']) || $entry['findings'] === []) {
            $problems[] = '"findings" is missing or empty; without fingerprints the deferral covers'
                .' whatever the assertion happens to report, which is a mute button';
            return $problems;
        }
        foreach (array_values($entry['findings']) as $index => $finding) {
            if (!is_string($finding) || trim($finding) === '') {
                $problems[] = 'findings['.$index.'] is not a non-empty string';
            }
        }
        return $problems;
    }
}
