<?php
/**
 * @author Joe Huss <detain@interserver.net>
 * @copyright 2026
 * @package MyAdmin
 * @category Testing
 */

namespace Tests\MyAdmin\Plugins\Testing\Fakes;

use MyAdmin\Plugins\Testing\CallLog;
use MyAdmin\Plugins\Testing\Fakes\FakeDb;
use PHPUnit\Framework\TestCase;

/**
 * @covers \MyAdmin\Plugins\Testing\Fakes\FakeDb
 * @covers \MyAdmin\Plugins\Testing\CallLog
 */
class FakeDbTest extends TestCase
{
    /**
     * **The landmine test.** Core's `get_module_db()` returns
     * `clone $GLOBALS['<module>_dbh']`, so a handler records into a clone while
     * the test holds the original. With a plain array property the test would
     * see nothing and pass while proving nothing — a silent false pass.
     *
     * Mutation-verified: swapping {@see CallLog} for an array property in
     * `FakeDb` makes this test fail with "0 queries recorded", which is what
     * makes it evidence rather than decoration.
     *
     * @return void
     */
    public function testCloneSharesRecordedQueries()
    {
        $original = new FakeDb();
        $clone = clone $original;
        $clone->query('SELECT 1');

        $this->assertSame(['SELECT 1'], $original->queries(), 'a query issued on a clone must be visible on the original');
    }

    /**
     * Two independently constructed fakes must NOT share a log — the failure
     * mode of the `public static $queries` approach used in
     * `myadmin-servers-module`, which makes two module handles impossible.
     *
     * @return void
     */
    public function testIndependentFakesDoNotShareALog()
    {
        $one = new FakeDb();
        $two = new FakeDb();
        $one->query('SELECT one');
        $two->query('SELECT two');

        $this->assertSame(['SELECT one'], $one->queries());
        $this->assertSame(['SELECT two'], $two->queries());
    }

    /**
     * @return void
     */
    public function testFakesCanBeMadeToShareALogDeliberately()
    {
        $log = new CallLog();
        $one = new FakeDb([], $log);
        $two = new FakeDb([], $log);
        $one->query('a');
        $two->query('b');

        $this->assertSame(['a', 'b'], $one->queries());
    }

    /**
     * @return void
     */
    public function testCloneContinuesTheRowCursor()
    {
        $db = new FakeDb([['id' => 1], ['id' => 2]]);
        $db->next_record();
        $this->assertSame(['id' => 1], $db->Record);

        $clone = clone $db;
        $this->assertTrue($clone->next_record());
        $this->assertSame(['id' => 2], $clone->Record, 'a clone must continue the shared cursor, as a real result set does');
    }

    /**
     * @return void
     */
    public function testNextRecordWalksRowsThenReturnsFalse()
    {
        $db = new FakeDb([['a' => 1], ['a' => 2]]);
        $this->assertTrue($db->next_record());
        $this->assertSame(['a' => 1], $db->Record);
        $this->assertTrue($db->next_record());
        $this->assertSame(['a' => 2], $db->Record);
        $this->assertFalse($db->next_record());
        $this->assertSame([], $db->Record);
    }

    /**
     * @return void
     */
    public function testQueryRewindsTheCursor()
    {
        $db = new FakeDb([['a' => 1]]);
        $db->next_record();
        $db->query('SELECT again');
        $this->assertTrue($db->next_record(), 'query() must rewind so the same rows are readable again');
    }

    /**
     * @return void
     */
    public function testRecordsLineAndFileArguments()
    {
        $db = new FakeDb();
        $db->query('SELECT 1', 42, '/path/file.php');
        $args = $db->argsFor('query');
        $this->assertSame(['SELECT 1', 42, '/path/file.php'], $args[0]);
    }

    /**
     * @return void
     */
    public function testLastQueryAndQueried()
    {
        $db = new FakeDb();
        $db->query('SELECT a');
        $db->query('UPDATE b SET c=1');
        $this->assertSame('UPDATE b SET c=1', $db->lastQuery());
        $this->assertTrue($db->queried('UPDATE b'));
        $this->assertFalse($db->queried('DELETE'));
    }

    /**
     * @return void
     */
    public function testRealEscapeActuallyEscapes()
    {
        $db = new FakeDb();
        $this->assertSame("O\\'Brien", $db->real_escape("O'Brien"), 'the fake must genuinely escape, so an escaping assertion means something');
    }

    /**
     * @return void
     */
    public function testQrReturnsFirstRowOrFalse()
    {
        $db = new FakeDb([['id' => 7]]);
        $this->assertSame(['id' => 7], $db->qr('SELECT ...'));

        $empty = new FakeDb();
        $this->assertFalse($empty->qr('SELECT ...'));
    }

    /**
     * @return void
     */
    public function testFReadsByNameOrPosition()
    {
        $db = new FakeDb([['id' => 7, 'name' => 'x']]);
        $db->next_record();
        $this->assertSame(7, $db->f('id'));
        $this->assertSame('x', $db->f(1));
    }

    /**
     * @return void
     */
    public function testResetKeepsLogIdentitySoClonesStayAttached()
    {
        $db = new FakeDb();
        $clone = clone $db;
        $db->reset();
        $clone->query('after reset');

        $this->assertSame(['after reset'], $db->queries(), 'reset() must not break sharing with existing clones');
    }

    /**
     * Structural compatibility with the published contract, asserted only when
     * `detain/myadmin-contracts` is installed. The class deliberately does not
     * `implements` it — the installer does not require that package, and
     * implementing an unloaded interface is fatal.
     *
     * @return void
     */
    public function testMatchesDatabaseInterfaceWhenContractsArePresent()
    {
        if (!interface_exists('MyAdmin\App\Contracts\DatabaseInterface')) {
            $this->markTestSkipped('detain/myadmin-contracts is not installed in this environment');
        }
        $interface = new \ReflectionClass('MyAdmin\App\Contracts\DatabaseInterface');
        $fake = new \ReflectionClass(FakeDb::class);
        foreach ($interface->getMethods() as $method) {
            $this->assertTrue(
                $fake->hasMethod($method->getName()),
                'FakeDb is missing DatabaseInterface::' . $method->getName() . '()'
            );
        }
    }
}
