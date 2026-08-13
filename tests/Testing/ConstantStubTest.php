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

/**
 * The token scanner is the piece most likely to over-capture and define
 * something real (risk R3), so it is tested against the scan in isolation —
 * `scanSource()` has no side effects — rather than through `defineFrom()`,
 * whose effects are process-global and irreversible.
 *
 * @covers \MyAdmin\Plugins\Testing\ConstantStub
 */
class ConstantStubTest extends TestCase
{
    /**
     * @param string $body PHP body, without the opening tag
     * @return array<int,string>
     */
    private function scan($body)
    {
        return ConstantStub::scanSource("<?php\n" . $body);
    }

    /**
     * @return void
     */
    public function testFindsPlainConstantReference()
    {
        $this->assertSame(['PRORATE_BILLING'], $this->scan('$x = PRORATE_BILLING;'));
    }

    /**
     * @return void
     */
    public function testFindsConstantInsideCondition()
    {
        $this->assertContains('MAXMIND_ENABLE', $this->scan('if (MAXMIND_ENABLE) { doThing(); }'));
    }

    /**
     * @return void
     */
    public function testReturnsEachNameOnceInFirstSeenOrder()
    {
        $found = $this->scan('$a = ALPHA_ONE; $b = BETA_TWO; $c = ALPHA_ONE;');
        $this->assertSame(['ALPHA_ONE', 'BETA_TWO'], $found);
    }

    // -----------------------------------------------------------------------
    // Over-capture: each of these must NOT be treated as a constant
    // -----------------------------------------------------------------------

    /**
     * @return void
     */
    public function testSkipsClassConstantAccess()
    {
        $this->assertNotContains('BAR', $this->scan('$x = Foo::BAR;'));
    }

    /**
     * @return void
     */
    public function testSkipsClassNameBeforeDoubleColon()
    {
        $this->assertNotContains('SOME_CLASS', $this->scan('$x = SOME_CLASS::method();'));
    }

    /**
     * @return void
     */
    public function testSkipsPropertyAccess()
    {
        $this->assertNotContains('BAZ', $this->scan('$x = $obj->BAZ;'));
    }

    /**
     * @return void
     */
    public function testSkipsFunctionCall()
    {
        $this->assertNotContains('SOME_FUNC', $this->scan('$x = SOME_FUNC($y);'));
    }

    /**
     * @return void
     */
    public function testSkipsFunctionDeclaration()
    {
        $this->assertNotContains('MY_FUNCTION', $this->scan('function MY_FUNCTION() { return 1; }'));
    }

    /**
     * @return void
     */
    public function testSkipsNamespacedName()
    {
        $found = $this->scan('$x = new \Some\NAMESPACE_SEG\Thing();');
        $this->assertNotContains('NAMESPACE_SEG', $found);
    }

    /**
     * @return void
     */
    public function testSkipsConstDeclaration()
    {
        $this->assertNotContains('MY_CONST', $this->scan('class A { const MY_CONST = 1; }'));
    }

    /**
     * @return void
     */
    public function testSkipsUseStatement()
    {
        $this->assertNotContains('SOME_THING', $this->scan('use Vendor\SOME_THING;'));
    }

    /**
     * @return void
     */
    public function testSkipsParameterTypeHint()
    {
        $this->assertNotContains('SOME_TYPE', $this->scan('function f(SOME_TYPE $x) {}'));
    }

    /**
     * @return void
     */
    public function testSkipsByReferenceParameterTypeHint()
    {
        $this->assertNotContains('SOME_TYPE', $this->scan('function f(SOME_TYPE &$x) {}'));
    }

    /**
     * @return void
     */
    public function testSkipsVariadicParameterTypeHint()
    {
        $this->assertNotContains('SOME_TYPE', $this->scan('function f(SOME_TYPE ...$x) {}'));
    }

    /**
     * @return void
     */
    public function testSkipsCatchType()
    {
        $this->assertNotContains('SOME_EXCEPTION', $this->scan('try { f(); } catch (SOME_EXCEPTION $e) {}'));
    }

    /**
     * @return void
     */
    public function testSkipsExtendsAndImplements()
    {
        $found = $this->scan('class A extends BASE_CLASS implements SOME_IFACE {}');
        $this->assertNotContains('BASE_CLASS', $found);
        $this->assertNotContains('SOME_IFACE', $found);
    }

    /**
     * @return void
     */
    public function testSkipsInstanceof()
    {
        $this->assertNotContains('SOME_CLASS', $this->scan('$x = $y instanceof SOME_CLASS;'));
    }

    /**
     * @return void
     */
    public function testSkipsNewClassName()
    {
        $this->assertNotContains('SOME_CLASS', $this->scan('$x = new SOME_CLASS;'));
    }

    /**
     * @return void
     */
    public function testSkipsGotoLabel()
    {
        $this->assertNotContains('SOME_LABEL', $this->scan('goto SOME_LABEL;'));
    }

    /**
     * @return void
     */
    public function testSkipsCommentsAndDocblocks()
    {
        $found = $this->scan("/** @see SOME_CONSTANT */\n// also NOT_A_CONSTANT\n\$x = 1;");
        $this->assertSame([], $found);
    }

