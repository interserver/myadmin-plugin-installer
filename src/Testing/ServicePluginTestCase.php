<?php
/**
 * @author Joe Huss <detain@interserver.net>
 * @copyright 2026
 * @package MyAdmin
 * @category Testing
 */

namespace MyAdmin\Plugins\Testing;

use MyAdmin\Plugins\Testing\Contract\Finding;
use MyAdmin\Plugins\Testing\Contract\PluginSubject;
use MyAdmin\Plugins\Testing\Contract\SubjectEvent;
use Throwable;

/**
 * Behavioural assertions for a plugin's service-lifecycle handlers, on top of the
 * eighteen structural ones {@see PluginContractTestCase} already runs.
 *
 * A repo gets both by changing which class it extends:
 *
 *     class PluginContractTest extends ServicePluginTestCase
 *     {
 *         protected function pluginClass()
 *         {
 *             return \Detain\MyAdminCpanel\Plugin::class;
 *         }
 *     }
 *
 * ---------------------------------------------------------------------------------
 * THE BUG CLASS
 * ---------------------------------------------------------------------------------
 * Every lifecycle hook in MyAdmin is a **shared** channel. `licenses.activate` has ten
 * listeners; `licenses.reactivate` ten; `licenses.deactivate` and `licenses.deactivate_ip`
 * eight each; `vps.deactivate` and `vps.queue` seven each. **59 of the fleet's 92 lifecycle
 * handlers sit on a key another plugin also listens to.**
 *
 * (A further 18 are declared but registered by no `getHooks()` at all — every `*-vps
 * ::getActivate()` has its `vps.activate` line commented out, and no `*-webhosting` package
 * registers a `change_ip` hook. They are dead code today, which is why the shared-key count
 * is 59 rather than the 75 a count of *handlers* rather than *registrations* produces. They
 * are still checked here: dead code that gets rewired later should already be correct.)
 *
 * Symfony's dispatcher runs listeners in order and stops the moment one calls
 * `stopPropagation()`. So the contract every one of those handlers is implicitly signed up
 * to is: *look at the service type first, and if it is not yours, do nothing and let the
 * event past.* A handler that gets that wrong does not fail loudly. It silently eats the
 * event for the other nine plugins, and the report reads "activation randomly stopped
 * working for product X after we installed plugin Y" — arriving weeks later, from a
 * customer, with nothing in a log to point at the cause.
 *
 * Two assertions per handler cover the two directions:
 *
 *  - **A — on a type it owns, the handler acts.** Something observable happens. A handler
 *    whose gate has drifted narrower than its hook registration is dead code, and dead code
 *    on a lifecycle hook means a service that silently never gets provisioned.
 *  - **B — on a type it does not own, the handler is inert.** Nothing observable happens
 *    *anywhere*, and `isPropagationStopped()` is false. This is the one that guards the nine
 *    other plugins.
 *
 * ---------------------------------------------------------------------------------
 * EXPECTED YIELD, AND WHY THAT IS NOT AN ARGUMENT AGAINST THE PHASE
 * ---------------------------------------------------------------------------------
 * Assertion B currently fails **nothing**. All 84 gated handlers in the fleet were run
 * against a foreign type and all 84 were completely inert: no recorder moved, nothing
 * printed, no propagation stopped. That is the correct and expected result, and it is worth
 * stating plainly so nobody later reads the green column as evidence the check is not wired
 * up. It is a regression guard over a large, dangerous, mostly-correct surface — the same
 * shape as the A-9 and B-9 columns the fleet matrix already annotates "0 yield".
 *
 * The value is in the day someone adds a plugin to `licenses.activate` and forgets the gate.
 * Today that ships. With B, it does not.
 *
 * Assertion A is the one that found things; see below.
 *
 * ---------------------------------------------------------------------------------
 * WHERE THIS DEPARTS FROM THE PHASE PLAN, AND WHY
 * ---------------------------------------------------------------------------------
 * **1. Assertion A does not require `isPropagationStopped() === true`.**
 *
 * The plan specifies it. Enforced, it produces seven false failures, and the seven are right
 * and the rule is wrong. `myadmin-{docker,hyperv,kvm,lxc,openvz,virtuozzo,xen}-vps
 * ::getDeactivate()` each gate on their own VPS types, act, and deliberately do **not** stop
 * propagation. All seven sit on `vps.deactivate` together. Because their gates are mutually
 * exclusive, exactly one can ever match, and stopping is unnecessary — declining to stop is
 * a legitimate design, not an oversight.
 *
 * The propagation contract that *is* real is one-directional and lives entirely in assertion
 * B: **do not stop an event you do not own.** Whether a handler stops one it does own is its
 * own business. Demanding symmetry would have converted a correct pattern into seven bug
 * reports and taught seven maintainers to distrust the harness.
 *
 * **2. An ungated handler is not automatically a defect, but an ungated handler that stops
 * propagation is disclosed.**
 *
 * Eight handlers have no `get_service_define()` gate at all. Five never stop propagation and
 * are simply the only listener on a private key — nothing to guard against. The remaining
 * three (`servers-module::getActivate`, `servers-module::getDeactivate`,
 * `quickservers-module::getQueue`) do stop, unconditionally, for every event that reaches
 * them. Each is currently alone on its hook key, so none is breaking anything today.
 *
 * They are reported as a {@see Finding::notice()} — visible, non-fatal, no matrix cell moved.
 * A failure would be manufacturing a defect that does not exist yet; silence would drop the
 * fact that these three cannot ever share a hook key safely. `quickservers-module::getQueue`
 * is the one to look at first: its gate is not missing, it is **commented out**, directly
 * above a `stopPropagation()` that still runs.
 *
 * **3. The gate key is derived, not assumed.** 46 handlers gate on `$event['category']` and
 * 38 on `$event['type']`. {@see ServiceHandlerProbe} explains at length why assuming either
 * one silently disables assertion B across half the fleet.
 *
 * ---------------------------------------------------------------------------------
 * ASSERTION A IS NOT UNIVERSALLY ENFORCEABLE — SAYING SO RATHER THAN WEAKENING IT
 * ---------------------------------------------------------------------------------
 * Running a handler on a type it owns runs the real lifecycle action. The harness fakes
 * MyAdmin's core surface and cannot fake a plugin's own vendored API client, so a handler
 * will frequently reach a symbol that is simply not there. Measured across the fleet's 84
 * gated handlers on the matching path:
 *
 *     72  produced an observable effect                    -> A is verified
 *     10  produced nothing and died on a missing symbol    -> A is skipped, honestly
 *      2  produced nothing and died of its own logic       -> A fails, correctly
 *      0  produced nothing and ran cleanly and did nothing
 *
 * The temptation is to treat "it threw" as good enough — the handler clearly *did* something.
 * That is the fabricated pass this phase is supposed to avoid, so the discriminator is
 * mechanical and lives in {@see ServiceHandlerProbe::UNRESOLVABLE}: a throw naming a symbol
 * the environment cannot supply is a **skip carrying `blockedBy`**; a throw that is the
 * handler's own logic failing is a **failure**. Everything else is judged on whether a
 * recorder moved, not on whether execution completed.
 *
 * The two failures that discriminator preserves are both real and both live:
 *
 *  - `myadmin-xen-vps::getDeactivate()` calls `$serviceClass->getId()` on the line *before*
 *    `$serviceClass = $event->getSubject();`, so it fatals on every Xen deactivation;
 *  - `myadmin-plesk-webhosting::getChangeIp()` constructs `new Plesk($user, $pass)` where
 *    `Plesk::__construct()` takes `($host, $login, $password)`, so it fatals on every Plesk
 *    IP change.
 *
 * Twelve skips would have buried both; twelve failures would have hidden them in ten
 * false ones.
 *
 * **The fakes are not a sandbox.** `myadmin-zonemta-mail::getDeactivate()` opens a MongoDB
 * connection and calls `deleteOne()`; under test it failed only because the host was a
 * harness sentinel that did not resolve. {@see ServiceHandlerProbe::unownedConstants()}
 * refuses to run assertion A when real configuration is present, and
 * {@see exercisesOwnedTypes()} turns it off entirely for a repo whose handlers do real I/O.
 * Assertion B is unaffected by all of this: a handler that returns at its gate does nothing
 * at all, which is both the point of the assertion and the reason it is safe to run
 * everywhere.
 *
 * ---------------------------------------------------------------------------------
 * ONE TEST PER HANDLER NAME, ALWAYS SEVEN
 * ---------------------------------------------------------------------------------
 * The providers are `static` and yield all seven handler names unconditionally, rather than
 * reflecting over the plugin to yield only the ones it declares. A provider that needed the
 * plugin class would have to be an instance method — deprecated in PHPUnit 10 and a
 * different shape from {@see PluginContractTestCase::contractAssertions()}, which is static.
 * A handler the plugin does not declare comes back {@see Finding::notApplicable()}, exactly
 * as B-13 answers for a plugin with no `getMenu()`.
 *
 * ---------------------------------------------------------------------------------
 * WHY THIS IS A TEST CASE AND NOT TWO MORE CATALOGUE INSPECTORS
 * ---------------------------------------------------------------------------------
 * {@see Contract\InspectorRegistry::classes()} discovers inspectors by globbing
 * `src/Testing/Contract/`. Two inspectors there would become two new columns and turn the
 * G2 fleet matrix from 18x71 into 20x71, changing the 1278-cell census the gate is reviewed
 * against. Phase 3 is additive to a repo's own test run and deliberately invisible to the
 * matrix; nothing in this phase lives under `Contract/`.
 *
 * ---------------------------------------------------------------------------------
 * NOT A SERVICE-ONLY CLASS, DESPITE THE NAME
 * ---------------------------------------------------------------------------------
 * Six of the 31 packages carrying lifecycle handlers declare `$type = 'module'`, not
 * `'service'`: backups, floating-ips, mail, quickservers, servers and scrub-ips. Two of them
 * call `stopPropagation()`. Gating this class on `$type === 'service'` would have excluded
 * `servers-module`, which is one of only three packages with anything to disclose.
 *
 * The name is kept because it describes the *handlers* — the service lifecycle — and not the
 * packages. Nothing here reads `$type`, and nothing should start.
 */
