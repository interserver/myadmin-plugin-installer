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
 * Stand-in for the accounts service, reached via `App::accounts()` — 127 fleet
 * references.
 *
 * Method set matches `MyAdmin\App\Contracts\AccountsInterface`; the `$data`
 * property is part of that contract via a `@property` docblock and is the
 * member plugin code actually reads.
 *
 * **`$data['ima']` is the account's real role and is not the same thing as
 * `App::ima()`**, which is the panel the request is currently rendering. An
 * admin browsing a client view has `App::ima() === 'client'` while
 * `accounts()->data['ima'] === 'admin'`. Handlers that gate on the wrong one
 * are a live bug class, so the harness keeps them independently settable.
 */
class FakeAccounts
{
    use Recorder;

    /**
     * Account row for the current session.
     *
     * @var array<string,mixed>
     */
    public $data = [];

    /**
     * Rows returned by read(), keyed by account id.
     *
     * @var array<int|string,array<string,mixed>>
     */
    private $rows = [];

    /**
     * @var int
     */
    private $nextId = 1;

    /**
     * @param array<string,mixed>                  $data current account row
     * @param \MyAdmin\Plugins\Testing\CallLog|null $log
     */
    public function __construct(array $data = [], ?CallLog $log = null)
    {
        $this->initRecorder($log);
        $this->data = $data;
    }

    /**
     * @param int|string  $accountId
     * @param string      $filters
     * @param string      $order_sort
     * @param bool|int    $limit
     * @param int         $offset
     * @return array<string,mixed>|false
     */
    public function read($accountId, $filters = '', $order_sort = '', $limit = false, $offset = 0)
    {
        $this->record(__FUNCTION__, func_get_args());
        if (array_key_exists($accountId, $this->rows)) {
            $this->data = $this->rows[$accountId];
            return $this->data;
        }
        return false;
    }

    /**
     * @param array<string,mixed> $values
     * @return int the new account id
     */
    public function add($values)
    {
        $this->record(__FUNCTION__, [$values]);
        $id = $this->nextId++;
        $this->rows[$id] = (array)$values;
        return $id;
    }

    /**
     * @param int|string          $accountId
     * @param array<string,mixed> $values
     * @return bool
     */
    public function update($accountId, $values)
    {
        $this->record(__FUNCTION__, [$accountId, $values]);
        if (!array_key_exists($accountId, $this->rows)) {
            return false;
        }
        $this->rows[$accountId] = array_merge($this->rows[$accountId], (array)$values);
        return true;
    }

    /**
     * @param int|string $accountId
     * @return bool
     */
    public function delete($accountId)
    {
        $this->record(__FUNCTION__, [$accountId]);
        if (!array_key_exists($accountId, $this->rows)) {
            return false;
        }
        unset($this->rows[$accountId]);
        return true;
    }

    /**
     * @param int|string $account
     * @param string     $tmodule
     * @param bool       $skip_cache
     * @return int|string
     */
    public function cross_reference($account, $tmodule = 'default', $skip_cache = false)
    {
        $this->record(__FUNCTION__, [$account, $tmodule, $skip_cache]);
        return $account;
    }

    /**
     * @param string $account_lid
     * @param string $tmodule
     * @param bool   $skip_cache
     * @return bool
     */
    public function exists($account_lid, $tmodule = 'default', $skip_cache = false)
    {
        $this->record(__FUNCTION__, [$account_lid, $tmodule, $skip_cache]);
        return array_key_exists($account_lid, $this->rows);
    }

    /**
     * @return int
     */
    public function get_next_id()
    {
        $this->record(__FUNCTION__, []);
        return $this->nextId;
    }

    /**
     * Seeds a row that read()/exists() will find.
     *
     * @param int|string          $accountId
     * @param array<string,mixed> $row
     * @return $this
     */
    public function setRow($accountId, array $row)
    {
        $this->rows[$accountId] = $row;
        return $this;
    }

    /**
     * @param array<string,mixed> $data
     * @return $this
     */
    public function setData(array $data)
    {
        $this->data = $data;
        return $this;
    }

    /**
     * @return void
     */
    public function reset()
    {
        $this->data = [];
        $this->rows = [];
        $this->nextId = 1;
        $this->resetCalls();
    }
}
