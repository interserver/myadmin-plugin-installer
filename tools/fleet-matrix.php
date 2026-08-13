<?php
/**
 * Regenerate docs/phase2-triage-matrix.md — the gate G2 fleet artefact.
 *
 * @author Joe Huss <detain@interserver.net>
 * @copyright 2026
 * @package MyAdmin
 * @category Testing
 *
 * ---------------------------------------------------------------------------------
 * WHY ONE PROCESS PER PACKAGE
 * ---------------------------------------------------------------------------------
 * Inspecting a plugin defines constants and calls `register_module()`. Constants cannot
 * be undefined and `register_module()` has no inverse, so plugin *n* would contaminate
 * plugin *n+1* in a shared process: the second plugin to declare `PRORATE_BILLING` would
 * be measured against the first plugin's value. The isolation is the reason this is a
 * process-spawning shim and not a PHPUnit data provider.
 *
 * ---------------------------------------------------------------------------------
 * WHY THIS FILE HAS ALMOST NO LOGIC
 * ---------------------------------------------------------------------------------
 * Selection, tabulation and rendering are in {@see \MyAdmin\Plugins\Testing\FleetMatrix},
 * which is unit-tested. What is left here is process management and file I/O — the parts
 * a test could only assert against itself.
 *
 * ---------------------------------------------------------------------------------
 * USAGE
 * ---------------------------------------------------------------------------------
 *   php tools/fleet-matrix.php                 regenerate docs/phase2-triage-matrix.md
 *   php tools/fleet-matrix.php --check         exit 2 if the committed doc is stale
 *   php tools/fleet-matrix.php --jsonl=PATH    also write the raw per-package records
 *   php tools/fleet-matrix.php --vendor-dir=D  inspect the fleet under a different checkout
 *
 * Exit codes: 0 clean · 1 a package produced no verdict (broken run) · 2 doc is stale.
 *
 * This needs a MyAdmin core checkout, because the fleet is the plugin packages installed
 * beside this one. Run from a standalone clone of the installer and it will tell you so
 * rather than emit an empty, green-looking matrix.
 */

use MyAdmin\Plugins\Testing\Contract\InspectorRegistry;
use MyAdmin\Plugins\Testing\Contract\PluginSubject;
use MyAdmin\Plugins\Testing\DeferralRegister;
use MyAdmin\Plugins\Testing\FleetMatrix;
use MyAdmin\Plugins\Testing\PluginContractTestCase;

$packageRoot = dirname(__DIR__);
$options = fleetMatrixOptions(array_slice($argv, 1), $packageRoot);

require_once $options['vendorDir'].'/autoload.php';

if (isset($options['package'])) {
    exit(fleetMatrixChild($options['package'], $options['class']));
}
exit(fleetMatrixParent($options, $packageRoot));

// ---------------------------------------------------------------------------
// Argument handling
// ---------------------------------------------------------------------------

/**
 * @param array<int,string> $argv arguments after the script name
 * @param string $packageRoot the installer package directory
 * @return array<string,mixed>
 */
function fleetMatrixOptions(array $argv, $packageRoot)
{
    $options = [
        'vendorDir' => dirname(dirname($packageRoot)),
        'out' => $packageRoot.'/docs/phase2-triage-matrix.md',
        'notes' => $packageRoot.'/docs/phase2-triage-matrix.notes.json',
        'jsonl' => null,
        'check' => false,
    ];
    foreach ($argv as $arg) {
        if ($arg === '--check') {
            $options['check'] = true;
            continue;
        }
        $eq = strpos($arg, '=');
        if (strpos($arg, '--') !== 0 || $eq === false) {
            fleetMatrixDie('unrecognised argument "'.$arg.'" — see the docblock at the top of this file');
        }
        $name = substr($arg, 2, $eq - 2);
        $value = substr($arg, $eq + 1);
        if (!array_key_exists(fleetMatrixCamel($name), $options) && !in_array($name, ['package', 'class'], true)) {
            fleetMatrixDie('unrecognised option "--'.$name.'"');
        }
        $options[fleetMatrixCamel($name)] = $value;
    }
    if (isset($options['package']) !== isset($options['class'])) {
        fleetMatrixDie('--package and --class must be given together');
    }
    if (!is_file($options['vendorDir'].'/autoload.php')) {
        fleetMatrixDie(
            'no composer autoloader at '.$options['vendorDir'].'/autoload.php.'."\n"
            .'The fleet is the plugin packages installed beside this one, so this tool needs a'."\n"
            .'MyAdmin core checkout. Pass --vendor-dir=/path/to/core/vendor to point at one.'
        );
    }
    return $options;
}

