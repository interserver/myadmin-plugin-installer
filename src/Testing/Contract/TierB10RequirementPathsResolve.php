<?php
/**
 * @author Joe Huss <detain@interserver.net>
 * @copyright 2026
 * @package MyAdmin
 * @category Testing
 */

namespace MyAdmin\Plugins\Testing\Contract;

use MyAdmin\Plugins\Loader;
use Throwable;

/**
 * Tier-B-10 — **requirement paths resolve**.
 *
 * Runs the plugin's `function.requirements` handler against the **real**
 * {@see \MyAdmin\Plugins\Loader} — not a fake — and asserts that every source path the
 * handler registers resolves to a file that exists on disk.
 *
 * ---------------------------------------------------------------------------------
 * THE BUG CLASS
 * ---------------------------------------------------------------------------------
 * `function_requirements('foo')` looks `foo` up in the loader's table and `require_once`s
 * whatever path it finds. A path that points at nothing produces a fatal — but only on the
 * request that first needs `foo`, which for an admin-only page can be months after the
 * typo shipped. Real fleet examples: eleven packages register
 * `.../<their-own-package>/src/abuse.inc.php`, copy-pasted from `myadmin-abuse-plugin`,
 * where no such file has ever existed; `myadmin-powerdns` registers `/add_domain` and
 * `/list_domains` against sources that are not there, which is a live 500 today.
 *
 * Day-one yield across the fleet is roughly **75 failures across 18 packages**. That is the
 * point of the check, not a sign it is mis-tuned.
 *
 * ---------------------------------------------------------------------------------
 * HOW CORE RESOLVES A REQUIREMENT (mirrored exactly)
 * ---------------------------------------------------------------------------------
 * From `include/tf.php::function_requirements()`:
 *
 * ```php
 * $include_root = defined('INCLUDE_ROOT') ? INCLUDE_ROOT : 'include';
 * ...
 * require_once $include_root . '/' . $requirements[$function];
 * ```
 *
 * with a second arm for values containing a namespace separator, which takes everything
 * after the last `\` **plus one** character. Both arms are reproduced in
 * {@see resolveSource()}; a check that resolved paths its own way would be testing a fiction.
 *
 * Registered values look like `/../vendor/detain/myadmin-foo/src/foo.php` — relative to
 * `INCLUDE_ROOT`, climbing back out of `include/` into `vendor/`.
 *
 * ---------------------------------------------------------------------------------
 * WHY THIS MUST NOT DEPEND ON THE CURRENT WORKING DIRECTORY
 * ---------------------------------------------------------------------------------
 * Core's own fallback — the bare relative string `'include'` — is resolved by
 * `require_once` against the include path and then the cwd. That is a real bug in this fleet
 * (`webuzo-vps` is the one usually blamed for surfacing it): the suite only passes when run
 * from inside the core tree, and a CI job that happens to `cd` elsewhere goes red for reasons
 * that have nothing to do with the change under test. This inspector therefore never uses a
 * relative root. {@see requirementRootFor()} resolves one absolutely, in this order:
 *
 *  1. `PluginSubject::requirementRoot()` when the repo set one — the explicit answer wins,
 *     **provided it is a directory**. It is not taken on trust: an explicit root that does
 *     not exist manufactures a dangling-path failure for every registered source, and one
 *     that happens to contain files hides the real ones. Both were reproduced in review.
 *     A bad explicit root is reported as a skip naming the root ({@see unusableExplicitRoot()})
 *     and does *not* fall through to the rungs below — silently substituting a directory the
 *     repo never named would be a third wrong answer.
 *  2. The `INCLUDE_ROOT` constant, **if it is defined and absolute**. This is what core uses,
 *     so honouring it means the check agrees with the running application. A *relative*
 *     `INCLUDE_ROOT` is rejected rather than used, because adopting it would import the very
 *     cwd dependence this check exists to expose.
 *  3. `<core>/include`, derived from `PluginSubject::packageDir()`. A plugin installed at
 *     `<core>/vendor/<vendor>/<package>` pins `<core>` with three `dirname()` calls, and
 *     `INCLUDE_ROOT` is defined as `INSTALL_ROOT.'/include'` in
 *     `include/config/config.inc.php`, so `<core>/include` is the same directory the
 *     application would use. Used only when it actually exists.
 *  4. The same derivation from *this package's* own location, for a subject whose class does
 *     not live under `vendor/` (a scratch copy, a fixture) but which is nonetheless being run
 *     from a normal install.
 *
 * If none of those produce an existing directory the check reports {@see Finding::skipped()}.
 * Guessing a root would manufacture 75 failures out of an environment problem.
 *
 * ---------------------------------------------------------------------------------
 * THE SECOND GROUND: A SOURCE THAT POINTS BACK INTO ITS OWN PACKAGE (R-10)
 * ---------------------------------------------------------------------------------
 * Every rung of that ladder needs a **core** checkout. Phase 4 moves this harness into the
 * individual plugin repositories, where there is none: plugin CI is `checkout` +
 * `composer install` + `phpunit`, the repo sits at `/home/runner/work/<pkg>/<pkg>`, its
 * `vendor/` holds only its own dependencies, and **0 of 71 plugin repos ship an `include/`
 * directory**. All four rungs fail, so B-10 would arrive in Phase 4 permanently skipped —
 * the most productive assertion in the catalogue (15 of the fleet's 18 failing cells, and
 * the one that surfaced a live 500) reduced to grey.
 *
 * It does not have to be. Look at what the fleet actually registers:
 *
 * ```php
 * $loader->add_requirement('activate_cpanel', '/../vendor/detain/myadmin-cpanel-licensing/src/cpanel.inc.php');
 * ```
 *
 * That path is written relative to core's `include/`, but it does not *point* at core. It
 * climbs out of `include/`, back down through `vendor/`, and lands inside **the very package
 * being inspected**. Everything after `/vendor/detain/myadmin-cpanel-licensing/` is a path
 * relative to {@see PluginSubject::packageDir()}, and `packageDir()` is known in a standalone
 * checkout — it is simply where the plugin class lives. Census of the real fleet
 * (`vendor/*\/myadmin-*`, composer `type: myadmin-plugin`, handler executed against a real
 * `Loader`): **383 registered sources across 49 packages — 305 self-referencing, 76
 * core-relative (1 package: `cpanel-webhosting`), 2 cross-package (1 package:
 * `fantastico-licensing`, which also has the fleet's only core-relative *failure*)**. All 305
 * self-referencing sources have the exact shape `/../vendor/<composer-name>/<tail>`, with no
 * namespace prefix, no second `vendor/` segment and no `..` in the tail. 47 of the 49
 * packages register self-referencing sources **only**, so the package-relative ground alone
 * carries B-10 into 47 of 49 plugin repos.
 *
 * ---------------------------------------------------------------------------------
 * WHY THIS IS A SECOND GROUND AND NOT A REPLACEMENT FOR THE FIRST
 * ---------------------------------------------------------------------------------
 * The tempting simplification is to resolve *everything* against `packageDir()` and delete
 * the ladder. That trades away a real finding. `myadmin-fantastico-licensing` registers
 * `/vps/addons/vps_add_fantastico.php` — a genuinely core-relative path, to a file that is
 * not in core, which is one of the 15 failing B-10 packages and a live fatal. Nothing about
 * `packageDir()` can judge it. Under a replacement it would silently become a skip, and the
 * fleet census would drop from 15 failing packages to 14.
 *
 * So the ladder is consulted **first and unchanged**. When it yields a root, every source is
 * judged exactly as before — same resolution, same message, same context keys — and the
 * fleet self-check produces byte-identical output. Only when no root can be had at all does
 * the package-relative ground engage, and then only for sources that really do point back
 * into the package. A core-relative or cross-package source with no core root is reported as
 * {@see Finding::skipped()} naming the path and saying precisely why: it points outside this
 * package and there is no core checkout here to resolve it against. That skip is
 * deliberately distinguishable from every other skip this inspector emits, because it is the
 * one that says "run me in core and you will get an answer".
 *
 * Two consequences worth stating, because both look like bugs until they are read as
 * decisions:
 *
 *  - **The explicit opt-out still opts out.** `requirementRoot(): null` returns before any
 *    of this. A repo that switched the assertion off does not get it switched back on by a
 *    resolution strategy it never asked for.
 *  - **A bad explicit root still short-circuits.** `requirementRoot()` naming a
 *    non-directory is reported by {@see unusableExplicitRoot()} and does not fall through to
 *    the package-relative ground. Substituting a second answer for the one the repo named is
 *    the same mistake as substituting a derived root for it.
 *
 * `inspect()` returning `[]` for a plugin that registers nothing stays where it is, ahead of
 * the grounding, and is now consistent rather than confusing: with the package-relative
 * ground in place a standalone repo gets a real verdict for 47 of 49 packages, so green no
 * longer means "no handler here" in one repo and "check disabled" in the next.
 *
 * ---------------------------------------------------------------------------------
 * SIDE EFFECTS
 * ---------------------------------------------------------------------------------
 * `Loader` holds no static or global state — four instance arrays, written only through its
 * own `add_*` methods — so a fresh `new Loader()` per inspection needs no snapshot/restore,
 * and none is performed. The one thing a foreign handler can leak into the process is
 * output, so the invocation is wrapped in an output buffer that is discarded; every plugin
 * `phpunit.xml.dist` in the fleet sets `beStrictAboutOutputDuringTests="true"`, and an
 * inspector that let a stray `echo` through would fail the *next* test rather than its own.
 * (Detecting that echo is Tier-B-15's job, and it buffers its own run.)
 */
