<?php
/**
 * @author Joe Huss <detain@interserver.net>
 * @copyright 2026
 * @package MyAdmin
 * @category Testing
 */

namespace Tests\MyAdmin\Plugins\Testing;

use MyAdmin\Plugins\Testing\Bootstrap;
use MyAdmin\Plugins\Testing\Fakes\FakeApp;
use MyAdmin\Plugins\Testing\Harness;
use MyAdmin\Plugins\Testing\Log;
use PHPUnit\Framework\TestCase;

/**
 * The integration this whole phase turns on: `Bootstrap::init()` must make the
 * installer's **real, unmodified** `autoload.files` functions work against the
 * fakes, rather than shadowing them.
 *
 * Those four functions — `get_module_settings()`, `get_module_db()`,
 * `get_service_define()`, `function_requirements()` — are defined at autoload
 * time into every production install, so a `function_exists()`-guarded stub of
 * them is dead code. Plan §629 called this out and proposed `FakeApp` as the
 * resolution; these tests are the proof that it works.
 *
 * @covers \MyAdmin\Plugins\Testing\Bootstrap
 * @covers \MyAdmin\Plugins\Testing\Fakes\FakeApp
 */
class BootstrapTest extends TestCase
{
    /**
     * @return void
     */
    protected function setUp(): void
    {
        Bootstrap::init(['module' => 'harnesstest', 'acl' => ['client_billing']]);
        Harness::reset();
    }

    /**
     * R9: the single assumption most likely to invalidate Phase 1.
     *
     * @return void
     */
    public function testInstallsFakeAppUnderTheRealClassName()
    {
        $this->assertTrue(class_exists('MyAdmin\App', false), '\MyAdmin\App must exist after init()');
        $this->assertTrue(is_a('MyAdmin\App', FakeApp::class, true));
    }

    /**
     * A second `class_alias()` to an existing name emits a warning and returns
     * false. With `failOnWarning="true"` that would turn every repo's second
     * `setUp()` into a failure, so the guard is load-bearing.
     *
     * @return void
     */
    public function testInitIsIdempotent()
    {
        Bootstrap::init(['module' => 'harnesstest']);
        Bootstrap::init(['module' => 'harnesstest']);
        $this->assertTrue(class_exists('MyAdmin\App', false));
        $this->assertFalse(Bootstrap::installApp(), 'installApp() must report it did not re-alias');
    }

    // -----------------------------------------------------------------------
    // The four functions the installer defines into production autoload
    // -----------------------------------------------------------------------

    /**
     * `get_module_settings()` reads `$GLOBALS['modules']` and needs no fake at
     * all — `register_module()` is enough. The plan listed it among the four
     * that "cannot be stubbed"; it turns out it does not need to be.
     *
     * @return void
     */
    public function testRealGetModuleSettingsReturnsRegisteredSettings()
    {
        Bootstrap::init(['module' => 'vps', 'settings' => ['PREFIX' => 'vps', 'TABLE' => 'vps', 'TBLNAME' => 'VPS']]);

        $settings = get_module_settings('vps');
        $this->assertIsArray($settings);
        $this->assertSame('vps', $settings['PREFIX']);
        $this->assertSame('VPS', $settings['TBLNAME']);
        $this->assertSame('vps', get_module_settings('vps', 'PREFIX'));
    }

    /**
     * @return void
     */
    public function testRegisterModuleDerivesUsableDefaults()
    {
        Bootstrap::init(['module' => 'licenses']);
        $settings = get_module_settings('licenses');
        $this->assertSame('licenses', $settings['PREFIX']);
        $this->assertSame('LICENSES', $settings['TBLNAME']);
    }

    /**
     * `get_service_define()` is a pure delegation to `\MyAdmin\App`, so the
     * alias makes the real function work.
     *
     * @return void
     */
    public function testRealGetServiceDefineResolvesThroughFakeApp()
    {
        Bootstrap::init(['module' => 'vps', 'defines' => ['KVM_LINUX' => 2]]);
        $this->assertSame(2, get_service_define('KVM_LINUX'));
    }

    /**
     * An unmapped name must not throw — a handler comparing against it should
     * still run so the test sees the whole path.
     *
     * @return void
     */
    public function testUnmappedServiceDefineReturnsSyntheticId()
    {
        $value = get_service_define('SOME_UNMAPPED_TYPE');
        $this->assertIsInt($value);
        $this->assertSame(FakeApp::syntheticDefine('SOME_UNMAPPED_TYPE'), $value);
    }

