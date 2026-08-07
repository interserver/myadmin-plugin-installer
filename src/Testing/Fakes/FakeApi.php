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
 * The sink behind the `api_register*()` globals an `api.register` handler calls.
 *
 * ---------------------------------------------------------------------------------
 * WHY THIS IS NOT A DOUBLE OF A CLASS
 * ---------------------------------------------------------------------------------
 * Every other fake here stands in for a core *object* — `Settings`, `Menu`, `Loader`. The
 * API surface has none: `include/Api/api.functions.inc.php` declares three bare functions
 * that write straight into `$GLOBALS['api_calls']`, `$GLOBALS['api_arrays']` and
 * `$GLOBALS['api_array_arrays']`, and `api_register_init()` dispatches `api.register` so
 * plugins can add to them. The handlers therefore take a `GenericEvent` whose subject they
 * ignore entirely — all nine fleet implementations have `//$subject = $event->getSubject();`
 * commented out — and reach for the globals instead.
 *
 * So the stand-in has to be a sink the *stubs* write into rather than an object handed to
 * the handler. Recording it here rather than in `$GLOBALS` keeps it typed, resettable in one
 * call, and inspectable without a test having to know which global core happens to use this
 * month.
 *
 * ---------------------------------------------------------------------------------
 * THE STORED SHAPE IS CORE'S, EXACTLY
 * ---------------------------------------------------------------------------------
 * `api_register()` appends an ordered list entry with the six keys core stores; the two
 * array registrars are name-keyed maps, so a second registration under one name *overwrites*
 * the first, silently, exactly as core does. Both properties are load-bearing for
 * {@see \MyAdmin\Plugins\Testing\Contract\TierB16ApiRegisterExecute}: the list is what makes a
 * duplicate function name observable at all, and the overwrite is the defect that inspector
 * reports for the maps.
 */
class FakeApi
{
    use Recorder;

    /**
     * Registered calls, in registration order — core appends, so duplicates are visible.
     *
     * @var array<int,array<string,mixed>>
     */
    private $calls = [];

    /**
     * Complex types, keyed by name, as `$GLOBALS['api_arrays']` is.
     *
     * @var array<string,mixed>
     */
    private $arrays = [];

    /**
     * Arrays-of-a-type, keyed by name, as `$GLOBALS['api_array_arrays']` is.
     *
     * @var array<string,mixed>
     */
    private $arrayArrays = [];

    /**
     * @param \MyAdmin\Plugins\Testing\CallLog|null $log
     */
    public function __construct(?CallLog $log = null)
    {
        $this->initRecorder($log);
    }

    /**
     * Signature lifted verbatim from `include/Api/api.functions.inc.php:176`.
     *
     * @param string $function
     * @param mixed  $input
     * @param mixed  $output
     * @param string $label
     * @param bool   $logged_in
     * @param bool   $wrap
     * @return void
     */
    public function api_register($function, $input, $output, $label = '', $logged_in = true, $wrap = true)
    {
        $this->record(__FUNCTION__, func_get_args());
        $this->calls[] = [
            'function'  => $function,
            'input'     => $input,
            'output'    => $output,
            'label'     => $label,
            'logged_in' => $logged_in,
            'wrap'      => $wrap,
        ];
    }

    /**
     * Signature lifted verbatim from `include/Api/api.functions.inc.php:201`.
     *
     * @param string $function
     * @param mixed  $data
     * @return void
     */
    public function api_register_array($function, $data)
    {
        $this->record(__FUNCTION__, func_get_args());
        $this->arrays[(string)$function] = $data;
    }

    /**
     * Signature lifted verbatim from `include/Api/api.functions.inc.php:156`.
     *
     * @param string $arraysName
     * @param mixed  $targetArray
     * @return void
     */
    public function api_register_array_array($arraysName, $targetArray)
    {
        $this->record(__FUNCTION__, func_get_args());
        $this->arrayArrays[(string)$arraysName] = $targetArray;
    }

    // -----------------------------------------------------------------------
    // Readers (D5)
    // -----------------------------------------------------------------------

    /**
     * Named `apiCalls()` rather than `calls()`: {@see Recorder} already owns `calls()`, and a
     * fake whose domain reader shadows its call log is a fake nobody can assert on.
     *
     * @return array<int,array<string,mixed>>
     */
    public function apiCalls()
    {
        return $this->calls;
    }

    /**
     * @return array<string,mixed>
     */
    public function apiArrays()
    {
        return $this->arrays;
    }

    /**
     * @return array<string,mixed>
     */
    public function apiArrayArrays()
    {
        return $this->arrayArrays;
    }

    /**
     * Every function name registered, in order and **with duplicates kept**.
     *
     * @return array<int,string>
     */
    public function registeredFunctions()
    {
        $names = [];
        foreach ($this->calls as $call) {
            $names[] = is_scalar($call['function']) ? (string)$call['function'] : gettype($call['function']);
        }
        return $names;
    }

    /**
     * Total registrations of every kind — the number that answers "did this handler do
     * anything at all?".
     *
     * @return int
     */
    public function registrationCount()
    {
        return count($this->calls) + count($this->arrays) + count($this->arrayArrays);
    }

    /**
     * @return void
     */
    public function reset()
    {
        $this->calls = [];
        $this->arrays = [];
        $this->arrayArrays = [];
        $this->resetCalls();
    }
}
