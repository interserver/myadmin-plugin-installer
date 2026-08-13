<?php

namespace Tests\MyAdmin\Plugins\Testing\Scaffold;

use MyAdmin\Plugins\Testing\Scaffold\PluginFacts;
use PHPUnit\Framework\TestCase;

/**
 * End-to-end tests for the measurement step.
 *
 * ---------------------------------------------------------------------------------
 * THE FIXTURE PACKAGE IS BUILT AT RUNTIME
 * ---------------------------------------------------------------------------------
 * It cannot be committed: it needs a `vendor/autoload.php`, and `vendor/` is gitignored at
 * every depth, so a committed fixture would work locally and vanish on CI — the worst of
 * both. Each test writes the package it needs into a temp directory and deletes it after,
 * which also keeps the packages independent of one another.
 *
 * @coversNothing probe.php is a script, not a class
 */
class ProbeTest extends TestCase
{
    /** @var string */
    private $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/probe-'.uniqid('', true);
        mkdir($this->root.'/src', 0755, true);
        mkdir($this->root.'/tests', 0755, true);
        mkdir($this->root.'/vendor', 0755, true);
    }

    protected function tearDown(): void
    {
        $this->deleteTree($this->root);
    }

    /**
     * @param string $path
     * @return void
     */
    private function deleteTree($path)
    {
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $full = $path.'/'.$entry;
            is_dir($full) ? $this->deleteTree($full) : unlink($full);
        }
        rmdir($path);
    }

    /**
     * Writes a minimal but real composer package: a manifest, a plugin class, and an
     * autoloader that pulls in the harness the way an installed package's would.
     *
     * @param string $pluginBody
     * @param string $prelude code that runs while the package autoloads
     * @return void
     */
    private function givenPackage($pluginBody, $prelude = '')
    {
        file_put_contents($this->root.'/composer.json', json_encode([
            'name' => 'detain/myadmin-fixture',
            'require' => ['php' => '>=8.2'],
            'autoload' => ['psr-4' => ['Fixture\\Probe\\' => 'src']],
            'autoload-dev' => ['psr-4' => ['Fixture\\Probe\\Tests\\' => 'tests']],
        ]));

        file_put_contents($this->root.'/src/Plugin.php', "<?php\n\nnamespace Fixture\\Probe;\n\n".$pluginBody);

        $harness = dirname(__DIR__, 3).'/vendor/autoload.php';
        file_put_contents(
            $this->root.'/vendor/autoload.php',
            "<?php\n".$prelude."\nrequire ".var_export($harness, true).";\n"
            ."require ".var_export($this->root.'/src/Plugin.php', true).";\n"
        );
    }

    /**
     * @return array{stdout: string, stderr: string, status: int}
     */
    private function runProbe()
    {
        $pipes = [];
        $process = proc_open(
            [PHP_BINARY, dirname(__DIR__, 3).'/src/Testing/Scaffold/probe.php', $this->root],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        $stdout = (string)stream_get_contents($pipes[1]);
        $stderr = (string)stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return ['stdout' => $stdout, 'stderr' => $stderr, 'status' => proc_close($process)];
    }

    public function testMeasuresWhatThePluginActuallyRegisters(): void
    {
        $this->givenPackage(<<<'PHP'
class Plugin
{
    public static $name = 'Fixture';
    public static $type = 'service';
    public static $module = 'licenses';

    public static function getHooks()
    {
        return [
            'function.requirements' => [__CLASS__, 'getRequirements'],
            self::$module.'.activate' => [__CLASS__, 'getActivate'],
        ];
    }

    public static function getRequirements()
    {
    }

    public static function getActivate($event)
    {
    }
}
PHP);

        $result = $this->runProbe();
        $this->assertSame(0, $result['status'], $result['stderr']);

        $facts = PluginFacts::fromJson($result['stdout']);
        $this->assertSame('Fixture\\Probe\\Plugin', $facts->pluginClass());
        $this->assertSame('Fixture\\Probe\\Tests', $facts->testNamespace());
        $this->assertSame('service', $facts->type());
        $this->assertSame('licenses', $facts->module());
        $this->assertSame(['function.requirements', 'licenses.activate'], $facts->hookKeys());
        $this->assertNull($facts->hookError());
    }

    /**
     * The point of measuring rather than parsing: this table is not a literal anywhere in
     * the source, it is assembled from a constant the host defines at runtime. A tokenizer
     * would report the expression; only execution reports the key.
     */
    public function testAHookKeyBuiltFromAConstantIsStillMeasured(): void
    {
        $this->givenPackage(<<<'PHP'
class Plugin
{
    public static $name = 'Fixture';
    public static $type = 'plugin';
    public static $module = 'licenses';

    public static function getHooks()
    {
        return [self::$module.'.'.FIXTURE_SUFFIX => [__CLASS__, 'handle']];
    }

    public static function handle($event)
    {
    }
}
PHP, "define('FIXTURE_SUFFIX', 'settings');");

        $facts = PluginFacts::fromJson($this->runProbe()['stdout']);

        $this->assertSame(['licenses.settings'], $facts->hookKeys());
    }

    /**
     * A getHooks() that throws is a finding assertion A-5 reports, not a reason to refuse
     * to scaffold — the package needs the test file for that finding to surface in.
     */
    public function testAThrowingHookTableIsRecordedAndTheProbeStillSucceeds(): void
    {
        $this->givenPackage(<<<'PHP'
class Plugin
{
    public static $name = 'Fixture';
    public static $type = 'plugin';
    public static $module = 'licenses';

    public static function getHooks()
    {
        throw new \RuntimeException('no hooks for you');
    }
}
PHP);

        $result = $this->runProbe();

        $this->assertSame(0, $result['status'], $result['stderr']);
        $facts = PluginFacts::fromJson($result['stdout']);
        $this->assertStringContainsString('no hooks for you', (string)$facts->hookError());
        $this->assertSame([], $facts->hookKeys());
    }

    /**
     * A package with no $module is legitimate — an analytics or payments plugin scopes its
     * hooks by system instead — and the scaffold simply omits that pin.
     */
    public function testAPluginWithoutAModuleIsMeasuredAsHavingNone(): void
    {
        $this->givenPackage(<<<'PHP'
class Plugin
{
    public static $name = 'Fixture';
    public static $type = 'plugin';

    public static function getHooks()
    {
        return ['system.settings' => [__CLASS__, 'getSettings']];
    }

    public static function getSettings($event)
    {
    }
}
PHP);

        $facts = PluginFacts::fromJson($this->runProbe()['stdout']);

        $this->assertNull($facts->module());
    }

    /**
     * stdout is a data channel. A package still carrying an old vendored installer emits
     * deprecations while autoloading, and one landing on stdout would corrupt the payload
     * into unparseable JSON — which is how this was found in the first place.
     */
    public function testNoiseDuringAutoloadCannotCorruptThePayload(): void
    {
        $this->givenPackage(<<<'PHP'
class Plugin
{
    public static $name = 'Fixture';
    public static $type = 'plugin';

    public static function getHooks()
    {
        return [];
    }
}
PHP, "trigger_error('an old vendored installer says hello', E_USER_DEPRECATED);");

        $result = $this->runProbe();

        $this->assertSame(0, $result['status'], $result['stderr']);
        $this->assertNotNull(
            json_decode(trim($result['stdout']), true),
            'stdout must be JSON and nothing else: '.$result['stdout']
        );
    }

    public function testSaysSoWhenThePackageWasNeverInstalled(): void
    {
        $this->givenPackage('class Plugin {}');
        unlink($this->root.'/vendor/autoload.php');

        $result = $this->runProbe();

        $this->assertSame(2, $result['status']);
        $this->assertStringContainsString('composer install', $result['stderr']);
    }

    public function testSaysSoWhenThereIsNoPluginClass(): void
    {
        $this->givenPackage('class NotThePlugin {}');

        $result = $this->runProbe();

        $this->assertSame(3, $result['status']);
        $this->assertStringContainsString('no such class', $result['stderr']);
    }
}
