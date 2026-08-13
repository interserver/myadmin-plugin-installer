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
 * Stand-in for `\TFTable` — 77 fleet references, all presentation.
 *
 * ## Why this one fake uses `__call`
 *
 * Every other fake in this harness mirrors its core signatures exactly,
 * because an arity mismatch there would let a handler run and a test pass
 * while proving nothing (gate G1, risk R5). `TFTable` is treated differently
 * on purpose, and the tradeoff is worth stating plainly:
 *
 * - Its surface is 40+ chainable setters that return `$this` and produce no
 *   value a behavioural assertion would ever read. There is nothing to get
 *   *wrong* in the way `FakeSettings::add_dropdown_setting()` could be wrong.
 * - Core's `set_post_location($dir = POST_LOCATION)` takes a **bare constant
 *   as a parameter default**. Copying that signature verbatim into the harness
 *   would make merely *loading* this class fatal unless `POST_LOCATION`
 *   happened to be defined first — the exact PHP 8 failure this harness exists
 *   to remove. So a verbatim copy is not just unnecessary here, it is harmful.
 *
 * The commonly-used methods are therefore declared explicitly, and `__call()`
 * records anything else and returns `$this` so a chain never breaks. Every
 * call is still recorded, so `calls()` remains a complete record of what the
 * handler did.
 *
 * If a plugin ever grows a `TFTable` method whose *return value* it depends
 * on, declare it explicitly rather than widening `__call`.
 */
class FakeTable
{
    use Recorder;

    /**
     * @var array<int,array<string,mixed>> header fields added
     */
    private $headers = [];

    /**
     * @var array<int,array<string,mixed>> body fields added
     */
    private $fields = [];

    /**
     * @var array<string,mixed> hidden inputs added
     */
    private $hidden = [];

    /**
     * @var string|null
     */
    private $title;

    /**
     * @param \MyAdmin\Plugins\Testing\CallLog|null $log
     */
    public function __construct(?CallLog $log = null)
    {
        $this->initRecorder($log);
    }

    /**
     * @param string $text
     * @param string $align
     * @return $this
     */
    public function add_header_field($text = '&nbsp;', $align = 'd')
    {
        $this->record(__FUNCTION__, [$text, $align]);
        $this->headers[] = ['text' => $text, 'align' => $align];
        return $this;
    }

    /**
     * @param string $text
     * @param string $align
     * @return $this
     */
    public function add_field($text = '&nbsp;', $align = 'd')
    {
        $this->record(__FUNCTION__, [$text, $align]);
        $this->fields[] = ['text' => $text, 'align' => $align];
        return $this;
    }

    /**
     * @param string $name
     * @param mixed  $value
     * @return $this
     */
    public function add_hidden($name, $value)
    {
        $this->record(__FUNCTION__, [$name, $value]);
        $this->hidden[$name] = $value;
        return $this;
    }

    /**
     * @param string $title
     * @return $this
     */
    public function set_title($title)
    {
        $this->record(__FUNCTION__, [$title]);
        $this->title = $title;
        return $this;
    }

    /**
     * @param string $name
     * @param bool   $onetime
     * @return string
     */
    public function csrf($name, $onetime = true)
    {
        $this->record(__FUNCTION__, [$name, $onetime]);
        return '__CSRF_' . $name . '__';
    }

    /**
     * Records any other TFTable method and keeps the chain alive.
     *
     * @param string           $method
     * @param array<int,mixed> $args
     * @return $this
     */
    public function __call($method, array $args)
    {
        $this->record($method, $args);
        return $this;
    }

    // -----------------------------------------------------------------------
    // Test-facing readers (D5)
    // -----------------------------------------------------------------------

    /**
     * @return array<int,array<string,mixed>>
     */
    public function headers()
    {
        return $this->headers;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function fields()
    {
        return $this->fields;
    }

    /**
     * @return array<string,mixed>
     */
    public function hidden()
    {
        return $this->hidden;
    }

    /**
     * @return string|null
     */
    public function title()
    {
        return $this->title;
    }

    /**
     * @return void
     */
    public function reset()
    {
        $this->headers = [];
        $this->fields = [];
        $this->hidden = [];
        $this->title = null;
        $this->resetCalls();
    }
}
