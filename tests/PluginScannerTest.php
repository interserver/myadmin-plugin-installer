<?php

namespace Tests\MyAdmin\Plugins;

use MyAdmin\Plugins\PluginScanner;
use PHPUnit\Framework\TestCase;

/**
 * Test suite for PluginScanner.
 *
 * Uses a real temporary vendor tree rather than mocks, because the behaviour under test is
 * filesystem discovery plus include_once of third-party PHP.
 *
 * @covers \MyAdmin\Plugins\PluginScanner
 */
class PluginScannerTest extends TestCase
{
    /** @var string */
    private $root;

    /** @var int makes each fixture's namespace unique so classes never collide across tests */
    private static $seq = 0;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/pluginscanner_'.getmypid().'_'.(++self::$seq);
        mkdir($this->root.'/vendor', 0777, true);
        mkdir($this->root.'/include/config', 0777, true);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->root);
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (array_diff(scandir($dir), ['.', '..']) as $entry) {
            $path = $dir.'/'.$entry;
            is_dir($path) ? $this->rrmdir($path) : @unlink($path);
        }
        @rmdir($dir);
    }

    /**
     * Writes a fixture plugin package.
     *
     * @param string $package    "vendor/name"
     * @param string $body       PHP body of the getHooks() method
     * @param bool   $withHooks  false to omit getHooks() entirely
     */
    private function makePlugin(string $package, string $body = "return ['a.b' => ['X', 'y']];", bool $withHooks = true): string
    {
        $ns = 'Fixture'.self::$seq.str_replace(['/', '-'], '', ucwords($package, '/-')).'\\';
        $dir = $this->root.'/vendor/'.$package;
        mkdir($dir.'/src', 0777, true);
        file_put_contents($dir.'/composer.json', json_encode([
            'name' => $package,
            'type' => 'myadmin-plugin',
            'autoload' => ['psr-4' => [$ns => 'src/']],
        ]));
        $hooks = $withHooks ? "public static function getHooks() { {$body} }" : '';
        file_put_contents(
            $dir.'/src/Plugin.php',
            "<?php\nnamespace ".rtrim($ns, '\\').";\nclass Plugin { public static \$module = 'x'; {$hooks} }\n"
        );
        return $ns;
    }

    private function scanner(): PluginScanner
    {
        return PluginScanner::forProjectRoot($this->root);
    }

    public function testDiscoversPackagesShippingAPluginFile(): void
    {
        $this->makePlugin('acme/one');
        $this->makePlugin('acme/two');
        $this->assertSame(['acme/one', 'acme/two'], $this->scanner()->discoverPackages());
    }

    public function testNeverDiscoversItself(): void
    {
        $this->makePlugin(PluginScanner::SELF_PACKAGE);
        $this->assertSame([], $this->scanner()->discoverPackages());
    }

    public function testLoadsHooksFromAValidPlugin(): void
    {
        $this->makePlugin('acme/one', "return ['vps.settings' => ['Acme', 'getSettings']];");
        $plugin = $this->scanner()->loadPlugin('acme/one');
        $this->assertIsArray($plugin);
        $this->assertSame(['vps.settings' => ['Acme', 'getSettings']], $plugin['hooks']);
        $this->assertSame('acme/one', $plugin['packagist']);
        $this->assertArrayNotHasKey('name', $plugin, 'name is renamed to packagist');
        $this->assertArrayNotHasKey('autoload', $plugin);
    }

    public function testSkipsAPackageWithNoGetHooks(): void
    {
        $this->makePlugin('acme/nohooks', '', false);
        $scanner = $this->scanner();
        $this->assertNull($scanner->loadPlugin('acme/nohooks'));
        $this->assertArrayHasKey('acme/nohooks', $scanner->getSkipped());
    }

    /**
     * Regression: several real packages reference MyAdmin constants (PRORATE_BILLING,
     * NORMAL_BILLING) that only exist once include/config/config.inc.php has loaded, so
     * getHooks() throws inside a Composer process. One such package must not abort the scan.
     */
    public function testAThrowingPluginDoesNotAbortTheScan(): void
    {
        $this->makePlugin('acme/good');
        $this->makePlugin('acme/explodes', 'return [UNDEFINED_MYADMIN_CONSTANT => 1];');
        $scanner = $this->scanner();
        $plugins = $scanner->scan();
        $this->assertArrayHasKey('acme/good', $plugins);
        $this->assertArrayNotHasKey('acme/explodes', $plugins);
        $this->assertArrayHasKey('acme/explodes', $scanner->getSkipped());
    }

    /**
     * THE critical regression. A package that is installed but unscannable must keep its
     * existing hooks. Pruning on scan success instead of disk presence would have deleted
     * twelve live modules from MyAdmin's dispatch table.
     */
    public function testRetainsExistingHooksForAnInstalledButUnscannablePackage(): void
    {
        $this->makePlugin('acme/explodes', 'return [UNDEFINED_MYADMIN_CONSTANT => 1];');
        $scanner = $this->scanner();
        $existing = ['acme/explodes' => ['vps.settings' => ['Acme', 'getSettings']]];

        $hooks = $scanner->buildHooks($scanner->scan(), $existing, $scanner->discoverPackages());

        $this->assertArrayHasKey('acme/explodes', $hooks);
        $this->assertSame($existing['acme/explodes'], $hooks['acme/explodes']);
    }

    public function testPrunesEntriesWhosePackageIsGoneFromDisk(): void
    {
        $this->makePlugin('acme/present');
        $scanner = $this->scanner();
        $existing = [
            'acme/present' => ['a.b' => ['X', 'y']],
            'acme/deleted' => ['c.d' => ['Z', 'w']],
        ];

        $hooks = $scanner->buildHooks($scanner->scan(), $existing, $scanner->discoverPackages());

        $this->assertArrayHasKey('acme/present', $hooks);
        $this->assertArrayNotHasKey('acme/deleted', $hooks);
    }

    public function testFreshHooksOverrideStaleOnesForScannablePackages(): void
    {
        $this->makePlugin('acme/one', "return ['new.hook' => ['New', 'handler']];");
        $scanner = $this->scanner();
        $existing = ['acme/one' => ['old.hook' => ['Old', 'handler']]];

        $hooks = $scanner->buildHooks($scanner->scan(), $existing, $scanner->discoverPackages());

        $this->assertSame(['new.hook' => ['New', 'handler']], $hooks['acme/one']);
    }

    /**
     * `enabled` is operator state, not derived data.
     */
    public function testPreservesTheEnabledFlagAcrossRebuilds(): void
    {
        $this->makePlugin('acme/one');
        $scanner = $this->scanner();
        $existing = ['acme/one' => ['enabled' => false, 'stale' => 'x']];

        $plugins = $scanner->buildPlugins($scanner->scan(), $existing, $scanner->discoverPackages());

        $this->assertFalse($plugins['acme/one']['enabled']);
    }

    public function testDefaultsEnabledToTrueForANewPackage(): void
    {
        $this->makePlugin('acme/one');
        $scanner = $this->scanner();
        $plugins = $scanner->buildPlugins($scanner->scan(), [], $scanner->discoverPackages());
        $this->assertTrue($plugins['acme/one']['enabled']);
    }

    /**
     * A null entry cannot be carried forward: include/tf.php iterates these values.
     */
    public function testDropsNonArrayEntriesWhenRetaining(): void
    {
        $this->makePlugin('acme/explodes', 'return [UNDEFINED_MYADMIN_CONSTANT => 1];');
        $scanner = $this->scanner();

        $hooks = $scanner->buildHooks($scanner->scan(), ['acme/explodes' => null], $scanner->discoverPackages());

        $this->assertArrayNotHasKey('acme/explodes', $hooks);
    }

    /**
     * An empty scan means something is wrong, not that every plugin was uninstalled.
     */
    public function testRefusesToWriteAnEmptyPayload(): void
    {
        $path = $this->root.'/include/config/hooks.json';
        file_put_contents($path, '{"acme/one":{}}');
        $this->assertFalse(PluginScanner::writeJson($path, []));
        $this->assertSame('{"acme/one":{}}', file_get_contents($path));
    }

    public function testWritesValidParseableJson(): void
    {
        $path = $this->root.'/include/config/hooks.json';
        $this->assertTrue(PluginScanner::writeJson($path, ['acme/one' => ['a.b' => ['X', 'y']]]));
        $this->assertSame(['acme/one' => ['a.b' => ['X', 'y']]], json_decode(file_get_contents($path), true));
    }

    public function testWritePreservesTheModeOfTheReplacedFile(): void
    {
        $path = $this->root.'/include/config/hooks.json';
        file_put_contents($path, '{}');
        chmod($path, 0666);
        PluginScanner::writeJson($path, ['acme/one' => []]);
        $this->assertSame(0666, fileperms($path) & 0777);
    }

    public function testWriteLeavesNoTemporaryFilesBehind(): void
    {
        $dir = $this->root.'/include/config';
        PluginScanner::writeJson($dir.'/hooks.json', ['acme/one' => []]);
        $leftovers = array_filter(scandir($dir), function ($f) {
            return strpos($f, '.pluginscan') === 0;
        });
        $this->assertSame([], array_values($leftovers));
    }

    public function testReadJsonReturnsEmptyArrayForAMissingFile(): void
    {
        $this->assertSame([], PluginScanner::readJson($this->root.'/nope.json'));
    }

    public function testReadJsonReturnsEmptyArrayForMalformedJson(): void
    {
        $path = $this->root.'/bad.json';
        file_put_contents($path, '{not json');
        $this->assertSame([], PluginScanner::readJson($path));
    }

    public function testDryRunReportsChangesWithoutWriting(): void
    {
        $this->makePlugin('acme/one');
        $hooksPath = $this->root.'/include/config/hooks.json';
        file_put_contents($hooksPath, '{}');

        $result = $this->scanner()->rebuild(true);

        $this->assertSame(1, $result['scanned']);
        $this->assertSame(['acme/one'], $result['hooks']['added']);
        $this->assertFalse($result['hooks']['written']);
        $this->assertSame('{}', file_get_contents($hooksPath));
    }

    public function testRebuildWritesBothDispatchTables(): void
    {
        $this->makePlugin('acme/one');

        $result = $this->scanner()->rebuild();

        $this->assertTrue($result['hooks']['written']);
        $this->assertTrue($result['plugins']['written']);
        $this->assertArrayHasKey('acme/one', PluginScanner::readJson($this->root.'/include/config/hooks.json'));
        $this->assertArrayHasKey('acme/one', PluginScanner::readJson($this->root.'/include/config/plugins.json'));
    }

    public function testRebuildReportsPresentScannedAndRetainedCounts(): void
    {
        $this->makePlugin('acme/good');
        $this->makePlugin('acme/explodes', 'return [UNDEFINED_MYADMIN_CONSTANT => 1];');

        $result = $this->scanner()->rebuild(true);

        $this->assertSame(2, $result['present']);
        $this->assertSame(1, $result['scanned']);
        $this->assertSame(1, $result['retained']);
    }
}
