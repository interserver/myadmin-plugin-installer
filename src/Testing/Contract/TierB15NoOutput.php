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
 *
 * ---------------------------------------------------------------------------------
 * A HANDLER THAT THROWS — THE DEFERRAL, AND WHY IT IS NOW SOUND
 * ---------------------------------------------------------------------------------
 * A handler that throws before finishing was only partly observed, so "it emitted no
 * output" would be a claim about a body that never ran to the end: a skip, not a pass.
 * The throw itself is not this inspector's finding — B-15 is "handlers emit no direct
 * output", and turning it red for a fatal would file the defect under the wrong heading
 * and paint one bug into two matrix columns.
 *
 * That deferral was, until R-3, **circular**. B-12 gated `getSettings()` on reachability
 * *before* executing it, so for the 13 packages whose handler no hook registers, B-12
 * skipped and this inspector deferred to a check that had declined to run. A plugin whose
 * `getSettings()` fatals on line 1 of its body passed all 17 assertions — 12 passes, 5
 * skips, 0 failures. The comment here said "B-12/B-13 own the throw itself" while the
 * message beside it proved the `Error` had been caught, read and thrown away.
 *
 * Deferral is kept rather than replaced with a failure because the premise is now true,
 * and true for a checkable reason rather than by assertion. The preconditions for
 * executing a handler are the same on both sides and B-12/B-13's are never the narrower:
 *
 *  - loadable class, declared method, `public static`, and an event this environment can
 *    build — each of those is decided by the same code ({@see PluginSubject},
 *    {@see SubjectEvent::argumentsFor()}) against the same subject, so whenever this
 *    inspector executes a handler, so do they;
 *  - {@see TierB12SettingsExecute} no longer consults reachability before invoking, and
 *    {@see TierB13MenuExecute} has never had a gate at all — it runs `getMenu()` in four
 *    panel/ACL states, one of which is this inspector's `ima=admin` + grant-all;
 *  - both report a throw as {@see Finding::failure()}, unconditionally.
 *
 * Pinned, not trusted: `TierB15NoOutputTest::testDeferringOnASettingsThrowIsBackedByAFailure
 * FromB12` and its `…OnAMenuThrow…FromB13` twin run both inspectors over one subject — the
 * orphaned fatal handler that used to slip through — and require the owner to be red
 * whenever this one defers. Reintroducing any pre-execution gate in B-12 turns those tests
 * red instead of quietly reopening the loop. The known residual is a handler that throws only
 * on a *later* invocation than the owner's, which no gate change can cause; the exception
 * message and the `blockedBy` context are what a reader has left in that case, and they are
 * carried here for exactly that reason even though the finding defers.
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
            // Nothing was printed, but the handler did not finish either, so the rest of its
            // body was never observed — an incomplete output check, which is a skip. The
            // throw is reported by the inspector that owns the handler; see the class
            // docblock for why that deferral is now sound and what it took to make it so.
            // The exception message rides along regardless, so this finding is never the
            // information-free "something went wrong somewhere" it used to be.
            $owner = $name === self::MENU_METHOD ? 'B-13' : 'B-12';
            return Finding::skipped(
                self::ID,
                sprintf(
                    '%s::%s() threw %s before completing, so the output check is incomplete;'
                        . ' %s fails on the same throw and reports it: %s',
                    $subject->pluginClass(),
                    $name,
                    get_class($result['error']),
                    $owner,
                    $result['error']->getMessage()
                ),
                [
                    'class'     => $subject->pluginClass(),
                    'method'    => $name,
                    'exception' => get_class($result['error']),
                    'blockedBy' => $owner,
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
