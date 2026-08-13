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
 * Stand-in for the session, reached via `App::session()` — 88 fleet references.
 *
 * Method set matches `MyAdmin\App\Contracts\SessionInterface` from
 * `detain/myadmin-contracts`. As with {@see FakeDb}, the interface is not
 * `implements`-ed because the installer does not require that package and
 * implementing an unloaded interface is fatal; `FakeSessionTest` asserts
 * structural compatibility whenever the interface is present.
 *
 * The app-session bag is a real in-memory store rather than a no-op, so a
 * handler that writes a value and later reads it back behaves as it would in
 * production.
 */
class FakeSession
{
    use Recorder;

    /**
     * Sentinel core uses to mean "read, don't write".
     *
     * @var string
     */
    const NOTHING = '##NOTHING##';

    /**
     * @var array<string,mixed>
     */
    private $appSession = [];

    /**
     * @var array<string,mixed>
     */
    private $noCache = [];

    /**
     * @var bool value returned by verify()
     */
    private $verifies = true;

    /**
     * @param \MyAdmin\Plugins\Testing\CallLog|null $log
     */
    public function __construct(?CallLog $log = null)
    {
        $this->initRecorder($log);
    }

    /**
     * @return bool
     */
    public function verify()
    {
        $this->record(__FUNCTION__, []);
        return $this->verifies;
    }

    /**
     * @param int         $accountId
     * @param string      $ima
     * @param bool        $browser
     * @param int         $expire
     * @param bool|string $dir
     * @param bool|string $sessionid
     * @param bool        $sudo
     * @return bool
     */
    public function create($accountId, $ima, $browser = true, $expire = 0, $dir = false, $sessionid = false, $sudo = false)
    {
        $this->record(__FUNCTION__, func_get_args());
        return true;
    }

    /**
     * @param bool|string $sessionid
     * @return bool
     */
    public function destroy($sessionid = false)
    {
        $this->record(__FUNCTION__, [$sessionid]);
        $this->appSession = [];
        return true;
    }

    /**
     * @return bool
     */
    public function update_dla()
    {
        $this->record(__FUNCTION__, []);
        return true;
    }

    /**
     * Read when `$value` is the NOTHING sentinel, write otherwise — exactly as
     * core behaves.
     *
     * @param string $location
     * @param mixed  $value
     * @return mixed
     */
    public function appsession($location = 'default', $value = self::NOTHING)
    {
        $this->record(__FUNCTION__, [$location, $value]);
        if ($value === self::NOTHING) {
            return array_key_exists($location, $this->appSession) ? $this->appSession[$location] : null;
        }
        $this->appSession[$location] = $value;
        return $value;
    }

    /**
     * @param string $location
     * @param mixed  $data
     * @return mixed
     */
    public function appnocache($location, $data)
    {
        $this->record(__FUNCTION__, [$location, $data]);
        $this->noCache[$location] = $data;
        return $data;
    }

    /**
     * @param string $location
     * @return void
     */
    public function delappsession($location)
    {
        $this->record(__FUNCTION__, [$location]);
        unset($this->appSession[$location]);
    }

    /**
     * @return array<string,mixed>
     */
    public function appcache()
    {
        $this->record(__FUNCTION__, []);
        return $this->appSession;
    }

    /**
     * @param string $unique_form_name
     * @param bool   $onetime
     * @return string
     */
    public function get_csrf($unique_form_name, $onetime = false)
    {
        $this->record(__FUNCTION__, [$unique_form_name, $onetime]);
        return '__CSRF_' . $unique_form_name . '__';
    }

    /**
     * @param string $unique_form_name
     * @param string $token_value
     * @return bool
     */
    public function verify_csrf($unique_form_name, $token_value)
    {
        $this->record(__FUNCTION__, [$unique_form_name, $token_value]);
        return $token_value === '__CSRF_' . $unique_form_name . '__';
    }

    /**
     * @param bool $verifies
     * @return $this
     */
    public function setVerifies($verifies)
    {
        $this->verifies = (bool)$verifies;
        return $this;
    }

    /**
     * @return array<string,mixed>
     */
    public function stored()
    {
        return $this->appSession;
    }

    /**
     * @return void
     */
    public function reset()
    {
        $this->appSession = [];
        $this->noCache = [];
        $this->verifies = true;
        $this->resetCalls();
    }
}
