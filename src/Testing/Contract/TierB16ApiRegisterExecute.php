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

/**
 * B-16 — `apiRegister()` executes clean.
 *
 * ---------------------------------------------------------------------------------
 * WHY THIS COLUMN EXISTS
 * ---------------------------------------------------------------------------------
 * Nine fleet packages register `api.register => [__CLASS__, 'apiRegister']` and, between
 * them, publish 63 SOAP operations and complex types — the whole of the VPS, licenses,
 * webhosting, DNS, storage, mail and helpdesk public API surface. Before this assertion,
 * **no inspector executed any of them**: A-5 reads the hook table, B-9 resolves the target by
 * reflection, B-9b checks the key is dispatched, and not one of those runs a line of the
 * handler's body. The measured executed-method count for `apiRegister` across the fleet was 0
 * before the Phase 4 conversion and 0 after it — a genuine catalogue gap rather than a check
 * that happened to be green.
 *
 * The gap matters because `apiRegister()` is the fleet's most helper-dependent handler after
 * `getSettings()`, and its failure mode is the same one B-12 exists for: a bare constant, a
 * helper that only exists in core, a call to a function that has been renamed. `api_register()`
 * is a *global* declared in `include/Api/api.functions.inc.php`, so the handler is one
 * undefined symbol away from fataling `api_register_init()` — which runs on every SOAP/API
 * request, for every package, not just the broken one.
 *
 * ---------------------------------------------------------------------------------
 * WHAT IS ASSERTED
 * ---------------------------------------------------------------------------------
 *  1. **It does not throw.** Asked of every package that declares the handler.
 *  2. **It registers something.** A handler that runs and registers nothing is
 *     indistinguishable from one that was never wired up. This is the one assertion the
 *     reachability gate below can withhold — see {@see orphaned()}.
 *  3. **What it registered is well-formed**, judged against what `api_prepare()` in
 *     `include/Api/api.functions.inc.php` does with it:
 *      - the function name is a non-empty string — it becomes the SOAP operation name;
 *      - `input` and `output` are arrays — `api_prepare()` iterates both with `foreach`;
 *      - every declared parameter/return type is a non-empty string — `api_prepare()` runs
 *        `in_array($value, ['string','int',...])`, `isset($GLOBALS['api_arrays'][$value])` and
 *        a `preg_match('/^array:.../')` on it, none of which is meaningful for a non-string;
 *      - **no name is registered twice by the same handler.** `$GLOBALS['api_calls']` is an
 *        append-only list, so a duplicate function name reaches `api_prepare()` twice and
 *        `$server->register()` is called twice for one operation name; `$GLOBALS['api_arrays']`
 *        and `$GLOBALS['api_array_arrays']` are name-keyed maps, so a duplicate there
 *        *silently discards* the first definition. Both are within one package's own control
 *        and neither produces any diagnostic in production.
 *
 * Deliberately **not** asserted: that every type a call references resolves. `result_status`
 * and friends are registered by core's own `api_register_init()`, not by any plugin, so
 * judging a package's references against only what that package registered would manufacture
 * failures out of a cross-package fact. That check belongs to a core-side test with the whole
 * `$GLOBALS['api_arrays']` map in hand; inventing it here would be the over-strict harness
 * rule D7 classifies as an H-bug.
 *
 * ---------------------------------------------------------------------------------
 * THE HANDLER IS FIXED-NAME, AND THAT IS CHECKED RATHER THAN ASSUMED
 * ---------------------------------------------------------------------------------
 * Unlike `<module>.settings`, `api.register` has exactly one form — it is a literal key in
 * {@see TierB9bHookKeysDispatched::LITERAL_KEYS}, dispatched once from
 * `api_register_init()`. All 9 fleet registrations name the method `apiRegister`, so keying
 * this inspector on the method name loses nothing today. A package that wired `api.register`
 * to a differently-named method would be reported here as *not applicable* while its handler
 * went unexecuted; that is a known and currently-empty blind spot, and it is stated here
 * rather than left for someone to infer from a green `o`.
 *
 * ---------------------------------------------------------------------------------
 * OUTPUT — CAPTURED **AND REPORTED** HERE (R-8)
 * ---------------------------------------------------------------------------------
 * {@see TierB15NoOutput} executes `getSettings()` and `getMenu()` and nothing else, so it
 * never runs this handler. Under R-8's rule — *an inspector may discard only what another
 * inspector is guaranteed to execute and report* — B-12 and B-13 may discard their bytes and
 * this one may not. Printed bytes are therefore reported as a failure in B-16's own column,
 * naming B-15's assertion as the defect class, exactly as {@see TierA1ClassIsConstructible}
 * does for a printing constructor. Left unbuffered they would instead surface as
 * `R  This test printed output: …` filed against B-16, which names neither the plugin nor the
 * handler.
 *
 * ---------------------------------------------------------------------------------
 * SIDE-EFFECT FREEDOM
 * ---------------------------------------------------------------------------------
 * The registrations land in {@see \MyAdmin\Plugins\Testing\Fakes\FakeApi}, not in
 * `$GLOBALS['api_calls']`, and the fleet self-check runs 71 packages back to back in one
 * process — so the fake is cleared before the handler runs and the observations are read out
 * before the trailing reset. A `FakeApi` left holding package *n*'s calls would let package
 * *n+1* satisfy assertion 2 for free, which is the exact false pass this assertion exists to
 * prevent.
 */