abstract class ServicePluginTestCase extends PluginContractTestCase
{
    /** Catalogue id of assertion A, for findings and the ledger. */
    const ASSERTION_ACTS = 'S-1';

    /** Catalogue id of assertion B. */
    const ASSERTION_INERT = 'S-2';

    /**
     * Runs of assertion A/B in which a repo declared its own owned types.
     *
     * Structured, static and separate from {@see PluginContractTestCase::overrideLedger()}
     * for the reasons that ledger's docblock gives, plus one specific to here:
     * {@see PluginSubject} declares exactly four hatches and `overridesInUse()` is driven by
     * that list. Teaching it a fifth would touch a class every one of the eighteen
     * inspectors depends on, to record something none of them can act on. The hatch is real
     * — a repo that declares a narrow `handledTypes()` can make assertion A pass on a type it
     * chose and assertion B never look at the ones it left out — so it is logged here rather
     * than not at all.
     *
     * @var array<int,array<string,mixed>>
     */
    private static $serviceOverrideLedger = [];

    /**
     * The service types each handler owns, or {@see PluginContractTestCase::NOT_SET} to read
     * them out of the handler's own source.
     *
     * Deriving is the default and is almost always right, because the scan reads the very
     * expression the handler evaluates. Declare only when the scan cannot see the gate — a
     * gate built from a variable, or one that lives in a helper the handler calls.
     *
     * Two accepted shapes:
     *
     *     return ['CPANEL', 'CPANEL_VPS'];                        // applies to every handler
     *     return ['getActivate' => ['CPANEL'], 'getChangeIp' => []];   // per handler
     *
     * Values are `get_service_define()` **names**, not ids; the harness resolves them the
     * same way the handler does, so a repo does not have to know what any of them evaluate
     * to. An empty array for a handler means "this handler owns nothing", which makes
     * assertion A not-applicable and assertion B fall back to its ungated reasoning.
     *
     * Declaring is a logged escape hatch. See {@see serviceOverrideLedger()}.
     *
     * @return array<int|string,mixed>|string
     */
    protected function handledTypes()
    {
        return self::NOT_SET;
    }