    /**
     * Synthetic ids must not collide with each other, or a "foreign type"
     * assertion would silently compare equal to a handled one.
     *
     * @return void
     */
    public function testSyntheticDefinesAreDistinctForDistinctNames()
    {
        $a = FakeApp::syntheticDefine('KVM_LINUX');
        $b = FakeApp::syntheticDefine('OPENVZ_LINUX');
        $this->assertNotSame($a, $b);
    }

    /**
     * @return void
     */
    public function testRealFunctionRequirementsResolvesThroughFakeApp()
    {
        $this->assertTrue(function_requirements('vps_add'));

        FakeApp::setMissingRequirements(['missing_thing']);
        $this->assertFalse(function_requirements('missing_thing'), 'the failure branch must be drivable');
        FakeApp::setMissingRequirements([]);
    }

    /**
     * The one with teeth: the real implementation returns
     * `clone $GLOBALS['<module>_dbh']`, and the clone must still record.
     *
     * @return void
     */
    public function testRealGetModuleDbReturnsARecordingFake()
    {
        Bootstrap::init(['module' => 'vps']);
        Harness::reset();

        $db = get_module_db('vps');
        $db->query('SELECT from handler');

        $this->assertSame(['SELECT from handler'], Harness::db()->queries(), 'a query through the returned clone must reach the harness fake');
    }

    /**
     * `powerdns` and `zonemta` are hardcoded special cases in the installer
     * that construct a real `\MyDb\Mdb2\Db` / `\MyDb\Mysqli\Db` against
     * undefined connection constants. Presetting the global handle skips that
     * construction entirely, so they need no special casing in the harness.
     *
     * Without this, `myadmin-powerdns` and `myadmin-zonemta-mail` would fatal
     * with `Class "MyDb\Mdb2\Db" not found` — verified by spike.
     *
     * @param string $module
     * @return void
     * @dataProvider hardcodedModuleProvider
     */
    public function testHardcodedModuleDbBranchesAreBypassed($module)
    {
        Bootstrap::init(['module' => $module]);
        Harness::reset();

        $db = get_module_db($module);
        $db->query('SELECT special');

        $this->assertSame(['SELECT special'], Harness::db()->queries());
    }

    /**
     * @return array<string,array<int,string>>
     */
    public function hardcodedModuleProvider()
    {
        return ['powerdns' => ['powerdns'], 'zonemta' => ['zonemta']];
    }

    /**
     * A module the harness was never configured for still has to work: the
     * installer's fallback path logs, then returns either `clone App::db()` or
     * `clone $default_dbh` depending on `App::has()`. `Bootstrap` seeds **both**
     * sides deliberately, so the plugin gets a working recording handle either
     * way.
     *
     * That belt-and-braces is why flipping `FakeApp::has()` to false is an
     * *equivalent mutation* — it cannot change observable behaviour. Recorded
     * here so a future reader does not mistake it for a coverage gap.
     *
     * @return void
     */
    public function testUnregisteredModuleFallsBackToARecordingHandle()
    {
        Bootstrap::init(['module' => 'harnesstest']);
        Harness::reset();

        $db = get_module_db('never_registered_module');
        $db->query('SELECT fallback');

        $this->assertSame(['SELECT fallback'], Harness::db()->queries());
        $this->assertTrue(
            Log::hasEntryContaining('never_registered_module'),
            'the installer logs this fallback; the harness must capture it rather than fatal on an undefined myadmin_log()'
        );
    }

    /**
     * Both sides of the `App::has()` branch must yield a working handle.
     *
     * @return void
     */
    public function testFallbackWorksWhicheverWayAppHasAnswers()
    {
        Bootstrap::init(['module' => 'harnesstest']);
        Harness::reset();

        $this->assertTrue(isset($GLOBALS['default_dbh']), 'the $default_dbh branch must be seeded too');
        $this->assertSame(Harness::db(), $GLOBALS['default_dbh']);
        $this->assertSame(Harness::db(), \MyAdmin\App::db());
    }

    // -----------------------------------------------------------------------
    // Global stubs
    // -----------------------------------------------------------------------

