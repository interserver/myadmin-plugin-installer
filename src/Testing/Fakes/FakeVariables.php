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
 * Stand-in for `\MyAdmin\Variables`, reached via `App::variables()`.
 *
 * This is the single most-referenced piece of the App surface across the
 * fleet — 429 of the 1,001 `App::` references — because it is how plugin code
 * reads request input.
 *
 * Both the array properties (`$request`, `$get`, `$post`, and their `_raw`
 * twins) and the accessor methods are provided, because fleet code uses both
 * spellings. Writing through `setRequest()` keeps every view consistent, which
 * hand-rolled fakes routinely got wrong: a test would seed `$request` and the
 * handler would read `request('key')` and get null.
 */
class FakeVariables
{
    use Recorder;

    /** @var array<string,mixed> */
    public $get = [];

    /** @var array<string,mixed> */
    public $post = [];

    /** @var array<string,mixed> */
    public $postget = [];

    /** @var array<string,mixed> */
    public $request = [];

    /** @var array<string,mixed> */
    public $get_raw = [];

    /** @var array<string,mixed> */
    public $post_raw = [];

    /** @var array<string,mixed> */
    public $request_raw = [];

    /**
     * @param array<string,mixed>                  $request initial request bag
     * @param \MyAdmin\Plugins\Testing\CallLog|null $log
     */
    public function __construct(array $request = [], ?CallLog $log = null)
    {
        $this->initRecorder($log);
        $this->setRequest($request);
    }

    /**
     * @param string|null $key
     * @param mixed       $default
     * @return mixed
     */
    public function get($key = null, $default = null)
    {
        $this->record(__FUNCTION__, [$key, $default]);
        return self::pick($this->get, $key, $default);
    }

    /**
     * @param string|null $key
     * @param mixed       $default
     * @return mixed
     */
    public function post($key = null, $default = null)
    {
        $this->record(__FUNCTION__, [$key, $default]);
        return self::pick($this->post, $key, $default);
    }

    /**
     * @param string|null $key
     * @param mixed       $default
     * @return mixed
     */
    public function request($key = null, $default = null)
    {
        $this->record(__FUNCTION__, [$key, $default]);
        return self::pick($this->request, $key, $default);
    }

    /**
     * @param string|null $key
     * @param mixed       $default
     * @return mixed
     */
    public function getRaw($key = null, $default = null)
    {
        $this->record(__FUNCTION__, [$key, $default]);
        return self::pick($this->get_raw, $key, $default);
    }

    /**
     * @param string|null $key
     * @param mixed       $default
     * @return mixed
     */
    public function postRaw($key = null, $default = null)
    {
        $this->record(__FUNCTION__, [$key, $default]);
        return self::pick($this->post_raw, $key, $default);
    }

    /**
     * @param string|null $key
     * @param mixed       $default
     * @return mixed
     */
    public function requestRaw($key = null, $default = null)
    {
        $this->record(__FUNCTION__, [$key, $default]);
        return self::pick($this->request_raw, $key, $default);
    }

    /**
     * Re-reads the superglobals, as core does.
     *
     * @return void
     */
    public function reloadFromSuperglobals()
    {
        $this->record(__FUNCTION__, []);
        $this->get = $this->get_raw = isset($_GET) ? $_GET : [];
        $this->post = $this->post_raw = isset($_POST) ? $_POST : [];
        $this->request = $this->request_raw = isset($_REQUEST) ? $_REQUEST : [];
        $this->postget = array_merge($this->get, $this->post);
    }

    /**
     * Seeds every view at once — request, get, post and the raw twins — so a
     * handler sees the same data whichever spelling it uses.
     *
     * @param array<string,mixed> $values
     * @return $this
     */
    public function setRequest(array $values)
    {
        $this->request = $this->request_raw = $values;
        $this->get = $this->get_raw = $values;
        $this->post = $this->post_raw = $values;
        $this->postget = $values;
        return $this;
    }

    /**
     * @return void
     */
    public function reset()
    {
        $this->setRequest([]);
        $this->resetCalls();
    }

    /**
     * @param array<string,mixed> $bag
     * @param string|null         $key
     * @param mixed               $default
     * @return mixed
     */
    private static function pick(array $bag, $key, $default)
    {
        if ($key === null) {
            return $bag;
        }
        return array_key_exists($key, $bag) ? $bag[$key] : $default;
    }
}
