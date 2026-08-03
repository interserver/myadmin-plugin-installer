<?php
/**
 * @author Joe Huss <detain@interserver.net>
 * @copyright 2026
 * @package MyAdmin
 * @category Testing
 */

namespace MyAdmin\Plugins\Testing\Fakes;

use MyAdmin\Plugins\Testing\CallLog;
use MyAdmin\Plugins\Testing\Recorder;

/**
 * Stand-in for the module database handle (`MyDb\Generic` / `MyDb\Mysqli\Db`),
 * promoted from `myadmin-vps-module/tests/Fakes/FakeDb.php` and the `DbSpy` in
 * `myadmin-servers-module`.
 *
 * ## The clone landmine
 *
 * Core's `get_module_db()` returns **`clone $GLOBALS['<module>_dbh']`**. A fake
 * that recorded into a plain array property would hand the handler a clone,
 * the handler would record into the clone, and the test would then assert
 * against an empty original and pass while proving nothing.
 *
 * `myadmin-servers-module` solved this with `public static $queries`, which
 * works but makes two independent fakes impossible in one process. This class
 * uses the shared {@see CallLog} object instead: shallow clone copies the
 * *reference*, so every clone writes to the same log, and two fakes
 * constructed with different logs stay independent.
 *
 * `FakeDbTest::testCloneSharesRecordedQueries` pins this behaviour and was
 * verified to fail when the CallLog is swapped for an array property.
 *
 * ## Contract compatibility
 *
 * The method set matches `MyAdmin\App\Contracts\DatabaseInterface` from
 * `detain/myadmin-contracts`. This class deliberately does **not** `implements`
 * it: the installer does not require that package, and implementing an
 * interface that is not loaded is a fatal error. `FakeDbTest` asserts
 * structural compatibility whenever the interface *is* present, which gives
 * the guarantee without the dependency.
 */
class FakeDb
{
    use Recorder;

    /**
     * Current row, as `next_record()` leaves it. Public because callers read
     * `$db->Record['col']` directly, exactly as they do against the real class.
     *
     * @var array<string,mixed>
     */
    public $Record = [];

    /**
     * Mirrors the public `$Type` on the real handle, which core's
     * `get_module_db()` assigns for the powerdns/zonemta branches.
     *
     * @var string
     */
    public $Type = 'mysqli';

    /**
     * Rows handed out by successive `next_record()` calls.
     *
     * Shared object, for the same clone reason as the call log.
     *
     * @var \ArrayObject<int,array<string,mixed>>
     */
    private $rows;

    /**
     * Cursor into $rows. Shared so a clone continues where the original left
     * off, matching how a real result set behaves.
     *
     * @var \ArrayObject<string,int>
     */
    private $cursor;

    /**
     * @var int value returned by getLastInsertId()
     */
    private $lastInsertId = 1;

    /**
     * @param array<int,array<string,mixed>>       $rows rows to hand out via next_record()
     * @param \MyAdmin\Plugins\Testing\CallLog|null $log  shared log, see {@see Recorder}
     */
    public function __construct(array $rows = [], ?CallLog $log = null)
    {
        $this->initRecorder($log);
        $this->rows = new \ArrayObject(array_values($rows));
        $this->cursor = new \ArrayObject(['i' => 0]);
    }

    // -----------------------------------------------------------------------
    // DatabaseInterface surface
    // -----------------------------------------------------------------------

    /**
     * @param string     $query
     * @param int|string $line
     * @param string     $file
     * @return bool
     */
    public function query($query = '', $line = '', $file = '')
    {
        $this->record(__FUNCTION__, [$query, $line, $file]);
        $this->cursor['i'] = 0;
        return true;
    }

    /**
     * @param int|null $type mirrors the core signature; unused
     * @return bool whether a row was loaded
     */
    public function next_record($type = null)
    {
        $this->record(__FUNCTION__, [$type]);
        $index = $this->cursor['i'];
        if (!isset($this->rows[$index])) {
            $this->Record = [];
            return false;
        }
        $this->Record = $this->rows[$index];
        $this->cursor['i'] = $index + 1;
        return true;
    }

    /**
     * @return int
     */
    public function num_rows()
    {
        $this->record(__FUNCTION__, []);
        return count($this->rows);
    }

    /**
     * @return int
     */
    public function affectedRows()
    {
        $this->record(__FUNCTION__, []);
        return count($this->rows);
    }

    /**
     * Escapes exactly as `addslashes()` would. Not real MySQL escaping — this
     * is a fake — but it is *an* escape, so a test asserting that user input
     * was escaped before interpolation still means something.
     *
     * @param string $str
     * @return string
     */
    public function real_escape($str)
    {
        $this->record(__FUNCTION__, [$str]);
        return addslashes((string)$str);
    }

    /**
     * @param string $table
     * @param string $column
     * @return int
     */
    public function getLastInsertId($table, $column)
    {
        $this->record(__FUNCTION__, [$table, $column]);
        return $this->lastInsertId;
    }

    /**
     * Query-and-read-one-row convenience, as on the real handle.
     *
     * @param string $query
     * @return array<string,mixed>|false
     */
    public function qr($query)
    {
        $this->record(__FUNCTION__, [$query]);
        $this->cursor['i'] = 0;
        if (!isset($this->rows[0])) {
            return false;
        }
        $this->Record = $this->rows[0];
        $this->cursor['i'] = 1;
        return $this->rows[0];
    }

    /**
     * Field N (or named field) of the current Record.
     *
     * @param int|string $field
     * @return mixed
     */
    public function f($field)
    {
        $this->record(__FUNCTION__, [$field]);
        if (array_key_exists($field, $this->Record)) {
            return $this->Record[$field];
        }
        $values = array_values($this->Record);
        return isset($values[$field]) ? $values[$field] : null;
    }

    // -----------------------------------------------------------------------
    // Test-facing readers (D5)
    // -----------------------------------------------------------------------

    /**
     * Every SQL string passed to query(), in order.
     *
     * @return array<int,string>
     */
    public function queries()
    {
        return array_map(static function (array $args) {
            return (string)$args[0];
        }, $this->argsFor('query'));
    }

    /**
     * @return string|null the most recent SQL, or null
     */
    public function lastQuery()
    {
        $queries = $this->queries();
        return $queries === [] ? null : $queries[count($queries) - 1];
    }

    /**
     * Every recorded call including next_record/real_escape, for assertions
     * that care about ordering across methods.
     *
     * @return array<int,array{method:string,args:array<int,mixed>}>
     */
    public function allQueries()
    {
        return $this->calls();
    }

    /**
     * Whether any query contains the given substring.
     *
     * @param string $needle
     * @return bool
     */
    public function queried($needle)
    {
        foreach ($this->queries() as $query) {
            if (strpos($query, $needle) !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * Replaces the rows handed out by next_record() and rewinds.
     *
     * @param array<int,array<string,mixed>> $rows
     * @return $this
     */
    public function setRows(array $rows)
    {
        $this->rows->exchangeArray(array_values($rows));
        $this->cursor['i'] = 0;
        $this->Record = [];
        return $this;
    }

    /**
     * @param int $id
     * @return $this
     */
    public function setLastInsertId($id)
    {
        $this->lastInsertId = (int)$id;
        return $this;
    }

    /**
     * Clears recorded calls and rewinds the cursor, keeping shared identity so
     * clones stay attached.
     *
     * @return void
     */
    public function reset()
    {
        $this->resetCalls();
        $this->rows->exchangeArray([]);
        $this->cursor['i'] = 0;
        $this->Record = [];
        $this->lastInsertId = 1;
    }
}
