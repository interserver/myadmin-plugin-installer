<?php
/**
 * @author Joe Huss <detain@interserver.net>
 * @copyright 2026
 * @package MyAdmin
 * @category Testing
 */

namespace Tests\MyAdmin\Plugins\Testing\Contract;

use MyAdmin\Plugins\Testing\Contract\PluginSubject;
use PHPUnit\Framework\TestCase;
use Tests\MyAdmin\Plugins\Testing\Fixtures\UnevaluableMetadataPlugin;

/**
 * Pins the harness-bug fix: a plugin whose static initializers cannot be evaluated must
 * still be *inspectable*.
 *
 * `PluginInspector` forbids an inspector from throwing for a defect it detects. Before this,
 * `PluginSubject::type()` made that impossible to honour on ten of the sixty-nine fleet
 * packages — the crash happened inside the subject, so no amount of care in the inspector
 * could prevent it, and the resulting stack trace named the plugin rather than the harness.
 *
 * Complements `ConstantOrderingTest`, which pins the underlying PHP semantics and the
 * `ConstantStub` remedy. This file pins what `PluginSubject` does when the remedy has not
 * been applied yet — which is the situation every Tier-A inspector runs in, because they
 * read metadata *before* `Bootstrap::init()` has anything to prime from.
 *
 * @covers \MyAdmin\Plugins\Testing\Contract\PluginSubject
 */
class PluginSubjectTest extends TestCase
{
    /**
     * @param string $class
     * @return PluginSubject
     */
    private function subject($class)
    {
        return new PluginSubject($class);
    }

    /**
     * Wraps a property declaration in just enough source for `token_get_all()`.
     *
     * @param string $body class-body source
     * @return string
     */
    private function source($body)
    {
        return "<?php\nnamespace Some\\Where;\nclass Thing\n{\n".$body."\n}\n";
    }

    // -----------------------------------------------------------------------
    // The regression: reading metadata off a constant-poisoned class
    // -----------------------------------------------------------------------

    /**
     * The whole point. Before the fix this call threw
     * `Error: Undefined constant "...\PLUGIN_SUBJECT_FIXTURE_BILLING"`.
     *
     * @return void
     */
    public function testTypeIsReadableEvenWhenAnotherInitializerCannotBeEvaluated()
    {
        $this->assertFalse(
            defined('PLUGIN_SUBJECT_FIXTURE_BILLING'),
            'the fixture constant must never be defined, or this stops testing anything'
        );

        $this->assertSame('module', $this->subject(UnevaluableMetadataPlugin::class)->type());
    }

    /**
     * @return void
     */
    public function testModuleIsReadableTooAndMatchesTheDeclaration()
    {
        $this->assertSame('a9unevaluable', $this->subject(UnevaluableMetadataPlugin::class)->module());
    }

    /**
     * Every scalar metadata property the inspectors read, not just the two convenience
     * accessors — including the empty string, which must survive the round trip as `''`
     * rather than degrading to null.
     *
     * @return void
     */
    public function testEveryScalarMetadataPropertyIsRecovered()
    {
        $subject = $this->subject(UnevaluableMetadataPlugin::class);
        $this->assertSame('Unevaluable Metadata Fixture', $subject->staticProperty('name'));
        $this->assertSame('Metadata is plain literals; $settings is not', $subject->staticProperty('description'));
        $this->assertSame('', $subject->staticProperty('help'));
    }

    /**
     * The failure must be *reportable*. A swallowed error that reads as a clean null is a
     * worse outcome than the original crash, because nothing downstream can tell.
     *
     * @return void
     */
    public function testTheSwallowedErrorIsExposedForReporting()
    {
        $error = $this->subject(UnevaluableMetadataPlugin::class)->staticPropertyError('type');
        $this->assertIsString($error);
        $this->assertStringContainsString('PLUGIN_SUBJECT_FIXTURE_BILLING', $error);
    }

    /**
     * "Declared but unevaluable" must stay distinguishable from "absent" — `staticProperty()`
     * returns null for both, so `hasStaticProperty()` is the only thing separating them.
     *
     * @return void
     */
    public function testAnUnevaluablePropertyStillReportsAsDeclared()
    {
        $subject = $this->subject(UnevaluableMetadataPlugin::class);
        $this->assertTrue($subject->hasStaticProperty('settings'), '$settings IS declared');
        $this->assertNull($subject->staticProperty('settings'), 'array initializers are not recovered');
        $this->assertIsString($subject->staticPropertyError('settings'), 'and the reason must be reportable');
    }

