<?php
/**
 * @author Joe Huss <detain@interserver.net>
 * @copyright 2026
 * @package MyAdmin
 * @category Testing
 */

namespace MyAdmin\Plugins\Testing\Contract;

use MyAdmin\Plugins\Testing\Bootstrap;
use MyAdmin\Plugins\Testing\Harness;
use ReflectionMethod;
use Throwable;

/**
 * B-15 — plugin handlers must not `echo`.
 *
 * ---------------------------------------------------------------------------------
 * WHY ASSERT SOMETHING PHPUNIT ALREADY ENFORCES
 * ---------------------------------------------------------------------------------
 * Every plugin's `phpunit.xml.dist` sets `beStrictAboutOutputDuringTests="true"`, so a
 * handler that echoes *already* fails the build fleet-wide. What it does not do is say
 * anything useful. PHPUnit reports:
 *
 *     R  This test printed output: <div class="alert">…
 *
 * against whichever test happened to trigger it, with no plugin name, no method name and
 * no indication that the emitting code was the plugin rather than the test. Worse, the
 * risky-test machinery attributes the output to the *test*, which sends the reader to the
 * wrong file. Restating it as an explicit assertion turns an opaque `R` into a message
 * that names the plugin, the handler and the bytes it printed.
 *
 * The defect itself matters in production too: MyAdmin buffers page output through
 * `add_output()` / `App::output()` precisely so that headers can still be sent and the
 * theme can still wrap the body. A handler that echoes directly emits *before* the
 * layout, which in a real request means content above `<!DOCTYPE html>` and, if it
 * happens early enough, a "headers already sent" fatal. `FakeOutput` exists for the same
 * reason — it buffers rather than echoing, so the correct route through `add_output()`
 * never trips this check, and only a genuine `echo`/`print`/`printf`/`var_dump` in the
 * handler does.
 *
 * ---------------------------------------------------------------------------------
 * BUFFER DISCIPLINE
 * ---------------------------------------------------------------------------------
 * This inspector runs inside a PHPUnit process that has its own output buffer open, and
 * over 69 plugins in a row. Leaving a buffer pushed — or popping one we did not push —
 * corrupts every test after it, so:
 *
 *  - the nesting level is recorded before `ob_start()`;
 *  - the drain happens in `finally`, so a throwing handler cannot skip it;
 *  - the drain loop pops until the level is back where it started, which also recovers
 *    the case where the plugin itself pushed buffers and never popped them;
 *  - if plugin code popped *past* our level and destroyed PHPUnit's buffer, empty
 *    replacements are pushed back so the nesting depth PHPUnit expects still exists.
 *
 * Content is reassembled outermost-first, which is chronological order: `ob_get_clean()`
 * returns the innermost buffer, and an inner buffer's content was written after the outer
 * one's.
 */
class TierB15NoOutput implements PluginInspector
{
    /** @var string catalogue id */
    const ID = 'B-15';

    /** @var int how much captured output is quoted in the message */
    const EXCERPT_BYTES = 200;

    /** @var string the settings handler, declared by all 69 plugins */
    const SETTINGS_METHOD = 'getSettings';

    /** @var string the menu handler, declared by 42 of them */
    const MENU_METHOD = 'getMenu';

    /**
     * @return string
     */
    public function id()
    {
        return self::ID;
    }

    /**
     * @return string
     */
    public function title()
    {
        return 'handlers emit no direct output';
    }

    /**
     * @param \MyAdmin\Plugins\Testing\Contract\PluginSubject $subject
     * @return array<int,\MyAdmin\Plugins\Testing\Contract\Finding>
     */
    public function inspect(PluginSubject $subject)
    {
        if (!$subject->isLoadable()) {
            return [Finding::skipped(
                self::ID,
                'plugin class is not loadable, so no handler can be executed',
                ['class' => $subject->pluginClass()]
            )];
        }

        $reflection = $subject->reflection();
        $present = [];
        foreach ([self::SETTINGS_METHOD, self::MENU_METHOD] as $name) {
            if ($reflection->hasMethod($name)) {
                $present[] = $reflection->getMethod($name);
            }
        }
        if ($present === []) {
            return [Finding::skipped(
                self::ID,
                'plugin declares neither ' . self::SETTINGS_METHOD . '() nor ' . self::MENU_METHOD . '()',
                ['class' => $subject->pluginClass()]
            )];
        }

        $module = $this->prime($subject);

        $findings = [];
        foreach ($present as $method) {
            $finding = $this->runMethod($subject, $method, $module);
            if ($finding !== null) {
                $findings[] = $finding;
            }
        }
        SubjectEvent::releaseHarness();

        return $findings;
    }

    // -----------------------------------------------------------------------
    // Execution
    // -----------------------------------------------------------------------