    /**
     * The service id assertion B drives handlers with.
     *
     * The default is a single synthetic id far outside both the real range and
     * {@see Fakes\FakeApp::syntheticDefine()}'s band, so it cannot accidentally equal a type
     * the plugin owns. Override only to add a *specific* neighbour's type — driving
     * `myadmin-cpanel-licensing` with the real `DIRECTADMIN` id is a sharper version of the
     * same assertion, because it is the collision that actually happens in production.
     *
     * @return array<int,int|string> ids, or `get_service_define()` names
     */
    protected function foreignTypes()
    {
        return [ServiceHandlerProbe::FOREIGN_TYPE];
    }

    /**
     * Whether assertion A may execute handlers on a type they own.
     *
     * Return false for a repo whose handlers reach real infrastructure on the matching path.
     * Assertion A then reports {@see Finding::skipped()} — never a pass — and assertion B
     * keeps running, because a handler that returns at its gate touches nothing.
     *
     * `myadmin-zonemta-mail` is the worked example: its `getDeactivate()` and `getTerminate()`
     * construct a `MongoDB\Client` and call `deleteOne()`. See
     * {@see ServiceHandlerProbe::unownedConstants()} for the guard that runs regardless of
     * this setting, and for exactly how much it does and does not protect.
     *
     * @return bool
     */
    protected function exercisesOwnedTypes()
    {
        return true;
    }

