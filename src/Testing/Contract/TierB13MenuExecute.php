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

/**
 * B-13 — `getMenu()` executes clean under every panel/permission combination.
 *
 * ---------------------------------------------------------------------------------
 * THE BUG CLASS THIS EXISTS TO CATCH
 * ---------------------------------------------------------------------------------
 * **An admin-only menu item that fatals for clients.** `getMenu()` is the one handler
 * that is *supposed* to branch on who is looking, and every one of the 42 implementations
 * in the fleet does, in the same shape:
 *
 *     if (\MyAdmin\App::ima() == 'admin') {
 *         function_requirements('has_acl');
 *         if (has_acl('client_billing')) {
 *             $menu->add_link('admin', …);
 *         }
 *     }
 *
 * A handler is exercised in development almost exclusively as an admin with full ACL —
 * one of four possible states. The other three are where the damage lives: a helper
 * resolved only inside the admin branch and then used outside it, an `$acl` array indexed
 * without a guard, a client-side `add_link()` reaching for a service the client branch
 * never loaded. The symptom is not a missing menu entry, it is a **fatal on every client
 * page load**, because the menu renders on all of them.
 *
 * So this inspector runs `getMenu()` four times — `ima` of `admin` and `client`, crossed
 * with `has_acl()` answering true and false — and requires that none of the four throws.
 * It deliberately does **not** assert that anything was added: an admin-only menu adding
 * nothing for a client is correct behaviour, and asserting otherwise would be the kind of
 * over-strict harness rule D7 classifies as an H-bug.
 *
 * One `Finding` is reported per failing combination, naming the combination and the
 * exception, because "getMenu() throws" is a much less useful bug report than
 * "getMenu() throws when ima=client and has_acl() is false".
 *
 * ---------------------------------------------------------------------------------
 * OUTPUT — CAPTURED HERE, REPORTED BY B-15 (R-8)
 * ---------------------------------------------------------------------------------
 * Each of the four invocations goes through {@see TierB15NoOutput::capture()}. Unbuffered,
 * a handler that `echo`es on any one of them escaped into the PHPUnit process, where
 * `beStrictAboutOutputDuringTests="true"` + `failOnRisky="true"` reported it as
 * `R  This test printed output: …` against **B-13** — no plugin name, no handler name, no
 * indication which of the four states produced it, and the reader sent to the harness rather
 * than to the plugin. {@see TierB15NoOutput} exists to replace exactly that report, so
 * emitting it from here defeated the point.
 *
 * The bytes are **discarded** here rather than reported, and that is only defensible because
 * B-15 executes `getMenu()` in every state this inspector does. It reads {@see combinations()}
 * directly to do so, so the two state lists are one list; before R-8 it ran a single
 * `ima=admin` + grant-all pass, and a handler that printed only for clients would have been
 * captured here and reported nowhere. Reporting the bytes in this column *as well* would put
 * one defect in two matrix cells, which is what the deferral in B-15's docblock refuses to do
 * for throws and refuses symmetrically here.
 *
 * `Finding::notice()` is not the compromise it looks like. Since R-5 the test case does read
 * notices, so nothing is swallowed any more — but a notice still leaves the matrix cell the
 * colour it would have been, and one defect reported in two columns is the thing being
 * avoided, not one defect reported quietly.
 *
 * ---------------------------------------------------------------------------------
 * ORDERING AND ISOLATION
 * ---------------------------------------------------------------------------------
 * Constants must be defined before the plugin class is touched (see
 * `ConstantOrderingTest`), so {@see TierB13MenuExecute::prime()} initialises with
 * `constants` + `plugin` before reading `$module`. Each of the four combinations then
 * gets its own `Harness::reset()` and its own explicit `ima`/`acl`, so combination *n*
 * cannot leave a menu entry, an ACL grant or a panel setting behind for combination
 * *n+1* — or, once the self-check is iterating 69 plugins in one process, for the next
 * plugin.
 */
class TierB13MenuExecute implements PluginInspector
{
    /** @var string catalogue id */
    const ID = 'B-13';

