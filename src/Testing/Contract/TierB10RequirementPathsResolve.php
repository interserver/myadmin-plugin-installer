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
            // Nothing registers requirements. Vacuously satisfied: the check ran, found no
            // paths, and all zero of them resolve.
            return [];
        }

        $root = $this->requirementRootFor($subject);
        if ($root === null) {
            return [Finding::skipped(
                self::ID,
                'no absolute requirement root could be determined (no requirementRoot(), no'
                    .' absolute INCLUDE_ROOT, and no <core>/include above the package)',
                ['plugin' => $subject->pluginClass()]
            )];
        }

        $requirements = $this->collectRequirements($target, $subject);
        if ($requirements instanceof Finding) {
            return [$requirements];
        }

        $findings = [];
        foreach ($requirements as $function => $source) {
            foreach ($this->inspectRequirement((string)$function, $source, $root, $subject) as $finding) {
                $findings[] = $finding;
            }
        }
        return $findings;
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
     * @param string              $function
     * @param mixed               $source
     * @param string              $root
     * @param PluginSubject       $subject
     * @return array<int,Finding>
     */
    private function inspectRequirement($function, $source, $root, PluginSubject $subject)
    {
        $base = ['plugin' => $subject->pluginClass(), 'function' => $function, 'root' => $root];

        // Core accepts a list of sources for one function and requires each in turn.
        if (is_array($source)) {
            $findings = [];
            foreach ($source as $one) {
                foreach ($this->inspectRequirement($function, $one, $root, $subject) as $finding) {
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
     * The path core's `function_requirements()` would `require_once`, made absolute.
     *
     * @param string $root
     * @param string $source
     * @return string
     */
    private function resolveSource($root, $source)
    {
        $separator = strrpos($source, '\\');
        if ($separator !== false) {
            // Core: substr($source, strrpos($source, '\\') + 2) — the namespace prefix is
            // stripped along with one further character. Reproduced verbatim, oddity included.
            $source = substr($source, $separator + 2);
        }
        return $root.'/'.ltrim((string)$source, '/');
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
