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
 * Stand-in for `\TFSmarty` — 73 fleet references, mostly in service handlers
 * that render a queue command or an admin email.
 *
 * `fetch()` returns a deterministic marker string rather than rendering, so a
 * test can assert *which* template was chosen and *what* was assigned to it
 * without a Smarty install or a compile directory. `setRendered()` pins a body
 * for the rare test that needs to assert on rendered output.
 */
class FakeSmarty
{
    use Recorder;

    /**
     * Everything assigned, by key.
     *
     * @var array<string,mixed>
     */
    private $assigned = [];

    /**
     * Bodies returned by fetch(), by template name.
     *
     * @var array<string,string>
     */
    private $rendered = [];

    /**
     * @param \MyAdmin\Plugins\Testing\CallLog|null $log
     */
    public function __construct(?CallLog $log = null)
    {
        $this->initRecorder($log);
    }

    /**
     * @param string|array<string,mixed> $key
     * @param mixed                      $value
     * @return $this
     */
    public function assign($key, $value = null)
    {
        $this->record(__FUNCTION__, [$key, $value]);
        if (is_array($key)) {
            $this->assigned = array_merge($this->assigned, $key);
            return $this;
        }
        $this->assigned[$key] = $value;
        return $this;
    }

    /**
     * @param string $template
     * @return string
     */
    public function fetch($template)
    {
        $this->record(__FUNCTION__, [$template]);
        if (array_key_exists($template, $this->rendered)) {
            return $this->rendered[$template];
        }
        return 'rendered:' . $template;
    }

    /**
     * Core's display() echoes; the fake records instead, because emitting
     * output during a test trips `beStrictAboutOutputDuringTests`.
     *
     * @param string $template
     * @return void
     */
    public function display($template)
    {
        $this->record(__FUNCTION__, [$template]);
    }

    /**
     * @param string $key
     * @return mixed
     */
    public function getTemplateVars($key = null)
    {
        $this->record(__FUNCTION__, [$key]);
        if ($key === null) {
            return $this->assigned;
        }
        return array_key_exists($key, $this->assigned) ? $this->assigned[$key] : null;
    }

    /**
     * @param string $key
     * @return void
     */
    public function clearAssign($key)
    {
        $this->record(__FUNCTION__, [$key]);
        unset($this->assigned[$key]);
    }

    // -----------------------------------------------------------------------
    // Test-facing readers (D5)
    // -----------------------------------------------------------------------

    /**
     * @return array<string,mixed>
     */
    public function assigned()
    {
        return $this->assigned;
    }

    /**
     * @param string $key
     * @return bool
     */
    public function hasAssigned($key)
    {
        return array_key_exists($key, $this->assigned);
    }

    /**
     * Template names passed to fetch()/display(), in order.
     *
     * @return array<int,string>
     */
    public function templates()
    {
        $names = [];
        foreach ($this->calls() as $call) {
            if ($call['method'] === 'fetch' || $call['method'] === 'display') {
                $names[] = (string)$call['args'][0];
            }
        }
        return $names;
    }

    /**
     * Pins the body fetch() returns for one template.
     *
     * @param string $template
     * @param string $body
     * @return $this
     */
    public function setRendered($template, $body)
    {
        $this->rendered[$template] = $body;
        return $this;
    }

    /**
     * @return void
     */
    public function reset()
    {
        $this->assigned = [];
        $this->rendered = [];
        $this->resetCalls();
    }
}
