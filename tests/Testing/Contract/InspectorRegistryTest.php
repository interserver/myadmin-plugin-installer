<?php
/**
 * @author Joe Huss <detain@interserver.net>
 * @copyright 2026
 * @package MyAdmin
 * @category Testing
 */

namespace Tests\MyAdmin\Plugins\Testing\Contract;

use MyAdmin\Plugins\Testing\Contract\InspectorRegistry;
use MyAdmin\Plugins\Testing\Contract\PluginInspector;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Pins what the registry discovers, in what order, and from where.
 *
 * ---------------------------------------------------------------------------------
 * WHY THE ID LIST IS SPELLED OUT
 * ---------------------------------------------------------------------------------
 * Asserting `count() === 18` would be satisfied by eighteen of the wrong things, and
 * satisfied again after someone swaps one inspector for another. The catalogue is the
 * contract; pinning it literally means adding an assertion has to be an explicit, reviewed
 * edit to this file rather than a number that silently ticks over. That is the same failure
 * the registry itself exists to prevent, one level up.
 *
 * ---------------------------------------------------------------------------------
 * WHY A SUBPROCESS FOR THE DISCOVERY RULES
 * ---------------------------------------------------------------------------------
 * Three behaviours cannot be observed against the real `src/Testing/Contract` directory:
 * that a *new* concrete inspector is picked up (proving discovery rather than a lucky
 * hardcoded list), that an abstract implementor is skipped (no abstract inspector exists
 * today, so the branch is unreachable), and that {@see InspectorRegistry::reset()} drops a
 * memoised scan (without a directory change, reset has no observable effect).
 *
 * All three need the scanned directory to change. Doing that to the real directory would
 * mean writing a `.php` file into `src/` mid-suite, which any concurrently running
 * `phpunit` in the same working tree would pick up — a shared-tree race that turns into an
 * unexplainable red for whoever else is working here. So the rules are exercised against a
 * throwaway directory holding *byte-for-byte copies* of the real files, in a child process
 * launched with an unrelated cwd. The copies are made at run time from the class's own
 * `getFileName()`, so this cannot drift from the implementation it is testing.
 *
 * @covers \MyAdmin\Plugins\Testing\Contract\InspectorRegistry
 */
class InspectorRegistryTest extends TestCase
{
    /**
     * The Phase 2 catalogue, in display order. Update deliberately, never mechanically.
     *
     * @var array<int,string>
     */
    private const CATALOGUE = [
        'A-1', 'A-2', 'A-3', 'A-4', 'A-5', 'A-6', 'A-7', 'A-8', 'A-9',
        'B-9', 'B-9b', 'B-10', 'B-11', 'B-12', 'B-13', 'B-14', 'B-15', 'B-16',
    ];

    /**
     * Concrete classes that share the inspectors' directory and must never be mistaken for
     * one. `TierB11*` / `TierB14*` are the dangerous names: they carry an inspector's prefix
     * and would look plausible in a matrix column header.
     *
     * @var array<int,string>
     */
    private const NEIGHBOURS = [
        'Finding',
        'HookTargetIndex',
        'PluginSubject',
        'SubjectEvent',
        'TierB11RecordingLoader',
        'TierB11RouteCallScanner',
        'TierB14QueueActionScanner',
    ];

    /** @var string|false cwd as it was before a test moved it */
    private $originalCwd = false;

