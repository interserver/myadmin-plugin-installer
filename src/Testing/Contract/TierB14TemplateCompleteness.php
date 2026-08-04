<?php
/**
 * @author Joe Huss <detain@interserver.net>
 * @copyright 2026
 * @package MyAdmin
 * @category Testing
 */

namespace MyAdmin\Plugins\Testing\Contract;

use ReflectionMethod;
use Throwable;

/**
 * Tier-B-14 — a service plugin's queue templates and its queue handler agree.
 *
 * ---------------------------------------------------------------------------------
 * SCOPE
 * ---------------------------------------------------------------------------------
 * Applies to `$type === 'service'` plugins that declare a `getQueue()`. Seven of the 71
 * plugins do. Everything else returns a {@see Finding} — never `[]`, because an empty result
 * is the pass signal, and a vacuous cell that reads as a pass is how a triage matrix claims
 * coverage it does not have.
 *
 * ---------------------------------------------------------------------------------
 * WHICH FINDING — THE SPLIT R-4 ADDED
 * ---------------------------------------------------------------------------------
 * This inspector produced 70 of the fleet's 155 grey cells, and they were not all grey for
 * the same reason. Two kinds:
 *
 *  - **{@see Finding::notApplicable()}** — the check reached a verdict and there is nothing of
 *    its kind here. A plugin that is not a service, a service with no `getQueue()`, or a
 *    `getQueue()` the scanner read cleanly and found renders no `*.sh.tpl` at all. Nothing is
 *    unverified about these packages; there is simply no queue-template surface to verify.
 *  - **{@see Finding::skipped()}** — the check could not run. A class that will not load, a
 *    handler with no readable source, a token scan that desynchronised, a render directory
 *    that is not recoverable, and — the one that matters most — a handler that *does* render
 *    templates but selects them from queue data and names no action literally.
 *
 * That last case is the reason this inspector does not go to zero skips. It looks like the
 * others (an empty result, no failure to report) and it is categorically different: those
 * packages have a queue-template surface, it is one of the largest in the fleet, and B-14
 * cannot see it. Rendering that `o` would be exactly the camouflage R-4 removed — a genuine
 * blind spot hidden inside a crowd of harmless ones. It stays `-`, and the residual skip
 * count on this row is the size of the blind spot rather than the size of the fleet.
 *
 * ---------------------------------------------------------------------------------
 * THE TWO DIRECTIONS
 * ---------------------------------------------------------------------------------
 *  - **referenced but missing** — the handler names an action for which no
 *    `<templates>/<action>.sh.tpl` exists. At runtime the handler's own `file_exists()` guard
 *    turns this into an `myadmin_log(..., 'error', 'Call <action> ... Does not Exist ...')`
 *    and the queued work silently does not happen. {@see Finding::failure()}.
 *  - **present but unreachable** — a `*.sh.tpl` no action can select. {@see Finding::notice()},
 *    which by construction never fails a build.
 *
 * The unreachable direction is reported **only when the dispatch is closed** — that is, when
 * the handler builds template names purely from literals. Five of the seven handlers instead
 * build the path as `__DIR__.'/../templates/'.$serviceInfo['action'].'.sh.tpl'`, where the
 * action comes from the queue row. Under that dispatch *every* file in the directory is
 * reachable by definition, so emitting "unreachable" notices would mean 27 false notices on
 * `myadmin-kvm-vps` alone. Suppressing them is not a weakened check; reporting them would be
 * an incorrect one.
 *
 * ---------------------------------------------------------------------------------
 * PATHS ARE RESOLVED ABSOLUTELY, NEVER AGAINST THE CWD
 * ---------------------------------------------------------------------------------
 * Every lookup is anchored to {@see PluginSubject::packageDir()} or to the directory of the
 * file that declares `getQueue()`, and `..` segments are collapsed lexically rather than by
 * `realpath()` so a directory that does not exist can still be named in the finding. This is
 * deliberate: `myadmin-webuzo-vps` carries a `require_once` resolved against the current
 * working directory, so its suite passes only when run from inside the core tree. An
 * inspector with the same flaw would report different results depending on where it was
 * invoked from, which is worse than not running at all.
 *
 * The anchoring also has to survive a handler that renders from **another package**:
 * `myadmin-quickservers-module` uses `__DIR__.'/../../myadmin-kvm-vps/templates/'`. The
 * directory therefore comes from the source, not from an assumption that it is
 * `<package>/templates`.
 *
 * ---------------------------------------------------------------------------------
 * ACCURACY OF THE ACTION SET
 * ---------------------------------------------------------------------------------
 * See {@see TierB14QueueActionScanner} for the three extraction rules and their documented
 * false negatives. The consequence here is that when the dispatch is dynamic *and* the
 * handler names no action literally, there is nothing to cross-check and the result is a
 * skip carrying that reason — not a pass, and after R-4 not a not-applicable either. On
 * today's fleet that is the outcome for five of the seven — docker, lxc, openvz, virtuozzo
 * and xen — which is an honest statement of what B-14 can see rather than five green cells.
 * Those five are also the entire residual skip count on this row, and on the fleet: the other
 * 65 cells in this column are packages with no queue-template surface at all.
 *
 * ---------------------------------------------------------------------------------
 * TWO SKIPS THAT LOOK REDUNDANT AND ARE NOT
 * ---------------------------------------------------------------------------------
 * Both were added after a review found this class emitting `Finding::failure()` naming
 * templates that exist on disk. Neither is defensive padding; each has a reproduction.
 *
 *  1. **{@see TierB14QueueActionScanner::scanDesyncs()} is consulted before anything is
 *     read out of the source.** Every conclusion below — which directory, which actions —
 *     is derived from one token walk. When that walk desynchronises, "no actions found" and
 *     "the scan fell over" are the same observation, and the honest-skip branch at the
 *     bottom of {@see crossCheck()} swallowed both. Skipping *loudly*, with
 *     {@see SCAN_TRUNCATED} in the reason, is the difference between a fleet run that says
 *     "68 cells had nothing to check" and one that says which of those were the harness
 *     failing. It cannot manufacture a false failure either, which the truncation could.
 *
 *  2. **A dispatch with `anchor === 'relative'` and an empty `directory` is skipped rather
 *     than resolved.** This is what a `.=`-assembled path produces: the `.sh.tpl` suffix
 *     arrives on its own line with no directory literal attached, so the recovered directory
 *     is the empty string. Resolving that against {@see PluginSubject::packageDir()} does
 *     not degrade gracefully — it silently retargets the whole check at the *package root*,
 *     finds none of the templates that live one level down in `templates/`, and reports
 *     every literal action as missing. It is an independent defect from (1): it reproduces
 *     from a plain `.=` chain with no interpolation anywhere.
 *
 *     Only the first dispatch is examined, here as before. A source that mixes an
 *     unrecoverable fragment with a usable chain skips on the fragment; first-seen-wins is
 *     the pre-existing rule and widening it is a separate decision.
 */
