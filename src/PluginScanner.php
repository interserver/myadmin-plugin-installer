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
 * Discovers MyAdmin plugin packages in vendor/ and rebuilds include/config/hooks.json and
 * include/config/plugins.json from what is actually on disk.
 *
 * ---------------------------------------------------------------------------------
 * WHY THIS EXISTS
 * ---------------------------------------------------------------------------------
 * Those two files are MyAdmin's plugin dispatch tables — include/tf.php turns every
 * hooks.json entry into a Symfony EventDispatcher listener at boot. Until now they were
 * rebuilt in exactly one place: include/admin/configuration/plugins.php, which runs only
 * when an administrator loads the admin Plugins page. There is no CLI path, so a
 * `composer update` that adds or removes a plugin left the dispatch tables stale until
 * somebody happened to visit that page.
 *
 * Worse, the original merged into the existing array and never pruned, so entries for
 * renamed or removed packages accumulated forever. At the time this class was written
 * plugins.json held 139 entries, 64 of which pointed at packages that no longer existed.
 *
 * This class rebuilds both files from the packages actually present, so removals prune.
 *
 * ---------------------------------------------------------------------------------
 * SAFETY RULES — these are not optional
 * ---------------------------------------------------------------------------------
 * 1. Scanning include_once's third-party PHP inside a Composer process. Any package may
 *    have side effects or reference MyAdmin globals that do not exist here, so every
 *    package is isolated in its own try/catch(\Throwable) and a failure only skips that
 *    package.
 * 2. include/tf.php json_decode()s hooks.json with NO null check. A truncated or invalid
 *    write is an instant site-wide fatal. writeJson() therefore validates the encoded
 *    payload, refuses to write an empty hook set, and writes via a temp file + rename so
 *    the replacement is atomic.
 * 3. The `enabled` flag in plugins.json is operator state, not derived data. It is carried
 *    across from the existing file for every package that still exists.
 *
 * @see \MyAdmin\Plugins\Plugin::onPostAutoloadDump()
 * @see \MyAdmin\Plugins\Command\UpdatePlugins
 */
class PluginScanner
{
    /**
     * Package name that must never be scanned: this package itself. Its src/Plugin.php is a
     * Composer plugin, not a MyAdmin plugin, and has no getHooks().
     */
    public const SELF_PACKAGE = 'detain/myadmin-plugin-installer';

    /**
     * Absolute path to the vendor directory being scanned.
     *
     * @var string
     */
    protected $vendorDir;

    /**
     * Absolute path to the directory holding hooks.json and plugins.json.
     *
     * @var string
     */
    protected $configDir;

    /**
     * Package names skipped during the last scan, as name => reason.
     *
     * @var array<string, string>
     */
    protected $skipped = [];

    /**
     * @param string $vendorDir absolute path to vendor/
     * @param string $configDir absolute path to include/config/
     */
    public function __construct($vendorDir, $configDir)
    {
        $this->vendorDir = rtrim($vendorDir, '/');
        $this->configDir = rtrim($configDir, '/');
    }

    /**
     * Builds a scanner for a project root.
     *
     * INPUT:   $projectRoot — directory containing composer.json.
     * RETURNS: self
     *
     * @param string $projectRoot
     * @return self
     */
    public static function forProjectRoot($projectRoot)
    {
        $projectRoot = rtrim($projectRoot, '/');
        return new self($projectRoot.'/vendor', $projectRoot.'/include/config');
    }

    /**
     * Package names that ship a src/Plugin.php, excluding this package.
     *
     * INPUT:   none — uses $this->vendorDir.
     * RETURNS: array<int, string> sorted "vendor/name" strings.
     *
     * @return array<int, string>
     */
    public function discoverPackages()
    {
        $found = [];
        foreach (glob($this->vendorDir.'/*/*/src/Plugin.php') as $file) {
            $package = str_replace($this->vendorDir.'/', '', dirname(dirname($file)));
            if ($package === self::SELF_PACKAGE) {
                continue;
            }
            $found[] = $package;
        }
        sort($found);
        return $found;
    }