    /** @var string the handler this inspector executes */
    const METHOD = 'getMenu';

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
        return 'getMenu() executes clean for admin and client, with and without ACL';
    }

    /**
     * The four states a menu handler can be rendered in.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function combinations()
    {
        return [
            ['ima' => 'admin',  'grant' => true,  'label' => 'ima=admin, has_acl()=true'],
            ['ima' => 'admin',  'grant' => false, 'label' => 'ima=admin, has_acl()=false'],
            ['ima' => 'client', 'grant' => true,  'label' => 'ima=client, has_acl()=true'],
            ['ima' => 'client', 'grant' => false, 'label' => 'ima=client, has_acl()=false'],
        ];
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
        if (!$reflection->hasMethod(self::METHOD)) {
            // 28 of 71 plugins genuinely have no menu. That is not a pass — an empty result
            // here would let the matrix claim coverage it does not have — and since R-4 it is
            // not a skip either. A skip claims the check could not run; reflection answered
            // the question outright, and the answer is that this package has no menu handler
            // for the assertion to be about. See Finding::NOT_APPLICABLE.
            return [Finding::notApplicable(
                self::ID,
                'plugin declares no ' . self::METHOD . '(), so there is nothing to execute',
                ['class' => $subject->pluginClass(), 'method' => self::METHOD]
            )];
        }

        $method = $reflection->getMethod(self::METHOD);
        if (!$method->isPublic() || !$method->isStatic()) {
            return [Finding::failure(
                self::ID,
                sprintf(
                    '%s::%s() is not public static, so the callable core dispatches can never invoke it',
                    $subject->pluginClass(),
                    self::METHOD
                ),
                ['class' => $subject->pluginClass(), 'method' => self::METHOD]
            )];
        }

        $module = $this->prime($subject);

        $findings = [];
        foreach (self::combinations() as $combination) {
            $finding = $this->runCombination($subject, $method, $module, $combination);
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
     * Runs `getMenu()` once, in one panel/ACL state.
     *
     * @param \MyAdmin\Plugins\Testing\Contract\PluginSubject $subject
     * @param \ReflectionMethod                              $method
     * @param string|null                                    $module
     * @param array<string,mixed>                            $combination
     * @return \MyAdmin\Plugins\Testing\Contract\Finding|null null when this combination passed
     */
    private function runCombination(PluginSubject $subject, ReflectionMethod $method, $module, array $combination)
    {
        $this->configure($subject, $module, $combination);
        $menuFake = Harness::menu();

        $prepared = SubjectEvent::argumentsFor(
            $method,
            $menuFake,
            $subject,
            self::ID,
            ['combination' => $combination['label']]
        );
        if ($prepared['skip'] !== null) {
            return $prepared['skip'];
        }

        $args = $prepared['args'];
        $run = TierB15NoOutput::capture(function () use ($method, $args) {
            $method->invokeArgs(null, $args);
        });

        if ($run['error'] !== null) {
            return Finding::failure(
                self::ID,
                sprintf(
                    '%s::%s() threw with %s — %s: %s',
                    $subject->pluginClass(),
                    self::METHOD,
                    $combination['label'],
                    get_class($run['error']),
                    $run['error']->getMessage()
                ),
                [
                    'class'       => $subject->pluginClass(),
                    'method'      => self::METHOD,
                    'combination' => $combination['label'],
                    'ima'         => $combination['ima'],
                    'acl'         => $combination['grant'] ? 'granted' : 'denied',
                    'exception'   => get_class($run['error']),
                ]
            );
        }

        return null;
    }

    // -----------------------------------------------------------------------
    // Harness plumbing
    // -----------------------------------------------------------------------

    /**
     * Brings the harness up for one plugin and returns its declared module.
     *
     * The first `init()` exists solely to define the plugin's bare constants: until it
     * has run, merely *reading* `$module` evaluates the class's static initializers and
     * can fatal with `Error: Undefined constant`. Only then is the module readable, and
     * the second call wires `register_module()` with it.
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
     * Clears everything the previous combination recorded and seeds this one's state.
     *
     * @param \MyAdmin\Plugins\Testing\Contract\PluginSubject $subject
     * @param string|null                                    $module
     * @param array<string,mixed>                            $combination
     * @return void
     */
    private function configure(PluginSubject $subject, $module, array $combination)
    {
        Harness::reset();
        Bootstrap::init([
            'module'    => ($module === null || $module === '') ? 'default' : $module,
            'constants' => $subject->constantOverrides(),
            'plugin'    => $subject->pluginClass(),
            'defines'   => $subject->serviceDefines(),
            'ima'       => $combination['ima'],
            'acl'       => $combination['grant'] ? true : [],
        ]);
        // init() itself records calls on the fakes while wiring them; drop those so the
        // handler runs against an empty FakeMenu.
        Harness::reset();
    }
}