/**
 * `vendor-dir` => `vendorDir`. Kept explicit so an unknown option is rejected rather
 * than silently creating a new key that nothing reads.
 *
 * @param string $name option name without the leading dashes
 * @return string
 */
function fleetMatrixCamel($name)
{
    return lcfirst(str_replace(' ', '', ucwords(str_replace('-', ' ', $name))));
}

/**
 * @param string $message what went wrong
 * @return void
 */
function fleetMatrixDie($message)
{
    fwrite(STDERR, 'fleet-matrix: '.$message."\n");
    exit(1);
}

// ---------------------------------------------------------------------------
// Child: inspect exactly one package
// ---------------------------------------------------------------------------

/**
 * Emit one JSON record for one package on stdout.
 *
 * Plugin code is executed here, so anything it prints is captured rather than allowed to
 * interleave with the record and corrupt the parent's decode. The byte count is reported
 * instead of discarded — silent swallowing is what B-15 exists to catch.
 *
 * @param string $package composer package name
 * @param string $class plugin class to inspect
 * @return int exit code
 */
function fleetMatrixChild($package, $class)
{
    $subject = new PluginSubject($class);
    ob_start();
    try {
        $rows = PluginContractTestCase::inspectAll($subject);
        $stray = strlen((string)ob_get_clean());
    } catch (Throwable $e) {
        ob_end_clean();
        fwrite(STDERR, $package.': '.get_class($e).' — '.$e->getMessage()."\n");
        return 1;
    }

    $cells = [];
    foreach ($rows as $id => $findings) {
        $severities = [];
        $messages = [];
        foreach ($findings as $finding) {
            $severities[] = $finding->severity();
            $messages[] = $finding->describe();
        }
        $verdict = FleetMatrix::verdictFor($severities);
        $cells[$id] = [
            'verdict' => $verdict,
            'messages' => $verdict === FleetMatrix::PASS ? [] : $messages,
        ];
    }

    echo json_encode([
        'package' => $package,
        'class' => $class,
        'stray' => $stray,
        'cells' => $cells,
        // Escape-hatch use is a G2 checklist item on its own: a hatch leaves no trace on a
        // passing run, which is exactly the case a reviewer needs. The ledger starts empty in
        // every child because each package gets its own process, so no reset is needed here.
        'hatches' => PluginContractTestCase::overrideLedger(),
        // Deferrals are the other kind of exemption, and the reason they are read here rather
        // than collected from the package's suite is that this process cannot load that suite.
        // See MyAdmin\Plugins\Testing\DeferralRegister. They deliberately do NOT alter any
        // verdict above: a deferred P-bug is still reported as a failing cell.
        'deferrals' => DeferralRegister::forSubject($subject),
        'deferralProblems' => DeferralRegister::problemsForSubject($subject),
    ]), "\n";
    return 0;
}

// ---------------------------------------------------------------------------
// Parent: walk the fleet, fan out, render
// ---------------------------------------------------------------------------

/**
 * @param array<string,mixed> $options parsed arguments
 * @param string $packageRoot the installer package directory
 * @return int exit code
 */
function fleetMatrixParent(array $options, $packageRoot)
{
    $ids = InspectorRegistry::ids();
    list($fleet, $excluded) = fleetMatrixDiscover($options['vendorDir']);
    if ($fleet === []) {
        fleetMatrixDie('no packages of type "'.FleetMatrix::SCOPE_TYPE.'" under '.$options['vendorDir']);
    }

    $rows = [];
    $records = [];
    $broken = [];
    foreach ($fleet as $package => $class) {
        $record = fleetMatrixRun($package, $class, $options['vendorDir']);
        if ($record === null) {
            $broken[] = $package;
            $rows[$package] = ['class' => $class, 'cells' => []];
            continue;
        }
        $records[] = $record;
        $rows[$package] = ['class' => $class, 'cells' => $record['cells']];
        if ($record['stray'] > 0) {
            fwrite(STDERR, 'note: '.$package.' printed '.$record['stray']." bytes while being inspected\n");
        }
    }

    $markdown = FleetMatrix::renderMarkdown($rows, $ids, [
        'notes' => fleetMatrixNotes($options['notes'], $ids),
        'excluded' => $excluded,
        'hatches' => FleetMatrix::collectHatches($records),
        'deferrals' => FleetMatrix::collectDeferrals($records),
        'generator' => 'php tools/fleet-matrix.php',
    ]);

    if ($options['jsonl'] !== null) {
        $lines = '';
        foreach ($records as $record) {
            $lines .= json_encode($record)."\n";
        }
        fleetMatrixWrite($options['jsonl'], $lines);
    }

    if ($options['check']) {
        $committed = is_file($options['out']) ? (string)file_get_contents($options['out']) : '';
        if ($committed === $markdown) {
            fwrite(STDERR, "fleet-matrix: committed matrix is current\n");
            return $broken === [] ? 0 : 1;
        }
        fwrite(STDERR, "fleet-matrix: committed matrix is STALE — rerun without --check\n");
        return 2;
    }

    fleetMatrixWrite($options['out'], $markdown);
    fwrite(STDERR, 'fleet-matrix: wrote '.$options['out']."\n");

    if ($broken !== []) {
        fwrite(STDERR, "fleet-matrix: NO VERDICT from ".count($broken)." package(s): ".implode(', ', $broken)."\n");
        return 1;
    }
    return 0;
}

