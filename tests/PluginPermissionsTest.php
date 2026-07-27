<?php

namespace Tests\MyAdmin\Plugins;

use Composer\Composer;
use Composer\IO\BufferIO;
use Composer\Package\RootPackage;
use Composer\Script\Event;
use MyAdmin\Plugins\Plugin;
use PHPUnit\Framework\TestCase;

/**
 * Test suite for Plugin's permission helpers.
 *
 * This is the only part of the package that executes in MyAdmin today: it is wired as a
 * Composer `scripts` callable, and script callables resolve through the project autoloader
 * rather than the plugin allowlist, so it runs on every install and update even though the
 * plugin itself is blocked by config.allow-plugins.
 *
 * @covers \MyAdmin\Plugins\Plugin
 */
class PluginPermissionsTest extends TestCase
{
    /** @var string */
    private $tmp;

    /** @var BufferIO */
    private $io;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir().'/pluginperms_'.getmypid().'_'.uniqid();
        mkdir($this->tmp, 0777, true);
        $this->io = new BufferIO();
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->tmp);
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (array_diff(scandir($dir), ['.', '..']) as $entry) {
            $path = $dir.'/'.$entry;
            is_dir($path) && !is_link($path) ? $this->rrmdir($path) : @unlink($path);
        }
        @rmdir($dir);
    }

    /**
     * Builds a real Script\Event whose root package carries the given `extra` block.
     */
    private function event(array $extra = []): Event
    {
        $package = new RootPackage('test/root', '1.0.0.0', '1.0.0');
        $package->setExtra($extra);
        $composer = new Composer();
        $composer->setPackage($package);
        return new Event('post-install-cmd', $composer, $this->io);
    }

    // ---------------------------------------------------------------- extra key reading

    /**
     * A project declaring neither key must get a clean no-op. These previously threw, and
     * MyAdmin's CI runs `composer install` without --no-scripts across every matrix leg.
     */
    public function testMissingExtraKeysYieldEmptyListsRatherThanThrowing(): void
    {
        $event = $this->event();
        $this->assertSame([], Plugin::getWritableDirs($event));
        $this->assertSame([], Plugin::getWritableFiles($event));
    }

    public function testNonArrayExtraKeysYieldEmptyLists(): void
    {
        $event = $this->event(['writable-dirs' => 'logs', 'writable-files' => 42]);
        $this->assertSame([], Plugin::getWritableDirs($event));
        $this->assertSame([], Plugin::getWritableFiles($event));
    }

    public function testReadsDeclaredPathLists(): void
    {
        $event = $this->event(['writable-dirs' => ['logs', 'cache'], 'writable-files' => ['a.json']]);
        $this->assertSame(['logs', 'cache'], Plugin::getWritableDirs($event));
        $this->assertSame(['a.json'], Plugin::getWritableFiles($event));
    }

    public function testDropsEmptyAndNonStringEntries(): void
    {
        $event = $this->event(['writable-dirs' => ['logs', '', null, 7, 'cache']]);
        $this->assertSame(['logs', 'cache'], Plugin::getWritableDirs($event));
    }

    public function testSetPermissionsIsANoOpWhenNothingIsDeclared(): void
    {
        Plugin::setPermissions($this->event());
        $this->assertStringNotContainsString('Setting up permissions', $this->io->getOutput());
    }

    // ---------------------------------------------------------------- EnsureFileExists (B5)

    /**
     * THE regression. The guard used to be `if (!is_dir(dirname($path)))`, so when the parent
     * directory already existed — the normal case — the whole body including touch() was
     * skipped and the file was never created. The chmod that followed then failed on a
     * missing path and, under the old control flow, aborted every remaining path in the run.
     */
    public function testEnsureFileExistsCreatesAFileWhenTheParentDirectoryAlreadyExists(): void
    {
        $path = $this->tmp.'/already-there/config.json';
        mkdir(dirname($path), 0777, true);
        $this->assertDirectoryExists(dirname($path));

        Plugin::EnsureFileExists($this->event(), $path);

        $this->assertFileExists($path);
    }

    public function testEnsureFileExistsCreatesMissingParentDirectories(): void
    {
        $path = $this->tmp.'/a/b/c/config.json';
        Plugin::EnsureFileExists($this->event(), $path);
        $this->assertFileExists($path);
    }

    public function testEnsureFileExistsLeavesAnExistingFileUntouched(): void
    {
        $path = $this->tmp.'/keep.json';
        file_put_contents($path, '{"keep":true}');

        Plugin::EnsureFileExists($this->event(), $path);

        $this->assertSame('{"keep":true}', file_get_contents($path));
    }

    public function testEnsureDirExistsCreatesNestedDirectories(): void
    {
        $path = $this->tmp.'/x/y/z';
        Plugin::EnsureDirExists($this->event(), $path);
        $this->assertDirectoryExists($path);
    }

    // ---------------------------------------------------------------- httpd user detection

    /**
     * B3: the old regex was end-anchored against `ps aux`, so it required a line to END with
     * "apache"/"nginx"/etc. Real lines end with the command tail, so it matched nothing and
     * the function fell off the end returning null implicitly. Whatever it returns now, it
     * must be a non-empty string or an explicit null — never '' and never root.
     */
    public function testGetHttpdUserReturnsNullOrAUsableUsername(): void
    {
        $user = Plugin::getHttpdUser($this->event());
        if ($user !== null) {
            $this->assertIsString($user);
            $this->assertNotSame('', $user);
            $this->assertNotSame('root', $user);
        } else {
            $this->assertNull($user);
        }
    }

    // ---------------------------------------------------------------- chmod/chown behaviour

    public function testDirectoriesGetModeSevenSevenSeven(): void
    {
        $path = $this->tmp.'/adir';
        Plugin::ChmodPermissionsSetter($this->event(), null, $path, 'dir');
        // is_dir() inside EnsureDirExists populates PHP's stat cache before the chmod lands.
        clearstatcache(true, $path);
        $this->assertSame(0777, fileperms($path) & 0777);
    }

    /**
     * B4: files are data. The old blanket `chmod 777` left include/config/hooks.json and
     * plugins.json marked executable on the live host.
     */
    public function testFilesGetModeSixSixSixAndAreNotExecutable(): void
    {
        $path = $this->tmp.'/data.json';
        Plugin::ChmodPermissionsSetter($this->event(), null, $path, 'file');
        clearstatcache(true, $path);
        $this->assertSame(0666, fileperms($path) & 0777);
        $this->assertFalse(is_executable($path));
    }

    /**
     * B4: with no detectable webserver user the chown must be skipped entirely rather than
     * emitted as `chown me: <path>`, which GNU chown accepts silently — making the broken
     * detection invisible forever.
     */
    public function testANullWebserverUserSkipsChownWithoutFailing(): void
    {
        $path = $this->tmp.'/nochown';
        Plugin::ChmodPermissionsSetter($this->event(), null, $path, 'dir');
        $this->assertDirectoryExists($path);
        $this->assertStringNotContainsString('chown', $this->io->getOutput());
    }

    // ---------------------------------------------------------------- run resilience (B5)

    /**
     * One unwritable path must not prevent the rest of the list from being processed.
     */
    public function testOneUnprocessablePathDoesNotAbortTheRest(): void
    {
        $ok1 = $this->tmp.'/first';
        $ok2 = $this->tmp.'/second';
        $blocked = '/proc/cannot-create-this/nope';

        Plugin::setPermissionsChmod($this->event(['writable-dirs' => [$ok1, $blocked, $ok2]]));

        $this->assertDirectoryExists($ok1);
        $this->assertDirectoryExists($ok2, 'a failure on an earlier path must not skip later ones');
    }

    public function testAFailingPathIsReportedAsAWarning(): void
    {
        Plugin::setPermissionsChmod($this->event(['writable-dirs' => ['/proc/cannot-create-this/nope']]));
        $this->assertStringContainsString('Could not set permissions', $this->io->getOutput());
    }

    public function testSetPermissionsNeverThrows(): void
    {
        Plugin::setPermissions($this->event([
            'writable-dirs' => ['/proc/cannot-create-this/nope'],
            'writable-files' => ['/proc/cannot-create-this/also.json'],
        ]));
        $this->addToAssertionCount(1);
    }

    // ---------------------------------------------------------------- runProcess

    public function testRunProcessReturnsStdout(): void
    {
        $this->assertSame('hello', Plugin::runProcess($this->event(), 'echo hello'));
    }

    /**
     * The old message was just "Returned Error Code 1", which made the permission step
     * undebuggable.
     */
    public function testRunProcessFailureNamesTheCommand(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessageMatches('/exit(ed)? 1|Command ".*" exited/');
        Plugin::runProcess($this->event(), 'exit 1');
    }
}
