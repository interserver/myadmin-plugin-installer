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
use MyAdmin\Plugins\Testing\TestContainerBuilder;
use PHPUnit\Framework\TestCase;

/**
 * Pins the container plumbing that turns on tests real repos already wrote and
 * that have never once executed.
 *
 * `myadmin-virtuozzo-vps` binds a `tf` whose `get_service_define()` returns a
 * sentinel so that **no** service type matches, then asserts the handler stays
 * inert — Phase 3's Assertion B, written a year early and dead because
 * `\MyAdmin\App\Testing\TestContainerBuilder` lives in the core tree and in no
 * package. Plan §0.5 recorded the same dead branch in `myadmin-vps-module`.
 *
 * @covers \MyAdmin\Plugins\Testing\TestContainerBuilder
 * @covers \MyAdmin\Plugins\Testing\TestContainer
 */
class TestContainerTest extends TestCase
{
    /**
     * @return void
     */
    protected function setUp(): void
    {
        Bootstrap::init(['module' => 'containertest']);
        Harness::reset();
        FakeApp::setContainer(null);
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        FakeApp::setContainer(null);
    }

    /**
     * The alias is what makes existing repo call sites resolve.
     *
     * @return void
     */
    public function testCoreClassNameIsAvailable()
    {
        $this->assertTrue(
            class_exists('MyAdmin\App\Testing\TestContainerBuilder', false),
            'repos call this unguarded; without the alias their tests fatal'
        );
        $this->assertTrue(is_a('MyAdmin\App\Testing\TestContainerBuilder', TestContainerBuilder::class, true));
    }

    /**
     * @return void
     */
    public function testBuilderRoundTripsABoundService()
    {
        $tf = new \stdClass();
        $container = TestContainerBuilder::make()->withTf($tf)->build();

        $this->assertTrue($container->has('MyAdmin\tf'));
        $this->assertSame($tf, $container->get('MyAdmin\tf'));
        $this->assertSame(['MyAdmin\tf'], $container->ids());
    }

    /**
     * @return void
     */
    public function testUnboundIdIsNullRatherThanThrowing()
    {
        $container = TestContainerBuilder::make()->build();
        $this->assertFalse($container->has('MyAdmin\tf'));
        $this->assertNull($container->get('MyAdmin\tf'));
    }

    /**
     * **The virtuozzo pattern, verbatim.** Core implements
     * `App::getServiceDefine()` as `self::tf()->get_service_define($service)`,
     * so binding a tf that returns a sentinel makes every type foreign — which
     * is exactly how a "handler must stay inert for a type it does not own"
     * assertion is driven.
     *
     * @return void
     */
    public function testBoundTfDrivesGetServiceDefine()
    {
        $stub = new class {
            /**
             * @param string $name
             * @return int
             */
            public function get_service_define($name)
            {
                return -9999;
            }
        };
        \MyAdmin\App::setContainer(TestContainerBuilder::make()->withTf($stub)->build());

        $this->assertSame(-9999, \MyAdmin\App::getServiceDefine('KVM_LINUX'));
        $this->assertSame(-9999, get_service_define('ANYTHING'), 'the installer global must route through it too');
    }

    /**
     * With no container bound, the harness's own define map is used — so the
     * two mechanisms coexist rather than one disabling the other.
     *
     * @return void
     */
    public function testHarnessDefinesApplyWhenNoTfIsBound()
    {
        Bootstrap::init(['module' => 'containertest', 'defines' => ['KVM_LINUX' => 2]]);
        FakeApp::setContainer(null);
        $this->assertSame(2, get_service_define('KVM_LINUX'));
    }

    /**
     * @return void
     */
    public function testBoundTfDrivesFunctionRequirements()
    {
        $stub = new class {
            /**
             * @param string $function
             * @return bool
             */
            public function function_requirements($function)
            {
                return false;
            }
        };
        \MyAdmin\App::setContainer(TestContainerBuilder::make()->withTf($stub)->build());
        $this->assertFalse(function_requirements('anything'));
    }

    /**
     * @return void
     */
    public function testAppTfReturnsTheBoundObject()
    {
        $stub = new \stdClass();
        $stub->marker = 'bound';
        \MyAdmin\App::setContainer(TestContainerBuilder::make()->withTf($stub)->build());
        $this->assertSame($stub, \MyAdmin\App::tf());
    }

    /**
     * @return void
     */
    public function testResetContainerClearsTheBinding()
    {
        \MyAdmin\App::setContainer(TestContainerBuilder::make()->withTf(new \stdClass())->build());
        \MyAdmin\App::resetContainer();
        $this->assertNull(\MyAdmin\App::container());
    }

    /**
     * Every `with*()` helper must bind under the id core uses, or a plugin
     * reading that service gets null and the test proves nothing.
     *
     * @param string $method
     * @param string $expectedId
     * @return void
     * @dataProvider builderHelperProvider
     */
    public function testBuilderHelpersBindUnderCoreIds($method, $expectedId)
    {
        $container = call_user_func([TestContainerBuilder::make(), $method], new \stdClass())->build();
        $this->assertSame([$expectedId], $container->ids());
    }

    /**
     * @return array<string,array<int,string>>
     */
    public function builderHelperProvider()
    {
        return [
            'withTf'        => ['withTf', 'MyAdmin\tf'],
            'withDb'        => ['withDb', 'MyAdmin\App\Contracts\DatabaseInterface'],
            'withSession'   => ['withSession', 'MyAdmin\App\Contracts\SessionInterface'],
            'withAccounts'  => ['withAccounts', 'MyAdmin\App\Contracts\AccountsInterface'],
            'withRequest'   => ['withRequest', 'MyAdmin\App\Contracts\RequestContextInterface'],
            'withVariables' => ['withVariables', 'MyAdmin\Variables'],
            'withOutput'    => ['withOutput', 'MyAdmin\App\Output'],
        ];
    }
}