    /**
     * String literals are `T_CONSTANT_ENCAPSED_STRING`, never `T_STRING` — so
     * an array key that happens to look like a constant is not one.
     *
     * @return void
     */
    public function testSkipsStringLiterals()
    {
        $this->assertSame([], $this->scan("\$x = ['SOME_KEY' => 'SOME_VALUE'];"));
    }

    /**
     * Short names are excluded by the `{2,}` in the regex, which is what keeps
     * `ID`, `OK` and similar out.
     *
     * @return void
     */
    public function testSkipsNamesShorterThanThreeCharacters()
    {
        $this->assertSame([], $this->scan('$x = ID; $y = OK;'));
    }

    /**
     * @return void
     */
    public function testSkipsMixedCaseNames()
    {
        $this->assertSame([], $this->scan('$x = SomeClass; $y = someThing;'));
    }

    // -----------------------------------------------------------------------
    // Denylist and definition guards
    // -----------------------------------------------------------------------

    /**
     * The four false positives the fleet-wide scan surfaced. `TRUE` and
     * `JSON_PRETTY_PRINT` are additionally caught by `defined()`; `COMMAND` and
     * `DEBUG` are **not** defined in a bare PHP process and are the two that
     * make the denylist load-bearing rather than defensive padding.
     *
     * @return void
     */
    public function testKnownFalsePositivesAreNeverDefined()
    {
        ConstantStub::defineOverrides([]);
        $before = ConstantStub::definedConstants();
        ConstantStub::defineOverrides(['COMMAND' => 'x', 'DEBUG' => 'x', 'TRUE' => 'x', 'JSON_PRETTY_PRINT' => 'x']);
        $this->assertSame($before, ConstantStub::definedConstants(), 'denylisted names must never be defined');
        $this->assertFalse(defined('COMMAND'));
        $this->assertFalse(defined('DEBUG'));
    }

    /**
     * @return void
     */
    public function testDefinesOverrideWithTheGivenValue()
    {
        $name = 'HARNESS_TEST_OVERRIDE_' . __LINE__;
        $this->assertFalse(defined($name));
        $defined = ConstantStub::defineOverrides([$name => 42]);
        $this->assertSame([$name], $defined);
        $this->assertSame(42, constant($name));
    }

    /**
     * A second call must not attempt to redefine — that would emit a warning,
     * and `failOnWarning="true"` would turn it into a failure.
     *
     * @return void
     */
    public function testDefiningTwiceIsANoOp()
    {
        $name = 'HARNESS_TEST_IDEMPOTENT_' . __LINE__;
        ConstantStub::defineOverrides([$name => 'first']);
        $second = ConstantStub::defineOverrides([$name => 'second']);
        $this->assertSame([], $second, 'an already-defined constant must not be redefined');
        $this->assertSame('first', constant($name), 'first definition wins — constants are immutable');
    }

    /**
     * @return void
     */
    public function testSentinelIsTruthyAndSelfDescribing()
    {
        $source = "<?php\n\$x = HARNESS_SENTINEL_PROBE;";
        $names = ConstantStub::scanSource($source);
        $this->assertSame(['HARNESS_SENTINEL_PROBE'], $names);

        ConstantStub::defineOverrides(['HARNESS_SENTINEL_PROBE' => '__STUB_HARNESS_SENTINEL_PROBE__']);
        $this->assertTrue((bool)constant('HARNESS_SENTINEL_PROBE'), 'sentinel must be truthy so if(CONST) covers the enabled branch');
        $this->assertStringContainsString('HARNESS_SENTINEL_PROBE', constant('HARNESS_SENTINEL_PROBE'));
    }

    /**
     * An all-caps *class* name must never be shadowed by a constant.
     *
     * @return void
     */
    public function testDoesNotDefineOverAnExistingClassName()
    {
        $this->assertTrue(class_exists(\Tests\MyAdmin\Plugins\Testing\Fixtures\ALLCAPSCLASS::class));
        $defined = ConstantStub::defineOverrides(['Tests\MyAdmin\Plugins\Testing\Fixtures\ALLCAPSCLASS' => 1]);
        $this->assertSame([], $defined);
    }

    /**
     * @return void
     */
    public function testScanOfMissingFileReturnsEmpty()
    {
        $this->assertSame([], ConstantStub::scanFile('/nonexistent/path/to/nothing.php'));
    }

    /**
     * R8: the scan is cached per file+mtime so repeated `Bootstrap::init()`
     * calls from `setUp()` do not re-tokenise.
     *
     * @return void
     */
    public function testScanIsCachedPerFile()
    {
        $file = sys_get_temp_dir() . '/harness_scan_cache_' . getmypid() . '.php';
        file_put_contents($file, "<?php\n\$x = CACHED_PROBE_ONE;");
        $first = ConstantStub::scanFile($file);
        $this->assertSame(['CACHED_PROBE_ONE'], $first);

        // Rewrite the content but keep the mtime: a cached scan must be returned.
        $mtime = filemtime($file);
        file_put_contents($file, "<?php\n\$x = CACHED_PROBE_TWO;");
        touch($file, $mtime);
        clearstatcache(true, $file);
        $this->assertSame(['CACHED_PROBE_ONE'], ConstantStub::scanFile($file), 'same path+mtime must hit the cache');

        unlink($file);
    }
}