class TierB14TemplateCompleteness implements PluginInspector
{
    /**
     * Plugin type this check applies to.
     *
     * @var string
     */
    const SERVICE_TYPE = 'service';

    /**
     * Queue handler method name.
     *
     * @var string
     */
    const QUEUE_METHOD = 'getQueue';

    /**
     * @var string
     */
    const TEMPLATE_GLOB = '*.sh.tpl';

    /**
     * Prefix that marks the "the scanner broke, this is not an honest skip" reason.
     *
     * A parse failure and "there was genuinely nothing to cross-check" used to render as the
     * same skip, so a silently truncated scan was indistinguishable from a handler that
     * dispatches on queue data. Both are still skips — both really are "could not run" — so
     * the R-4 severity split does not separate them either, and the distinction stays in the
     * reason text where `grep` can find it across a fleet run. What R-4 did buy is that these
     * two are now the *only* things on this row wearing a dash, instead of two among seventy.
     *
     * @var string
     */
    const SCAN_TRUNCATED = 'B-14 scan truncated';

    /**
     * @return string
     */
    public function id()
    {
        return 'B-14';
    }

    /**
     * @return string
     */
    public function title()
    {
        return 'Queue templates and queue handler agree';
    }

    /**
     * @param PluginSubject $subject
     * @return array<int,Finding>
     */
    public function inspect(PluginSubject $subject)
    {
        if (!$subject->isLoadable()) {
            return [Finding::skipped($this->id(), 'plugin class does not load', [
                'class' => $subject->pluginClass(),
            ])];
        }
        $type = $this->type($subject);
        if ($type !== self::SERVICE_TYPE) {
            // Out of scope by declaration, not unverifiable: a non-service plugin has no queue
            // for templates to belong to. See the class docblock on the R-4 split.
            return [Finding::notApplicable(
                $this->id(),
                'plugin type is '.($type === null ? 'undeclared' : '"'.$type.'"').', not "'.self::SERVICE_TYPE.'"',
                ['class' => $subject->pluginClass()]
            )];
        }
        if (!$subject->reflection()->hasMethod(self::QUEUE_METHOD)) {
            return [Finding::notApplicable($this->id(), 'plugin declares no '.self::QUEUE_METHOD.'() handler', [
                'class' => $subject->pluginClass(),
            ])];
        }
        $method = $subject->reflection()->getMethod(self::QUEUE_METHOD);
        $source = $this->methodSource($method);
        if ($source === null) {
            return [Finding::skipped($this->id(), self::QUEUE_METHOD.'() has no readable source', [
                'class' => $subject->pluginClass(),
            ])];
        }
        $desyncs = TierB14QueueActionScanner::scanDesyncs($source);
        if ($desyncs !== []) {
            return [Finding::skipped(
                $this->id(),
                self::SCAN_TRUNCATED.': the token scan of '.self::QUEUE_METHOD.'() desynchronised ('
                    .implode('; ', $desyncs).'), so neither the template directory nor the action set '
                    .'recovered from it can be trusted',
                ['class' => $subject->pluginClass(), 'desyncs' => count($desyncs)]
            )];
        }
        $dispatches = TierB14QueueActionScanner::templateDispatches($source);
        if ($dispatches === []) {
            // Reached only after scanDesyncs() came back clean, so the scan is trustworthy and
            // this is a verdict rather than a shrug: the handler renders no shell templates,
            // so there is no template set for the assertion to cross-check.
            return [Finding::notApplicable(
                $this->id(),
                self::QUEUE_METHOD.'() does not render '.self::TEMPLATE_GLOB.' templates',
                ['class' => $subject->pluginClass()]
            )];
        }
        if ($dispatches[0]['anchor'] === 'relative' && $dispatches[0]['directory'] === '') {
            return [Finding::skipped(
                $this->id(),
                self::QUEUE_METHOD.'() reaches the '.TierB14QueueActionScanner::TEMPLATE_SUFFIX.' suffix without a '
                    .'directory literal attached to it, so the render directory is not recoverable from the source',
                ['class' => $subject->pluginClass(), 'template' => $dispatches[0]['template']]
            )];
        }
        $directory = $this->resolveDirectory($subject, $method, $dispatches[0]);
        if ($directory === null) {
            return [Finding::skipped(
                $this->id(),
                'template directory could not be resolved to an absolute path',
                ['class' => $subject->pluginClass(), 'literal' => $dispatches[0]['directory']]
            )];
        }
        if (!is_dir($directory)) {
            return [Finding::failure(
                $this->id(),
                self::QUEUE_METHOD.'() renders templates from "'.$directory.'", which does not exist; every queued '
                    .'action for this plugin fails the handler\'s file_exists() guard',
                ['class' => $subject->pluginClass(), 'directory' => $directory]
            )];
        }
        return $this->crossCheck($subject, $source, $directory, $this->isDynamic($dispatches));
    }

