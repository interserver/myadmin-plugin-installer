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
use MyAdmin\Plugins\Testing\Contract\TierB15NoOutput;
use MyAdmin\Plugins\Testing\Fakes\FakeApp;
use MyAdmin\Plugins\Testing\Fakes\FakeServiceClass;
use ReflectionMethod;
use ReflectionNamedType;
use Throwable;

/**
 * The mechanics behind {@see ServicePluginTestCase}: work out which service types a
 * lifecycle handler owns, hand it an event, run it, and report everything that moved.
 *
 * Kept out of `Contract/` **deliberately and load-bearingly**.
 * {@see Contract\InspectorRegistry::classes()} globs `src/Testing/Contract/*.php` and adds
 * every {@see Contract\PluginInspector} it finds to the eighteen-assertion catalogue. A
 * probe placed there — even one that never implemented the interface — is one refactor away
 * from becoming a nineteenth matrix column and silently changing the 1278-cell census gate
 * G2 is reviewed against. Phase 3 is a *separate test case*, not a new catalogue assertion,
 * and this file's location is how that stays true.
 *
 * ---------------------------------------------------------------------------------
 * WHICH KEY THE GATE IS ON — THE DECISION THIS CLASS EXISTS TO GET RIGHT
 * ---------------------------------------------------------------------------------
 * A lifecycle handler decides whether an event is its business by comparing one event
 * argument against `get_service_define('SOMETHING')`. **Which argument is not uniform.**
 * Measured across the fleet's 92 lifecycle handlers:
 *
 *     $event['category']   46 handlers      $event['type']   38 handlers
 *     no get_service_define gate at all      8 handlers
 *
 * and **no handler gates on more than one key**.
 *
 * That 46/38 split is the whole risk of this phase. A harness that assumed `type` — as the
 * obvious reading of the plan does — would write the owned id into a key 46 handlers never
 * look at. Every one of them would see a foreign id in the key it *does* read, return
 * immediately, and:
 *
 *  - assertion A would report 46 plugins as "does not act on a type it owns", all false;
 *  - assertion B would pass all 46 **vacuously**, because a handler that never matches is
 *    trivially inert. The regression guard would be switched off in exactly the half of the
 *    fleet it was built for, and it would look green.
 *
 * The second failure is much worse than the first, because nothing about it is visible.
 *
 * So the key is **derived from the handler's own source**, by finding the comparison against
 * `get_service_define()` and reading the event key on its left-hand side. Both syntactic
 * forms in use are recognised — `$event['k'] == get_service_define('X')` and
 * `in_array($event['k'], [get_service_define('X'), ...])`. A repo can override the result
 * (see `ServicePluginTestCase::handledTypes()`), and the provenance travels with every
 * finding so a reader always knows whether they are looking at a scanned fact or a declared
 * one.
 *
 * ### Why the scan is over tokens and not a regex
 *
 * A regex over the method body counts commented-out code.
 * `myadmin-quickservers-module::getQueue()` has its entire type gate commented out —
 *
 *     //if (in_array($event['type'], [get_service_define('KVM_LINUX'), ...])) {
 *
 * — and a regex-based census of this same fleet reported it as gated on `type`, hiding the
 * fact that the handler is ungated *and* calls `stopPropagation()`. A token scan cannot make
 * that mistake: `token_get_all()` returns a whole `//` comment as **one opaque `T_COMMENT`**,
 * so the `$event` and `get_service_define` inside it are never tokens at all.
 *
 * That is the tokeniser's doing, not {@see tokenise()}'s — mutation testing proved it, by
 * showing that leaving comments in the stream still reads that handler as ungated, and an
 * earlier revision of this paragraph credited the wrong mechanism. What {@see tokenise()}
 * actually buys is **adjacency**. Every match below is positional: `definesAt()` looks at
 * `$tokens[$index + 4]` for the comparison operator. An interstitial comment —
 *
 *     if ($event['category'] /* the service type *&#47; == get_service_define('CPANEL')) {
 *
 * — shifts every one of those offsets, and the gate is missed. A missed gate is the *silent*
 * failure: assertion B then passes vacuously. So the filter is load-bearing in the direction
 * that matters, just not for the reason it looked like.
 *
 * ### The fail-safe direction
 *
 * {@see seedArguments()} writes the **foreign** sentinel into `type` and `category` up front
 * and only then overwrites the one derived key with the owned id. So if the scanner ever
 * misses a gate, the handler sees a foreign value and stays inert: assertion A reports "did
 * not act" — a visible, investigable failure — rather than assertion B passing vacuously.
 * A scanner bug must surface as noise, never as silence.
 */