    /** @var string|null throwaway directory for the subprocess probe */
    private $sandbox = null;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->originalCwd = getcwd();
        InspectorRegistry::reset();
    }

    /**
     * Restores the process-wide state a test may have moved. `chdir()` outlives the test
     * that called it, and a stale memoised scan would outlive the directory it was taken
     * from, so both are undone whether the test passed or failed.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        if (is_string($this->originalCwd) && $this->originalCwd !== '') {
            chdir($this->originalCwd);
        }
        InspectorRegistry::reset();
        if ($this->sandbox !== null) {
            self::removeTree($this->sandbox);
            $this->sandbox = null;
        }
        parent::tearDown();
    }

    /**
     * Directory the real inspectors live in, asked of the class itself rather than built
     * from a relative path.
     *
     * @return string
     */
    private function inspectorDir()
    {
        return dirname((string)(new ReflectionClass(InspectorRegistry::class))->getFileName());
    }

    /**
     * @param string $shortName
     * @return string
     */
    private function fqcn($shortName)
    {
        return 'MyAdmin\\Plugins\\Testing\\Contract\\'.$shortName;
    }

    // -----------------------------------------------------------------------
    // What it finds
    // -----------------------------------------------------------------------

    /**
     * The load-bearing assertion. "B-10" sorts before "B-9" under `sort()`, so an ordering
     * regression is invisible to anything that only compares sets.
     *
     * @return void
     */
    public function testIdsAreTheWholeCatalogueInDisplayOrder()
    {
        $this->assertSame(self::CATALOGUE, InspectorRegistry::ids());
    }

    /**
     * Guards the pinned list against becoming decorative: if the order below were ever
     * accepted as correct, the ordering assertion above would be testing nothing.
     *
     * @return void
     */
    public function testPlainStringSortWouldGetTheOrderWrong()
    {
        $naive = self::CATALOGUE;
        sort($naive);
        $this->assertNotSame(self::CATALOGUE, $naive, 'the catalogue must not be in sorted order by accident');
        $this->assertLessThan(
            array_search('B-9', $naive, true),
            array_search('B-10', $naive, true),
            'a plain sort has to put B-10 before B-9, or compareIds() would have nothing to fix'
        );
    }

    /**
     * @return void
     */
    public function testAllReturnsConstructedInspectorsInTheSameOrderAsIds()
    {
        $all = InspectorRegistry::all();
        $this->assertCount(count(self::CATALOGUE), $all);

        $ids = [];
        foreach ($all as $inspector) {
            $this->assertInstanceOf(PluginInspector::class, $inspector);
            $this->assertNotSame('', $inspector->title(), get_class($inspector).' must carry a matrix header');
            $ids[] = $inspector->id();
        }
        $this->assertSame(self::CATALOGUE, $ids);
    }

    /**
     * @return void
     */
    public function testClassesReturnsLoadableClassNamesRatherThanInstances()
    {
        $classes = InspectorRegistry::classes();
        $this->assertCount(count(self::CATALOGUE), $classes);
        foreach ($classes as $class) {
            $this->assertIsString($class);
            $this->assertTrue(class_exists($class), $class.' must be loadable');
            $this->assertContains(PluginInspector::class, class_implements($class));
        }
        $this->assertSame(array_values($classes), $classes, 'classes() must be a list, not a sparse map');
    }

    // -----------------------------------------------------------------------
    // What it refuses to find
    // -----------------------------------------------------------------------

    /**
     * The assertion that stops the registry silently widening.
     *
     * Each neighbour's file is asserted to exist first. Without that, renaming a helper
     * would leave this test passing on a class that is no longer there — an exclusion
     * assertion that excludes nothing is worse than none, because it reads as coverage.
     *
     * @return void
     */
    public function testConcreteNeighboursInTheSameDirectoryAreNotInspectors()
    {
        $classes = InspectorRegistry::classes();
        foreach (self::NEIGHBOURS as $short) {
            $file = $this->inspectorDir().'/'.$short.'.php';
            $this->assertFileExists($file, 'neighbour fixture moved; this exclusion no longer tests anything');
            $this->assertTrue(class_exists($this->fqcn($short)), $short.' must be a real concrete class');
            $this->assertNotContains($this->fqcn($short), $classes);
        }
    }

    /**
     * @return void
     */
    public function testTheInspectorInterfaceItselfIsNotDiscovered()
    {
        $this->assertFileExists($this->inspectorDir().'/PluginInspector.php');
        $this->assertTrue(interface_exists(PluginInspector::class));
        $this->assertNotContains(PluginInspector::class, InspectorRegistry::classes());
    }

    /**
     * Re-derives the expected set from the source text instead of from reflection, so this
     * cannot agree with the implementation by sharing its logic. A file whose top-level
     * declaration is a non-abstract `class ... implements PluginInspector` must be in;
     * everything else — interface, abstract, `extends`-only helper, plain value object —
     * must be out.
     *
     * @return void
     */
    public function testMembershipMatchesWhatTheSourceFilesDeclare()
    {
        $expected = [];
        $files = glob($this->inspectorDir().'/*.php');
        $this->assertIsArray($files);
        $this->assertNotEmpty($files);

        foreach ($files as $file) {
            $source = (string)file_get_contents($file);
            $matches = [];
            if (preg_match('/^(abstract\s+)?class\s+(\w+)([^\r\n{]*)/m', $source, $matches) !== 1) {
                continue;
            }
            if ($matches[1] !== '') {
                continue;
            }
            if (strpos($matches[3], 'implements') === false || strpos($matches[3], 'PluginInspector') === false) {
                continue;
            }
            $this->assertSame(
                basename($file, '.php'),
                $matches[2],
                'class name must match its file name or discovery cannot resolve it'
            );
            $expected[] = $this->fqcn($matches[2]);
        }

        sort($expected);
        $this->assertSame($expected, InspectorRegistry::classes());
    }

    // -----------------------------------------------------------------------
    // Ordering
    // -----------------------------------------------------------------------

    /**
     * `compareIds()` is tested directly rather than through a fixture inspector, because the
     * A-10 case has no inspector behind it yet and inventing one would mean adding a file to
     * a directory every consumer scans.
     *
     * @return void
     */
    public function testCompareIdsOrdersBySuffixThenByNumberNotByString()
    {
        $this->assertLessThan(0, InspectorRegistry::compareIds('B-9', 'B-9b'));
        $this->assertGreaterThan(0, InspectorRegistry::compareIds('B-9b', 'B-9'));

        $this->assertLessThan(0, InspectorRegistry::compareIds('B-9b', 'B-10'));
        $this->assertGreaterThan(0, InspectorRegistry::compareIds('B-10', 'B-9b'));

        $this->assertLessThan(0, InspectorRegistry::compareIds('B-9', 'B-10'));
        $this->assertGreaterThan(0, InspectorRegistry::compareIds('B-10', 'B-9'));

        // The hypothetical eighteenth Tier-A assertion: numeric, not lexicographic.
        $this->assertLessThan(0, InspectorRegistry::compareIds('A-2', 'A-10'));
        $this->assertGreaterThan(0, InspectorRegistry::compareIds('A-10', 'A-2'));

        $this->assertLessThan(0, InspectorRegistry::compareIds('A-9', 'B-1'));
        $this->assertSame(0, InspectorRegistry::compareIds('B-9b', 'B-9b'));
    }

    /**
     * Sorting a shuffled catalogue with the comparator has to reproduce display order —
     * a comparator can be right on every pair this file names and still be inconsistent.
     *
     * @return void
     */
    public function testSortingTheShuffledCatalogueReproducesDisplayOrder()
    {
        $shuffled = array_reverse(self::CATALOGUE);
        usort($shuffled, [InspectorRegistry::class, 'compareIds']);
        $this->assertSame(self::CATALOGUE, $shuffled);
    }

    // -----------------------------------------------------------------------
    // Where it looks
    // -----------------------------------------------------------------------

    /**
     * Requirement: the fleet self-check runs from an arbitrary cwd. A `glob('*.php')`
     * relative to the working directory would find nothing here and report a clean matrix
     * with zero columns.
     *
     * @return void
     */
    public function testDiscoveryIsAnchoredOnTheClassDirectoryNotTheWorkingDirectory()
    {
        $fromPackage = InspectorRegistry::ids();

        $elsewhere = sys_get_temp_dir();
        $this->assertTrue(chdir($elsewhere), 'could not move cwd, so this test proves nothing');
        $this->assertNotSame(
            realpath($this->inspectorDir()),
            realpath((string)getcwd()),
            'cwd did not actually move'
        );

        InspectorRegistry::reset();
        $this->assertSame($fromPackage, InspectorRegistry::ids());
        $this->assertNotSame([], InspectorRegistry::classes());
    }

    // -----------------------------------------------------------------------
    // Discovery rules, against a directory this test controls
    // -----------------------------------------------------------------------

    /**
     * One child process exercises everything that needs the scanned directory to change.
     * See the class docblock for why it is not the real directory.
     *
     * @return void
     */
    public function testDiscoveryRulesAgainstAControlledDirectory()
    {
        $report = $this->runSandboxProbe();

        $concrete = $this->fqcn('IregProbeConcrete');
        $second = $this->fqcn('IregProbeSecond');

        // Ran nowhere near the package, and still found the copied registry's neighbours.
        $this->assertSame('/', $report['cwd']);

        // Discovery is real: a class that did not exist when this file was written is found,
        // and the abstract implementor, the plain class and the interface beside it are not.
        $this->assertSame([$concrete], $report['classesBefore']);

        // classes() must not construct — a provider enumerating assertions runs before any
        // test body, and an inspector constructor is the wrong place to meet a broken plugin.
        $this->assertSame(0, $report['ctorCountAfterClasses']);
        $this->assertSame(1, $report['ctorCountAfterAll']);
        $this->assertSame([$concrete], $report['allClasses']);
        $this->assertSame(['A-10'], $report['idsBefore']);

        // Memoisation: a file added after the first scan is invisible until reset().
        $this->assertSame([$concrete], $report['classesWhileMemoised']);
        $this->assertSame([$concrete, $second], $report['classesAfterReset']);

        // And the reordered result proves compareIds() drives the real display order.
        $this->assertSame(['A-2', 'A-10'], $report['idsAfterReset']);
    }

    /**
     * Builds the throwaway package, runs the probe from `/`, and decodes its one line of
     * output.
     *
     * @return array<string,mixed>
     */
    private function runSandboxProbe()
    {
        $root = $this->makeSandbox();
        $driver = $root.'/driver.php';

        $output = [];
        $status = 0;
        exec('cd / && '.escapeshellarg(PHP_BINARY).' '.escapeshellarg($driver).' 2>&1', $output, $status);
        $raw = implode("\n", $output);

        $this->assertSame(0, $status, 'probe exited non-zero: '.$raw);
        $decoded = json_decode($raw, true);
        $this->assertIsArray($decoded, 'probe did not emit JSON: '.$raw);

        return $decoded;
    }

    /**
     * Copies the real implementation next to three synthetic neighbours.
     *
     * The copies come from `getFileName()` rather than a written-out path, so the probe can
     * never test a stale duplicate of the registry.
     *
     * @return string sandbox root
     */
    private function makeSandbox()
    {
        $root = sys_get_temp_dir().'/ireg-probe-'.getmypid().'-'.uniqid();
        $contract = $root.'/Contract';
        $this->assertTrue(mkdir($contract, 0777, true), 'could not create sandbox');
        $this->sandbox = $root;

        foreach (['InspectorRegistry', 'PluginInspector', 'Finding', 'PluginSubject'] as $short) {
            $this->assertTrue(
                copy($this->inspectorDir().'/'.$short.'.php', $contract.'/'.$short.'.php'),
                'could not copy '.$short
            );
        }

        $header = "<?php\nnamespace MyAdmin\\Plugins\\Testing\\Contract;\n";

        // Concrete, discoverable, and it records every construction in a global so the probe
        // can tell classes() apart from all() by effect rather than by return type.
        file_put_contents($contract.'/IregProbeConcrete.php', $header.<<<'PHP'
class IregProbeConcrete implements PluginInspector
{
    public function __construct()
    {
        $GLOBALS['ireg_probe_constructions'] = (isset($GLOBALS['ireg_probe_constructions'])
            ? $GLOBALS['ireg_probe_constructions'] : 0) + 1;
    }

    public function id()
    {
        return 'A-10';
    }

    public function title()
    {
        return 'probe inspector';
    }

    public function inspect(PluginSubject $subject)
    {
        return [];
    }
}
PHP
        );

        // Implements the interface but cannot be instantiated: `new $class()` on this is a
        // fatal, which is why the registry filters on isAbstract() and not on the interface
        // alone.
        file_put_contents($contract.'/IregProbeAbstract.php', $header.<<<'PHP'
abstract class IregProbeAbstract implements PluginInspector
{
    public function id()
    {
        return 'A-11';
    }

    public function title()
    {
        return 'abstract probe base';
    }
}
PHP
        );

        // Concrete, in the directory, not an inspector — the TierB11RecordingLoader shape.
        file_put_contents($contract.'/IregProbePlain.php', $header.<<<'PHP'
class IregProbePlain
{
    public function id()
    {
        return 'A-12';
    }
}
PHP
        );

        // A `.php` file whose name does not name a class. `new ReflectionClass()` on that is
        // an uncaught ReflectionException, so the `class_exists()` gate is what keeps the
        // scan from dying on a helper, a stray script or a class renamed without its file.
        file_put_contents(
            $contract.'/IregProbeNoClass.php',
            $header."// This file deliberately declares nothing at all.\n"
        );

        file_put_contents($root.'/driver.php', $this->driverSource());

        return $root;
    }

    /**
     * The probe body.
     *
     * Lives one directory above the scanned files on purpose: a `.php` file inside the
     * scanned directory would be handed to `class_exists()`, autoloaded, and re-executed
     * mid-scan.
     *
     * @return string
     */
    private function driverSource()
    {
        return <<<'PHP'
<?php
$dir = __DIR__.'/Contract';
spl_autoload_register(function ($class) use ($dir) {
    $prefix = 'MyAdmin\\Plugins\\Testing\\Contract\\';
    if (strpos($class, $prefix) !== 0) {
        return;
    }
    $file = $dir.'/'.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';
    if (is_file($file)) {
        require $file;
    }
});

$registry = 'MyAdmin\\Plugins\\Testing\\Contract\\InspectorRegistry';
$count = function () {
    return isset($GLOBALS['ireg_probe_constructions']) ? $GLOBALS['ireg_probe_constructions'] : 0;
};

$report = ['cwd' => getcwd()];
$report['classesBefore'] = $registry::classes();
$report['ctorCountAfterClasses'] = $count();

$all = $registry::all();
$report['allClasses'] = array_map('get_class', $all);
$report['ctorCountAfterAll'] = $count();
$report['idsBefore'] = $registry::ids();

file_put_contents($dir.'/IregProbeSecond.php', "<?php\nnamespace MyAdmin\\Plugins\\Testing\\Contract;\n"
    ."class IregProbeSecond implements PluginInspector {\n"
    ."public function id() { return 'A-2'; }\n"
    ."public function title() { return 'second probe inspector'; }\n"
    ."public function inspect(PluginSubject \$subject) { return []; }\n"
    ."}\n");

$report['classesWhileMemoised'] = $registry::classes();
$registry::reset();
$report['classesAfterReset'] = $registry::classes();
$report['idsAfterReset'] = $registry::ids();

echo json_encode($report);
PHP;
    }

    /**
     * @param string $path
     * @return void
     */
    private static function removeTree($path)
    {
        if (!is_dir($path)) {
            return;
        }
        $entries = scandir($path);
        if ($entries === false) {
            return;
        }
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $full = $path.'/'.$entry;
            if (is_dir($full)) {
                self::removeTree($full);
            } else {
                unlink($full);
            }
        }
        rmdir($path);
    }
}