    /**
     * An absent property is not an error condition, and must not be reported as one.
     *
     * @return void
     */
    public function testAnAbsentPropertyReportsNoError()
    {
        $subject = $this->subject(UnevaluableMetadataPlugin::class);
        $this->assertFalse($subject->hasStaticProperty('neverDeclared'));
        $this->assertNull($subject->staticProperty('neverDeclared'));
        $this->assertNull($subject->staticPropertyError('neverDeclared'));
    }

    /**
     * The overwhelmingly common case must not pay for the rare one.
     *
     * @return void
     */
    public function testAHealthyClassReportsNoErrorAndReadsThroughReflection()
    {
        $subject = $this->subject(PluginSubjectHealthyFixture::class);
        $this->assertSame('service', $subject->type());
        $this->assertSame('healthy', $subject->module());
        $this->assertNull($subject->staticPropertyError('type'));
        $this->assertSame(['from' => 'reflection'], $subject->staticProperty('settings'));
    }

    /**
     * Constants are process-global, so a class that throws before priming succeeds after it.
     * Caching either the value or the error would freeze the pre-priming answer and make
     * `Bootstrap::init()` look like it had done nothing.
     *
     * The define() is one-way for the whole process, which is why this fixture is used
     * nowhere else in the suite.
     *
     * @return void
     */
    public function testEvaluationIsNotCachedSoLaterPrimingWins()
    {
        $this->assertFalse(defined('PLUGIN_SUBJECT_FIXTURE_LATE_BILLING'), 'must run before the define below');

        $subject = $this->subject(PluginSubjectLateConstantFixture::class);
        $this->assertSame('module', $subject->type(), 'recovered from source while unprimed');
        $this->assertIsString($subject->staticPropertyError('type'));
        $this->assertNull($subject->staticProperty('settings'), 'array not recoverable while unprimed');

        define('PLUGIN_SUBJECT_FIXTURE_LATE_BILLING', 'primed');

        $this->assertSame('module', $subject->type(), 'same answer, now via reflection');
        $this->assertNull($subject->staticPropertyError('type'), 'the error must clear once priming fixes it');
        $this->assertSame(['billing' => 'primed'], $subject->staticProperty('settings'));
    }

    // -----------------------------------------------------------------------
    // The source scan, exercised directly
    // -----------------------------------------------------------------------

    /**
     * @return array<string,array{0:string,1:mixed}>
     */
    public function scalarLiteralProvider()
    {
        return [
            'single-quoted' => ["    public static \$v = 'plain';", 'plain'],
            'single-quoted escapes' => ["    public static \$v = 'it\\'s a \\\\ backslash';", "it's a \\ backslash"],
            'double-quoted' => ['    public static $v = "plain";', 'plain'],
            'double-quoted escapes' => ['    public static $v = "a\tb\nc\\\\d\"e\$f";', "a\tb\nc\\d\"e\$f"],
            'empty string' => ["    public static \$v = '';", ''],
            'integer' => ['    public static $v = 42;', 42],
            'negative integer' => ['    public static $v = -42;', -42],
            'hex integer' => ['    public static $v = 0x1A;', 26],
            'float' => ['    public static $v = 1.5;', 1.5],
            'negative float' => ['    public static $v = -1.5;', -1.5],
            'true' => ['    public static $v = true;', true],
            'false' => ['    public static $v = false;', false],
            'null' => ['    public static $v = null;', null],
            'uppercase keyword' => ['    public static $v = TRUE;', true],
            'protected static' => ["    protected static \$v = 'plain';", 'plain'],
            'static before visibility' => ["    static public \$v = 'plain';", 'plain'],
            'grouped declaration' => ["    public static \$v = 'first', \$w = 'second';", 'first'],
        ];
    }

    /**
     * @dataProvider scalarLiteralProvider
     * @param string $body
     * @param mixed  $expected
     * @return void
     */
    public function testScalarLiteralsAreRecoveredFromSource($body, $expected)
    {
        $this->assertSame($expected, PluginSubject::scanLiteral($this->source($body), 'v'));
    }

    /**
     * @return array<string,array{0:string}>
     */
    public function unrecoverableProvider()
    {
        return [
            'array' => ['    public static $v = [1, 2, 3];'],
            'array keyword' => ['    public static $v = array(1, 2);'],
            'bare constant' => ['    public static $v = PRORATE_BILLING;'],
            'class constant' => ['    public static $v = self::SOMETHING;'],
            'concatenation' => ["    public static \$v = 'a' . 'b';"],
            'arithmetic' => ['    public static $v = 1 + 2;'],
            'no initializer' => ['    public static $v;'],
            'not static' => ["    public \$v = 'plain';"],
            'no visibility' => ["    function f() { static \$v = 'plain'; return \$v; }"],
            'method local' => ["    function f() { \$v = 'plain'; return \$v; }"],
            'different name' => ["    public static \$other = 'plain';"],
            'unmodelled escape' => ['    public static $v = "\x41";'],
        ];
    }