    /**
     * @return void
     */
    public function testMyadminLogIsCapturedNotDiscarded()
    {
        myadmin_log('vps', 'error', 'something broke', __LINE__, __FILE__);
        $entries = Log::entriesAtLevel('error');
        $this->assertCount(1, $entries);
        $this->assertSame('something broke', $entries[0]['text']);
        $this->assertSame('vps', $entries[0]['section']);
    }

    /**
     * @return void
     */
    public function testHasAclAnswersFromTheAllowlistInBothDirections()
    {
        Bootstrap::init(['module' => 'harnesstest', 'acl' => ['client_billing']]);
        $this->assertTrue(has_acl('client_billing'));
        $this->assertFalse(has_acl('admin_billing'), 'the denied branch must be reachable, or half of getMenu() is untested');
    }

    /**
     * @return void
     */
    public function testAclCanGrantEverything()
    {
        Bootstrap::init(['module' => 'harnesstest', 'acl' => true]);
        $this->assertTrue(has_acl('anything_at_all'));
        Bootstrap::init(['module' => 'harnesstest', 'acl' => []]);
    }

    /**
     * @return void
     */
    public function testAddOutputBuffersRatherThanEchoing()
    {
        add_output('hello');
        $this->assertSame('hello', Harness::output()->get());
        $this->assertTrue(Harness::output()->contains('hell'));
    }

    /**
     * @return void
     */
    public function testMakeInsertQueryBuildsRealSql()
    {
        $query = make_insert_query('vps', ['vps_id' => 5, 'vps_hostname' => "o'brien"]);
        $this->assertSame("insert into vps (vps_id, vps_hostname) values (5, 'o\\'brien')", $query);
    }

    /**
     * @return void
     */
    public function testMakeInsertQueryHandlesDuplicateArgs()
    {
        $query = make_insert_query('vps', ['a' => 1], ['b' => 2]);
        $this->assertStringContainsString('on duplicate key update b=2', $query);
    }

    /**
     * @return void
     */
    public function testMakeInsertQueryOfEmptyArgsIsEmpty()
    {
        $this->assertSame('', make_insert_query('vps', []));
    }

    /**
     * @return void
     */
    public function testGetServiceReturnsSeededRowOrFalse()
    {
        Harness::setService(42, 'vps', ['vps_id' => 42, 'vps_custid' => 7]);
        $service = get_service(42, 'vps');
        $this->assertSame(7, $service['vps_custid']);
        $this->assertFalse(get_service(99, 'vps'), 'an unknown service must be false, as core returns');
    }

    /**
     * @return void
     */
    public function testRunEventIsRecorded()
    {
        run_event('vps.activate', ['id' => 1], 'vps');
        $this->assertTrue(Harness::events()->wasDispatched('vps.activate'));
    }

    /**
     * `dialog()` writes to the output buffer rather than echoing, so
     * `beStrictAboutOutputDuringTests` stays satisfied.
     *
     * @return void
     */
    public function testDialogDoesNotEchoAndIsRecorded()
    {
        dialog('Title', 'Body');
        $this->assertCount(1, Harness::dialogs());
        $this->assertStringContainsString('Title', Harness::output()->get());
    }

    /**
     * @return void
     */
    public function testDialogReturnsMarkupWhenAsked()
    {
        $markup = dialog('T', 'B', true);
        $this->assertStringContainsString('T', $markup);
        $this->assertTrue(Harness::output()->isEmpty(), 'with $return=true nothing should be emitted');
    }

    /**
     * @return void
     */
    public function testGeneratePasswordIsDeterministicAndRespectsLength()
    {
        $this->assertSame(generate_password(12), generate_password(12), 'a random password makes assertions impossible');
        $this->assertSame(12, strlen(generate_password(12)));
        $this->assertSame(8, strlen(generate_password()), 'core default length is 8');
    }

    /**
     * @return void
     */
    public function testGettextPassthrough()
    {
        $this->assertSame('Hello', _('Hello'));
    }

    // -----------------------------------------------------------------------
    // Constants
    // -----------------------------------------------------------------------

    /**
     * `MYSQL_ASSOC` is a core `define()`, not a PHP built-in — `MYSQLI_ASSOC`
     * is the built-in. Six plugins pass it to `next_record()`.
     *
     * @return void
     */
    public function testBaseConstantsAreDefined()
    {
        $this->assertTrue(defined('MYSQL_ASSOC'));
        $this->assertTrue(defined('MYSQL_NUM'));
        $this->assertTrue(defined('MYSQL_BOTH'));
    }

