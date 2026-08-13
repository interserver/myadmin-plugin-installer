<?php
/**
 * @author Joe Huss <detain@interserver.net>
 * @copyright 2026
 * @package MyAdmin
 * @category Testing
 */

namespace MyAdmin\Plugins\Testing\Contract;

/**
 * A-1 — the plugin class exists, is concrete, and constructs with no arguments.
 *
 * Everything else in Tier A reads properties or invokes `getHooks()` on this class, so this
 * is the one inspector that must not assume `PluginSubject::reflection()` is usable. It
 * therefore does its own existence probing before touching reflection at all.
 *
 * `class_exists()` answers **false** for an interface and for a trait. A plugin refactored
 * into `interface Plugin` or `trait Plugin` would otherwise register as "not loadable" —
 * a skip, which reads as "the check never ran" rather than "this can never be dispatched
 * to". Both are probed explicitly so they surface as failures.
 *
 * The no-argument construction is verified by actually calling the constructor rather than
 * by trusting reflection, because `ReflectionClass::isInstantiable()` says nothing about a
 * constructor that fatals. Instantiation happens only after reflection has confirmed the
 * class is concrete and takes no required arguments, the instance is dropped immediately,
 * and every fleet plugin's constructor is an empty no-op — so the side-effect budget in
 * {@see PluginInspector} is respected.
 *
 * ---------------------------------------------------------------------------------
 * THE CONSTRUCTOR RUNS UNDER A BUFFER, AND THIS INSPECTOR REPORTS WHAT IT PRINTS (R-8)
 * ---------------------------------------------------------------------------------
 * Construction is plugin code, so it is wrapped in {@see TierB15NoOutput::capture()} like
 * every other execution in the catalogue. Unbuffered, a constructor containing a stray
 * `echo` — or a leftover `var_dump()` — escaped into the PHPUnit process, and
 * `beStrictAboutOutputDuringTests="true"` + `failOnRisky="true"` turned it into
 * `R  This test printed output: …` attributed to **A-1**: no plugin name, no indication that
 * the printing code was the plugin's rather than the harness's. That is precisely the
 * unusable report {@see TierB15NoOutput} exists to replace.
 *
 * Unlike B-12 and B-13, this inspector **reports** the captured bytes instead of discarding
 * them. It has no one to defer to: B-15 executes `getSettings()` and `getMenu()` and never
 * constructs the plugin, so bytes dropped here would be reported by nothing at all — the
 * swallowed evidence this harness exists to catch, recreated inside the harness itself.
 * The failure is filed in A-1's column because A-1 is the only inspector that runs this
 * code, and its message names B-15's assertion so the defect class is unambiguous.
 *
 * A constructor that both prints and throws is reported once, as the throw, with the printed
 * bytes carried in the same message and context: two findings for one construction would
 * double-count, and dropping either half would lose the evidence.
 *
 * `Finding::notice()` is deliberately not used. {@see \MyAdmin\Plugins\Testing\PluginContractTestCase}
 * reads only failures and skips, so a notice is discarded by the consumer.
 */
class TierA1ClassIsConstructible implements PluginInspector
{
    /**
     * @return string
     */
    public function id()
    {
        return 'A-1';
    }

    /**
     * @return string
     */
    public function title()
    {
        return 'Plugin class is concrete and constructible with no arguments';
    }

    /**
     * @param PluginSubject $subject
     * @return array<int,Finding>
     */
    public function inspect(PluginSubject $subject)
    {
        $class = $subject->pluginClass();

        if (!$subject->isLoadable()) {
            if (interface_exists($class)) {
                return [Finding::failure(
                    'A-1',
                    $class.' is declared as an interface. A plugin must be a concrete class:'
                        .' the dispatcher constructs it and calls its hook targets.',
                    ['class' => $class, 'kind' => 'interface']
                )];
            }
            if (trait_exists($class)) {
                return [Finding::failure(
                    'A-1',
                    $class.' is declared as a trait. A plugin must be a concrete class:'
                        .' the dispatcher constructs it and calls its hook targets.',
                    ['class' => $class, 'kind' => 'trait']
                )];
            }
            return [Finding::skipped(
                'A-1',
                'Plugin class '.$class.' could not be loaded, so nothing about it can be'
                    .' inspected. Check the package\'s PSR-4 mapping and the class name.',
                ['class' => $class]
            )];
        }

        $reflection = $subject->reflection();

        if ($reflection->isAbstract()) {
            return [Finding::failure(
                'A-1',
                $class.' is abstract and can never be instantiated. Declare the plugin as a'
                    .' concrete class, or point the subject at the concrete subclass.',
                ['class' => $class, 'kind' => 'abstract']
            )];
        }

        $constructor = $reflection->getConstructor();

        if ($constructor !== null && !$constructor->isPublic()) {
            return [Finding::failure(
                'A-1',
                $class.'::__construct() is '.($constructor->isPrivate() ? 'private' : 'protected')
                    .'. A plugin must be constructible from outside its own class.',
                ['class' => $class, 'visibility' => $constructor->isPrivate() ? 'private' : 'protected']
            )];
        }

        if (!$reflection->isInstantiable()) {
            return [Finding::failure(
                'A-1',
                $class.' is not instantiable. A plugin must be a concrete, constructible class.',
                ['class' => $class]
            )];
        }

        if ($constructor !== null && $constructor->getNumberOfRequiredParameters() > 0) {
            return [Finding::failure(
                'A-1',
                $class.'::__construct() requires '.$constructor->getNumberOfRequiredParameters()
                    .' argument(s); a plugin is constructed with none. Give every constructor'
                    .' parameter a default, or drop the constructor.',
                ['class' => $class, 'required' => $constructor->getNumberOfRequiredParameters()]
            )];
        }

        $run = TierB15NoOutput::capture(function () use ($reflection) {
            $reflection->newInstance();
        });
        $printed = $run['output'];

        if ($run['error'] !== null) {
            $e = $run['error'];
            return [Finding::failure(
                'A-1',
                'Constructing '.$class.' with no arguments threw '.get_class($e).': '.$e->getMessage()
                    .($printed === '' ? '' : ' — and printed '.strlen($printed).' byte(s) first: '
                        .TierB15NoOutput::excerpt($printed)),
                [
                    'class' => $class,
                    'exception' => get_class($e),
                    'error' => $e->getMessage(),
                ] + ($printed === '' ? [] : [
                    'bytes' => strlen($printed),
                    'output' => TierB15NoOutput::excerpt($printed),
                ])
            )];
        }

        if ($printed !== '') {
            return [Finding::failure(
                'A-1',
                TierB15NoOutput::describeOutput($class, '__construct()', $printed)
                    .' A constructor runs before the theme has emitted anything, so this lands above'
                    .' <!DOCTYPE html> in a real request and can trigger "headers already sent".'
                    .' Reported here rather than under B-15 because B-15 executes the handlers, not'
                    .' the constructor, so nothing else in the catalogue would ever see these bytes.',
                [
                    'class' => $class,
                    'site' => '__construct',
                    'bytes' => strlen($printed),
                    'output' => TierB15NoOutput::excerpt($printed),
                ]
            )];
        }

        return [];
    }
}
