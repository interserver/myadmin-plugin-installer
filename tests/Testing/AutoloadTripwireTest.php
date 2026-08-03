<?php
/**
 * @author Joe Huss <detain@interserver.net>
 * @copyright 2026
 * @package MyAdmin
 * @category Testing
 */

namespace Tests\MyAdmin\Plugins\Testing;

use PHPUnit\Framework\TestCase;

/**
 * D2 enforcement. **This is a tripwire, not documentation.**
 *
 * The installer is a `composer-plugin`, so every entry in its
 * `autoload.files` is loaded into every production install. `src/Testing/`
 * contains stub definitions of `myadmin_log()`, `has_acl()` and `dialog()`.
 * If any of them reached production autoload they would shadow the real
 * implementations: logging would silently stop and `has_acl()` would return a
 * fixed answer for every permission check in the panel. Risk R2, severity
 * Critical.
 *
 * This test was verified to fail — not merely asserted to exist — by
 * temporarily adding `src/Testing/stubs.php` to `autoload.files` and observing
 * a red run. See `docs/testing-harness.md` for the recorded output.
 *
 * @covers \MyAdmin\Plugins\Testing\Bootstrap
 */
class AutoloadTripwireTest extends TestCase
{
    /**
     * @return array<string,mixed>
     */
    private function composerJson()
    {
        $path = dirname(__DIR__, 2) . '/composer.json';
        $this->assertFileExists($path, 'installer composer.json must exist');
        $decoded = json_decode((string)file_get_contents($path), true);
        $this->assertIsArray($decoded, 'composer.json must be valid JSON');
        return $decoded;
    }

    /**
     * @return void
     */
    public function testAutoloadFilesContainsNoTestingPath()
    {
        $composer = $this->composerJson();
        $files = isset($composer['autoload']['files']) ? $composer['autoload']['files'] : [];
        $this->assertIsArray($files);
        foreach ($files as $file) {
            $this->assertStringNotContainsString(
                'Testing',
                $file,
                'D2 VIOLATION: "' . $file . '" is under src/Testing/ and is in autoload.files. '
                . 'Test stubs would be loaded into every production install and would shadow the real '
                . 'myadmin_log() / has_acl() / dialog(). Remove it — the harness loads stubs.php via '
                . 'Bootstrap::init() only.'
            );
        }
    }

    /**
     * The production autoload map is exactly the two known files, so a new
     * entry of any kind is a deliberate decision that has to be made here.
     *
     * @return void
     */
    public function testAutoloadFilesIsTheExpectedProductionSet()
    {
        $composer = $this->composerJson();
        $files = isset($composer['autoload']['files']) ? $composer['autoload']['files'] : [];
        sort($files);
        $this->assertSame(
            ['src/function_requirements.php', 'src/modules.php'],
            $files,
            'autoload.files changed. Every entry here is loaded into all ~69 production installs; '
            . 'adding one is a release-safety decision, not a refactor.'
        );
    }

    /**
     * PSR-4 maps are lazy, so classes under src/Testing/ cost nothing at
     * runtime — but only as long as they stay classes. A file-style entry
     * anywhere in the autoload block is what would break this.
     *
     * @return void
     */
    public function testTestingNamespaceIsReachedOnlyThroughPsr4()
    {
        $composer = $this->composerJson();
        $psr4 = isset($composer['autoload']['psr-4']) ? $composer['autoload']['psr-4'] : [];
        $this->assertSame(['MyAdmin\\Plugins\\' => 'src/'], $psr4);
    }

    /**
     * The stub file must never be autoloadable as a class either — it declares
     * functions in the global namespace and has no class in it.
     *
     * @return void
     */
    public function testStubsFileDeclaresNoClass()
    {
        $path = dirname(__DIR__, 2) . '/src/Testing/stubs.php';
        $this->assertFileExists($path);
        $source = (string)file_get_contents($path);
        $tokens = token_get_all($source);
        foreach ($tokens as $token) {
            if (is_array($token) && $token[0] === T_CLASS) {
                $this->fail('src/Testing/stubs.php must contain only functions, never a class.');
            }
        }
        $this->assertTrue(true);
    }

    /**
     * Every function in stubs.php must be `function_exists`-guarded. An
     * unguarded one is a fatal redeclaration the moment core, or ext-gettext,
     * or another repo's own bootstrap already provided it.
     *
     * @return void
     */
    public function testEveryStubIsGuarded()
    {
        $path = dirname(__DIR__, 2) . '/src/Testing/stubs.php';
        $source = (string)file_get_contents($path);
        // Functions in stubs.php are indented inside their function_exists()
        // guard, so the match must not be anchored to column 0.
        preg_match_all('/^\s*function\s+([a-zA-Z_][a-zA-Z0-9_]*)\s*\(/m', $source, $matches);
        $this->assertNotEmpty($matches[1], 'stubs.php should declare functions');
        foreach ($matches[1] as $function) {
            $this->assertMatchesRegularExpression(
                "/if\s*\(\s*!\s*function_exists\(\s*'" . preg_quote($function, '/') . "'\s*\)\s*\)/",
                $source,
                'stub ' . $function . '() is not wrapped in a function_exists() guard'
            );
        }
    }
}