    /**
     * One case per lifecycle handler name. Always all seven; see the class docblock.
     *
     * @return array<string,array{0:string}>
     */
    public static function serviceLifecycleHandlers()
    {
        $cases = [];
        foreach (ServiceHandlerProbe::HANDLERS as $handler) {
            $cases[$handler . '()'] = [$handler];
        }
        return $cases;
    }

    /**
     * Assertion A — on a service type it owns, the handler does something observable.
     *
     * @dataProvider serviceLifecycleHandlers
     * @param string $handler
     * @return void
     */
    public function testHandlerActsOnAServiceTypeItOwns($handler)
    {
        $this->reportFindings(self::ASSERTION_ACTS, $handler, $this->inspectActs($handler));
    }

    /**
     * Assertion B — on a service type it does not own, the handler changes nothing and lets
     * the event past.
     *
     * @dataProvider serviceLifecycleHandlers
     * @param string $handler
     * @return void
     */
    public function testHandlerIsInertForAForeignServiceType($handler)
    {
        $this->reportFindings(self::ASSERTION_INERT, $handler, $this->inspectInert($handler));
    }

    // -----------------------------------------------------------------------
    // Assertion A
    // -----------------------------------------------------------------------

    /**
     * @param string $handler
     * @return array<int,\MyAdmin\Plugins\Testing\Contract\Finding>
     */
    protected function inspectActs($handler)
    {
        $subject = $this->contractSubject();
        $unusable = $this->handlerUnusable(self::ASSERTION_ACTS, $subject, $handler);
        if ($unusable !== null) {
            return [$unusable];
        }

        $gate = $this->gateFor($subject, $handler);
        $context = $this->contextFor($subject, $handler, $gate);

        if ($gate['defines'] === []) {
            // Nothing to drive it with. The ungated case is assertion B's to reason about;
            // here there is simply no "type it owns" for the sentence to be about.
            return [Finding::notApplicable(
                self::ASSERTION_ACTS,
                sprintf(
                    '%s::%s() compares no event argument against get_service_define(), so it claims'
                    . ' no service type of its own and there is no owned type to drive it with'
                    . ' (%s). Whether that is safe is assertion %s\'s question, not this one\'s.',
                    $subject->pluginClass(),
                    $handler,
                    $gate['source'],
                    self::ASSERTION_INERT
                ),
                $context
            )];
        }

        if (!$this->exercisesOwnedTypes()) {
            return [Finding::skipped(
                self::ASSERTION_ACTS,
                sprintf(
                    '%s::%s() was not executed: this repo sets exercisesOwnedTypes() to false, because'
                    . ' driving the handler with a type it owns performs the real lifecycle action.'
                    . ' Assertion %s still ran.',
                    $subject->pluginClass(),
                    $handler,
                    self::ASSERTION_INERT
                ),
                array_merge($context, ['blockedBy' => 'exercisesOwnedTypes()'])
            )];
        }

        $unowned = ServiceHandlerProbe::unownedConstants($subject);
        if ($unowned !== []) {
            return [Finding::skipped(
                self::ASSERTION_ACTS,
                sprintf(
                    '%s::%s() was not executed: %s already hold real values this process did not'
                    . ' define, so the harness is not in control of this plugin\'s configuration and'
                    . ' running the handler could reach real infrastructure. Run the contract tests'
                    . ' from the plugin repo rather than inside a configured MyAdmin checkout.',
                    $subject->pluginClass(),
                    $handler,
                    implode(', ', array_slice($unowned, 0, 6))
                ),
                array_merge($context, ['blockedBy' => 'real configuration constants'])
            )];
        }

        $owned = $this->resolveType($gate['defines'][0]);
        $arguments = ServiceHandlerProbe::seedArguments($gate, $owned);
        $run = ServiceHandlerProbe::run($subject, $handler, $arguments, self::ASSERTION_ACTS);
        SubjectEvent::releaseHarness();

        if ($run['skip'] !== null) {
            return [$run['skip']];
        }

        if ($run['effects'] !== []) {
            // The claim is "it acts", and a recorder moving proves it. Whatever happens after
            // that does not unprove it.
            if ($run['error'] === null || ServiceHandlerProbe::isUnresolvableDependency($run['error'])) {
                // Silent. Running out of road at a symbol the harness cannot supply is the
                // *normal* end of a matching-type run — it happens to 44 of the fleet's 84
                // gated handlers — and it says nothing about the plugin. An earlier revision
                // reported it as a notice and turned 26 of 31 packages yellow for a fact that
                // is true of the harness rather than of any of them. A signal that fires on
                // the common case is not a signal.
                return [];
            }
            // A throw that is *not* a missing symbol is the handler's own logic failing after
            // it had already started work. Assertion A is still satisfied — it acted — so this
            // is not a failure and must not be reported as one. But it is a genuine
            // observation about this plugin, and it is rare enough to be worth reading.
            return [Finding::notice(
                self::ASSERTION_ACTS,
                sprintf(
                    '%s::%s() acted on %s (%s) and then threw %s — "%s". The assertion is satisfied,'
                    . ' because the effects were observed first. It is reported because the throw'
                    . ' names no missing symbol: every class and function it referenced was present,'
                    . ' so this may be the handler failing part-way rather than the harness running'
                    . ' out of road. Worth a look.',
                    $subject->pluginClass(),
                    $handler,
                    $gate['defines'][0],
                    implode(', ', $run['effects']),
                    get_class($run['error']),
                    $run['error']->getMessage()
                ),
                array_merge($context, ['exception' => get_class($run['error'])])
            )];
        }

        if ($run['error'] !== null && ServiceHandlerProbe::isUnresolvableDependency($run['error'])) {
            return [Finding::skipped(
                self::ASSERTION_ACTS,
                sprintf(
                    '%s::%s() could not be verified: it reached a symbol this environment does not'
                    . ' provide before doing anything observable — %s: "%s". That is the harness\'s'
                    . ' limit, not a defect; reporting it as a pass would claim a check that never'
                    . ' happened.',
                    $subject->pluginClass(),
                    $handler,
                    get_class($run['error']),
                    $run['error']->getMessage()
                ),
                array_merge($context, [
                    'blockedBy' => $run['error']->getMessage(),
                    'exception' => get_class($run['error']),
                ])
            )];
        }

        if ($run['error'] !== null) {
            return [Finding::failure(
                self::ASSERTION_ACTS,
                sprintf(
                    '%s::%s() threw on a service type it owns (%s) before doing anything observable —'
                    . ' %s: "%s". This is not a missing dependency: the symbols it named are all'
                    . ' present, so the handler failed on its own logic and cannot be doing its job in'
                    . ' production either.',
                    $subject->pluginClass(),
                    $handler,
                    $gate['defines'][0],
                    get_class($run['error']),
                    $run['error']->getMessage()
                ),
                array_merge($context, ['exception' => get_class($run['error'])])
            )];
        }

        return [Finding::failure(
            self::ASSERTION_ACTS,
            sprintf(
                '%s::%s() ran to completion on %s — a service type its own gate says it owns — and'
                . ' changed nothing: no log entry, no history row, no query, no output, no event'
                . ' argument. A handler registered on a lifecycle hook that does nothing when its'
                . ' type arrives is dead code, and the service it is meant to provision silently'
                . ' never gets provisioned.',
                $subject->pluginClass(),
                $handler,
                $gate['defines'][0]
            ),
            $context
        )];
    }

