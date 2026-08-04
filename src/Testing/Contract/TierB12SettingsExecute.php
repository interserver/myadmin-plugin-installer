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
use Throwable;

/**
 * B-12 — `getSettings()` executes clean.
 *
 * ---------------------------------------------------------------------------------
 * WHAT MAKES THIS AN *EXECUTION* CHECK
 * ---------------------------------------------------------------------------------
 * The Tier-A assertions read a plugin; this one runs it. `getSettings()` is the single
 * most universally implemented handler in the fleet — all 69 plugins declare
 * `getSettings(GenericEvent $event)` — and it is the handler whose failure mode is
 * least visible from source: a bare constant in the body, a helper that only exists in
 * core, a `$settings->` method that never existed. None of those show up in a
 * reflection-only test; all of them are a fatal the first time an admin opens the
 * settings page.
 *
 * Three things are asserted, in this order:
 *
 *  1. it does not throw;
 *  2. it registers **at least one** setting — a handler that runs and registers nothing
 *     is indistinguishable from a handler that was never wired up. This is the one
 *     assertion the reachability gate below can withhold; 1 and 3 are asked of all 69;
 *  3. when the plugin declares a non-empty `$module`, every registered setting lands
 *     under that module's section. Measured across the fleet, all 42 module-declaring
 *     plugins pass `self::$module` (or `_(self::$module)`) as the section argument to
 *     every `add_*` call, so this is a real invariant rather than an aspiration.
 *
 * ---------------------------------------------------------------------------------
 * REACHABILITY GATE — WHY ASSERTION 2 IS NOT ASKED OF EVERY PLUGIN
 * ---------------------------------------------------------------------------------
 * Assertion 2 is a statement about what happens *when core invokes the handler*. Core
 * invokes it only through a hook: `getHooks()` maps an event key to `[__CLASS__,
 * 'getSettings']`, `PluginScanner` writes that into `hooks.json`, and the dispatcher calls
 * it from there. A class that declares `getSettings()` but never registers it has no such
 * path — the method is dead code, and "it registers nothing" is then a vacuous complaint
 * about a body that production never runs.
 *
 * **The gate covers assertion 2 and nothing else, and it is consulted only after the
 * handler has run.** That ordering is the whole correction of a bug this inspector shipped
 * with. The gate used to return *before* `invokeArgs()`, so an unregistered handler was
 * never executed — and B-15, which does execute it, caught the resulting `Error` and
 * downgraded itself to a skip on the grounds that "B-12 owns the throw". B-12 had declined
 * to run. A plugin whose `getSettings()` fatals on the first line of its body therefore
 * passed all 17 assertions with zero failures: 12 passes, 5 skips, 0 failures, reproduced
 * twice. Neither inspector was blind; they were each waiting for the other.
 *
 * What makes assertion 2 different from its two siblings is not observability — the body
 * is equally observable either way, which is exactly what B-15 demonstrated by observing
 * it. It is **consequence**. "Registered nothing" is a defect because core would render an
 * empty settings page; with no dispatch path there is no page and the complaint evaporates.
 * A throw and a stray section evaporate under no such argument: a handler that fatals is
 * broken code that the next commit — the one that finally registers the hook — turns into
 * a production fatal, and it is reported here for every plugin that declares one.
 *
 * Nor does the gate consume a *pass*. A handler that registers settings has satisfied
 * assertion 2 as a matter of fact, whatever dispatches it, so an unregistered handler that
 * runs clean is a pass and not a skip: reporting "could not run" about a check that ran and
 * passed understates coverage, which is the same misreport as overstating it
 * ({@see \MyAdmin\Plugins\Testing\PluginContractTestCase::testPluginSatisfiesContractAssertion}
 * makes the same argument about notices). The dead-code fact belongs to whichever check
 * owns dead code; it is not smuggled in here as a false negative.
 *
 * Measured over all 69 fleet packages, 56 register the handler and 13 do not. Of those 13,
 * ten return an empty hook table (registrations deliberately commented out — the same ten
 * A-5's docblock names), and three — modernbill-plugin, monitoring-plugin, webuzo-vps —
 * register only `function.requirements`. Reporting all 13 as contract violations would put
 * thirteen false failures next to the single genuine one (`detain/myadmin-powerdns`, which
 * *does* register the handler and still registers no settings), and dead code is a different
 * defect owned by a different check. So an unregistered handler that registered nothing is a
 * {@see Finding::skipped()}, never a failure and never an empty pass. Force-executing all 13
 * confirms the gate is load-bearing rather than decorative and that removing it would produce
 * exactly those 13 false failures: 13 of 13 register zero settings — and, equally, 0 throw,
 * 0 register into a foreign section, so opening assertions 1 and 3 to them costs nothing.
 *
 * **The gate is on the hook's target, not on a fixed key.** The obvious rule — "does
 * `getHooks()` contain `system.settings`?" — is wrong, and wrong by a factor of three:
 * across the 56 packages that register the handler, only 14 use `system.settings`. The
 * other 42 registrations use the per-module form, `vps.settings` (14), `licenses.settings`
 * (11), `webhosting.settings` (6), `backups.settings`, `domains.settings`, `ssl.settings`,
 * `mail.settings` (2 each), and `floating_ips.settings` / `quickservers.settings` /
 * `servers.settings` (1 each). Both forms are real dispatch sites — {@see
 * TierB9bHookKeysDispatched} lists `system.settings` among its literal keys and `settings`
 * among its per-module suffixes. Keying this gate on the literal string would skip 41
 * plugins that production dispatches to every day, which is a far larger lie than the 13
 * false failures it set out to fix. Asking instead "does any hook name this class's
 * `getSettings`?" answers the question that actually matters and needs no key vocabulary at
 * all — B-9b owns whether a key is dispatched, and this inspector deliberately does not
 * re-litigate it.
 *
 * ---------------------------------------------------------------------------------
 * ORDERING — THE ONE THING TO GET RIGHT
 * ---------------------------------------------------------------------------------
 * Nine repos initialise a static property from a bare constant (`PRORATE_BILLING` and
 * friends). PHP evaluates a static initializer lazily, on **first access to the class**,
 * so reading `$subject->module()` before the constants exist is a fatal `Error` — see
 * `ConstantOrderingTest`, which pins this. {@see TierB12SettingsExecute::prime()}
 * therefore calls `Bootstrap::init()` with `constants` + `plugin` *first*, and only then
 * reads the module and re-initialises with it.
 *
 * The second ordering rule is the one the deferral loop above cost us: **execute, snapshot,
 * then ask about reachability.** Executing last would put a gate in front of the handler
 * again. Snapshotting last would let a `getHooks()` with side effects add to the very
 * `FakeSettings` whose emptiness decides the verdict.
 *
 * ---------------------------------------------------------------------------------
 * SIDE-EFFECT FREEDOM
 * ---------------------------------------------------------------------------------
 * The Phase 2 self-check runs this over 69 plugins **in one process**, so a `FakeSettings`
 * left holding plugin *n*'s registrations would make plugin *n+1* pass check 2 for free —
 * precisely the false pass this assertion exists to prevent. Every execution is bracketed
 * by `Harness::reset()`, observations are read out *before* the trailing reset, and
 * `ima`/`has_acl()` are re-seeded explicitly rather than inherited. Constants are the one
 * thing that cannot be undone (PHP has no `undefine()`); that is why the fleet smoke runs
 * one plugin per process on top of this.
 */
