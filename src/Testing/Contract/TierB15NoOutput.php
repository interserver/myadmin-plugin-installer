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
 * R-8 — ONE BUFFER IMPLEMENTATION FOR THE WHOLE CATALOGUE
 * ---------------------------------------------------------------------------------
 * A-1, A-5, B-11, B-12 and B-13 all execute plugin code too, and until R-8 they did it
 * unbuffered. Under `beStrictAboutOutputDuringTests="true"` + `failOnRisky="true"` one
 * echoing handler therefore surfaced as `R  This test printed output: …` against whichever
 * of them happened to run it — the opaque, mis-attributed report this whole inspector was
 * written to replace, reproduced five times over.
 *
 * {@see capture()} is the single implementation they all now call. Copying the drain loop
 * into five files was rejected for the same reason {@see TierA5HooksAreIdempotent::hookTable()}
 * exists: five copies of a subtle rule are five things that can drift, and a drift here is
 * not a wrong verdict, it is a corrupted process. It lives on B-15 because B-15 is the
 * catalogue entry that owns output.
 *
 * ---------------------------------------------------------------------------------
 * WHO REPORTS THE BYTES — AND WHY SOME CALLERS DISCARD THEM
 * ---------------------------------------------------------------------------------
 * Buffering without reporting would only move the swallowing one level down, so every
 * execution site in the catalogue has exactly one nominated reporter:
 *
 *  - `getSettings()` and `getMenu()` — **this inspector**. {@see TierB12SettingsExecute} and
 *    {@see TierB13MenuExecute} capture and discard, because B-15 executes the same handler,
 *    on the same subject, in the same states (see below), and reports the bytes as a
 *    failure in the one column that owns them. Discarding there is what keeps one defect
 *    in one cell instead of painting it across three.
 *  - `__construct()` — {@see TierA1ClassIsConstructible}. B-15 never constructs the plugin.
 *  - `getHooks()` — {@see TierA5HooksAreIdempotent}. B-15 never calls it. A-6, A-7, A-8 and
 *    B-12 reach it through {@see TierA5HooksAreIdempotent::hookTable()}, which captures and
 *    discards on the same "the owner runs the identical call" argument; B-11 invokes it
 *    directly and buffers it the same way.
 *  - `getRequirements()` — {@see TierB11RoutesWellFormed}. B-15 never calls it either.
 *
 * The rule the four cross-references encode: **an inspector may discard only what another
 * inspector is guaranteed to execute and report.** Where no such inspector exists, the one
 * doing the executing reports the bytes itself, in its own column, naming this assertion as
 * the defect class so a reader is not left wondering why A-1 is talking about `echo`.
 *
 * **The catalogue is not yet uniform.** {@see TierB9HookTargetsResolve},
 * {@see TierB9bHookKeysDispatched} and {@see TierB10RequirementPathsResolve} each invoke
 * `getHooks()` themselves rather than through `hookTable()`, and none of those three
 * invocations is buffered; B-10 additionally buffers `getRequirements()` but drops the bytes
 * without naming a reporter. Those four sites are the remainder of R-8 and were outside the
 * file scope this pass was made under. The fix for each is mechanical: route the call through
 * `hookTable()`, or wrap it in {@see capture()} and discard on A-5's argument.
 * `Finding::notice()` is not an option for any of them: since R-5
 * {@see \MyAdmin\Plugins\Testing\PluginContractTestCase} does read notices, so nothing is
 * discarded by the consumer any more — but a notice still leaves the matrix cell whatever
 * colour it already was, and bytes printed above `<!DOCTYPE html>` are a defect that has to
 * change it.
 *
 * ---------------------------------------------------------------------------------
 * FOUR MENU STATES, BECAUSE THE OWNER MUST NOT SEE LESS THAN THE DISCARDER
 * ---------------------------------------------------------------------------------
 * B-12/B-13 may only discard while this inspector observes at least as much as they do.
 * For `getSettings()` that was already true — both run it once, as `ima=admin` with
 * `has_acl()` granting everything. For `getMenu()` it was **not**: B-13 runs four
 * panel/ACL states and this inspector ran one, so a handler echoing only for clients would
 * have been captured by B-13 and reported by nobody.
 *
 * So `getMenu()` is now executed in every state B-13 uses, read straight from
 * {@see TierB13MenuExecute::combinations()} rather than restated here — a second copy of
 * that list is a second thing to forget to update, and the whole discard argument rests on
 * the two lists being identical. `TierB15NoOutputTest::testMenuIsObservedInEveryStateB13Executes`
 * pins the coupling.
 *
 * At most one finding per handler is still returned. Output wins over a throw (the printed
 * bytes are the actionable half), the first offending state names itself in the message,
 * and the remaining states are still run — a handler that throws in one state and prints in
 * another is reported for the print.
 *
 * ---------------------------------------------------------------------------------
 * A HANDLER THAT THROWS — THE DEFERRAL, AND WHY IT IS NOW SOUND
 * ---------------------------------------------------------------------------------
 * A handler that throws before finishing was only partly observed, so "it emitted no
 * output" would be a claim about a body that never ran to the end: a skip, not a pass.
 *
 * **R-4 changed nothing here, and the review's proposal to give the deferral a state of its
 * own was declined.** A deferral is a genuine "could not run": the rest of the handler's body
 * was never executed, so this inspector holds no verdict about it, and that is exactly what
 * {@see Finding::SKIPPED} means. It is not {@see Finding::NOT_APPLICABLE} — there is a handler
 * here, of precisely this assertion's kind, and it was not fully observed. A fifth severity
 * would buy nothing the finding does not already carry: R-3 made the deferral a skip carrying
 * `blockedBy` plus the exception message, which is machine-readable, names the owning column,
 * and is more precise than a glyph could be. The general rule this case anchors: a finding
 * carrying `blockedBy` is always a skip.
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
            // Not a skip: reflection answered the question and there is no handler here whose
            // output could be observed. Contrast the deferral in runMethod(), which is a skip
            // precisely because there *is* a handler and its body was only partly observed.
            return [Finding::notApplicable(
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
     * Executes one handler under an output buffer, in every state its owner executes it in.
     *
     * At most one finding comes back per handler; see the class docblock for the precedence
     * (output beats throw) and for why the menu states are read from B-13 rather than
     * restated here.
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

        $isMenu = $name === self::MENU_METHOD;
        $deferral = null;

        foreach (self::statesFor($name) as $state) {
            $this->configure($subject, $module, $state);
            $eventSubject = $isMenu ? Harness::menu() : Harness::settings();

            $extra = $isMenu ? ['combination' => $state['label']] : [];
            $prepared = SubjectEvent::argumentsFor($method, $eventSubject, $subject, self::ID, $extra);
            if ($prepared['skip'] !== null) {
                // A signature the harness cannot satisfy is a fact about the declaration, not
                // about the panel state, so it is reported once rather than once per state.
                return $prepared['skip'];
            }

            $args = $prepared['args'];
            $result = self::capture(function () use ($method, $args) {
                $method->invokeArgs(null, $args);
            });

            if ($result['output'] !== '') {
                return Finding::failure(
                    self::ID,
                    self::describeOutput($subject->pluginClass(), $name.'()', $result['output'])
                        . ($isMenu ? ' [observed with '.$state['label'].']' : ''),
                    [
                        'class'  => $subject->pluginClass(),
                        'method' => $name,
                        'bytes'  => strlen($result['output']),
                        'output' => self::excerpt($result['output']),
                    ] + ($isMenu ? ['combination' => $state['label']] : [])
                );
            }

            if ($result['error'] !== null && $deferral === null) {
                // Nothing was printed, but the handler did not finish either, so the rest of
                // its body was never observed — an incomplete output check, which is a skip.
                // The throw is reported by the inspector that owns the handler; see the class
                // docblock for why that deferral is now sound and what it took to make it so.
                // The exception message rides along regardless, so this finding is never the
                // information-free "something went wrong somewhere" it used to be.
                //
                // Held rather than returned: a later state may still print, and the printed
                // bytes are the actionable half.
                $owner = $isMenu ? 'B-13' : 'B-12';
                $deferral = Finding::skipped(
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
                    ] + ($isMenu ? ['combination' => $state['label']] : [])
                );
            }
        }

        return $deferral;
    }

    /**
     * The panel/ACL states one handler is executed in.
     *
     * `getMenu()` gets B-13's four, taken from B-13 so the two lists cannot drift apart —
     * the discard in B-13 is only honest while this inspector covers everything B-13 runs.
     * `getSettings()` gets the single state B-12 uses, `ima=admin` with `has_acl()` granting
     * everything: the state in which the most handler code is reachable, and therefore the
     * one most likely to reach an `echo`.
     *
     * @param string $method handler name
     * @return array<int,array<string,mixed>>
     */
    private static function statesFor($method)
    {
        if ($method === self::MENU_METHOD) {
            return TierB13MenuExecute::combinations();
        }
        return [['ima' => 'admin', 'grant' => true, 'label' => 'ima=admin, has_acl()=true']];
    }

    // -----------------------------------------------------------------------
    // Shared buffer discipline — used by A-1, A-5, B-11, B-12 and B-13 too
    // -----------------------------------------------------------------------

    /**
     * Runs a callable with an output buffer wrapped around it.
     *
     * The one implementation of the catalogue's buffer discipline; see the class docblock
     * for why it lives here and why nobody copies it. Callers get back both what escaped
     * and what the callable threw, and decide for themselves which of the two to report —
     * this method never throws and never files a Finding.
     *
     * See the class docblock on why the drain is written the way it is. The `finally` is
     * load-bearing: an exception escaping with our buffer still pushed would make PHPUnit
     * attribute this inspector's buffer to the surrounding test. Every caller must route its
     * `catch` through the returned `error` rather than around this method, or that guarantee
     * is only as good as the caller's own early returns.
     *
     * @param callable $run the plugin code to execute
     * @return array{output:string,error:\Throwable|null}
     */
    public static function capture(callable $run)
    {
        $baseline = ob_get_level();
        $captured = '';
        $error = null;

        ob_start();
        try {
            $run();
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

    /**
     * The sentence every inspector uses to report bytes a plugin printed.
     *
     * Shared so that a failure filed by A-1, A-5 or B-11 reads as the same defect this
     * assertion names, rather than as four differently-worded complaints about `echo`.
     *
     * @param string $class  plugin class
     * @param string $site   what was executed, e.g. `getSettings()` or `__construct()`
     * @param string $output the captured bytes
     * @return string
     */
    public static function describeOutput($class, $site, $output)
    {
        return sprintf(
            '%s::%s wrote %d byte(s) directly to output instead of going through add_output(): %s',
            $class,
            $site,
            strlen($output),
            self::excerpt($output)
        );
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
     * `acl => []` rather than `false` for a denied grant, matching {@see TierB13MenuExecute}
     * byte for byte: the two must configure the harness identically, or "B-15 sees everything
     * B-13 sees" stops being true for reasons no test would notice.
     *
     * @param \MyAdmin\Plugins\Testing\Contract\PluginSubject $subject
     * @param string|null                                    $module
     * @param array<string,mixed>                            $state   ima/grant pair
     * @return void
     */
    private function configure(PluginSubject $subject, $module, array $state)
    {
        Harness::reset();
        Bootstrap::init([
            'module'    => ($module === null || $module === '') ? 'default' : $module,
            'constants' => $subject->constantOverrides(),
            'plugin'    => $subject->pluginClass(),
            'defines'   => $subject->serviceDefines(),
            'ima'       => $state['ima'],
            'acl'       => $state['grant'] ? true : [],
        ]);
        Harness::reset();
    }

    /**
     * Quotes the captured output at a length that fits a failure line.
     *
     * Public because A-1, A-5 and B-11 quote captured bytes into their own findings and must
     * truncate the same way — an inspector that pasted 40KB of markup into a matrix cell
     * would make the artefact unreadable for everyone.
     *
     * @param string $output
     * @return string
     */
    public static function excerpt($output)
    {
        $output = (string)$output;
        if (strlen($output) <= self::EXCERPT_BYTES) {
            return $output;
        }
        return substr($output, 0, self::EXCERPT_BYTES) . '... [truncated]';
    }
}