    // -----------------------------------------------------------------------
    // Assertion B
    // -----------------------------------------------------------------------

    /**
     * @param string $handler
     * @return array<int,\MyAdmin\Plugins\Testing\Contract\Finding>
     */
    protected function inspectInert($handler)
    {
        $subject = $this->contractSubject();
        $unusable = $this->handlerUnusable(self::ASSERTION_INERT, $subject, $handler);
        if ($unusable !== null) {
            return [$unusable];
        }

        $gate = $this->gateFor($subject, $handler);
        $context = $this->contextFor($subject, $handler, $gate);

        if ($gate['defines'] === []) {
            return [$this->ungatedFinding($subject, $handler, $gate, $context)];
        }

        $findings = [];
        foreach ($this->foreignTypes() as $foreign) {
            $finding = $this->runForeign($subject, $handler, $gate, $context, $foreign);
            if ($finding !== null) {
                $findings[] = $finding;
            }
        }
        return $findings;
    }

    /**
     * One foreign-type run.
     *
     * @param \MyAdmin\Plugins\Testing\Contract\PluginSubject $subject
     * @param string                                          $handler
     * @param array<string,mixed>                             $gate
     * @param array<string,mixed>                             $context
     * @param int|string                                      $foreign
     * @return \MyAdmin\Plugins\Testing\Contract\Finding|null null when this run was clean
     */
    private function runForeign(PluginSubject $subject, $handler, array $gate, array $context, $foreign)
    {
        $value = $this->resolveType($foreign);
        // A "foreign" id that the plugin actually owns would make this assertion test the
        // opposite of what it claims, and it would fail loudly and look like a real defect.
        foreach ($gate['defines'] as $name) {
            if ($this->resolveType($name) === $value) {
                return Finding::skipped(
                    self::ASSERTION_INERT,
                    sprintf(
                        'the foreign service type supplied for %s::%s() resolves to the same id as'
                        . ' %s, which this handler owns, so it cannot demonstrate inertness.'
                        . ' Change foreignTypes().',
                        $subject->pluginClass(),
                        $handler,
                        $name
                    ),
                    array_merge($context, ['blockedBy' => 'foreignTypes() collides with an owned type'])
                );
            }
        }

        $arguments = ServiceHandlerProbe::seedArguments($gate, $value);
        $run = ServiceHandlerProbe::run($subject, $handler, $arguments, self::ASSERTION_INERT);
        SubjectEvent::releaseHarness();

        if ($run['skip'] !== null) {
            return $run['skip'];
        }

        $context = array_merge($context, ['foreignType' => $value]);

        if ($run['stopped']) {
            return Finding::failure(
                self::ASSERTION_INERT,
                sprintf(
                    '%s::%s() called stopPropagation() for service type %s, which it does not own'
                    . ' (it owns %s). Symfony stops dispatching the moment a listener does that, so'
                    . ' every plugin registered after this one on the same hook key is silently'
                    . ' skipped for a service that was never this plugin\'s to handle. The symptom is'
                    . ' another product\'s lifecycle quietly ceasing to work.%s',
                    $subject->pluginClass(),
                    $handler,
                    $value,
                    implode('/', $gate['defines']),
                    $run['effects'] === [] ? '' : ' It also touched: ' . implode(', ', $run['effects']) . '.'
                ),
                array_merge($context, ['stoppedPropagation' => true])
            );
        }

        if ($run['effects'] !== []) {
            return Finding::failure(
                self::ASSERTION_INERT,
                sprintf(
                    '%s::%s() acted on service type %s, which it does not own (it owns %s): %s.'
                    . ' A lifecycle handler must return without touching anything when the event is'
                    . ' not its business.',
                    $subject->pluginClass(),
                    $handler,
                    $value,
                    implode('/', $gate['defines']),
                    implode(', ', $run['effects'])
                ),
                $context
            );
        }

        if ($run['error'] !== null) {
            return Finding::failure(
                self::ASSERTION_INERT,
                sprintf(
                    '%s::%s() threw on service type %s, which it does not own — %s: "%s". Whatever the'
                    . ' cause, the handler executed code outside its own gate: a handler that does not'
                    . ' own the type should have returned before reaching anything that can throw.',
                    $subject->pluginClass(),
                    $handler,
                    $value,
                    get_class($run['error']),
                    $run['error']->getMessage()
                ),
                array_merge($context, ['exception' => get_class($run['error'])])
            );
        }

        return null;
    }