class TierB16ApiRegisterExecute implements PluginInspector
{
    /** @var string catalogue id */
    const ID = 'B-16';

    /** @var string the handler this inspector executes */
    const METHOD = 'apiRegister';

    /** @var string the one key core dispatches this handler from */
    const HOOK = 'api.register';

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
        return 'apiRegister() executes clean';
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
            // Not applicable rather than skipped: reflection answered the question outright,
            // and the answer is that this package publishes no API surface. 62 of 71.
            return [Finding::notApplicable(
                self::ID,
                'plugin declares no '.self::METHOD.'(), so it registers no API surface',
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

        // Priming must come first: touching the class at all initialises it, which evaluates
        // every static initializer including the bare-constant ones. See ConstantOrderingTest.
        $this->prime($subject);

        $api = Harness::api();
        // Core builds the event as `new GenericEvent(null, ['api_calls' => …])` and every one
        // of the nine fleet handlers has `$event->getSubject()` commented out: the subject is
        // not the channel here, the `api_register*()` globals are. A bare object is passed
        // because SubjectEvent::argumentsFor() verifies the event accepted the subject it was
        // handed, which a null cannot demonstrate.
        $prepared = SubjectEvent::argumentsFor($method, new \stdClass(), $subject, self::ID);
        if ($prepared['skip'] !== null) {
            SubjectEvent::releaseHarness();
            return [$prepared['skip']];
        }

        $args = $prepared['args'];
        $run = TierB15NoOutput::capture(function () use ($method, $args) {
            $method->invokeArgs(null, $args);
        });

        // Read every observation out before the reset that drops it, and before reachability()
        // calls getHooks(), so a hook table with side effects cannot alter what was observed.
        $calls = $api->apiCalls();
        $arrayNames = $this->namesPassedTo($api, 'api_register_array');
        $arrayArrayNames = $this->namesPassedTo($api, 'api_register_array_array');
        $registrations = $api->registrationCount();
        $printed = $run['output'];

        if ($run['error'] !== null) {
            SubjectEvent::releaseHarness();
            $e = $run['error'];
            return [Finding::failure(
                self::ID,
                sprintf(
                    '%s::%s() threw %s: %s',
                    $subject->pluginClass(),
                    self::METHOD,
                    get_class($e),
                    $e->getMessage()
                ).($printed === '' ? '' : ' — and printed '.strlen($printed).' byte(s) first: '
                    .TierB15NoOutput::excerpt($printed)),
                [
                    'class'     => $subject->pluginClass(),
                    'method'    => self::METHOD,
                    'exception' => get_class($e),
                ] + ($printed === '' ? [] : ['bytes' => strlen($printed)])
            )];
        }

        if ($printed !== '') {
            SubjectEvent::releaseHarness();
            return [Finding::failure(
                self::ID,
                TierB15NoOutput::describeOutput($subject->pluginClass(), self::METHOD.'()', $printed)
                    .' api_register_init() runs while the API response is being assembled, so this'
                    .' lands in front of the SOAP/JSON body. Reported here rather than under B-15'
                    .' because B-15 executes getSettings() and getMenu() only, so nothing else in'
                    .' the catalogue would ever see these bytes.',
                [
                    'class'  => $subject->pluginClass(),
                    'site'   => self::METHOD,
                    'bytes'  => strlen($printed),
                    'output' => TierB15NoOutput::excerpt($printed),
                ]
            )];
        }

        if ($registrations === 0) {
            $reachable = $this->reachability($subject);
            SubjectEvent::releaseHarness();
            if ($reachable instanceof Finding) {
                return [$reachable];
            }
            return [Finding::failure(
                self::ID,
                sprintf(
                    '%s::%s() is registered on "%s" but ran and registered no API calls, complex'
                        .' types or arrays at all. Core dispatches this handler on every API'
                        .' request; an empty one is either dead scaffolding that should be deleted'
                        .' along with its hook entry, or a surface that was lost.',
                    $subject->pluginClass(),
                    self::METHOD,
                    implode('", "', $reachable)
                ),
                [
                    'class'         => $subject->pluginClass(),
                    'method'        => self::METHOD,
                    'registrations' => 0,
                    'hookKeys'      => implode(',', $reachable),
                ]
            )];
        }

        SubjectEvent::releaseHarness();

        return $this->inspectRegistrations($subject, $calls, $arrayNames, $arrayArrayNames);
    }

    // -----------------------------------------------------------------------
    // Assertion 3 — what was registered
    // -----------------------------------------------------------------------

    /**
     * Every shape problem in what the handler registered, one Finding each.
     *
     * Deliberately does not stop at the first: a handler registering fourteen calls can have
     * more than one wrong, and a reader fixing one and re-running to find the next is how a
     * nine-package sweep turns into a week.
     *
     * @param \MyAdmin\Plugins\Testing\Contract\PluginSubject $subject
     * @param array<int,array<string,mixed>>                  $calls
     * @param array<int,string>                               $arrayNames
     * @param array<int,string>                               $arrayArrayNames
     * @return array<int,\MyAdmin\Plugins\Testing\Contract\Finding>
     */
    private function inspectRegistrations(PluginSubject $subject, array $calls, array $arrayNames, array $arrayArrayNames)
    {
        $findings = [];
        $base = ['class' => $subject->pluginClass(), 'method' => self::METHOD];

        foreach ($calls as $index => $call) {
            $name = $call['function'];
            if (!is_string($name) || trim($name) === '') {
                $findings[] = Finding::failure(
                    self::ID,
                    'api_register() call #'.($index + 1).' names its function as '
                        .(is_scalar($name) ? var_export($name, true) : gettype($name))
                        .'; the function name becomes the SOAP operation name and must be a'
                        .' non-empty string',
                    $base + ['call' => $index + 1]
                );
                continue;
            }
            foreach (['input', 'output'] as $slot) {
                foreach ($this->slotProblems($name, $slot, $call[$slot]) as $problem) {
                    $findings[] = Finding::failure(self::ID, $problem, $base + ['function' => $name]);
                }
            }
        }

        foreach ([
            'api_register()'             => $this->functionNames($calls),
            'api_register_array()'       => $arrayNames,
            'api_register_array_array()' => $arrayArrayNames,
        ] as $registrar => $names) {
            foreach ($this->duplicates($names) as $duplicate => $times) {
                $findings[] = Finding::failure(
                    self::ID,
                    $subject->pluginClass().'::'.self::METHOD.'() registers "'.$duplicate.'" via '
                        .$registrar.' '.$times.' times. '
                        .($registrar === 'api_register()'
                            ? 'api_calls is an append-only list, so both reach api_prepare() and the'
                                .' operation is registered twice under one name.'
                            : 'The registry is keyed by name, so the later definition silently'
                                .' replaces the earlier one and the first is lost with no diagnostic.'),
                    $base + ['name' => $duplicate, 'registrar' => $registrar, 'times' => $times]
                );
            }
        }

        return $findings;
    }

    /**
     * Problems with one call's `input` or `output` map.
     *
     * @param string $function
     * @param string $slot
     * @param mixed  $value
     * @return array<int,string>
     */
    private function slotProblems($function, $slot, $value)
    {
        if (!is_array($value)) {
            return ['api_register(\''.$function.'\') passes '.gettype($value).' as its '.$slot
                .'; api_prepare() iterates it with foreach, so it must be an array of'
                .' name => type'];
        }
        $problems = [];
        foreach ($value as $key => $type) {
            if (!is_string($type) || trim($type) === '') {
                $problems[] = 'api_register(\''.$function.'\') declares '.$slot.'["'.$key.'"] as '
                    .(is_scalar($type) ? var_export($type, true) : gettype($type))
                    .'; api_prepare() matches the type against a list of scalar names and against'
                    .' the registered complex types, so it must be a non-empty string';
            }
        }
        return $problems;
    }

    /**
     * @param array<int,array<string,mixed>> $calls
     * @return array<int,string>
     */
    private function functionNames(array $calls)
    {
        $names = [];
        foreach ($calls as $call) {
            if (is_string($call['function']) && trim($call['function']) !== '') {
                $names[] = $call['function'];
            }
        }
        return $names;
    }

    /**
     * The first argument of every call to one registrar, in order and with duplicates kept.
     *
     * Read from the {@see \MyAdmin\Plugins\Testing\Recorder} call log rather than from the
     * stored map, because the map is exactly where a duplicate has already been lost.
     *
     * @param \MyAdmin\Plugins\Testing\Fakes\FakeApi $api
     * @param string                                 $registrar
     * @return array<int,string>
     */
    private function namesPassedTo($api, $registrar)
    {
        $names = [];
        foreach ($api->argsFor($registrar) as $args) {
            if (isset($args[0]) && is_string($args[0]) && trim($args[0]) !== '') {
                $names[] = $args[0];
            }
        }
        return $names;
    }

    /**
     * name => how many times it appears, for names appearing more than once.
     *
     * @param array<int,string> $names
     * @return array<string,int>
     */
    private function duplicates(array $names)
    {
        $counts = [];
        foreach ($names as $name) {
            $counts[$name] = isset($counts[$name]) ? $counts[$name] + 1 : 1;
        }
        return array_filter($counts, function ($times) {
            return $times > 1;
        });
    }

    // -----------------------------------------------------------------------
    // Reachability — assertion 2 only
    // -----------------------------------------------------------------------

    /**
     * The hook keys that register this class's `apiRegister`, or the Finding explaining why
     * "it registered nothing" cannot be held against this plugin.
     *
     * The structural question is {@see HookTargetIndex}'s; the wording is this inspector's.
     * Three outcomes, the same three B-12 has and for the same reasons: a list of keys means
     * core has a dispatch path and an empty registration is a real defect; a skip naming
     * **A-5** means the hook table itself could not be obtained; a skip naming **A-8** means
     * the table exists but holds no parseable target, so "is it registered?" is unanswerable
     * rather than false. Both of those carry a `blockedBy` context key and are therefore
     * skips, never not-applicables — deferring to another inspector means this one reached no
     * verdict.
     *
     * @param \MyAdmin\Plugins\Testing\Contract\PluginSubject $subject
     * @return array<int,string>|\MyAdmin\Plugins\Testing\Contract\Finding
     */
    private function reachability(PluginSubject $subject)
    {
        $index = HookTargetIndex::keysTargeting($subject, self::METHOD);

        if ($index['hooks'] === null) {
            return Finding::skipped(
                self::ID,
                $subject->pluginClass().'::'.self::METHOD.'() ran clean and registered nothing, but '
                    .$subject->pluginClass().'::getHooks() could not be evaluated, so whether'
                    .' anything ever registers the handler cannot be determined and the empty'
                    .' registration cannot be called a defect; Tier-A-5 reports the root cause',
                [
                    'class'     => $subject->pluginClass(),
                    'method'    => self::METHOD,
                    'blockedBy' => 'A-5',
                    'executed'  => true,
                ]
            );
        }

        if ($index['keys'] !== []) {
            return $index['keys'];
        }

        if ($index['hooks'] !== [] && $index['pairs'] === 0) {
            return Finding::skipped(
                self::ID,
                $subject->pluginClass().'::'.self::METHOD.'() ran clean and registered nothing, but no'
                    .' entry in '.$subject->pluginClass().'::getHooks() is a [class, method] pair, so'
                    .' whether the handler is registered cannot be determined and the empty'
                    .' registration cannot be called a defect; Tier-A-8 reports the malformed hook'
                    .' values',
                [
                    'class'     => $subject->pluginClass(),
                    'method'    => self::METHOD,
                    'blockedBy' => 'A-8',
                    'hooks'     => count($index['hooks']),
                    'executed'  => true,
                ]
            );
        }

        return $this->orphaned($subject, $index['hooks']);
    }

    /**
     * The verdict for a handler nothing registers, which ran and registered nothing.
     *
     * The same three-way argument {@see TierB12SettingsExecute::orphaned()} makes, applied to
     * the one key that dispatches this handler. *Failure* is wrong and is not this inspector's
     * to make: B-16 asserts that `apiRegister()` executes clean, and it did — dead code is a
     * real defect owned by whichever check owns reachability. *Skip* is a false statement: the
     * check primed the process, invoked the handler, and watched what it registered; two of
     * its three assertions reached a verdict. *Not applicable* is what is left, and it is
     * literally true — assertion 2 asks whether the API surface this handler publishes comes
     * out empty, and for a handler `api_register_init()` never calls there is no surface.
     *
     * The dead-code fact is not lost: it is the whole content of this message, the matrix
     * prints the message for every non-passing cell, and `orphaned=true` rides in the context
     * so a consumer can key on it without parsing English.
     *
     * @param \MyAdmin\Plugins\Testing\Contract\PluginSubject $subject
     * @param array<mixed,mixed>                             $hooks
     * @return \MyAdmin\Plugins\Testing\Contract\Finding
     */
    private function orphaned(PluginSubject $subject, array $hooks)
    {
        return Finding::notApplicable(
            self::ID,
            $subject->pluginClass().'::'.self::METHOD.'() is ORPHANED: no hook returned by '
                .$subject->pluginClass().'::getHooks() targets it, so it is not registered on "'
                .self::HOOK.'" and api_register_init() can never invoke it. It was executed here'
                .' all the same — it neither threw nor printed — but it registered nothing, and an'
                .' API surface core never asks for cannot be empty. That one assertion is withheld'
                .' instead of failed. The handler is dead code until a hook registers it — a real'
                .' defect, but a different one.',
            [
                'class'    => $subject->pluginClass(),
                'method'   => self::METHOD,
                'orphaned' => true,
                'executed' => true,
                'hooks'    => count($hooks),
                'hookKeys' => implode(',', array_keys($hooks)),
            ]
        );
    }

    // -----------------------------------------------------------------------
    // Harness plumbing
    // -----------------------------------------------------------------------

    /**
     * Brings the harness up for one plugin and hands the handler an empty {@see Fakes\FakeApi}.
     *
     * The two `init()` calls are not redundant, for the reason
     * {@see TierB12SettingsExecute::prime()} records: the first exists purely to define the
     * plugin's bare constants, and only after it has run is it safe to touch the class at all
     * — which is what reading `$module` does. The trailing `Harness::reset()` drops the calls
     * `init()` itself recorded while wiring the fakes, so the handler starts against an empty
     * registry.
     *
     * `ima`/`acl` are seeded explicitly rather than inherited from whichever inspector ran
     * before this one.
     *
     * @param \MyAdmin\Plugins\Testing\Contract\PluginSubject $subject
     * @return void
     */
    private function prime(PluginSubject $subject)
    {
        Harness::reset();

        $base = [
            'constants' => $subject->constantOverrides(),
            'plugin'    => $subject->pluginClass(),
            'defines'   => $subject->serviceDefines(),
            'ima'       => 'admin',
            'acl'       => true,
        ];
        Bootstrap::init($base);

        $module = $subject->module();
        $base['module'] = ($module === null || $module === '') ? 'default' : $module;
        Bootstrap::init($base);

        Harness::reset();
    }
}