    /**
     * The plugin's `$type`, read without letting a throwing static initialiser escape.
     *
     * Reading *any* static property makes PHP evaluate *every* constant expression the class
     * declares, so a plugin whose unrelated `$settings` initialiser references a bare
     * `PRORATE_BILLING` makes `PluginSubject::type()` throw even though `$type` itself is a
     * plain literal. That is not hypothetical: it is true of 10 of the 69 plugins, and
     * treating the throw as "no type declared" would silently downgrade the gate on this
     * whole check from a decision to a guess. Falling back to the source keeps a
     * `type = 'service'` plugin in scope even when its class cannot be touched.
     *
     * @param PluginSubject $subject
     * @return string|null
     */
    private function type(PluginSubject $subject)
    {
        try {
            $type = $subject->type();
            if ($type !== null) {
                return $type;
            }
        } catch (Throwable $error) {
            // Fall through to the source scan below.
        }
        $file = $subject->reflection()->getFileName();
        return $file === false || !is_file($file) ? null : $this->typeFromSource((string)file_get_contents($file));
    }

    /**
     * Reads `static $type = '<literal>';` out of a class's source.
     *
     * @param string $source
     * @return string|null
     */
    private function typeFromSource($source)
    {
        $tokens = [];
        foreach (token_get_all($source) as $token) {
            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $tokens[] = $token;
        }
        $count = count($tokens);
        for ($i = 0; $i + 3 < $count; $i++) {
            $token = $tokens[$i];
            if (!is_array($token) || $token[0] !== T_STATIC) {
                continue;
            }
            $name = $tokens[$i + 1];
            if (!is_array($name) || $name[0] !== T_VARIABLE || $name[1] !== '$type') {
                continue;
            }
            $value = $tokens[$i + 3];
            if ($tokens[$i + 2] !== '=' || !is_array($value) || $value[0] !== T_CONSTANT_ENCAPSED_STRING) {
                continue;
            }
            return trim($value[1], '"\'');
        }
        return null;
    }

