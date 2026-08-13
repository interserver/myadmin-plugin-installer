<?php
/**
 * Plugins Management
 * @author Joe Huss <detain@interserver.net>
 * @copyright 2026
 * @package MyAdmin
 * @category Testing
 */

/**
 * Measures one plugin package by executing it, and prints the result as one line of JSON.
 *
 *     php src/Testing/Scaffold/probe.php /path/to/plugin-repo
 *
 * ---------------------------------------------------------------------------------
 * WHY THIS IS A SCRIPT AND NOT A CLASS
 * ---------------------------------------------------------------------------------
 * It has to run in a process of its own, under the *plugin repo's* autoloader rather than
 * the installer's. Two reasons, both learned the hard way:
 *
 *   1. Priming is irreversible. Bootstrap::init() defines real constants and calls
 *      register_module(). PHP cannot undefine a constant and register_module() has no
 *      inverse, so a probe that ran in the scaffolder's process would leave that process
 *      permanently contaminated — and the scaffolder may be asked to probe a second package
 *      in the same run.
 *   2. The plugin class only exists under its own repo's autoloader. The installer is a
 *      dependency of the plugin, not the other way round.
 *
 * ---------------------------------------------------------------------------------
 * STDOUT IS A DATA CHANNEL
 * ---------------------------------------------------------------------------------
 * Exactly one line of JSON goes to stdout and nothing else ever may. A package still
 * carrying an old vendored installer emits deprecations while autoloading, and those
 * landing on stdout would corrupt the payload. They are still worth seeing, so they are
 * routed to stderr, which the caller surfaces separately.
 *
 * Exit codes: 0 measured · 2 no vendor/autoload.php · 3 no plugin class · 4 bad manifest.
 */

error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);
ini_set('display_errors', 'stderr');

$root = rtrim(isset($argv[1]) ? $argv[1] : getcwd(), '/');

if (!is_file($root.'/vendor/autoload.php')) {
    fwrite(STDERR, "no {$root}/vendor/autoload.php — run composer install in the package first\n");
    exit(2);
}
if (!is_file($root.'/composer.json')) {
    fwrite(STDERR, "no {$root}/composer.json\n");
    exit(4);
}

require $root.'/vendor/autoload.php';

$manifest = json_decode(file_get_contents($root.'/composer.json'), true);
if (!is_array($manifest)) {
    fwrite(STDERR, "{$root}/composer.json is not valid JSON\n");
    exit(4);
}

/**
 * Finds the PSR-4 prefix a manifest maps to a given directory.
 *
 * @param array  $map
 * @param string $dir
 * @return string|null
 */
$prefixFor = static function (array $map, $dir) {
    foreach ($map as $prefix => $path) {
        foreach ((array)$path as $one) {
            if (rtrim((string)$one, '/') === $dir) {
                return $prefix;
            }
        }
    }
    return null;
};

$srcPrefix = $prefixFor(
    isset($manifest['autoload']['psr-4']) ? $manifest['autoload']['psr-4'] : [],
    'src'
);
$testPrefix = $prefixFor(
    isset($manifest['autoload-dev']['psr-4']) ? $manifest['autoload-dev']['psr-4'] : [],
    'tests'
);

if ($srcPrefix === null) {
    fwrite(STDERR, "no autoload.psr-4 prefix mapped to src/ in {$root}/composer.json\n");
    exit(4);
}

$class = $srcPrefix.'Plugin';

// Prime before the class is mentioned. A static property initializer can itself reference a
// bare constant — `$settings` holding REPEAT_BILLING_METHOD => PRORATE_BILLING is the shape
// that bit mail-module — and that initializer runs when the class loads, so even asking
// class_exists() about it fatals on an unprimed class.
\MyAdmin\Plugins\Testing\Bootstrap::init(['plugin' => $class, 'acl' => true]);

if (!class_exists($class)) {
    fwrite(STDERR, "no such class: {$class}\n");
    exit(3);
}

/**
 * Reads a static property without assuming it is declared.
 *
 * @param string $class
 * @param string $property
 * @return mixed|null
 */
$staticValue = static function ($class, $property) {
    $reflection = new ReflectionClass($class);
    return $reflection->hasProperty($property) ? $reflection->getStaticPropertyValue($property) : null;
};

$hookKeys = [];
$hookError = null;
try {
    $hooks = $class::getHooks();
    $hookKeys = is_array($hooks) ? array_keys($hooks) : [];
} catch (\Throwable $e) {
    // Not fatal to the probe: a getHooks() that throws is a finding assertion A-5 reports,
    // and the scaffold should still be generated so that finding has somewhere to appear.
    $hookError = get_class($e).': '.$e->getMessage();
}

echo json_encode([
    'class'         => $class,
    'testNamespace' => $testPrefix,
    'name'          => $staticValue($class, 'name'),
    'type'          => $staticValue($class, 'type'),
    'module'        => $staticValue($class, 'module'),
    'hookKeys'      => $hookKeys,
    'hookError'     => $hookError,
], JSON_UNESCAPED_SLASHES), "\n";
