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

        try {
            $reflection->newInstance();
        } catch (\Throwable $e) {
            return [Finding::failure(
                'A-1',
                'Constructing '.$class.' with no arguments threw '.get_class($e).': '.$e->getMessage(),
                ['class' => $class, 'exception' => get_class($e), 'error' => $e->getMessage()]
            )];
        }

        return [];
    }
}