class ServiceHandlerProbe
{
    /**
     * The service-lifecycle handlers, in catalogue order.
     *
     * `getDeactivateIp` is in this list and is missing from the phase plan's list of six.
     * It is small — two plugins — but it sits on `licenses.deactivate_ip`, an eight-listener
     * shared hook, which is the exact shape assertion B exists to guard.
     *
     * @var array<int,string>
     */
    const HANDLERS = [
        'getActivate',
        'getReactivate',
        'getDeactivate',
        'getDeactivateIp',
        'getTerminate',
        'getChangeIp',
        'getQueue',
    ];

    /**
     * The two event keys a fleet gate is ever on. Both are pre-seeded foreign; see the
     * class docblock on the fail-safe direction.
     *
     * @var array<int,string>
     */
    const GATE_KEYS = ['type', 'category'];

    /**
     * A service id no plugin owns.
     *
     * Chosen far above both the real define range and
     * {@see FakeApp::syntheticDefine()}'s 900000-989999 band, so it cannot collide with the
     * id a plugin's own unmapped define resolves to. A collision would make a foreign event
     * match, and assertion B would then report the handler acting on a foreign type — a
     * fabricated failure that would look exactly like the real defect.
     *
     * @var int
     */
    const FOREIGN_TYPE = 2147000001;

    /**
     * Every recorder a side effect can land in.
     *
     * The list is exhaustive on purpose and is the reason assertion B is worth anything.
     * "No side effects" checked against the log alone passes a handler that wrote a history
     * row, issued a query, queued an insert or rendered a template — and a partial sweep
     * here is invisible, because the assertion still goes green. Each name is a
     * {@see Harness} accessor returning an object with the {@see Recorder} trait's
     * `calls()`.
     *
     * @var array<int,string>
     */
    const RECORDERS = [
        'settings', 'menu', 'db', 'history', 'session', 'accounts',
        'variables', 'smarty', 'table', 'events', 'output',
    ];

    /**
     * Throwable messages that mean "this environment cannot supply a symbol the handler
     * needs", as opposed to "the handler is broken".
     *
     * This distinction is what makes assertion A worth running rather than skipping
     * wholesale. Measured over the fleet: of the 12 handlers that produced no observable
     * effect on a matching type, 10 died on a symbol the harness cannot provide — a
     * plugin-private API client, `TFSmarty`, a core helper like `get_service_master()` — and
     * two died of their own logic. Collapsing all 12 into "skip" would have buried those two;
     * collapsing them into "fail" would have invented ten.
     *
     * Matched as message prefixes, because PHP does not give these distinct exception
     * classes: `Error` covers both `Call to undefined function foo()` and
     * `Call to a member function getId() on null`.
     *
     * Prefixes, not free substrings, and only ever on an `\Error`. A bare `not found`
     * substring would swallow a plugin's own `RuntimeException('customer not found')` and
     * turn a real defect into a skip — which is the one direction
     * {@see Contract\Finding}'s docblock says nothing may ever collapse.
     *
     * @var array<int,string>
     */
    const UNRESOLVABLE = [
        'Call to undefined function',
        'Call to undefined method',
        'Undefined constant',
        'Cannot instantiate abstract class',
        'Cannot instantiate interface',
    ];

    /**
     * PHP's "the autoloader could not produce this name" messages.
     *
     * @var string
     */
    const UNRESOLVABLE_CLASS = '/^(Class|Interface|Trait|Enum) "[^"]*" not found$/';

    /**
     * Everything known about one handler's type gate.
     *
     * @param \MyAdmin\Plugins\Testing\Contract\PluginSubject $subject
     * @param string                                          $handler
     * @param array<string,mixed>|null                        $declared repo-declared owned
     *        types for this handler, or null to derive from source
     * @return array{key:string|null,defines:array<int,string>,stops:bool,source:string,readKeys:array<int,string>}
     */
    public static function gateFor(PluginSubject $subject, $handler, $declared = null)
    {
        $scan = self::scan($subject, $handler);
        if ($declared === null) {
            return $scan;
        }
        // A repo that declares its owned types still gets the scanned key and the scanned
        // read-key list: it is being trusted about *which* types it owns, not about how its
        // own source is written. Letting a declaration move the gate key would reintroduce
        // the vacuous-pass failure this class exists to prevent, and would do it silently.
        $scan['defines'] = array_values(array_unique(array_map('strval', (array)$declared)));
        $scan['source'] = 'declared by the repo';
        if ($scan['key'] === null && $scan['defines'] !== []) {
            // Nothing was scannable but the repo says it owns types. Take it at its word and
            // fall back to the fleet-majority key, disclosing that the key is a guess.
            $scan['key'] = 'category';
            $scan['source'] = 'declared by the repo (gate key not found in source; assuming "category")';
        }
        return $scan;
    }