    /**
     * The verdict for a handler with no `get_service_define()` gate.
     *
     * See the class docblock, point 2, for why the stopping case is a notice rather than a
     * failure and why the rest is not-applicable rather than a pass.
     *
     * @param \MyAdmin\Plugins\Testing\Contract\PluginSubject $subject
     * @param string                                          $handler
     * @param array<string,mixed>                             $gate
     * @param array<string,mixed>                             $context
     * @return \MyAdmin\Plugins\Testing\Contract\Finding
     */
    private function ungatedFinding(PluginSubject $subject, $handler, array $gate, array $context)
    {
        if ($gate['stops']) {
            return Finding::notice(
                self::ASSERTION_INERT,
                sprintf(
                    '%s::%s() compares no event argument against get_service_define() and yet calls'
                    . ' stopPropagation(), so it claims every event that reaches it. That is safe only'
                    . ' for as long as it is the sole listener on its hook key; the day a second'
                    . ' plugin registers on the same key, this handler silences it for every service'
                    . ' type. Not reported as a failure because nothing is broken today — but this'
                    . ' handler can never share a hook key. (%s)',
                    $subject->pluginClass(),
                    $handler,
                    $gate['source']
                ),
                array_merge($context, ['ungatedAndStops' => true])
            );
        }

        return Finding::notApplicable(
            self::ASSERTION_INERT,
            sprintf(
                '%s::%s() compares no event argument against get_service_define(), so it owns no'
                . ' particular service type and there is no foreign type for it to be inert against.'
                . ' It never calls stopPropagation(), so it cannot silence a co-listener either. (%s)',
                $subject->pluginClass(),
                $handler,
                $gate['source']
            ),
            $context
        );
    }

    // -----------------------------------------------------------------------
    // Shared plumbing
    // -----------------------------------------------------------------------