class TierB12SettingsExecute implements PluginInspector
{
    /** @var string catalogue id */
    const ID = 'B-12';

    /** @var string the handler this inspector executes */
    const METHOD = 'getSettings';

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
        return 'getSettings() executes clean';
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
            return [Finding::skipped(
                self::ID,
                'plugin declares no ' . self::METHOD . '(), so there is nothing to execute',
                ['class' => $subject->pluginClass(), 'method' => self::METHOD]
            )];
        }

        $method = $reflection->getMethod(self::METHOD);
        if (!$method->isPublic() || !$method->isStatic()) {
            // Not a skip: core dispatches the hook as [__CLASS__, 'getSettings'], so a
            // handler that is not public static can never run in production either.
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

        // Priming must come first: touching the class at all — reading `$module` here,
        // calling the handler below, reading the hook table later — initialises it, which
        // evaluates every static initializer including the bare-constant ones. See prime()
        // for why that ordering is load-bearing.
        $module = $this->prime($subject);

        $settingsFake = Harness::settings();

        $prepared = SubjectEvent::argumentsFor($method, $settingsFake, $subject, self::ID);
        if ($prepared['skip'] !== null) {
            SubjectEvent::releaseHarness();
            return [$prepared['skip']];
        }

        try {
            $method->invokeArgs(null, $prepared['args']);
        } catch (Throwable $e) {
            SubjectEvent::releaseHarness();
            return [Finding::failure(
                self::ID,
                sprintf(
                    '%s::%s() threw %s: %s',
                    $subject->pluginClass(),
                    self::METHOD,
                    get_class($e),
                    $e->getMessage()
                ),
                [
                    'class'     => $subject->pluginClass(),
                    'method'    => self::METHOD,
                    'exception' => get_class($e),
                ]
            )];
        }

        // Read every observation out before the reset that drops it — and before
        // reachability() calls getHooks(), so that even a hook table with side effects
        // cannot alter what this run observed.
        $registered = $settingsFake->settingsAdded();
        $populated = $this->populatedSections($settingsFake);

        if ($registered === []) {
            // The only branch reachability can make vacuous, and therefore the only branch
            // it gates. "Registered nothing" is a defect *because core would render an empty
            // page*; with no dispatch path there is no page, so the complaint evaporates.
            // Its two siblings do not evaporate: a throw and a stray section are facts about
            // the body, and they are reported above and below for all 69 packages.
            $registeredBy = $this->reachability($subject);
            SubjectEvent::releaseHarness();
            if ($registeredBy instanceof Finding) {
                return [$registeredBy];
            }
            return [Finding::failure(
                self::ID,
                sprintf(
                    '%s::%s() ran but registered no settings at all',
                    $subject->pluginClass(),
                    self::METHOD
                ),
                ['class' => $subject->pluginClass(), 'method' => self::METHOD, 'settings' => 0]
            )];
        }

        SubjectEvent::releaseHarness();

        if ($module === null || $module === '') {
            return [];
        }

        $expected = self::slug($module);
        $foreign = [];
        foreach ($populated as $section => $count) {
            if ($section !== $expected) {
                $foreign[] = $section;
            }
        }
        if ($foreign !== []) {
            return [Finding::failure(
                self::ID,
                sprintf(
                    '%s declares $module = %s but %s() registered settings under section(s) %s',
                    $subject->pluginClass(),
                    var_export($module, true),
                    self::METHOD,
                    implode(', ', $foreign)
                ),
                [
                    'class'    => $subject->pluginClass(),
                    'method'   => self::METHOD,
                    'module'   => $module,
                    'expected' => $expected,
                    'found'    => implode(',', $foreign),
                ]
            )];
        }

        return [];
    }

    // -----------------------------------------------------------------------
    // Reachability
    // -----------------------------------------------------------------------

    /**
     * The hook keys that register this class's `getSettings`, or the Finding explaining why
     * "it registered nothing" cannot be held against this plugin.
     *
     * Called only from the `$registered === []` branch, and only after the handler has run:
     * it decides the fate of assertion 2 alone. See the class docblock for why the other two
     * assertions are not its business.
     *
     * Three outcomes, and the difference between them is the whole point:
     *
     *  - a non-empty list of keys — core has a path to the handler, so an empty registration
     *    is a real defect and the caller reports it;
     *  - a skip naming **A-5** — the hook table itself could not be obtained (missing,
     *    non-static, throwing, non-array `getHooks()`). A-5 turns exactly that condition red;
     *    reporting it a second time here would make one defect look like two;
     *  - a skip naming **A-8** — a hook table that exists but in which no value is a
     *    `[class, method]` pair. "Registered" is then unanswerable rather than false, and
     *    claiming the handler is orphaned would be a guess. A-8 owns hook value shape.
     *
     * Otherwise the handler is orphaned, and that is its own skip — see {@see orphaned()}.
     *
     * The table is read through {@see TierA5HooksAreIdempotent::hookTable()} rather than by
     * calling `getHooks()` here, for the reason that helper exists: two inspectors that
     * separately decide "can getHooks() be called?" are two inspectors that can disagree.
     *
     * Reading the table *after* the handler ran cannot manufacture a verdict either way,
     * because the caller has already snapshotted what the handler registered. Belt and
     * braces: A-5 requires `getHooks()` to be a pure, idempotent producer of an array, so a
     * `getHooks()` that registered settings as a side effect would be an A-5 failure long
     * before it were a B-12 one.
     *
     * @param \MyAdmin\Plugins\Testing\Contract\PluginSubject $subject
     * @return array<int,string>|\MyAdmin\Plugins\Testing\Contract\Finding
     */
    private function reachability(PluginSubject $subject)
    {
        $hooks = TierA5HooksAreIdempotent::hookTable($subject);
        if ($hooks === null) {
            return Finding::skipped(
                self::ID,
                sprintf(
                    '%s::%s() ran clean and registered nothing, but %s::getHooks() could not be'
                        . ' evaluated, so whether anything ever registers the handler cannot be'
                        . ' determined and the empty registration cannot be called a defect;'
                        . ' Tier-A-5 reports the root cause',
                    $subject->pluginClass(),
                    self::METHOD,
                    $subject->pluginClass()
                ),
                [
                    'class'     => $subject->pluginClass(),
                    'method'    => self::METHOD,
                    'blockedBy' => 'A-5',
                    'executed'  => true,
                ]
            );
        }

        $names = $this->subjectClassNames($subject);
        $keys = [];
        $pairs = 0;
        foreach ($hooks as $key => $value) {
            $target = self::targetOf($value);
            if ($target === null) {
                continue;
            }
            $pairs++;
            if (strcasecmp($target['method'], self::METHOD) !== 0) {
                continue;
            }
            // PHP class names are case-insensitive, and a hook may legitimately name a
            // parent class, so compare against the whole ancestry rather than one string.
            if (!in_array(strtolower(ltrim($target['class'], '\\')), $names, true)) {
                continue;
            }
            $keys[] = (string)$key;
        }

        if ($keys !== []) {
            return $keys;
        }

        if ($hooks !== [] && $pairs === 0) {
            return Finding::skipped(
                self::ID,
                sprintf(
                    '%s::%s() ran clean and registered nothing, but no entry in %s::getHooks() is a'
                        . ' [class, method] pair, so whether the handler is registered cannot be'
                        . ' determined and the empty registration cannot be called a defect;'
                        . ' Tier-A-8 reports the malformed hook values',
                    $subject->pluginClass(),
                    self::METHOD,
                    $subject->pluginClass()
                ),
                [
                    'class'     => $subject->pluginClass(),
                    'method'    => self::METHOD,
                    'blockedBy' => 'A-8',
                    'hooks'     => count($hooks),
                    'executed'  => true,
                ]
            );
        }

        return $this->orphaned($subject, $hooks);
    }

    /**
     * The skip for a handler nothing registers, which ran and registered nothing.
     *
     * Both halves of that sentence are load-bearing. The handler **was executed** — this is
     * reached from below `invokeArgs()`, so a body that fatals has already been reported as a
     * failure and never arrives here — and it is withheld from assertion 2 only, because a
     * settings page core can never render cannot be empty. Saying "unobservable" here, as an
     * earlier revision did, was both wrong and expensive: B-15 executes the same handler and
     * observes it perfectly well, and the word licensed a gate that skipped the plugin before
     * running it at all.
     *
     * Deliberately a skip carrying the orphan fact in its own message and context, rather
     * than a skip plus a {@see Finding::notice()}. The notice would be lost, not gained:
     * {@see \MyAdmin\Plugins\Testing\PluginContractTestCase} marks a case skipped only when
     * `count($skips) === count($findings)`, so pairing a skip with a notice makes the case
     * fall through to a passing assertion — turning "could not run" into "ran and was fine",
     * which is the exact overstatement `Finding::SKIPPED` exists to prevent. `NOTICE` is also
     * documented as existing for one catalogue case only (B-14's unreachable template), and
     * widening it is not worth a signal that the skip reason already carries.
     *
     * `orphaned=true` is in the context, not only in the prose, so the triage matrix can key
     * on it without parsing English — {@see Finding::describe()} renders scalar context pairs
     * into the matrix line, so it is visible either way.
     *
     * @param \MyAdmin\Plugins\Testing\Contract\PluginSubject $subject
     * @param array<mixed,mixed>                             $hooks
     * @return \MyAdmin\Plugins\Testing\Contract\Finding
     */
    private function orphaned(PluginSubject $subject, array $hooks)
    {
        return Finding::skipped(
            self::ID,
            sprintf(
                '%s::%s() is ORPHANED: no hook returned by %s::getHooks() targets it, so it is'
                    . ' not registered via system.settings (nor via the per-module <module>.settings'
                    . ' form) and core can never invoke it in production. It was executed here all'
                    . ' the same — it neither threw nor filed a setting under a foreign section — but'
                    . ' it registered nothing, and an empty settings page core can never render is'
                    . ' inconsequential rather than defective, so that one assertion is withheld'
                    . ' instead of failed. The handler is dead code until a hook registers it — that'
                    . ' is a real defect, but a different one.',
                $subject->pluginClass(),
                self::METHOD,
                $subject->pluginClass()
            ),
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

    /**
     * Every class name a hook may legitimately use to name this plugin's handler, lowercased
     * and without a leading separator.
     *
     * Covers the declaring class and the whole parent chain so an inherited `getSettings`
     * registered as `[ParentPlugin::class, 'getSettings']` still counts as reachable. Built
     * from reflection metadata already in hand, so it can never autoload — resolving a hook's
     * named class is {@see TierB9HookTargetsResolve}'s job, not this one's.
     *
     * @param \MyAdmin\Plugins\Testing\Contract\PluginSubject $subject
     * @return array<int,string>
     */
    private function subjectClassNames(PluginSubject $subject)
    {
        $reflection = $subject->reflection();
        $names = [strtolower(ltrim($reflection->getName(), '\\'))];
        $names[] = strtolower(ltrim(
            $reflection->getMethod(self::METHOD)->getDeclaringClass()->getName(),
            '\\'
        ));
        for ($parent = $reflection->getParentClass(); $parent !== false; $parent = $parent->getParentClass()) {
            $names[] = strtolower(ltrim($parent->getName(), '\\'));
        }
        return array_values(array_unique($names));
    }

    /**
     * The `[class, method]` a hook value names, or null when it names neither.
     *
     * Accepts the `[__CLASS__, 'getSettings']` form every fleet package uses and the
     * `'Class::method'` string form `call_user_func()` also honours. Anything else returns
     * null and is counted as unparseable rather than as "not getSettings", which is what lets
     * {@see reachability()} tell "this hook targets something else" apart from "this hook
     * table is malformed and A-8 should say so".
     *
     * @param mixed $value
     * @return array{class:string,method:string}|null
     */
    private static function targetOf($value)
    {
        if (is_array($value) && count($value) === 2 && isset($value[0], $value[1]) && is_string($value[1])) {
            if (is_object($value[0])) {
                return ['class' => get_class($value[0]), 'method' => $value[1]];
            }
            if (is_string($value[0])) {
                return ['class' => $value[0], 'method' => $value[1]];
            }
            return null;
        }
        if (is_string($value) && strpos($value, '::') !== false) {
            $parts = explode('::', $value, 2);
            return ['class' => $parts[0], 'method' => $parts[1]];
        }
        return null;
    }

    // -----------------------------------------------------------------------
    // Harness plumbing
    // -----------------------------------------------------------------------

    /**
     * Brings the harness up for one plugin and returns its declared module.
     *
     * The two `init()` calls are not redundant. The first exists purely to get the
     * plugin's constants defined; only after it has run is it safe to touch the class at
     * all, which is what reading `$module` does. The second wires `register_module()` and
     * `$GLOBALS['<module>_dbh']` for the module that read produced. `init()` is
     * documented idempotent, and `ConstantStub` caches its token scan per file+mtime, so
     * the second call is cheap.
     *
     * `ima`/`acl` are seeded explicitly — never inherited from whatever inspector ran
     * before this one.
     *
     * @param \MyAdmin\Plugins\Testing\Contract\PluginSubject $subject
     * @return string|null the declared module, or null when it declares none
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

        // init() registers a module and installs fakes; it records calls doing so.
        // Clear those so the handler starts against an empty FakeSettings.
        Harness::reset();

        return $module;
    }

    /**
     * Section slug => number of settings registered under it, counting only sections
     * that actually received a setting.
     *
     * `handle_section_category()` creates an empty section as a side effect, and several
     * plugins call it to control ordering; an empty section is not a scoping violation
     * and must not be reported as one.
     *
     * @param \MyAdmin\Plugins\Testing\Fakes\FakeSettings $settings
     * @return array<string,int>
     */
    private function populatedSections($settings)
    {
        $counts = [];
        foreach ($settings->get_settings() as $slug => $section) {
            $total = 0;
            if (isset($section['cats']) && is_array($section['cats'])) {
                foreach ($section['cats'] as $category) {
                    if (isset($category['settings']) && is_array($category['settings'])) {
                        $total += count($category['settings']);
                    }
                }
            }
            if ($total > 0) {
                $counts[$slug] = $total;
            }
        }
        return $counts;
    }

    /**
     * The same slugification `FakeSettings` (and core's `Settings`) applies to a section
     * name, so `$module` can be compared against a stored section key.
     *
     * @param string $text
     * @return string
     */
    private static function slug($text)
    {
        $slug = strtolower((string)$text);
        $slug = preg_replace('/[^a-z0-9]+/', '_', $slug);
        return trim((string)$slug, '_');
    }
}
