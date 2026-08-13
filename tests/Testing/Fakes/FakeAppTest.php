<?php
/**
 * @author Joe Huss <detain@interserver.net>
 * @copyright 2026
 * @package MyAdmin
 * @category Testing
 */

namespace Tests\MyAdmin\Plugins\Testing\Fakes;

use MyAdmin\Plugins\Testing\Bootstrap;
use MyAdmin\Plugins\Testing\Fakes\FakeApp;
use MyAdmin\Plugins\Testing\Harness;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * @covers \MyAdmin\Plugins\Testing\Fakes\FakeApp
 */
class FakeAppTest extends TestCase
{
    /**
     * @return void
     */
    protected function setUp(): void
    {
        Bootstrap::init(['module' => 'fakeapptest']);
        Harness::reset();
    }

    /**
     * Every accessor the fleet measurement found, with its usage count. A
     * missing one is a fatal `Error` in whichever plugin reaches for it.
     *
     * @param string $method
     * @return void
     * @dataProvider measuredSurfaceProvider
     */
    public function testMeasuredSurfaceIsPresentAndStatic($method)
    {
        $reflection = new ReflectionClass(FakeApp::class);
        $this->assertTrue($reflection->hasMethod($method), 'FakeApp::' . $method . '() is missing');
        $this->assertTrue($reflection->getMethod($method)->isStatic(), 'FakeApp::' . $method . '() must be static');
        $this->assertTrue($reflection->getMethod($method)->isPublic(), 'FakeApp::' . $method . '() must be public');
    }

    /**
     * Counts are the measured fleet reference totals from the Phase 0 baseline.
     *
     * @return array<string,array<int,string>>
     */
    public function measuredSurfaceProvider()
    {
        return [
            'variables (429)'   => ['variables'],
            'history (130)'     => ['history'],
            'accounts (127)'    => ['accounts'],
            'ima (117)'         => ['ima'],
            'session (88)'      => ['session'],
            'decrypt (55)'      => ['decrypt'],
            'link (43)'         => ['link'],
            'db (34)'           => ['db'],
            'output (24)'       => ['output'],
            'events (7)'        => ['events'],
            'encrypt (7)'       => ['encrypt'],
            'tf (6)'            => ['tf'],
            'has'               => ['has'],
            'getServiceDefine'  => ['getServiceDefine'],
            'functionRequirements' => ['functionRequirements'],
        ];
    }

    /**
     * @return void
     */
    public function testHistoryAddIsRecordedWithItsArguments()
    {
        \MyAdmin\App::history()->add('vps', 'change_status', 'active', 'pending', 42);

        $entries = Harness::history()->entries();
        $this->assertCount(1, $entries);
        $this->assertSame('vps', $entries[0]['section']);
        $this->assertSame('change_status', $entries[0]['type']);
        $this->assertSame('active', $entries[0]['new']);
        $this->assertSame('pending', $entries[0]['old']);
        $this->assertSame(42, $entries[0]['custid']);
    }

    /**
     * @return void
     */
    public function testEncryptDecryptRoundTrips()
    {
        $this->assertSame('secret', \MyAdmin\App::decrypt(\MyAdmin\App::encrypt('secret')));
    }

    /**
     * A value that was never encrypted must survive decrypt() unchanged —
     * plugin code decrypts uniformly over mixed data.
     *
     * @return void
     */
    public function testDecryptOfPlaintextIsIdentity()
    {
        $this->assertSame('not-encrypted', \MyAdmin\App::decrypt('not-encrypted'));
    }

    /**
     * `App::ima()` is the panel being rendered. The account's real role is
     * `accounts()->data['ima']`. Conflating them is a live bug class, so the
     * two must be independently settable.
     *
     * @return void
     */
    public function testImaAndAccountRoleAreIndependent()
    {
        Bootstrap::init(['module' => 'fakeapptest', 'ima' => 'client']);
        Harness::accounts()->setData(['ima' => 'admin']);

        $this->assertSame('client', \MyAdmin\App::ima(), 'ima() is the current panel');
        $this->assertSame('admin', \MyAdmin\App::accounts()->data['ima'], "data['ima'] is the account's real role");
        $this->assertFalse(\MyAdmin\App::isAdmin(), 'isAdmin() follows the panel, not the account');
    }

    /**
     * @return void
     */
    public function testImaCanBeDrivenToAdmin()
    {
        Bootstrap::init(['module' => 'fakeapptest', 'ima' => 'admin']);
        $this->assertSame('admin', \MyAdmin\App::ima());
        $this->assertTrue(\MyAdmin\App::isAdmin());
        Bootstrap::init(['module' => 'fakeapptest', 'ima' => 'client']);
    }

    /**
     * @return void
     */
    public function testVariablesExposesRequestThroughBothSpellings()
    {
        Bootstrap::init(['module' => 'fakeapptest', 'request' => ['id' => '7']]);

        $this->assertSame('7', \MyAdmin\App::variables()->request('id'));
        $this->assertSame('7', \MyAdmin\App::variables()->request['id'], 'array access must agree with the accessor');
        $this->assertSame('7', \MyAdmin\App::variables()->get('id'));
        $this->assertSame('7', \MyAdmin\App::variables()->postRaw('id'));
    }

    /**
     * @return void
     */
    public function testUnknownRequestKeyReturnsTheDefault()
    {
        $this->assertSame('fallback', \MyAdmin\App::variables()->request('missing', 'fallback'));
    }

    /**
     * The legacy `tf` shim must expose the same fakes, or a handler using
     * `App::tf()->db` would silently record somewhere else.
     *
     * @return void
     */
    public function testTfShimExposesTheSameFakes()
    {
        $tf = \MyAdmin\App::tf();
        $this->assertSame(Harness::db(), $tf->db);
        $this->assertSame(Harness::history(), $tf->history);
        $this->assertSame(Harness::session(), $tf->session);
    }

    /**
     * @return void
     */
    public function testLinkBuildsAUrl()
    {
        $this->assertSame('/index.php', \MyAdmin\App::link('/index.php'));
        $this->assertSame('/index.php?choice=none.x', \MyAdmin\App::link('/index.php', 'choice=none.x'));
    }

    /**
     * @return void
     */
    public function testFacadeCallsAreThemselvesRecorded()
    {
        \MyAdmin\App::ima();
        \MyAdmin\App::history();
        $this->assertTrue(FakeApp::callLog()->wasCalled('ima'));
        $this->assertTrue(FakeApp::callLog()->wasCalled('history'));
    }

    /**
     * @return void
     */
    public function testResetClearsFakesWithoutSwappingThem()
    {
        $history = Harness::history();
        \MyAdmin\App::history()->add('vps', 'x', 'y');
        $this->assertCount(1, $history->entries());

        FakeApp::reset();

        $this->assertCount(0, $history->entries());
        $this->assertSame($history, \MyAdmin\App::history());
    }
}
