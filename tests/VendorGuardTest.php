<?php

namespace Tests\MyAdmin\Plugins;

use MyAdmin\Plugins\VendorGuard;
use PHPUnit\Framework\TestCase;

/**
 * Test suite for VendorGuard.
 *
 * Creates real git repositories in a temp directory — the behaviour under test is
 * `git status --porcelain` output, which cannot be meaningfully mocked.
 *
 * @covers \MyAdmin\Plugins\VendorGuard
 */
class VendorGuardTest extends TestCase
{
    /** @var string */
    private $vendor;

    /** @var string|false */
    private $originalEnv;

    protected function setUp(): void
    {
        $this->vendor = sys_get_temp_dir().'/vendorguard_'.getmypid().'_'.uniqid();
        mkdir($this->vendor, 0777, true);
        $this->originalEnv = getenv(VendorGuard::OVERRIDE_ENV);
        putenv(VendorGuard::OVERRIDE_ENV);
    }

    protected function tearDown(): void
    {
        if ($this->originalEnv === false) {
            putenv(VendorGuard::OVERRIDE_ENV);
        } else {
            putenv(VendorGuard::OVERRIDE_ENV.'='.$this->originalEnv);
        }
        $this->rrmdir($this->vendor);
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
     * Creates a git working copy with one committed file.
     */
    private function makeRepo(string $package): string
    {
        $dir = $this->vendor.'/'.$package;
        mkdir($dir, 0777, true);
        file_put_contents($dir.'/README.md', "initial\n");
        $q = escapeshellarg($dir);
        exec("git -C {$q} init -q 2>/dev/null");
        exec("git -C {$q} config user.email test@example.com 2>/dev/null");
        exec("git -C {$q} config user.name Test 2>/dev/null");
        exec("git -C {$q} add -A 2>/dev/null");
        exec("git -C {$q} commit -q -m initial 2>/dev/null");
        return $dir;
    }

    private function guard(): VendorGuard
    {
        return new VendorGuard($this->vendor);
    }

    public function testFindsGitWorkingCopies(): void
    {
        $this->makeRepo('acme/one');
        $this->makeRepo('acme/two');
        $this->assertSame(['acme/one', 'acme/two'], $this->guard()->findWorkingCopies());
    }

    public function testIgnoresDirectoriesThatAreNotRepositories(): void
    {
        mkdir($this->vendor.'/acme/plain', 0777, true);
        $this->assertSame([], $this->guard()->findWorkingCopies());
    }

    public function testACleanRepositoryIsNotReportedDirty(): void
    {
        $this->makeRepo('acme/one');
        $this->assertSame([], $this->guard()->findDirty());
    }

    public function testDetectsAModifiedTrackedFile(): void
    {
        $dir = $this->makeRepo('acme/one');
        file_put_contents($dir.'/README.md', "changed\n");

        $dirty = $this->guard()->findDirty();

        $this->assertArrayHasKey('acme/one', $dirty);
        $this->assertNotEmpty($dirty['acme/one']);
    }

    public function testDetectsAnUntrackedFile(): void
    {
        $dir = $this->makeRepo('acme/one');
        file_put_contents($dir.'/scratch.php', "<?php\n");

        $this->assertArrayHasKey('acme/one', $this->guard()->findDirty());
    }

    public function testDetectsADeletedFile(): void
    {
        $dir = $this->makeRepo('acme/one');
        unlink($dir.'/README.md');

        $this->assertArrayHasKey('acme/one', $this->guard()->findDirty());
    }

    public function testReportsOnlyTheDirtyRepositories(): void
    {
        $this->makeRepo('acme/clean');
        $dirty = $this->makeRepo('acme/dirty');
        file_put_contents($dirty.'/README.md', "changed\n");

        $found = $this->guard()->findDirty();

        $this->assertSame(['acme/dirty'], array_keys($found));
    }

    /**
     * The guard must never itself be the reason a build fails, so a directory that merely
     * looks like a repository is treated as clean rather than raising.
     */
    public function testTreatsABogusGitDirectoryAsClean(): void
    {
        mkdir($this->vendor.'/acme/fake/.git', 0777, true);
        $this->assertSame([], $this->guard()->findDirty());
    }

    public function testHandlesAnEmptyVendorDirectory(): void
    {
        $this->assertSame([], $this->guard()->findWorkingCopies());
        $this->assertSame([], $this->guard()->findDirty());
    }

    public function testOverrideIsOffByDefault(): void
    {
        $this->assertFalse(VendorGuard::isOverridden());
    }

    /**
     * @dataProvider truthyOverrides
     */
    public function testOverrideIsOnForTruthyValues(string $value): void
    {
        putenv(VendorGuard::OVERRIDE_ENV.'='.$value);
        $this->assertTrue(VendorGuard::isOverridden());
    }

    public function truthyOverrides(): array
    {
        return [['1'], ['yes'], ['true'], ['on']];
    }

    /**
     * @dataProvider falsyOverrides
     */
    public function testOverrideStaysOffForFalsyValues(string $value): void
    {
        putenv(VendorGuard::OVERRIDE_ENV.'='.$value);
        $this->assertFalse(VendorGuard::isOverridden());
    }

    public function falsyOverrides(): array
    {
        return [[''], ['0'], ['false'], ['FALSE']];
    }

    public function testFormatReportNamesEachDirtyPackage(): void
    {
        $lines = VendorGuard::formatReport(['acme/one' => [' M src/Thing.php']]);
        $this->assertNotEmpty($lines);
        $this->assertStringContainsString('acme/one', $lines[0]);
        $this->assertStringContainsString('src/Thing.php', implode("\n", $lines));
    }

    public function testFormatReportTruncatesLongChangeLists(): void
    {
        $changes = array_map(function ($i) {
            return ' M file'.$i.'.php';
        }, range(1, 12));

        $text = implode("\n", VendorGuard::formatReport(['acme/one' => $changes]));

        $this->assertStringContainsString('and 7 more', $text);
    }

    public function testFormatReportOfNothingIsEmpty(): void
    {
        $this->assertSame([], VendorGuard::formatReport([]));
    }
}
