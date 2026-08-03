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
 * Stand-in for `\MyAdmin\History`, reached via `App::history()` — 130 fleet
 * references, and the observable side effect most service handlers produce.
 *
 * Signature lifted verbatim from `include/History.php:162`.
 */
class FakeHistory
{
    use Recorder;

    /**
     * @param \MyAdmin\Plugins\Testing\CallLog|null $log
     */
    public function __construct(?CallLog $log = null)
    {
        $this->initRecorder($log);
    }

    /**
     * @param string     $section
     * @param string     $type
     * @param string     $new
     * @param string     $old
     * @param bool|int   $custid
     * @param bool|string $extra
     * @return int the fake history id (1-based sequence number)
     */
    public function add($section, $type, $new, $old = '', $custid = false, $extra = false)
    {
        return $this->record(__FUNCTION__, func_get_args());
    }

    /**
     * Every recorded add(), as an associative row per call.
     *
     * @return array<int,array{section:mixed,type:mixed,new:mixed,old:mixed,custid:mixed,extra:mixed}>
     */
    public function entries()
    {
        return array_map(static function (array $args) {
            return [
                'section' => isset($args[0]) ? $args[0] : null,
                'type'    => isset($args[1]) ? $args[1] : null,
                'new'     => isset($args[2]) ? $args[2] : null,
                'old'     => isset($args[3]) ? $args[3] : '',
                'custid'  => isset($args[4]) ? $args[4] : false,
                'extra'   => isset($args[5]) ? $args[5] : false,
            ];
        }, $this->argsFor('add'));
    }

    /**
     * @return int
     */
    public function count()
    {
        return $this->callCount('add');
    }

    /**
     * @return void
     */
    public function reset()
    {
        $this->resetCalls();
    }
}