    /**
     * Loads one package's plugin metadata.
     *
     * INPUT:   $package — "vendor/name".
     * RETURNS: array|null — the plugin data array, or null when the package is not a MyAdmin
     *          plugin (no getHooks(), unreadable composer.json, no psr-4 src/ mapping) or
     *          when loading it threw.
     *
     * Mirrors the shape produced by plugin_load() in
     * include/admin/configuration/plugins.php so the generated files stay byte-compatible
     * with what the admin UI writes.
     *
     * @param string $package
     * @return array|null
     */
    public function loadPlugin($package)
    {
        $base = $this->vendorDir.'/'.$package;
        if (!file_exists($base.'/src/Plugin.php') || !file_exists($base.'/composer.json')) {
            $this->skipped[$package] = 'missing src/Plugin.php or composer.json';
            return null;
        }
        try {
            $composer = json_decode(file_get_contents($base.'/composer.json'), true);
            if (!is_array($composer) || !isset($composer['autoload']['psr-4'])) {
                $this->skipped[$package] = 'composer.json has no autoload.psr-4 section';
                return null;
            }
            $namespace = null;
            foreach ($composer['autoload']['psr-4'] as $ns => $path) {
                if ($path === 'src/' || $path === 'src') {
                    $namespace = $ns;
                    break;
                }
            }
            if ($namespace === null) {
                $this->skipped[$package] = 'no psr-4 namespace mapped to src/';
                return null;
            }
            include_once $base.'/src/Plugin.php';
            $class = $namespace.'Plugin';
            if (!class_exists($class, false) || !method_exists($class, 'getHooks')) {
                $this->skipped[$package] = 'no '.$class.'::getHooks()';
                return null;
            }
            $hooks = call_user_func([$class, 'getHooks']);
            if (!is_array($hooks)) {
                $this->skipped[$package] = 'getHooks() did not return an array';
                return null;
            }
            $composer['packagist'] = isset($composer['name']) ? $composer['name'] : $package;
            unset($composer['name'], $composer['autoload'], $composer['type'], $composer['config'], $composer['help'], $composer['description']);
            $plugin = array_merge($composer, get_class_vars($class));
            $plugin['namespace'] = $namespace;
            $plugin['hooks'] = $hooks;
            return $plugin;
        } catch (\Throwable $e) {
            // Third-party code: a broken package must not take down the whole scan.
            $this->skipped[$package] = get_class($e).': '.$e->getMessage();
            return null;
        }
    }

    /**
     * Scans every discovered package.
     *
     * INPUT:   none.
     * RETURNS: array<string, array> package name => plugin data, for packages that really
     *          are MyAdmin plugins.
     *
     * @return array<string, array>
     */
    public function scan()
    {
        $this->skipped = [];
        $plugins = [];
        foreach ($this->discoverPackages() as $package) {
            $plugin = $this->loadPlugin($package);
            if ($plugin !== null) {
                $plugins[$package] = $plugin;
            }
        }
        return $plugins;
    }

    /**
     * Packages skipped by the most recent scan.
     *
     * RETURNS: array<string, string> package name => reason.
     *
     * @return array<string, string>
     */
    public function getSkipped()
    {
        return $this->skipped;
    }

    /**
     * Builds the hooks.json payload.
     *
     * INPUT:   $plugins  — output of scan(): packages that loaded successfully.
     *          $existing — the currently stored hooks.json, decoded.
     *          $present  — output of discoverPackages(): every package on disk that ships a
     *                      src/Plugin.php, whether or not it scanned.
     * RETURNS: array<string, array> package name => hook map.
     *
     * ---------------------------------------------------------------------------------
     * WHY PRUNING KEYS ON DISK PRESENCE, NOT SCAN SUCCESS
     * ---------------------------------------------------------------------------------
     * A package can be installed and perfectly healthy yet still fail to scan here, because
     * getHooks() implementations reference MyAdmin constants (PRORATE_BILLING,
     * NORMAL_BILLING, ...) that are defined in include/config/config.inc.php and simply do
     * not exist inside a Composer process.
     *
     * Twelve packages in this project behave exactly that way — backups, domains,
     * floating-ips, licenses, mail and quickservers among them. Rebuilding purely from scan
     * results would silently DROP those live modules from MyAdmin's dispatch table and break
     * them in production.
     *
     * So: an entry is removed only when its package is genuinely gone from disk. A package
     * that is present but unscannable keeps whatever hooks it already had.
     *
     * @param array<string, array> $plugins
     * @param array<string, array> $existing
     * @param array<int, string>   $present
     * @return array<string, array>
     */
    public function buildHooks(array $plugins, array $existing = [], array $present = [])
    {
        $presentSet = array_flip($present);
        $hooks = [];
        foreach ($existing as $package => $entry) {
            if (isset($presentSet[$package]) && is_array($entry)) {
                $hooks[$package] = $entry;
            }
        }
        foreach ($plugins as $package => $plugin) {
            $hooks[$package] = $plugin['hooks'];
        }
        return $hooks;
    }