    /**
     * The reasons a handler cannot be inspected at all, shared by both assertions.
     *
     * @param string                                          $id
     * @param \MyAdmin\Plugins\Testing\Contract\PluginSubject $subject
     * @param string                                          $handler
     * @return \MyAdmin\Plugins\Testing\Contract\Finding|null null when it can be inspected
     */
    private function handlerUnusable($id, PluginSubject $subject, $handler)
    {
        if (!$subject->isLoadable()) {
            return Finding::skipped(
                $id,
                'plugin class is not loadable, so no handler can be executed',
                ['class' => $subject->pluginClass(), 'method' => $handler, 'blockedBy' => 'class not loadable']
            );
        }

        $this->primeConstants();
        Bootstrap::init([
            'module'    => $this->moduleFor($subject),
            'constants' => $subject->constantOverrides(),
            'plugin'    => $subject->pluginClass(),
            'defines'   => $subject->serviceDefines(),
            'acl'       => true,
            'ima'       => 'admin',
        ]);

        $reflection = $subject->reflection();
        if (!$reflection->hasMethod($handler)) {
            return Finding::notApplicable(
                $id,
                sprintf('plugin declares no %s(), so there is no lifecycle handler of this kind to check', $handler),
                ['class' => $subject->pluginClass(), 'method' => $handler]
            );
        }

        $method = $reflection->getMethod($handler);
        if (!$method->isPublic() || !$method->isStatic()) {
            return Finding::failure(
                $id,
                sprintf(
                    '%s::%s() is not public static, so the callable getHooks() registers can never be'
                    . ' invoked by the dispatcher',
                    $subject->pluginClass(),
                    $handler
                ),
                ['class' => $subject->pluginClass(), 'method' => $handler]
            );
        }

        return null;
    }

    /**
     * The plugin's declared module, defaulted for a plugin that declares none.
     *
     * Read only after a first `Bootstrap::init()` has primed constants: touching any static
     * on a class whose `$settings` initializer references `PRORATE_BILLING` evaluates *every*
     * initializer and fatals. {@see PluginSubject::staticProperty()} documents the semantic;
     * {@see primeConstants()} is what makes reading `$module` here safe.
     *
     * @param \MyAdmin\Plugins\Testing\Contract\PluginSubject $subject
     * @return string
     */
    private function moduleFor(PluginSubject $subject)
    {
        $module = $subject->module();
        return ($module === null || $module === '') ? 'default' : $module;
    }

    /**
     * The gate for one handler, honouring a repo declaration and logging it when present.
     *
     * @param \MyAdmin\Plugins\Testing\Contract\PluginSubject $subject
     * @param string                                          $handler
     * @return array{key:string|null,defines:array<int,string>,stops:bool,source:string,readKeys:array<int,string>}
     */
    protected function gateFor(PluginSubject $subject, $handler)
    {
        $declared = $this->declaredTypesFor($handler);
        $gate = ServiceHandlerProbe::gateFor($subject, $handler, $declared);
        if ($declared !== null) {
            self::$serviceOverrideLedger[] = [
                'plugin'    => $subject->pluginClass(),
                'handler'   => $handler,
                'override'  => 'handledTypes',
                'declared'  => $declared,
                'gateKey'   => $gate['key'],
            ];
        }
        return $gate;
    }

    /**
     * The repo's declared owned types for one handler, or null when it declared none.
     *
     * @param string $handler
     * @return array<int,string>|null
     */
    private function declaredTypesFor($handler)
    {
        $declared = $this->handledTypes();
        if ($declared === self::NOT_SET || !is_array($declared)) {
            return null;
        }
        if (array_key_exists($handler, $declared)) {
            return array_values((array)$declared[$handler]);
        }
        // A flat list applies to every handler; a per-handler map that omits this one does
        // not. Distinguished by whether any key is a handler name, which is unambiguous
        // because a service-define name is never one of the seven.
        foreach (array_keys($declared) as $key) {
            if (in_array($key, ServiceHandlerProbe::HANDLERS, true)) {
                return null;
            }
        }
        return array_values($declared);
    }

    /**
     * Resolves a `get_service_define()` name to the id the handler will compare against.
     *
     * An int passes straight through, so `foreignTypes()` can return either.
     *
     * @param int|string $type
     * @return mixed
     */
    protected function resolveType($type)
    {
        if (is_int($type)) {
            return $type;
        }
        if (function_exists('get_service_define')) {
            return get_service_define($type);
        }
        return Fakes\FakeApp::getServiceDefine($type);
    }

    /**
     * The context every finding for one handler carries.
     *
     * `gateSource` is on every finding on purpose: a reader must never have to guess whether
     * they are looking at a scanned fact or a repo declaration.
     *
     * @param \MyAdmin\Plugins\Testing\Contract\PluginSubject $subject
     * @param string                                          $handler
     * @param array<string,mixed>                             $gate
     * @return array<string,mixed>
     */
    private function contextFor(PluginSubject $subject, $handler, array $gate)
    {
        return [
            'class'      => $subject->pluginClass(),
            'method'     => $handler,
            'gateKey'    => $gate['key'],
            'ownedTypes' => $gate['defines'] === [] ? '(none)' : implode('/', $gate['defines']),
            'gateSource' => $gate['source'],
        ];
    }