    /**
     * Reads a handler's gate straight out of its source.
     *
     * @param \MyAdmin\Plugins\Testing\Contract\PluginSubject $subject
     * @param string                                          $handler
     * @return array{key:string|null,defines:array<int,string>,stops:bool,source:string,readKeys:array<int,string>}
     */
    public static function scan(PluginSubject $subject, $handler)
    {
        $empty = [
            'key' => null, 'defines' => [], 'stops' => false,
            'source' => 'derived from source', 'readKeys' => [],
        ];
        $reflection = $subject->reflection();
        if (!$reflection->hasMethod($handler)) {
            return $empty;
        }
        $file = $reflection->getMethod($handler)->getDeclaringClass()->getFileName();
        if ($file === false || !is_file($file) || !is_readable($file)) {
            return $empty;
        }
        $body = self::methodBody((string)file_get_contents($file), $handler);
        if ($body === null) {
            return $empty;
        }
        return array_merge($empty, self::analyse($body));
    }

    /**
     * Significant tokens of one method's body, or null when it cannot be located.
     *
     * @param string $source
     * @param string $method
     * @return array<int,array{0:int,1:string}|string>|null
     */
    public static function methodBody($source, $method)
    {
        $tokens = self::tokenise($source);
        $count = count($tokens);
        for ($i = 0; $i < $count; $i++) {
            if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_FUNCTION) {
                continue;
            }
            if (!isset($tokens[$i + 1]) || !is_array($tokens[$i + 1])
                || $tokens[$i + 1][0] !== T_STRING || $tokens[$i + 1][1] !== $method) {
                continue;
            }
            $open = null;
            for ($j = $i + 1; $j < $count; $j++) {
                $text = is_array($tokens[$j]) ? $tokens[$j][1] : $tokens[$j];
                if ($text === '{') {
                    $open = $j;
                    break;
                }
                if ($text === ';') {
                    // An abstract or interface declaration: no body to read.
                    break;
                }
            }
            if ($open === null) {
                return null;
            }
            $depth = 0;
            $body = [];
            for ($j = $open; $j < $count; $j++) {
                $text = is_array($tokens[$j]) ? $tokens[$j][1] : $tokens[$j];
                if ($text === '{') {
                    $depth++;
                }
                if ($text === '}') {
                    $depth--;
                    if ($depth === 0) {
                        return $body;
                    }
                }
                if ($j > $open) {
                    $body[] = $tokens[$j];
                }
            }
            return $body;
        }
        return null;
    }

    /**
     * Tokens with whitespace **and comments** removed.
     *
     * Dropping comments is the load-bearing part; see the class docblock on
     * `quickservers-module::getQueue()`.
     *
     * @param string $source
     * @return array<int,array{0:int,1:string}|string>
     */
    public static function tokenise($source)
    {
        $out = [];
        foreach (token_get_all($source) as $token) {
            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $out[] = $token;
        }
        return $out;
    }

    /**
     * Finds the gate, the owned define names, the `stopPropagation()` call and every event
     * key the body reads.
     *
     * The read-key list matters as much as the gate: `GenericEvent::offsetGet()` **throws**
     * `InvalidArgumentException` for an argument that was never set, so an event seeded with
     * only the gate key blows up the moment a handler reads `$event['field1']`. Seeding
     * every key the body mentions is what lets the handler run at all.
     *
     * @param array<int,array{0:int,1:string}|string> $tokens
     * @return array{key:string|null,defines:array<int,string>,stops:bool,readKeys:array<int,string>}
     */
    public static function analyse(array $tokens)
    {
        $count = count($tokens);
        $key = null;
        $defines = [];
        $stops = false;
        $readKeys = [];

        for ($i = 0; $i < $count; $i++) {
            if (is_array($tokens[$i]) && $tokens[$i][0] === T_STRING && $tokens[$i][1] === 'stopPropagation') {
                $stops = true;
            }
            if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_VARIABLE || $tokens[$i][1] !== '$event') {
                continue;
            }
            if (!isset($tokens[$i + 3]) || self::text($tokens[$i + 1]) !== '[' || self::text($tokens[$i + 3]) !== ']') {
                continue;
            }
            if (!is_array($tokens[$i + 2]) || $tokens[$i + 2][0] !== T_CONSTANT_ENCAPSED_STRING) {
                continue;
            }
            $name = trim($tokens[$i + 2][1], "'\"");
            if (!in_array($name, $readKeys, true)) {
                $readKeys[] = $name;
            }

            $found = self::definesAt($tokens, $i);
            if ($found === []) {
                continue;
            }
            // First gate wins. No fleet handler has two, and picking the first keeps the
            // choice deterministic if one ever does.
            if ($key === null) {
                $key = $name;
            }
            if ($key === $name) {
                $defines = array_values(array_unique(array_merge($defines, $found)));
            }
        }

        return ['key' => $key, 'defines' => $defines, 'stops' => $stops, 'readKeys' => $readKeys];
    }

    /**
     * The `get_service_define()` names compared against the `$event[...]` at `$index`, in
     * either of the two syntactic forms the fleet uses.
     *
     * @param array<int,array{0:int,1:string}|string> $tokens
     * @param int                                     $index position of the `$event` token
     * @return array<int,string>
     */
    private static function definesAt(array $tokens, $index)
    {
        $count = count($tokens);
        $after = $index + 4;
        if ($after >= $count) {
            return [];
        }

        // Form 1:  $event['k'] == get_service_define('X')
        if (in_array(self::text($tokens[$after]), ['==', '===', '!=', '!=='], true)) {
            $name = self::defineNameAt($tokens, $after + 1);
            return $name === null ? [] : [$name];
        }

        // Form 2:  in_array($event['k'], [get_service_define('X'), ...])
        if ($index < 2 || !is_array($tokens[$index - 2]) || $tokens[$index - 2][0] !== T_STRING
            || strtolower($tokens[$index - 2][1]) !== 'in_array' || self::text($tokens[$index - 1]) !== '(') {
            return [];
        }
        $depth = 1;
        $names = [];
        for ($j = $index + 4; $j < $count; $j++) {
            $text = self::text($tokens[$j]);
            if ($text === '(') {
                $depth++;
            }
            if ($text === ')') {
                $depth--;
                if ($depth === 0) {
                    break;
                }
            }
            $name = self::defineNameAt($tokens, $j);
            if ($name !== null) {
                $names[] = $name;
            }
        }
        return array_values(array_unique($names));
    }

    /**
     * The literal argument of a `get_service_define(...)` starting at `$index`, or null.
     *
     * @param array<int,array{0:int,1:string}|string> $tokens
     * @param int                                     $index
     * @return string|null
     */
    private static function defineNameAt(array $tokens, $index)
    {
        if (!isset($tokens[$index + 2]) || !is_array($tokens[$index])) {
            return null;
        }
        if ($tokens[$index][0] !== T_STRING || $tokens[$index][1] !== 'get_service_define') {
            return null;
        }
        if (self::text($tokens[$index + 1]) !== '('
            || !is_array($tokens[$index + 2]) || $tokens[$index + 2][0] !== T_CONSTANT_ENCAPSED_STRING) {
            return null;
        }
        return trim($tokens[$index + 2][1], "'\"");
    }

    /**
     * @param array{0:int,1:string}|string $token
     * @return string
     */
    private static function text($token)
    {
        return is_array($token) ? $token[1] : $token;
    }

    // -----------------------------------------------------------------------
    // Execution
    // -----------------------------------------------------------------------

    /**
     * The event arguments to run a handler with.
     *
     * Both gate keys start foreign — see the class docblock on the fail-safe direction —
     * and the owned id is written into the derived key only. Everything else the body reads
     * is seeded with an empty string so `GenericEvent::offsetGet()` does not throw.
     *
     * @param array{key:string|null,readKeys:array<int,string>} $gate
     * @param mixed                                             $typeValue what the gate key gets
     * @return array<string,mixed>
     */
    public static function seedArguments(array $gate, $typeValue)
    {
        $arguments = [];
        foreach ($gate['readKeys'] as $name) {
            $arguments[$name] = '';
        }
        foreach (self::GATE_KEYS as $name) {
            $arguments[$name] = self::FOREIGN_TYPE;
        }
        // Written whether or not the body reads them: a handler that only *assigns*
        // `$event['success']` still needs the key to exist for the assignment to be
        // observable as a mutation rather than as a new key.
        $arguments += ['output' => '', 'success' => false, 'status' => '', 'status_text' => ''];
        if ($gate['key'] !== null) {
            $arguments[$gate['key']] = $typeValue;
        }
        return $arguments;
    }

    /**
     * Runs one handler against one event and reports everything that moved.
     *
     * @param \MyAdmin\Plugins\Testing\Contract\PluginSubject $subject
     * @param string                                          $handler
     * @param array<string,mixed>                             $arguments event arguments
     * @param string                                          $id        catalogue id, for a skip
     * @return array{skip:Finding|null,effects:array<int,string>,stopped:bool,error:Throwable|null,output:string}
     */
    public static function run(PluginSubject $subject, $handler, array $arguments, $id)
    {
        $method = $subject->reflection()->getMethod($handler);
        $service = new FakeServiceClass(['action' => 'start', 'module' => (string)$subject->module()]);

        $built = self::buildEvent($method, $service, $arguments, $subject, $handler, $id);
        if ($built['skip'] !== null) {
            return [
                'skip' => $built['skip'], 'effects' => [], 'stopped' => false,
                'error' => null, 'output' => '',
            ];
        }
        $event = $built['event'];

        // Anything init() recorded while wiring the fakes is not the handler's doing.
        Harness::reset();
        FakeApp::reset();
        $service->reset(['action' => 'start', 'module' => (string)$subject->module()]);

        $run = TierB15NoOutput::capture(static function () use ($method, $event) {
            $method->invokeArgs(null, [$event]);
        });

        return [
            'skip'    => null,
            'effects' => self::observedEffects($service, $event, $arguments, $run['output']),
            'stopped' => method_exists($event, 'isPropagationStopped') ? (bool)$event->isPropagationStopped() : false,
            'error'   => $run['error'],
            'output'  => $run['output'],
        ];
    }

    /**
     * Builds the event object a handler's signature asks for.
     *
     * Prefers the class the handler declares — in a plugin repo that is always Symfony's
     * real `GenericEvent`, which is what production dispatches. Falls back to
     * {@see ServiceLifecycleEvent} only for an untyped parameter.
     *
     * It does **not** substitute the stand-in for a declared-but-unloadable `GenericEvent`.
     * {@see Contract\SubjectEvent}'s docblock records why, and it applies here with more
     * force: a stand-in accepted in place of a type the handler asked for would run the
     * handler against the wrong shape and report the result as if it meant something. A skip
     * naming the missing component is the honest answer, and it is the answer the installer's
     * own suite gets, because `symfony/event-dispatcher` is not one of its dependencies.
     *
     * @param \ReflectionMethod                               $method
     * @param object                                          $service
     * @param array<string,mixed>                             $arguments
     * @param \MyAdmin\Plugins\Testing\Contract\PluginSubject $subject
     * @param string                                          $handler
     * @param string                                          $id
     * @return array{event:object|null,skip:Finding|null}
     */
    public static function buildEvent(
        ReflectionMethod $method,
        $service,
        array $arguments,
        PluginSubject $subject,
        $handler,
        $id
    ) {
        $context = ['class' => $subject->pluginClass(), 'method' => $handler];

        $parameters = $method->getParameters();
        if ($parameters === []) {
            return ['event' => null, 'skip' => Finding::skipped(
                $id,
                sprintf('%s::%s() takes no event argument, so it cannot be driven with a service type', $subject->pluginClass(), $handler),
                $context
            )];
        }
        if ($method->getNumberOfRequiredParameters() > 1) {
            return ['event' => null, 'skip' => Finding::skipped(
                $id,
                sprintf(
                    '%s::%s() requires %d arguments; the harness can only supply the event',
                    $subject->pluginClass(),
                    $handler,
                    $method->getNumberOfRequiredParameters()
                ),
                $context
            )];
        }

        $type = $parameters[0]->getType();
        if (!$type instanceof ReflectionNamedType || $type->isBuiltin()) {
            return ['event' => new ServiceLifecycleEvent($service, $arguments), 'skip' => null];
        }

        $name = $type->getName();
        if (!class_exists($name)) {
            return ['event' => null, 'skip' => Finding::skipped(
                $id,
                sprintf(
                    'event class %s is not loadable here, so %s() cannot be invoked with a real event',
                    $name,
                    $handler
                ),
                array_merge($context, ['event' => $name, 'blockedBy' => $name])
            )];
        }

        try {
            $event = new $name($service, $arguments);
            if (!method_exists($event, 'getSubject') || $event->getSubject() !== $service) {
                throw new \RuntimeException('event did not accept the harness subject');
            }
            if (!($event instanceof \ArrayAccess)) {
                throw new \RuntimeException('event does not support array access, so no service type can be written to it');
            }
        } catch (Throwable $e) {
            return ['event' => null, 'skip' => Finding::skipped(
                $id,
                sprintf('could not build a %s to pass to %s(): %s', $name, $handler, $e->getMessage()),
                array_merge($context, ['event' => $name, 'blockedBy' => $name])
            )];
        }

        return ['event' => $event, 'skip' => null];
    }

    /**
     * Names of every recorder that moved, plus event-argument mutation and printed output.
     *
     * Returns names rather than a boolean because assertion B's failure message has to say
     * *what* the handler touched — "wrote to history and issued a query" is actionable and
     * "was not inert" is not.
     *
     * @param \MyAdmin\Plugins\Testing\Fakes\FakeServiceClass $service
     * @param object                                          $event
     * @param array<string,mixed>                             $seeded the arguments as handed in
     * @param string                                          $output
     * @return array<int,string>
     */
    public static function observedEffects(FakeServiceClass $service, $event, array $seeded, $output)
    {
        $effects = [];
        if (Log::entries() !== []) {
            $effects[] = 'myadmin_log (' . count(Log::entries()) . ')';
        }
        if (Harness::insertQueries() !== []) {
            $effects[] = 'make_insert_query (' . count(Harness::insertQueries()) . ')';
        }
        if (Harness::dialogs() !== []) {
            $effects[] = 'dialog (' . count(Harness::dialogs()) . ')';
        }
        foreach (self::RECORDERS as $name) {
            $fake = Harness::$name();
            if (!method_exists($fake, 'calls')) {
                continue;
            }
            $calls = $fake->calls();
            if ($calls !== []) {
                $effects[] = $name . ' (' . count($calls) . ')';
            }
        }
        if ($service->calls() !== []) {
            $effects[] = 'service subject (' . count($service->calls()) . ')';
        }
        if ($output !== '') {
            $effects[] = 'printed ' . strlen($output) . ' bytes';
        }
        $mutated = self::mutatedArguments($event, $seeded);
        if ($mutated !== []) {
            $effects[] = 'event arguments ' . implode('/', $mutated);
        }
        return $effects;
    }

    /**
     * Event argument keys the handler added or changed.
     *
     * @param object              $event
     * @param array<string,mixed> $seeded
     * @return array<int,string>
     */
    public static function mutatedArguments($event, array $seeded)
    {
        if (!method_exists($event, 'getArguments')) {
            return [];
        }
        $after = $event->getArguments();
        if (!is_array($after)) {
            return [];
        }
        $changed = [];
        foreach ($after as $name => $value) {
            if (!array_key_exists($name, $seeded) || $seeded[$name] !== $value) {
                $changed[] = (string)$name;
            }
        }
        return $changed;
    }

    /**
     * Whether a Throwable means "the environment is missing a symbol" rather than "the
     * handler is broken".
     *
     * See {@see UNRESOLVABLE} for why this distinction carries assertion A.
     *
     * Restricted to `\Error`, which is what the engine raises for a name it could not
     * resolve. A plugin throwing its own exception is describing its own domain, however the
     * message happens to read, and must never be mistaken for a missing symbol.
     *
     * @param \Throwable $error
     * @return bool
     */
    public static function isUnresolvableDependency(Throwable $error)
    {
        if (!$error instanceof \Error) {
            return false;
        }
        $message = $error->getMessage();
        foreach (self::UNRESOLVABLE as $prefix) {
            if (strpos($message, $prefix) === 0) {
                return true;
            }
        }
        return (bool)preg_match(self::UNRESOLVABLE_CLASS, $message);
    }

    /**
     * Whether the constants this plugin's code reads are all harness-owned.
     *
     * ---------------------------------------------------------------------------------
     * WHY ASSERTION A NEEDS THIS AND ASSERTION B DOES NOT
     * ---------------------------------------------------------------------------------
     * Driving a handler with a type it owns is, by construction, asking it to perform the
     * real lifecycle action. The harness fakes MyAdmin's core surface; it does **not** fake a
     * plugin's own vendored API client, and it cannot — those classes autoload out of the
     * plugin's own tree and know nothing about it.
     *
     * This is measured, not hypothetical. Running the fleet's matching-type path,
     * `myadmin-zonemta-mail::getDeactivate()` reached
     *
     *     $client = new \MongoDB\Client('mongodb://'.ZONEMTA_USERNAME.':'...'@'.ZONEMTA_HOST...);
     *     $users->deleteOne(['username' => $serviceClass->getUsername()]);
     *
     * and opened a socket. It failed only because `ZONEMTA_HOST` was the harness sentinel and
     * did not resolve. **On a host where the real constant is defined, that line deletes a
     * row.** The fakes are not a sandbox and must never be described as one.
     *
     * So before assertion A runs anything, this asks whether the harness owns the plugin's
     * configuration. {@see ConstantStub} defines what it stubs as the self-describing string
     * `__STUB_<NAME>__` and, being unable to redefine an existing constant, leaves real values
     * alone. A constant that is defined but is *not* the sentinel is therefore real
     * configuration this process did not put there — the signature of running inside a
     * configured core checkout rather than a plugin repo's CI.
     *
     * The guard is honest about what it buys, which is less than it looks:
     *
     *  - it **does** stop the destructive case, where a real host and real credentials turn
     *    an assertion into a live delete;
     *  - it does **not** stop a handler dialling the sentinel host and timing out. The
     *    connection attempt still leaves the machine. A repo whose handlers do real I/O
     *    should turn assertion A off with `exercisesOwnedTypes()`, and its docblock says so.
     *
     * Constants supplied through `constantOverrides()` are the repo's own deliberate values
     * and count as harness-owned.
     *
     * ### Only `define()`d constants count, and that filter is load-bearing
     *
     * {@see ConstantStub::scanFile()} returns *candidate* names — every all-caps token that
     * could be a constant reference. Plenty of them are PHP's own: `PHP_EOL` in a message,
     * `SOAP_1_2` in a client option array, `MYSQL_ASSOC` passed to `next_record()`. All three
     * are defined, none is the sentinel, and a naive check reported all three as "real
     * configuration this process did not define" — which skipped assertion A for five entire
     * packages on the strength of `PHP_EOL`. A guard that fires on `PHP_EOL` is not a safety
     * feature, it is an outage.
     *
     * `get_defined_constants(true)` groups by the extension that registered each name and
     * puts everything created by a runtime `define()` under `user`. That is exactly the
     * distinction wanted: application configuration is `define()`d, `PHP_EOL` and `SOAP_1_2`
     * are not. Filtering to `user` and then subtracting what this harness itself defined
     * leaves precisely the constants somebody else's bootstrap installed.
     *
     * @param \MyAdmin\Plugins\Testing\Contract\PluginSubject $subject
     * @return array<int,string> the real, non-harness constants found; empty when all clear
     */
    public static function unownedConstants(PluginSubject $subject)
    {
        $reflection = $subject->reflection();
        $file = $reflection->getFileName();
        if ($file === false) {
            return [];
        }

        $grouped = get_defined_constants(true);
        $userDefined = isset($grouped['user']) && is_array($grouped['user']) ? $grouped['user'] : [];

        $harnessOwned = array_flip(ConstantStub::definedConstants());
        // Bootstrap::defineBaseConstants() defines these itself, and they are not sentinels.
        foreach (['MYSQL_ASSOC', 'MYSQL_NUM', 'MYSQL_BOTH'] as $name) {
            $harnessOwned[$name] = true;
        }
        foreach (array_keys($subject->constantOverrides()) as $name) {
            $harnessOwned[$name] = true;
        }

        $unowned = [];
        foreach (ConstantStub::scanFile($file) as $name) {
            if (!array_key_exists($name, $userDefined) || isset($harnessOwned[$name])) {
                continue;
            }
            if ($userDefined[$name] === sprintf(ConstantStub::SENTINEL_FORMAT, $name)) {
                continue;
            }
            $unowned[] = $name;
        }
        return $unowned;
    }
}