/**
 * The fleet, and the packages that claim to be in it but cannot be inspected.
 *
 * @param string $vendorDir composer vendor directory to walk
 * @return array{0:array<string,string>,1:array<string,string>} [package => class, package => exclusion reason]
 */
function fleetMatrixDiscover($vendorDir)
{
    $fleet = [];
    $excluded = [];
    $files = glob($vendorDir.'/*/*/composer.json');
    foreach ($files === false ? [] : $files as $file) {
        $json = json_decode((string)file_get_contents($file), true);
        if (!is_array($json) || !FleetMatrix::isInScope($json)) {
            continue;
        }
        $package = isset($json['name']) ? (string)$json['name'] : dirname($file);
        $class = FleetMatrix::pluginClassFor($json);
        if ($class === null) {
            $excluded[$package] = 'no PSR-4 prefix maps to `src`, so the plugin class cannot be resolved';
            continue;
        }
        if (!is_file(dirname($file).'/src/Plugin.php')) {
            $excluded[$package] = 'declares `'.$class.'` but ships no `src/Plugin.php`';
            continue;
        }
        $fleet[$package] = $class;
    }
    ksort($fleet);
    return [$fleet, $excluded];
}

/**
 * Inspect one package in its own process.
 *
 * @param string $package composer package name
 * @param string $class plugin class
 * @param string $vendorDir composer vendor directory
 * @return array<string,mixed>|null decoded record, or null when the child produced none
 */
function fleetMatrixRun($package, $class, $vendorDir)
{
    $command = escapeshellarg(PHP_BINARY)
        .' '.escapeshellarg(__FILE__)
        .' --vendor-dir='.escapeshellarg($vendorDir)
        .' --package='.escapeshellarg($package)
        .' --class='.escapeshellarg($class);

    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $pipes = [];
    $process = proc_open($command, $descriptors, $pipes);
    if (!is_resource($process)) {
        fwrite(STDERR, 'fleet-matrix: could not start a process for '.$package."\n");
        return null;
    }
    $stdout = (string)stream_get_contents($pipes[1]);
    $stderr = (string)stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);

    $record = json_decode(trim($stdout), true);
    if (!is_array($record) || !isset($record['cells'])) {
        fwrite(STDERR, 'fleet-matrix: '.$package." produced no record\n");
        if (trim($stderr) !== '') {
            fwrite(STDERR, '  stderr: '.trim($stderr)."\n");
        }
        return null;
    }
    return $record;
}

/**
 * Editorial census notes, kept out of the generated file so hand-written prose and
 * measured numbers cannot be confused for one another.
 *
 * A note naming an id the catalogue does not have is a hard error: a stale note is a
 * claim about an assertion that no longer exists.
 *
 * @param string $path notes JSON file
 * @param array<int,string> $ids catalogue ids
 * @return array<string,string>
 */
function fleetMatrixNotes($path, array $ids)
{
    if (!is_file($path)) {
        return [];
    }
    $notes = json_decode((string)file_get_contents($path), true);
    if (!is_array($notes)) {
        fleetMatrixDie($path.' is not valid JSON');
    }
    $unknown = array_diff(array_keys($notes), $ids);
    if ($unknown !== []) {
        fleetMatrixDie($path.' has notes for unknown assertion(s): '.implode(', ', $unknown));
    }
    return array_map('strval', $notes);
}

/**
 * @param string $path destination
 * @param string $contents what to write
 * @return void
 */
function fleetMatrixWrite($path, $contents)
{
    if (file_put_contents($path, $contents) === false) {
        fleetMatrixDie('could not write '.$path);
    }
}
