<?php
/**
 * @author Joe Huss <detain@interserver.net>
 * @copyright 2026
 * @package MyAdmin
 * @category Testing
 */

namespace Tests\MyAdmin\Plugins\Testing;

use MyAdmin\Plugins\Testing\ConstantStub;
use PHPUnit\Framework\TestCase;
use Tests\MyAdmin\Plugins\Testing\Fixtures\BareConstantPlugin;

/**
 * Pins the ordering constraint every converted repo has to respect, and which
 * is invisible until it bites.
 *
 * **A plugin's static properties must not be touched before
 * `Bootstrap::init()` has run.** PHP evaluates a static property initializer
 * lazily, on first access to the class, so a plugin whose `$settings`
 * initializer references a bare constant — 9 repos reference `PRORATE_BILLING`
 * alone — throws `Error: Undefined constant` at that first access, not at
 * autoload time.
 *
 * This was found the hard way while smoke-testing: a probe script read
 * `Plugin::$module` to decide what to pass to `init()`, and fataled on
 * `myadmin-mail-module` even though that repo's own suite passed. Reading the
 * module out of the source text, or calling `init(['plugin' => ...])` first,
 * both avoid it. The generated `tests/bootstrap.php` does the former.
 *
 * The corollary is the reassuring half: `class_exists()` inside
 * `ConstantStub::defineFrom()` autoloads the file **without** evaluating the
 * initializers, so scanning-then-defining is safe and ordering inside
 * `defineFrom()` is not a problem.
 *
 * @covers \MyAdmin\Plugins\Testing\ConstantStub
 */
class ConstantOrderingTest extends TestCase
{
    /**
     * Touching the class before the constant exists throws — catchable,
     * because PHP 8 raises `Error` rather than fataling outright.
     *
     * @return void
     */
    public function testAccessingAStaticPropertyBeforeDefinitionThrows()
    {
        $this->assertFalse(defined('HARNESS_FIXTURE_PRORATE'), 'this test must run before the constant is defined');

        $thrown = null;
        try {
            BareConstantPlugin::$settings;
        } catch (\Throwable $e) {
            $thrown = $e;
        }

        $this->assertInstanceOf(\Error::class, $thrown, 'a bare constant in a static initializer must throw when undefined');
        $this->assertStringContainsString('HARNESS_FIXTURE_PRORATE', $thrown->getMessage());
    }

    /**
     * The scan autoloads the class file to find it, but must not trigger the
     * initializer — otherwise `defineFrom()` could never fix its own problem.
     *
     * @depends testAccessingAStaticPropertyBeforeDefinitionThrows
     * @return void
     */
    public function testScanningTheClassDoesNotEvaluateInitializers()
    {
        $found = ConstantStub::scanFile((new \ReflectionClass(BareConstantPlugin::class))->getFileName());
        $this->assertContains('HARNESS_FIXTURE_PRORATE', $found);
        $this->assertFalse(defined('HARNESS_FIXTURE_PRORATE'), 'scanning must have no side effects');
    }

    /**
     * And with the constant defined, the same access succeeds. This is the
     * whole §0.8 fix in three lines.
     *
     * @depends testScanningTheClassDoesNotEvaluateInitializers
     * @return void
     */
    public function testDefiningTheConstantMakesTheClassUsable()
    {
        $defined = ConstantStub::defineFrom(BareConstantPlugin::class);
        $this->assertContains('HARNESS_FIXTURE_PRORATE', $defined);

        $settings = BareConstantPlugin::$settings;
        $this->assertSame('__STUB_HARNESS_FIXTURE_PRORATE__', $settings['PRORATE']);
    }

    /**
     * The sentinel must be truthy, so `if (PRORATE_BILLING)` takes the enabled
     * branch — the branch worth covering.
     *
     * @depends testDefiningTheConstantMakesTheClassUsable
     * @return void
     */
    public function testStubbedConstantIsTruthy()
    {
        $this->assertTrue((bool)constant('HARNESS_FIXTURE_PRORATE'));
    }
}