    /**
     * Executes one handler under an output buffer.
     *
     * @param \MyAdmin\Plugins\Testing\Contract\PluginSubject $subject
     * @param \ReflectionMethod                              $method
     * @param string|null                                    $module
     * @return \MyAdmin\Plugins\Testing\Contract\Finding|null null when the handler stayed silent
     */
    private function runMethod(PluginSubject $subject, ReflectionMethod $method, $module)
    {
        $name = $method->getName();
        if (!$method->isPublic() || !$method->isStatic()) {
            // B-12/B-13 report the non-callable handler itself. Here it means the
            // observation could not be made, which is a skip, not a pass.
            return Finding::skipped(
                self::ID,
                sprintf('%s::%s() is not public static, so it could not be executed', $subject->pluginClass(), $name),
                ['class' => $subject->pluginClass(), 'method' => $name]
            );
        }

        $this->configure($subject, $module);
        $eventSubject = $name === self::MENU_METHOD ? Harness::menu() : Harness::settings();

        $prepared = SubjectEvent::argumentsFor($method, $eventSubject, $subject, self::ID);
        if ($prepared['skip'] !== null) {
            return $prepared['skip'];
        }

        $result = $this->captureOutput($method, $prepared['args']);

        if ($result['output'] !== '') {
            return Finding::failure(
                self::ID,
                sprintf(
                    '%s::%s() wrote %d byte(s) directly to output instead of going through add_output(): %s',
                    $subject->pluginClass(),
                    $name,
                    strlen($result['output']),
                    self::excerpt($result['output'])
                ),
                [
                    'class'  => $subject->pluginClass(),
                    'method' => $name,
                    'bytes'  => strlen($result['output']),
                    'output' => self::excerpt($result['output']),
                ]
            );
        }

        if ($result['error'] !== null) {
            // Nothing was printed, but the handler did not finish either, so the rest of
            // its body was never observed. B-12/B-13 own the throw itself.
            return Finding::skipped(
                self::ID,
                sprintf(
                    '%s::%s() threw %s before completing, so the output check is incomplete',
                    $subject->pluginClass(),
                    $name,
                    get_class($result['error'])
                ),
                [
                    'class'     => $subject->pluginClass(),
                    'method'    => $name,
                    'exception' => get_class($result['error']),
                ]
            );
        }

        return null;
    }

    /**
     * Invokes the handler with an output buffer wrapped around it.
     *
     * See the class docblock on why the drain is written the way it is. The `finally` is
     * load-bearing: an exception escaping with our buffer still pushed would make PHPUnit
     * attribute this inspector's buffer to the surrounding test.
     *
     * @param \ReflectionMethod $method
     * @param array<int,mixed>  $args
     * @return array{output:string,error:\Throwable|null}
     */
    private function captureOutput(ReflectionMethod $method, array $args)
    {
        $baseline = ob_get_level();
        $captured = '';
        $error = null;

        ob_start();
        try {
            $method->invokeArgs(null, $args);
        } catch (Throwable $e) {
            $error = $e;
        } finally {
            while (ob_get_level() > $baseline) {
                $chunk = ob_get_clean();
                $captured = ($chunk === false ? '' : $chunk) . $captured;
            }
            // The handler popped past us and took PHPUnit's buffer with it. The content
            // is gone either way; restoring the depth at least keeps the surrounding
            // process consistent.
            while (ob_get_level() < $baseline) {
                ob_start();
            }
        }

        return ['output' => $captured, 'error' => $error];
    }

    // -----------------------------------------------------------------------
    // Harness plumbing
    // -----------------------------------------------------------------------

    /**
     * Brings the harness up for one plugin and returns its declared module.
     *
     * Constants first: reading `$module` evaluates the plugin's static initializers, and
     * nine repos initialise one from a bare constant, which is a fatal `Error` until the
     * constant exists.
     *
     * @param \MyAdmin\Plugins\Testing\Contract\PluginSubject $subject
     * @return string|null
     */
    private function prime(PluginSubject $subject)
    {
        Harness::reset();
        Bootstrap::init([
            'constants' => $subject->constantOverrides(),
            'plugin'    => $subject->pluginClass(),
            'defines'   => $subject->serviceDefines(),
        ]);
        return $subject->module();
    }

    /**
     * Resets the fakes and seeds the state one handler run sees.
     *
     * `admin` + grant-all is deliberate: it is the state in which the most handler code
     * is reachable, so it is the state most likely to reach an `echo`.
     *
     * @param \MyAdmin\Plugins\Testing\Contract\PluginSubject $subject
     * @param string|null                                    $module
     * @return void
     */
    private function configure(PluginSubject $subject, $module)
    {
        Harness::reset();
        Bootstrap::init([
            'module'    => ($module === null || $module === '') ? 'default' : $module,
            'constants' => $subject->constantOverrides(),
            'plugin'    => $subject->pluginClass(),
            'defines'   => $subject->serviceDefines(),
            'ima'       => 'admin',
            'acl'       => true,
        ]);
        Harness::reset();
    }

    /**
     * Quotes the captured output at a length that fits a failure line.
     *
     * @param string $output
     * @return string
     */
    private static function excerpt($output)
    {
        $output = (string)$output;
        if (strlen($output) <= self::EXCERPT_BYTES) {
            return $output;
        }
        return substr($output, 0, self::EXCERPT_BYTES) . '... [truncated]';
    }
}