    /**
     * Compares the directory listing against the literal action set, both ways.
     *
     * @param PluginSubject $subject
     * @param string        $source
     * @param string        $directory
     * @param bool          $dynamic
     * @return array<int,Finding>
     */
    private function crossCheck(PluginSubject $subject, $source, $directory, $dynamic)
    {
        $actions = TierB14QueueActionScanner::actionLiterals($source);
        $present = $this->templateNames($directory);
        if ($actions === [] && $dynamic) {
            // DELIBERATELY still a skip after R-4, and the only branch of this inspector that
            // is. Everything of B-14's kind is present here — a service, a queue handler, a
            // template directory, often dozens of templates — and this inspector cannot read
            // the action set that would let it check any of them. That is a coverage hole, not
            // an inapplicable assertion, and calling it `o` would hide the fleet's largest
            // queue surfaces inside the crowd of packages that simply have no queue.
            return [Finding::skipped(
                $this->id(),
                self::QUEUE_METHOD.'() selects templates from queue data and names no action literally, so there is '
                    .'no action set to cross-check',
                ['class' => $subject->pluginClass(), 'directory' => $directory, 'templates' => count($present)]
            )];
        }
        $findings = [];
        foreach ($actions as $action) {
            if (in_array($action, $present, true)) {
                continue;
            }
            $findings[] = Finding::failure(
                $this->id(),
                'queue action "'.$action.'" has no template; '.$directory.'/'.$action.'.sh.tpl is missing',
                ['class' => $subject->pluginClass(), 'action' => $action, 'directory' => $directory]
            );
        }
        if ($dynamic) {
            return $findings;
        }
        foreach ($present as $template) {
            if (in_array($template, $actions, true)) {
                continue;
            }
            $findings[] = Finding::notice(
                $this->id(),
                'template "'.$template.'.sh.tpl" is present but no action in '.self::QUEUE_METHOD.'() selects it',
                ['class' => $subject->pluginClass(), 'template' => $template, 'directory' => $directory]
            );
        }
        return $findings;
    }

    /**
     * Whether any render target builds its template name from non-literal data.
     *
     * @param array<int,array<string,mixed>> $dispatches
     * @return bool
     */
    private function isDynamic(array $dispatches)
    {
        foreach ($dispatches as $dispatch) {
            if ($dispatch['dynamic']) {
                return true;
            }
        }
        return false;
    }

    /**
     * Action names for which a template exists, without the `.sh.tpl` suffix.
     *
     * @param string $directory
     * @return array<int,string>
     */
    private function templateNames($directory)
    {
        $names = [];
        $matches = glob($directory.'/'.self::TEMPLATE_GLOB);
        if ($matches === false) {
            return [];
        }
        foreach ($matches as $path) {
            if (!is_file($path)) {
                continue;
            }
            $names[] = basename($path, TierB14QueueActionScanner::TEMPLATE_SUFFIX);
        }
        sort($names);
        return $names;
    }

    /**
     * Turns the scanned directory fragment into an absolute path.
     *
     * `__DIR__` resolves against the file declaring `getQueue()`; a bare relative fragment
     * resolves against {@see PluginSubject::packageDir()}. Neither consults `getcwd()`.
     *
     * @param PluginSubject       $subject
     * @param ReflectionMethod    $method
     * @param array<string,mixed> $dispatch
     * @return string|null
     */
    private function resolveDirectory(PluginSubject $subject, ReflectionMethod $method, array $dispatch)
    {
        $fragment = rtrim($dispatch['directory'], '/');
        if ($dispatch['anchor'] === 'absolute') {
            return $this->normalise($fragment);
        }
        if ($dispatch['anchor'] === 'dir') {
            $file = $method->getFileName();
            if ($file === false) {
                return null;
            }
            $base = dirname($file);
        } else {
            $base = $subject->packageDir();
            if ($base === null) {
                return null;
            }
        }
        return $this->normalise($base.'/'.ltrim($fragment, '/'));
    }

    /**
     * Collapses `.` and `..` lexically.
     *
     * `realpath()` is deliberately not used: it returns false for a path that does not
     * exist, and naming a missing template directory in the finding is the whole point of
     * the failure branch above.
     *
     * @param string $path
     * @return string
     */
    private function normalise($path)
    {
        $absolute = strpos($path, '/') === 0;
        $segments = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                if ($segments !== [] && end($segments) !== '..') {
                    array_pop($segments);
                    continue;
                }
                if ($absolute) {
                    continue;
                }
            }
            $segments[] = $segment;
        }
        return ($absolute ? '/' : '').implode('/', $segments);
    }

    /**
     * The declared source of a method, wrapped so it can be tokenised on its own.
     *
     * @param ReflectionMethod $method
     * @return string|null
     */
    private function methodSource(ReflectionMethod $method)
    {
        $file = $method->getFileName();
        $start = $method->getStartLine();
        $end = $method->getEndLine();
        if ($file === false || !is_file($file) || $start === false || $end === false) {
            return null;
        }
        $lines = file($file);
        if ($lines === false) {
            return null;
        }
        $body = array_slice($lines, $start - 1, $end - $start + 1);
        return "<?php\n".implode('', $body);
    }
}