    /**
     * Builds the plugins.json payload, preserving operator state.
     *
     * INPUT:   $plugins  — output of scan().
     *          $existing — the currently stored plugins.json, decoded.
     *          $present  — output of discoverPackages().
     * RETURNS: array<string, array> package name => plugin data.
     *
     * Same presence-based pruning rule as buildHooks(). The `enabled` flag is operator state
     * and is carried across for every package that still exists.
     *
     * @param array<string, array> $plugins
     * @param array<string, array> $existing
     * @param array<int, string>   $present
     * @return array<string, array>
     */
    public function buildPlugins(array $plugins, array $existing = [], array $present = [])
    {
        $presentSet = array_flip($present);
        $out = [];
        foreach ($existing as $package => $entry) {
            if (isset($presentSet[$package]) && is_array($entry)) {
                $out[$package] = $entry;
            }
        }
        foreach ($plugins as $package => $plugin) {
            $enabled = true;
            if (isset($existing[$package]) && is_array($existing[$package]) && array_key_exists('enabled', $existing[$package])) {
                $enabled = $existing[$package]['enabled'];
            }
            $out[$package] = array_merge(['enabled' => $enabled], $plugin);
        }
        return $out;
    }

    /**
     * Reads and decodes a JSON file.
     *
     * INPUT:   $path
     * RETURNS: array — empty when the file is missing or does not decode to an array.
     *
     * @param string $path
     * @return array
     */
    public static function readJson($path)
    {
        if (!file_exists($path)) {
            return [];
        }
        $decoded = json_decode((string)file_get_contents($path), true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Atomically writes a JSON file, refusing to write anything unusable.
     *
     * INPUT:   $path, $data — payload to encode.
     * RETURNS: bool — true if written, false if refused or the write failed.
     *
     * Guards, in order: refuse an empty payload (a scan that found nothing means something
     * is wrong, not that every plugin was uninstalled); refuse if json_encode fails; verify
     * the encoded string decodes back to an array; write to a temp file in the same
     * directory and rename() over the target so readers never observe a partial file.
     *
     * This matters because include/tf.php json_decode()s hooks.json without a null check —
     * a truncated write there is an immediate site-wide fatal.
     *
     * @param string $path
     * @param array  $data
     * @return bool
     */
    public static function writeJson($path, array $data)
    {
        if ($data === []) {
            return false;
        }
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false || json_decode($json, true) === null) {
            return false;
        }
        $dir = dirname($path);
        if (!is_dir($dir)) {
            return false;
        }
        $tmp = tempnam($dir, '.pluginscan');
        if ($tmp === false) {
            return false;
        }
        if (file_put_contents($tmp, $json) === false) {
            @unlink($tmp);
            return false;
        }
        // tempnam() creates 0600; match the mode of the file being replaced, else 0664.
        $mode = file_exists($path) ? (fileperms($path) & 0777) : 0664;
        @chmod($tmp, $mode);
        if (!rename($tmp, $path)) {
            @unlink($tmp);
            return false;
        }
        return true;
    }

    /**
     * Full rebuild of both dispatch tables.
     *
     * INPUT:   $dryRun — when true, compute and report but write nothing.
     * RETURNS: array{
     *              scanned: int, present: int, retained: int, skipped: array<string,string>,
     *              hooks: array{written: bool, added: string[], removed: string[]},
     *              plugins: array{written: bool, added: string[], removed: string[]}
     *          }
     *
     * `scanned` counts packages whose getHooks() was successfully evaluated; `present` counts
     * packages on disk; `retained` is the difference — present but unscannable, whose
     * existing entries were preserved rather than dropped. See buildHooks() for why that
     * distinction is load-bearing.
     *
     * Callers should surface `added`/`removed` rather than writing silently — a prune that
     * drops dozens of entries is something an operator wants to see.
     *
     * @param bool $dryRun
     * @return array
     */
    public function rebuild($dryRun = false)
    {
        $present = $this->discoverPackages();
        $plugins = $this->scan();
        $hooksPath = $this->configDir.'/hooks.json';
        $pluginsPath = $this->configDir.'/plugins.json';

        $existingHooks = self::readJson($hooksPath);
        $existingPlugins = self::readJson($pluginsPath);

        $newHooks = $this->buildHooks($plugins, $existingHooks, $present);
        $newPlugins = $this->buildPlugins($plugins, $existingPlugins, $present);

        $result = [
            'scanned' => count($plugins),
            'present' => count($present),
            'retained' => count($present) - count($plugins),
            'skipped' => $this->skipped,
            'hooks' => [
                'written' => false,
                'added' => array_values(array_diff(array_keys($newHooks), array_keys($existingHooks))),
                'removed' => array_values(array_diff(array_keys($existingHooks), array_keys($newHooks))),
            ],
            'plugins' => [
                'written' => false,
                'added' => array_values(array_diff(array_keys($newPlugins), array_keys($existingPlugins))),
                'removed' => array_values(array_diff(array_keys($existingPlugins), array_keys($newPlugins))),
            ],
        ];
        if ($dryRun) {
            return $result;
        }
        $result['hooks']['written'] = self::writeJson($hooksPath, $newHooks);
        $result['plugins']['written'] = self::writeJson($pluginsPath, $newPlugins);
        return $result;
    }
}