    /**
     * @dataProvider unrecoverableProvider
     * @param string $body
     * @return void
     */
    public function testNonLiteralInitializersAreNotGuessedAt($body)
    {
        $this->assertNull(PluginSubject::scanLiteral($this->source($body), 'v'));
    }

    /**
     * The concrete false positive this guards against: `myadmin-icontact-mailinglist`
     * declares no `$module` property at all, yet assigns a local
     * `$module = get_module_name('default');` inside `doSetup()`. A scan that ignored
     * modifiers would invent a `$module` for a plugin that has none, and A-7/A-9 would
     * both change verdict on the strength of it.
     *
     * @return void
     */
    public function testAMethodLocalAssignmentIsNotMistakenForADeclaration()
    {
        $source = $this->source(
            "    public static \$type = 'plugin';\n"
            ."    public static function doSetup()\n"
            ."    {\n"
            ."        \$module = 'invented';\n"
            ."        return \$module;\n"
            ."    }"
        );
        $this->assertNull(PluginSubject::scanLiteral($source, 'module'));
        $this->assertSame('plugin', PluginSubject::scanLiteral($source, 'type'));
    }

    /**
     * The scan must not be reachable for a property the class does not declare, whatever
     * the source text happens to contain.
     *
     * @return void
     */
    public function testTheScanIsGatedOnTheDeclarationExisting()
    {
        $subject = $this->subject(UnevaluableMetadataPlugin::class);
        $this->assertFalse($subject->hasStaticProperty('REPEAT_BILLING_METHOD'));
        $this->assertNull($subject->staticProperty('REPEAT_BILLING_METHOD'));
    }

    /**
     * The recovery must read the file and the line range of the class that **declares** the
     * property, not of the subject. Scanning the subject's own range finds nothing for an
     * inherited property and would silently report it absent.
     *
     * @return void
     */
    public function testAnInheritedPropertyIsRecoveredFromTheClassThatDeclaresIt()
    {
        $subject = $this->subject(PluginSubjectChildFixture::class);
        $this->assertTrue($subject->hasStaticProperty('type'));
        $this->assertIsString($subject->staticPropertyError('type'), 'the inherited initializer still throws');
        $this->assertSame('service', $subject->type());
        $this->assertSame('a9inherited', $subject->module());
    }

    /**
     * The subject must be usable from any directory: `getFileName()` is absolute and
     * nothing here joins a relative path.
     *
     * @return void
     */
    public function testRecoveryDoesNotDependOnTheWorkingDirectory()
    {
        $original = getcwd();
        $this->assertIsString($original);
        $this->assertTrue(chdir(sys_get_temp_dir()), 'need a different cwd to prove the point');
        try {
            $this->assertSame('module', $this->subject(UnevaluableMetadataPlugin::class)->type());
        } finally {
            chdir($original);
        }
    }
}

// ---------------------------------------------------------------------------
// Fixtures — `PluginSubject` prefix, unique per file, because this directory
// shares one process.
// ---------------------------------------------------------------------------

/** A plugin whose statics evaluate normally. */
class PluginSubjectHealthyFixture
{
    /** @var string */
    public static $type = 'service';

    /** @var string */
    public static $module = 'healthy';

    /** @var array<string,string> */
    public static $settings = ['from' => 'reflection'];
}

/**
 * Declares the metadata *and* the initializer that poisons it. Must precede the child below
 * so PHP can bind the two in one file.
 */
class PluginSubjectBaseFixture
{
    /** @var string */
    public static $type = 'service';

    /** @var string */
    public static $module = 'a9inherited';

    /** @var array<string,mixed> */
    public static $settings = ['billing' => PLUGIN_SUBJECT_FIXTURE_BILLING];
}

/** Inherits every static and declares none of its own, so its own line range holds nothing. */
class PluginSubjectChildFixture extends PluginSubjectBaseFixture
{
}

/**
 * Used by exactly one test, which defines `PLUGIN_SUBJECT_FIXTURE_LATE_BILLING` partway
 * through. Sharing it would make that define leak into another test's premise.
 */
class PluginSubjectLateConstantFixture
{
    /** @var string */
    public static $type = 'module';

    /** @var array<string,mixed> */
    public static $settings = ['billing' => PLUGIN_SUBJECT_FIXTURE_LATE_BILLING];
}