    /**
     * @return void
     */
    public function testConstantOverridesAreApplied()
    {
        Bootstrap::init(['module' => 'harnesstest', 'constants' => ['HARNESS_BOOTSTRAP_OVERRIDE' => 7]]);
        $this->assertSame(7, constant('HARNESS_BOOTSTRAP_OVERRIDE'));
    }

    // -----------------------------------------------------------------------
    // Reset semantics
    // -----------------------------------------------------------------------

    /**
     * `reset()` must clear recordings but keep the fake objects, so a
     * reference grabbed in `setUp()` keeps working.
     *
     * @return void
     */
    public function testResetClearsRecordingsButKeepsFakeIdentity()
    {
        $db = Harness::db();
        $db->query('before');
        myadmin_log('vps', 'info', 'before');

        Harness::reset();

        $this->assertSame([], $db->queries());
        $this->assertSame(0, Log::count());
        $this->assertSame($db, Harness::db(), 'reset() must not swap the fake out from under a held reference');
    }

    /**
     * @return void
     */
    public function testResetDoesNotTouchConstants()
    {
        Bootstrap::init(['module' => 'harnesstest', 'constants' => ['HARNESS_RESET_PROBE' => 'kept']]);
        Harness::reset();
        $this->assertSame('kept', constant('HARNESS_RESET_PROBE'), 'constants are immutable; reset() must not try');
    }

    // -----------------------------------------------------------------------
    // Namespace-scoped stubs
    // -----------------------------------------------------------------------

    /**
     * The mechanism that lets a plugin-specific helper be stubbed: an
     * unqualified call resolves against the plugin's own namespace first.
     *
     * @return void
     */
    public function testStubNamespaceDeclaresAFunctionThatWinsOverGlobal()
    {
        $namespace = 'Tests\\MyAdmin\\Plugins\\Testing\\NsProbe';
        Bootstrap::stubNamespace($namespace, [
            'vps_get_password' => static function ($id, $custid) {
                return 'pass-' . $id . '-' . $custid;
            },
        ]);
        $this->assertTrue(function_exists($namespace . '\\vps_get_password'));
        $this->assertSame('pass-1-2', call_user_func($namespace . '\\vps_get_password', 1, 2));
    }

    /**
     * A namespace-scoped `get_module_db` must beat the installer's global one
     * — this is the property the whole mechanism rests on.
     *
     * @return void
     */
    public function testNamespaceScopedStubBeatsTheInstallerGlobal()
    {
        $namespace = 'Tests\\MyAdmin\\Plugins\\Testing\\NsProbe2';
        Bootstrap::stubNamespace($namespace, [
            'get_module_db' => static function ($module) {
                return 'NS-WINS:' . $module;
            },
        ]);
        eval('namespace ' . $namespace . '; function probe() { return get_module_db("vps"); }');
        $this->assertSame('NS-WINS:vps', call_user_func($namespace . '\\probe'));
    }

    /**
     * PHP cannot redeclare a function, so the implementation must be swappable
     * through the dispatch table rather than by redeclaring.
     *
     * @return void
     */
    public function testNamespaceStubImplementationCanBeSwapped()
    {
        $namespace = 'Tests\\MyAdmin\\Plugins\\Testing\\NsProbe3';
        Bootstrap::stubNamespace($namespace, ['thing' => static function () {
            return 'first';
        }]);
        $this->assertSame('first', call_user_func($namespace . '\\thing'));

        Bootstrap::setNamespaceStub($namespace . '\\thing', static function () {
            return 'second';
        });
        $this->assertSame('second', call_user_func($namespace . '\\thing'));
    }

    /**
     * @return void
     */
    public function testStubNamespaceIsIdempotent()
    {
        $namespace = 'Tests\\MyAdmin\\Plugins\\Testing\\NsProbe4';
        $first = Bootstrap::stubNamespace($namespace, ['dup' => static function () {
            return 1;
        }]);
        $second = Bootstrap::stubNamespace($namespace, ['dup' => static function () {
            return 2;
        }]);
        $this->assertCount(1, $first);
        $this->assertSame([], $second, 'a second declaration would be a fatal redeclare');
    }
}