class TierB10RequirementPathsResolve implements PluginInspector
{
    /** Catalogue id. */
    const ID = 'B-10';

    /**
     * Referenced as a string. This package does not depend on symfony/event-dispatcher; see
     * {@see TierB9HookTargetsResolve} for the same reasoning applied to type hints.
     */
    const EVENT_CLASS = 'Symfony\Component\EventDispatcher\GenericEvent';

    /** The hook whose handler registers requirements. */
    const HOOK = 'function.requirements';

    /**
     * Context marker: judged against `packageDir()` because no core root was available.
     *
     * Findings carry which ground produced them so a triage matrix never has to infer it from
     * the resolved path. Root-mode findings carry no marker at all — that keeps the fleet
     * self-check's committed output byte-identical, and absence there is unambiguous because
     * root mode is the only arm that reports a `root`.
     */
    const GROUNDING_PACKAGE_RELATIVE = 'package-relative';

    /**
     * Context marker: not judged, because the path leaves the package and there is no core
     * root here.
     *
     * This is the skip R-10 introduces, and it **stays a skip** now that R-4 has landed the
     * fourth state. The argument for promoting it to `not applicable` was that nothing is wrong
     * with the plugin and nothing is wrong with the environment. True, and beside the point:
     * `not applicable` means *this plugin has nothing of this kind*, and a package reaching here
     * has a registered path — the check simply could not resolve it. That is `skip`'s definition
     * exactly, and it is the same shape as B-14's five dynamic-dispatch cells, which are the
     * fleet's other genuine blind spot.
     *
     * It matters which way this falls. `skip` is the state a reader is expected to act on;
     * `not applicable` is the state that needs no action. A core-relative path in a standalone
     * repo is actionable — run the check in a core checkout and you get an answer — so filing it
     * under "nothing to see" is precisely the misreport R-4 removed everywhere else. It is also
     * what keeps a plugin repo's green B-10 legible as the weaker claim it is; flatten this and
     * R-10's added ground quietly degrades into the CI-fixture option without the fixture.
     */
    const GROUNDING_OUTSIDE_PACKAGE = 'outside-package-no-core-root';

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
        return 'Requirement paths resolve';
    }

    /**
     * @param PluginSubject $subject
     * @return array<int,Finding>
     */
    public function inspect(PluginSubject $subject)
    {
        // Gate G2: an escape hatch that is used must be visible in the matrix, so the opt-out
        // returns a skip naming itself rather than an empty (== passing) result.
        if ($subject->skipsRequirementCheck()) {
            return [Finding::skipped(
                self::ID,
                'repo opted out of the requirement-path check via requirementRoot(): null',
                ['plugin' => $subject->pluginClass(), 'override' => 'requirementRoot']
            )];
        }

        if (!$subject->isLoadable()) {
            return [Finding::skipped(
                self::ID,
                'plugin class does not load, so its requirements cannot be registered',
                ['plugin' => $subject->pluginClass()]
            )];
        }

        // Checked before the handler is resolved, and before any path is examined, because a
        // root that is not a directory makes every subsequent verdict meaningless in one
        // direction or the other — see unusableExplicitRoot(). Reporting it here also means a
        // repo whose hatch is wrong and whose plugin registers nothing hears about the hatch,
        // instead of passing vacuously and finding out years later.
        $badRoot = $this->unusableExplicitRoot($subject);
        if ($badRoot !== null) {
            return [$badRoot];
        }

        $target = $this->resolveHandler($subject);
        if ($target instanceof Finding) {
            return [$target];
        }
        if ($target === null) {
            // Nothing registers requirements. This was a pass until R-4, on the reasoning that
            // the check ran, found no paths, and all zero of them resolve — true, and exactly
            // the overstatement the fourth state exists to remove. B-11 called the identical
            // fact a skip for the same 18 packages, so `backups-module` was green in this
            // column and grey in that one. Not-applicable is what both meant.
            return [Finding::notApplicable(
                self::ID,
                $subject->pluginClass().' registers no requirement paths, so there is nothing'
                    .' for this check to resolve',
                ['plugin' => $subject->pluginClass()]
            )];
        }

        $ground = $this->groundFor($subject);
        if ($ground['root'] === null && $ground['packageDir'] === null) {
            return [Finding::skipped(
                self::ID,
                'no absolute requirement root could be determined (no requirementRoot(), no'
                    .' absolute INCLUDE_ROOT, and no <core>/include above the package), and the'
                    .' package could not be identified either — no readable composer.json naming'
                    .' it at '.($subject->packageDir() === null ? '(unknown package directory)' : $subject->packageDir())
                    .' and it is not installed under a vendor/ directory, so sources pointing back'
                    .' into the package cannot be grounded on it',
                ['plugin' => $subject->pluginClass()]
            )];
        }

        $requirements = $this->collectRequirements($target, $subject);
        if ($requirements instanceof Finding) {
            return [$requirements];
        }

        $findings = [];
        foreach ($requirements as $function => $source) {
            foreach ($this->inspectRequirement((string)$function, $source, $ground, $subject) as $finding) {
                $findings[] = $finding;
            }
        }
        return $findings;
    }

    /**
     * The two grounds a source can be resolved against, either of which may be absent.
     *
     * `root` is the core `include/` directory the ladder settled on; `packageDir` and
     * `packageName` are the second ground described on the class. `packageDir` is reported as
     * null unless the package can also be *named*, because naming it is what makes a
     * self-referencing path recognisable — an unnamed package directory is a path with
     * nothing to match against, not a usable ground.
     *
     * @param PluginSubject $subject
     * @return array{root:string|null,packageDir:string|null,packageName:string|null}
     */
    private function groundFor(PluginSubject $subject)
    {
        $packageDir = $subject->packageDir();
        $packageName = $this->packageNameFor($packageDir);
        return [
            'root' => $this->requirementRootFor($subject),
            'packageDir' => ($packageDir === null || $packageName === null)
                ? null
                : $this->normalizeRoot($packageDir),
            'packageName' => $packageName,
        ];
    }

    /**
     * The package's composer name (`vendor/package`), or null when it cannot be established.
     *
     * Read from the package's own `composer.json` first, because that is the one source of
     * the name that survives the move to a standalone repository: a plugin checked out at
     * `/home/runner/work/myadmin-cpanel-licensing/myadmin-cpanel-licensing` has no `vendor/`
     * segment above it, and its directory names say `myadmin-cpanel-licensing` twice rather
     * than `detain/myadmin-cpanel-licensing`. Guessing from the directory there would fail to
     * match the `/vendor/detain/myadmin-cpanel-licensing/` the plugin actually registers.
     *
     * The directory-shape fallback exists for a subject that has no manifest at all — a
     * scratch copy, a fixture — but is nonetheless laid out the way Composer lays packages
     * out. It is deliberately second: a manifest states the name, a directory only implies it.
     *
     * @param string|null $packageDir
     * @return string|null
     */
    private function packageNameFor($packageDir)
    {
        if (!is_string($packageDir) || $packageDir === '') {
            return null;
        }
        $manifest = $packageDir.'/composer.json';
        if (is_file($manifest) && is_readable($manifest)) {
            $json = json_decode((string)file_get_contents($manifest), true);
            if (is_array($json) && isset($json['name']) && is_string($json['name'])) {
                $name = trim($json['name'], '/');
                if ($name !== '') {
                    return $name;
                }
            }
        }
        $parent = dirname($packageDir);
        if (basename(dirname($parent)) === 'vendor') {
            return basename($parent).'/'.basename($packageDir);
        }
        return null;
    }

    /**
     * The absolute directory registered sources resolve under, or null when none can be had.
     *
     * Null covers two distinguishable situations, and {@see inspect()} distinguishes them
     * before calling this: no root could be derived at all, or the repo named one that is not
     * a directory. Callers that only need a path can ignore the difference; a caller that
     * reports to a human must not.
     *
     * Public because it is the part of this check most likely to be argued with, and an
     * argument you can call is better than one you have to reconstruct from a failure
     * message. The ordering is documented on the class.
     *
     * Since R-10 a null here no longer ends the inspection: {@see groundFor()} falls through
     * to the package-relative ground, which needs no core checkout. What null still means is
     * exactly what it always meant — *this* ladder found nothing — and that is why the second
     * ground was added beside it rather than folded into it as a fifth rung. A rung returning
     * `packageDir()` would make every core-relative source resolve under the package and
     * report `fantastico-licensing`'s live fatal as a file that is merely absent from a
     * directory it was never in.
     *
     * @param PluginSubject $subject
     * @return string|null
     */
    public function requirementRootFor(PluginSubject $subject)
    {
        $explicit = $subject->requirementRoot();
        if (is_string($explicit) && $explicit !== '') {
            // Same `is_dir()` gate rungs 3 and 4 apply, for the same reason: a root that is
            // not a directory cannot produce an honest verdict. Rung 1 does NOT fall through
            // to the later rungs when it fails — an explicit answer that is wrong must be
            // reported, not quietly replaced with a different directory the repo never named.
            return is_dir($explicit) ? $this->normalizeRoot($explicit) : null;
        }

        if (defined('INCLUDE_ROOT')) {
            $constant = constant('INCLUDE_ROOT');
            if (is_string($constant) && $constant !== '' && $this->isAbsolute($constant)) {
                return $this->normalizeRoot($constant);
            }
        }

        // __DIR__ is <installer>/src/Testing/Contract; three dirnames give the package root.
        $candidates = [$subject->packageDir(), dirname(dirname(dirname(__DIR__)))];
        foreach ($candidates as $candidate) {
            $root = $this->includeRootAbove($candidate);
            if ($root !== null) {
                return $root;
            }
        }

        return null;
    }

    /**
     * A skip naming the repo's own `requirementRoot()` when it is not a usable directory,
     * or null when the hatch is unset or fine.
     *
     * ---------------------------------------------------------------------------------
     * WHY THIS IS A SKIP AND NOT A PILE OF FAILURES
     * ---------------------------------------------------------------------------------
     * Rungs 3 and 4 of the root ladder have always been `is_dir()`-gated; rung 1 — the
     * explicit hatch — was not. That asymmetry made both misuse directions silent, and both
     * were reproduced in review:
     *
     *  - point the hatch at a directory that does not exist, and **every** registered source
     *    resolves under it to a file that is not there. The plugin gets a wall of
     *    dangling-path failures indistinguishable from the real ones this check exists to
     *    find — 15 fleet packages have genuine ones, so the noise lands on top of signal.
     *  - point it somewhere files happen to exist, and real dangling paths resolve to
     *    something and the check goes green.
     *
     * Neither verdict is about the plugin, so neither may be reported as one. A skip naming
     * the root says so, is visible in the matrix, and cannot be mistaken for compliance.
     *
     * @param PluginSubject $subject
     * @return Finding|null
     */
    private function unusableExplicitRoot(PluginSubject $subject)
    {
        $explicit = $subject->requirementRoot();
        // A null here is either "never overridden" or the explicit opt-out, and the opt-out
        // has already returned by this point.
        if ($explicit === null || is_dir((string)$explicit)) {
            return null;
        }

        return Finding::skipped(
            self::ID,
            'requirementRoot() names "'.$explicit.'", which is not a directory on this machine, so'
                .' no source path can be resolved honestly: every registered path would resolve'
                .' under a root that holds nothing and be reported as dangling, whether or not it'
                .' really is. Point requirementRoot() at the directory core uses as INCLUDE_ROOT'
                .' (the install\'s include/ directory), delete the override to let B-10 derive that'
                .' directory itself, or return null from it to opt this repo out of the check'
                .' deliberately.',
            [
                'plugin' => $subject->pluginClass(),
                'override' => 'requirementRoot',
                'requirementRoot' => $explicit,
            ]
        );
    }

    /**
     * `<core>/include` for a package installed at `<core>/vendor/<vendor>/<package>`.
     *
     * @param string|null $packageDir
     * @return string|null
     */
    private function includeRootAbove($packageDir)
    {
        if (!is_string($packageDir) || $packageDir === '') {
            return null;
        }
        $vendorDir = dirname(dirname($packageDir));
        if (basename($vendorDir) !== 'vendor') {
            return null;
        }
        $include = dirname($vendorDir).'/include';
        return is_dir($include) ? $this->normalizeRoot($include) : null;
    }

    /**
     * The `[class, method]` production would dispatch, or null when the plugin registers no
     * requirements at all.
     *
     * The hook table is the authority — that is what `run_event()` walks — but a plugin that
     * declares `getRequirements()` without hooking it still has paths worth checking, and
     * checking them costs nothing. Which route was taken is recorded in the finding context
     * so the matrix does not have to guess.
     *
     * @param PluginSubject $subject
     * @return array{0:string,1:string}|Finding|null
     */
    private function resolveHandler(PluginSubject $subject)
    {
        $reflection = $subject->reflection();
        $hooks = [];

        if ($reflection->hasMethod('getHooks')) {
            $method = $reflection->getMethod('getHooks');
            if ($method->isStatic() && $method->isPublic()) {
                try {
                    $returned = $method->invoke(null);
                } catch (Throwable $e) {
                    return Finding::skipped(
                        self::ID,
                        'getHooks() threw '.get_class($e).': '.$e->getMessage(),
                        ['plugin' => $subject->pluginClass()]
                    );
                }
                if (is_array($returned)) {
                    $hooks = $returned;
                }
            }
        }

        if (isset($hooks[self::HOOK])) {
            $target = $hooks[self::HOOK];
            if (!is_array($target) || !isset($target[0], $target[1])
                || !is_string($target[0]) || !is_string($target[1])) {
                return Finding::skipped(
                    self::ID,
                    'the "'.self::HOOK.'" hook target is not a [class, method] pair, so the'
                        .' handler cannot be run (shape is Tier-A-8\'s assertion)',
                    ['plugin' => $subject->pluginClass()]
                );
            }
            if (!class_exists($target[0]) || !method_exists($target[0], $target[1])) {
                // Tier-B-9 reports the dangling target itself; B-10 only records that it
                // could not therefore collect any paths.
                return Finding::skipped(
                    self::ID,
                    'the "'.self::HOOK.'" hook points at '.$target[0].'::'.$target[1]
                        .'(), which does not exist (Tier-B-9 reports this)',
                    ['plugin' => $subject->pluginClass()]
                );
            }
            return [(string)$target[0], (string)$target[1]];
        }

        if ($reflection->hasMethod('getRequirements')) {
            return [$subject->pluginClass(), 'getRequirements'];
        }

        return null;
    }

    /**
     * Runs the handler against a real Loader and hands back its requirement table.
     *
     * Mirrors `tf::set_function_requirements()`, which reads the loader back out of the event
     * (`$this->loader = $event->getSubject();`) rather than reusing the one it passed in — a
     * handler is free to replace the subject, and a check that ignored that would inspect the
     * wrong object.
     *
     * The event is a real `GenericEvent` whenever the component is loadable — which is every
     * environment the fleet runs in — and {@see SubjectEvent} otherwise. See that class for
     * why no stand-in is declared under Symfony's name.
     *
     * @param array{0:string,1:string} $target
     * @param PluginSubject            $subject
     * @return array<string,mixed>|Finding
     */
    private function collectRequirements(array $target, PluginSubject $subject)
    {
        $loader = new Loader();
        $eventClass = self::EVENT_CLASS;
        $haveRealEvent = class_exists($eventClass);
        $event = $haveRealEvent ? new $eventClass($loader) : new SubjectEvent($loader);

        // Unwinding to the level recorded *before* ob_start() rather than calling
        // ob_end_clean() once: a handler that opens a buffer and does not close it would
        // otherwise have its buffer closed here and leave ours open for the rest of the run.
        $level = ob_get_level();
        ob_start();
        try {
            call_user_func([$target[0], $target[1]], $event);
        } catch (Throwable $e) {
            if (!$haveRealEvent && $e instanceof \TypeError) {
                return Finding::skipped(
                    self::ID,
                    $eventClass.' is not available in this environment and '.$target[0].'::'
                        .$target[1].'() type-hints it, so the handler could not be invoked',
                    ['plugin' => $subject->pluginClass()]
                );
            }
            return Finding::skipped(
                self::ID,
                $target[0].'::'.$target[1].'() threw '.get_class($e).': '.$e->getMessage()
                    .', so its requirement paths could not be collected',
                ['plugin' => $subject->pluginClass()]
            );
        } finally {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
        }

        $after = $event->getSubject();
        if ($after instanceof Loader) {
            $loader = $after;
        }

        return $loader->get_requirements();
    }

    /**
     * @param string                                                              $function
     * @param mixed                                                               $source
     * @param array{root:string|null,packageDir:string|null,packageName:string|null} $ground
     * @param PluginSubject                                                       $subject
     * @return array<int,Finding>
     */
    private function inspectRequirement($function, $source, array $ground, PluginSubject $subject)
    {
        // `root` is omitted rather than set to null when the ladder found nothing, so a
        // finding never claims a root it did not use. Insertion order is load-bearing:
        // Finding::describe() renders the context in order and the fleet matrix commits those
        // strings verbatim, so the root-mode context must stay plugin, function, root, ... .
        $base = ['plugin' => $subject->pluginClass(), 'function' => $function];
        if ($ground['root'] !== null) {
            $base['root'] = $ground['root'];
        }

        // Core accepts a list of sources for one function and requires each in turn.
        if (is_array($source)) {
            $findings = [];
            foreach ($source as $one) {
                foreach ($this->inspectRequirement($function, $one, $ground, $subject) as $finding) {
                    $findings[] = $finding;
                }
            }
            return $findings;
        }

        if (!is_string($source) || $source === '') {
            return [Finding::failure(
                self::ID,
                'requirement "'.$function.'" registers a source that is not a non-empty string'
                    .' ('.gettype($source).')',
                $base + ['source' => is_scalar($source) ? $source : gettype($source)]
            )];
        }

        if ($ground['root'] !== null) {
            return $this->judgeAgainstRoot($function, $source, $ground['root'], $base);
        }
        return $this->judgeAgainstPackage($function, $source, $ground, $base);
    }

    /**
     * The original verdict, against a core root. Untouched by R-10 on purpose: this is the
     * arm the fleet self-check runs in, and its 15 failing packages are the catalogue's
     * highest-yield finding. See the class docblock.
     *
     * @param string              $function
     * @param string              $source
     * @param string              $root
     * @param array<string,mixed> $base
     * @return array<int,Finding>
     */
    private function judgeAgainstRoot($function, $source, $root, array $base)
    {
        $resolved = $this->resolveSource($root, $source);
        if (is_file($resolved)) {
            return [];
        }

        return [Finding::failure(
            self::ID,
            'requirement "'.$function.'" registers '.$source.', which resolves to '.$resolved
                .' — no such file; function_requirements(\''.$function.'\') will fatal',
            $base + ['source' => $source, 'resolved' => $resolved]
        )];
    }

    /**
     * The standalone verdict, against the package's own directory.
     *
     * Reached only when the root ladder came up empty. A source that points back into this
     * package is judged — that is 305 of the fleet's 383 registered sources, and 47 of its 49
     * registering packages. Anything else is honestly unjudgeable here, and says so in a
     * reason no other skip in this inspector produces: it names the path, names the package
     * it failed to match, and tells the reader the answer exists in a core checkout.
     *
     * The failure message is deliberately the same sentence the root arm produces. The defect
     * is identical — `function_requirements()` will fatal on a path that is not there — and a
     * reader triaging a plugin repo should not have to learn a second phrasing for it.
     *
     * @param string                                                              $function
     * @param string                                                              $source
     * @param array{root:string|null,packageDir:string|null,packageName:string|null} $ground
     * @param array<string,mixed>                                                 $base
     * @return array<int,Finding>
     */
    private function judgeAgainstPackage($function, $source, array $ground, array $base)
    {
        $packageDir = (string)$ground['packageDir'];
        $packageName = (string)$ground['packageName'];
        $tail = $this->packageRelativeTail($source, $packageName);
        if ($tail === null) {
            return [Finding::skipped(
                self::ID,
                'requirement "'.$function.'" registers '.$source.', which points outside '
                    .$packageName.' and there is no core root on this machine to resolve it'
                    .' against, so whether it resolves cannot be decided here. Run B-10 inside a'
                    .' MyAdmin core checkout, or set requirementRoot() to that install\'s include/'
                    .' directory, to get a verdict on this path.',
                $base + [
                    'source' => $source,
                    'package' => $packageName,
                    'packageDir' => $packageDir,
                    'grounding' => self::GROUNDING_OUTSIDE_PACKAGE,
                ]
            )];
        }

        $resolved = $packageDir.'/'.$tail;
        if (is_file($resolved)) {
            return [];
        }

        return [Finding::failure(
            self::ID,
            'requirement "'.$function.'" registers '.$source.', which resolves to '.$resolved
                .' — no such file; function_requirements(\''.$function.'\') will fatal',
            $base + [
                'source' => $source,
                'resolved' => $resolved,
                'grounding' => self::GROUNDING_PACKAGE_RELATIVE,
            ]
        )];
    }

    /**
     * The part of a source that lies inside this package, or null when it lies outside.
     *
     * The needle is `/vendor/<composer-name>/`, matched with both delimiters so that
     * `detain/myadmin-cpanel` cannot match a path belonging to `detain/myadmin-cpanel-vps-addon`.
     * The **last** occurrence wins: a path that enters the package twice has really landed in
     * the inner one, and answering about the outer would be answering about a directory the
     * path passes through rather than the one it names.
     *
     * A tail that climbs back out with `..` is rejected rather than resolved. Zero of the
     * fleet's 305 self-referencing sources do that, but one that did would be answered against
     * whatever happens to sit beside the package on this machine — precisely the
     * wrong-directory verdict the root ladder's `is_dir()` gates exist to refuse.
     *
     * @param string $source raw registered source, namespace arm not yet applied
     * @param string $packageName composer `vendor/package`
     * @return string|null
     */
    private function packageRelativeTail($source, $packageName)
    {
        $path = '/'.ltrim($this->strippedSource($source), '/');
        $needle = '/vendor/'.$packageName.'/';
        $at = strrpos($path, $needle);
        if ($at === false) {
            return null;
        }
        $tail = substr($path, $at + strlen($needle));
        if ($tail === '' || $this->escapesPackage($tail)) {
            return null;
        }
        return $tail;
    }

    /**
     * Whether a package-relative tail walks out of the package with `..`.
     *
     * Counts segments rather than calling `realpath()`: the question is about the path as
     * written, and `realpath()` answers null for anything that does not exist — which is the
     * case this whole inspector is built to report on.
     *
     * @param string $relative
     * @return bool
     */
    private function escapesPackage($relative)
    {
        $depth = 0;
        foreach (explode('/', $relative) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                $depth--;
                if ($depth < 0) {
                    return true;
                }
                continue;
            }
            $depth++;
        }
        return false;
    }

    /**
     * The path core's `function_requirements()` would `require_once`, made absolute.
     *
     * @param string $root
     * @param string $source
     * @return string
     */
    private function resolveSource($root, $source)
    {
        return $root.'/'.ltrim($this->strippedSource($source), '/');
    }

    /**
     * Core's namespace arm, applied to a registered source.
     *
     * Shared by both grounds so they cannot disagree about what the source even is. A path
     * core would strip must be stripped before it is matched against the package needle, or
     * a namespaced self-referencing source would be judged against a string core never uses.
     *
     * @param string $source
     * @return string
     */
    private function strippedSource($source)
    {
        $separator = strrpos($source, '\\');
        if ($separator === false) {
            return $source;
        }
        // Core: substr($source, strrpos($source, '\\') + 2) — the namespace prefix is
        // stripped along with one further character. Reproduced verbatim, oddity included.
        return (string)substr($source, $separator + 2);
    }

    /**
     * @param string $path
     * @return bool
     */
    private function isAbsolute($path)
    {
        return $path !== '' && ($path[0] === '/' || $path[0] === '\\' || preg_match('#^[A-Za-z]:[\\\\/]#', $path) === 1);
    }

    /**
     * @param string $path
     * @return string
     */
    private function normalizeRoot($path)
    {
        $trimmed = rtrim($path, '/\\');
        return $trimmed === '' ? '/' : $trimmed;
    }
}