    /**
     * Turns findings into a PHPUnit outcome, in the same precedence
     * {@see PluginContractTestCase::testPluginSatisfiesContractAssertion()} uses.
     *
     * Reimplemented rather than shared because that method is a `@dataProvider` test body
     * bound to the inspector interface, and calling it would need an inspector object this
     * phase deliberately does not create. The precedence — fail, then all-skip-or-NA, then
     * assert, then notice — is kept identical so a reader who has learned one has learned
     * both, and {@see PluginContractTestCase::outcomeOf()} is reused so the two cannot drift
     * on what a mixed set of findings means.
     *
     * @param string                                               $id
     * @param string                                               $handler
     * @param array<int,\MyAdmin\Plugins\Testing\Contract\Finding> $findings
     * @return void
     */
    protected function reportFindings($id, $handler, array $findings)
    {
        $failures = [];
        $skips = [];
        $notices = [];
        $inapplicable = [];
        foreach ($findings as $finding) {
            if ($finding->isFailure()) {
                $failures[] = $finding->describe();
            } elseif ($finding->isSkipped()) {
                $skips[] = $finding->describe();
            } elseif ($finding->isNotice()) {
                $notices[] = $finding->describe();
            } elseif ($finding->isNotApplicable()) {
                $inapplicable[] = $finding->describe();
            }
        }

        if ($failures !== []) {
            $this->fail(
                $id . ' — ' . self::titleFor($id) . ' — ' . $handler . "()\n"
                . implode("\n", $failures)
            );
        }

        if (($skips !== [] || $inapplicable !== [])
            && count($skips) + count($inapplicable) === count($findings)) {
            $this->markTestSkipped(
                $skips === []
                    ? $id . ' is not applicable to ' . $handler . '(): ' . implode('; ', $inapplicable)
                      . ' — the check ran and there is nothing of this kind here, which is not the same'
                      . ' as the check being unable to run.'
                    : $id . ' could not run against ' . $handler . '(): ' . implode('; ', $skips)
                      . ($inapplicable === [] ? '' : "\nSeparately, part of it does not apply: " . implode('; ', $inapplicable))
            );
            return;
        }

        $this->assertSame([], $failures, $id . ' reported no lifecycle-contract violations for ' . $handler . '()');

        if ($notices !== []) {
            $this->markTestIncomplete(
                $id . ' — ' . self::titleFor($id) . ' — ' . $handler . "()\n"
                . "Satisfied, but with observations that must not be lost in a green run:\n  - "
                . implode("\n  - ", $notices)
            );
        }
    }

    /**
     * @param string $id
     * @return string
     */
    private static function titleFor($id)
    {
        return $id === self::ASSERTION_ACTS
            ? 'handler acts on a service type it owns'
            : 'handler is inert for a service type it does not own';
    }

    /**
     * Every run in which a repo declared its own owned types instead of letting them be
     * derived. The Phase 3 half of the G2 hatch record.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function serviceOverrideLedger()
    {
        return self::$serviceOverrideLedger;
    }

    /**
     * @return void
     */
    public static function clearServiceOverrideLedger()
    {
        self::$serviceOverrideLedger = [];
    }

    /**
     * Every finding for one plugin, keyed by assertion id — the Phase 3 counterpart to
     * {@see PluginContractTestCase::inspectAll()}, for the fleet self-check.
     *
     * Deliberately **not** merged into `inspectAll()`. That method's return value becomes
     * fleet-matrix rows, and its docblock records that adding a key there quietly changes the
     * 17-column census gate G2 is reviewed against. This is a separate call with a separate
     * shape for exactly that reason.
     *
     * @return array<string,array<string,array<int,\MyAdmin\Plugins\Testing\Contract\Finding>>>
     */
    public function inspectLifecycle()
    {
        $rows = [self::ASSERTION_ACTS => [], self::ASSERTION_INERT => []];
        foreach (ServiceHandlerProbe::HANDLERS as $handler) {
            foreach ([self::ASSERTION_ACTS => 'inspectActs', self::ASSERTION_INERT => 'inspectInert'] as $id => $method) {
                try {
                    $rows[$id][$handler] = $this->{$method}($handler);
                } catch (Throwable $e) {
                    $rows[$id][$handler] = [Finding::failure(
                        $id,
                        'HARNESS BUG (H-bug): the probe threw ' . get_class($e) . ' — ' . $e->getMessage(),
                        ['harnessBug' => true, 'exception' => get_class($e), 'method' => $handler]
                    )];
                }
            }
        }
        return $rows;
    }
}
