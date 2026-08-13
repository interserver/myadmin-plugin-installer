<?php

namespace Tests\MyAdmin\Plugins\Testing\Contract;

use MyAdmin\Plugins\Testing\Contract\Path;
use PHPUnit\Framework\TestCase;

/**
 * Windows path handling, asserted from Linux.
 *
 * ---------------------------------------------------------------------------------
 * WHY THESE RUN EVERYWHERE
 * ---------------------------------------------------------------------------------
 * Every function under test is lexical — it takes a string and returns a string, and
 * never touches the filesystem — so a Windows-shaped input can be fed to it on any host.
 * That matters, because the bug these pin was invisible on the three Linux CI legs and
 * only fired on the one Windows leg, where nobody looks. Pinning it with ordinary tests
 * moves it somewhere it will be caught in under a second, locally.
 *
 * @covers \MyAdmin\Plugins\Testing\Contract\Path
 */
class PathTest extends TestCase
{
    /**
     * @dataProvider absolutePaths
     */
    public function testRecognisesEveryShapeOfRootedPath(string $path): void
    {
        $this->assertTrue(Path::isAbsolute($path), $path);
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function absolutePaths(): array
    {
        return [
            'posix' => ['/home/sites/mystage'],
            'windows backslash' => ['D:\\a\\pkg\\src'],
            'windows forward slash' => ['D:/a/pkg/src'],
            'windows lowercase drive' => ['c:\\temp'],
            'drive relative' => ['\\pkg\\src'],
            'unc' => ['\\\\server\\share\\pkg'],
        ];
    }

    /**
     * @dataProvider relativePaths
     */
    public function testRelativePathsAreNotMistakenForRootedOnes(string $path): void
    {
        $this->assertFalse(Path::isAbsolute($path), $path);
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function relativePaths(): array
    {
        return [
            'bare fragment' => ['templates'],
            'dotted' => ['../templates'],
            'nested' => ['src/templates'],
            'empty' => [''],
            'drive letter with no separator' => ['D:templates'],
        ];
    }

    /**
     * The bug, stated directly: `dirname(__FILE__)` on Windows starts with a drive letter,
     * the old check said that was relative, and the drive was then dropped by a split on
     * `/` that saw the whole path as one segment.
     */
    public function testAWindowsRootSurvivesNormalisation(): void
    {
        $this->assertSame('D:/a/pkg/templates', Path::normalise('D:\\a\\pkg\\templates'));
        $this->assertSame('D:/a/pkg/templates', Path::normalise('D:\\a\\pkg\\src\\..\\templates'));
        $this->assertSame('//server/share/pkg', Path::normalise('\\\\server\\share\\pkg'));
    }

    public function testPosixBehaviourIsUnchanged(): void
    {
        $this->assertSame('/home/pkg/templates', Path::normalise('/home/pkg/src/../templates'));
        $this->assertSame('/home/pkg', Path::normalise('/home/./pkg/'));
        $this->assertSame('templates', Path::normalise('templates'));
        $this->assertSame('a/b', Path::normalise('a//b'));
    }

    /**
     * `/..` is `/`. Keeping the climb would produce a path that names nothing and reads
     * like a defect in whatever plugin it was reported against.
     */
    public function testClimbingPastAnAbsoluteRootStopsAtTheRoot(): void
    {
        $this->assertSame('/etc', Path::normalise('/../../etc'));
        $this->assertSame('D:/etc', Path::normalise('D:\\..\\..\\etc'));
    }

    /**
     * A relative path has no root to be above, so dropping the climb would silently change
     * which directory is meant.
     */
    public function testClimbingOutOfARelativePathIsKept(): void
    {
        $this->assertSame('../../templates', Path::normalise('../../templates'));
        $this->assertSame('../templates', Path::normalise('src/../../templates'));
    }

    public function testJoinAnchorsARelativeFragmentOnTheBase(): void
    {
        $this->assertSame('/home/pkg/templates', Path::join('/home/pkg/src', '../templates'));
        $this->assertSame('D:/a/pkg/templates', Path::join('D:\\a\\pkg\\src', '../templates'));
        $this->assertSame('D:/a/pkg/templates', Path::join('D:\\a\\pkg', 'templates'));
    }

    /**
     * A fragment that is already rooted must not be appended to anything — that is how a
     * plugin naming an absolute template directory keeps naming it.
     */
    public function testJoinLeavesARootedFragmentAlone(): void
    {
        $this->assertSame('/opt/templates', Path::join('/home/pkg', '/opt/templates'));
        $this->assertSame('D:/opt/templates', Path::join('D:\\a\\pkg', 'D:\\opt\\templates'));
    }

    public function testEqualsIgnoresSeparatorStyleButNotIdentity(): void
    {
        $this->assertTrue(Path::equals('D:\\a\\pkg', 'D:/a/pkg'));
        $this->assertTrue(Path::equals('/home/pkg/', '/home/./pkg'));
        $this->assertFalse(Path::equals('/home/pkg', '/home/other'));
    }

    /**
     * Case-insensitivity would let a finding's path match one that was never written. The
     * inspectors only ever compare paths they derived from the same source, so exactness
     * costs nothing and a fuzzy match could hide a real mismatch.
     */
    public function testCaseStillMattersOnWindowsShapedPaths(): void
    {
        $this->assertFalse(Path::equals('C:/Temp/Pkg', 'c:/temp/pkg'));
    }

    /**
     * Output is `/`-separated on every platform. PHP accepts `/` on Windows everywhere it
     * accepts `\`, and a finding that reads identically on every leg is worth more than
     * one matching the local shell's preferred slash.
     */
    public function testOutputIsAlwaysForwardSlashed(): void
    {
        $this->assertStringNotContainsString('\\', Path::normalise('D:\\a\\pkg\\templates'));
        $this->assertStringNotContainsString('\\', Path::join('D:\\a', 'pkg\\templates'));
    }
}
