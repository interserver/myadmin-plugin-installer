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
 * Stand-in for `\MyAdmin\App\Output`, reached via `App::output()` (24 fleet
 * references) and by the global `add_output()` (297 references).
 *
 * Buffers rather than echoes. This matters beyond tidiness: every plugin's
 * `phpunit.xml.dist` sets `beStrictAboutOutputDuringTests="true"`, so a fake
 * that echoed would turn a passing handler into a risky/failed test for a
 * reason unrelated to the plugin.
 */
class FakeOutput
{
    use Recorder;

    /**
     * @var array<int,string>
     */
    private $chunks = [];

    /**
     * @param \MyAdmin\Plugins\Testing\CallLog|null $log
     */
    public function __construct(?CallLog $log = null)
    {
        $this->initRecorder($log);
    }

    /**
     * @param mixed $value
     * @return void
     */
    public function add($value)
    {
        $this->record(__FUNCTION__, [$value]);
        $this->chunks[] = (string)$value;
    }

    /**
     * @return string everything added, concatenated
     */
    public function get()
    {
        return implode('', $this->chunks);
    }

    /**
     * @return array<int,string> each add() separately
     */
    public function chunks()
    {
        return $this->chunks;
    }

    /**
     * @param string $needle
     * @return bool
     */
    public function contains($needle)
    {
        return strpos($this->get(), $needle) !== false;
    }

    /**
     * @return bool whether anything was emitted at all
     */
    public function isEmpty()
    {
        return $this->chunks === [];
    }

    /**
     * @return void
     */
    public function reset()
    {
        $this->chunks = [];
        $this->resetCalls();
    }
}
