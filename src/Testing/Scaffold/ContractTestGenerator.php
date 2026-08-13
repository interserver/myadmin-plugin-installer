<?php
/**
 * Plugins Management
 * @author Joe Huss <detain@interserver.net>
 * @copyright 2026
 * @package MyAdmin
 * @category Testing
 */

namespace MyAdmin\Plugins\Testing\Scaffold;

/**
 * Renders one package's `tests/ContractTest.php` from measured facts.
 *
 * ---------------------------------------------------------------------------------
 * WHAT THIS FILE IS THE SOURCE OF TRUTH FOR
 * ---------------------------------------------------------------------------------
 * 66 packages carry a generated ContractTest, and they were written by two throwaway
 * generators a week apart. The difference between those two generations is not cosmetic —
 * it is three specific mistakes that this class exists to stop being made again:
 *
 *   1. **Prime before the class is mentioned.** The first generation asserted `::$type`
 *      and only then called `primeConstants()`. That works right up until a package has a
 *      static property initializer referencing a bare constant — `$settings` holding
 *      `REPEAT_BILLING_METHOD => PRORATE_BILLING` is the shape mail-module has — because
 *      the initializer runs when the class *loads*, so even reading `::$type` fatals.
 *   2. **Read the hook table through A-5's accessor**, never by calling `getHooks()`
 *      directly. A direct call is a second, independent answer to "can this table be
 *      evaluated?", and for a plugin whose body touches a bare constant the two answers
 *      disagree: the inspectors handle it, the direct caller throws.
 *   3. **Never call `getHooks()` twice.** The first generation called it once for the key
 *      list and again for the callable loop, which asserts idempotence by accident and
 *      doubles any side effect the body has.
 *
 * ---------------------------------------------------------------------------------
 * WHY THE OUTPUT IS SO HEAVILY COMMENTED
 * ---------------------------------------------------------------------------------
 * The generated file is read far more often than it is written, usually by someone whose
 * build just went red and who has never seen this harness. Everything they need to act on
 * the failure is in the file itself rather than in a doc they would have to find first.
 */
class ContractTestGenerator
{
    /**
     * Renders the file.
     *
     * INPUT:   $facts — measured by probe.php, never parsed out of source.
     * RETURNS: string — complete PHP file contents, newline-terminated.
     *
     * @param \MyAdmin\Plugins\Testing\Scaffold\PluginFacts $facts
     * @return string
     */
    public function render(PluginFacts $facts)
    {
        $short = $facts->shortClass();
        $namespace = $facts->testNamespace();
        $base = $facts->isServicePlugin() ? 'ServicePluginTestCase' : 'PluginContractTestCase';

        return $this->header($facts, $namespace, $base)
            .$this->classOpening($base)
            .$this->pluginClassMethod($short)
            .$this->identityPin($facts, $short)
            ."    }\n}\n";
    }

    /**
     * File header: strict types, namespace, imports, and the class docblock.
     *
     * @param \MyAdmin\Plugins\Testing\Scaffold\PluginFacts $facts
     * @param string                                        $namespace
     * @param string                                        $base
     * @return string
     */
    private function header(PluginFacts $facts, $namespace, $base)
    {
        $class = $facts->pluginClass();
        $serviceNote = $facts->isServicePlugin() ? $this->serviceNote() : '';

        return <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace};

use {$class};
use MyAdmin\\Plugins\\Testing\\Contract\\TierA5HooksAreIdempotent;
use MyAdmin\\Plugins\\Testing\\{$base};

/**
 * Shared contract assertions for this plugin, plus the identity pin the shared
 * harness cannot provide.
 *
 * ---------------------------------------------------------------------------------
 * WHY THIS FILE IS ADDITIVE
 * ---------------------------------------------------------------------------------
 * This is a new file, not a replacement. Every pre-existing test in this package is
 * kept exactly as it was: the catalogue below runs *alongside* them, so the package
 * gains the 18 fleet-wide contract inspectors without giving up a single assertion it
 * already had. Some coverage is therefore duplicated -- deliberately, because losing
 * an assertion nobody has re-read is the more expensive mistake.
 *
 * ---------------------------------------------------------------------------------
 * WHAT THE CATALOGUE ADDS
 * ---------------------------------------------------------------------------------
 * {@see {$base}} executes this plugin rather than reading it: it primes
 * the bare constants the class body references, resolves every requirement path it
 * registers against the filesystem, checks each hook key is one core actually
 * dispatches, and runs getSettings()/getMenu()/apiRegister() for real. A dangling
 * registration or an undispatched hook key fails here even though it is invisible to
 * an assertion that only reads the registration table.{$serviceNote}
 *
 * ---------------------------------------------------------------------------------
 * WHAT THE IDENTITY PIN ADDS
 * ---------------------------------------------------------------------------------
 * Every catalogue assertion is conditional on the registration existing, so an
 * emptied getHooks() would leave the shared suite green. The pin below is the part
 * only this repo can state: which hooks this plugin is supposed to register, and that
 * \$type still selects the assertions intended for it.
 *
 * ---------------------------------------------------------------------------------
 * WHY THIS CLASS RUNS IN ITS OWN PROCESS
 * ---------------------------------------------------------------------------------
 * Inspecting a plugin defines real constants and calls register_module(). PHP cannot
 * undefine a constant and register_module() has no inverse, so this class cannot be
 * unwound once it has run: whatever executes after it in the same process sees primed
 * constants and a registered module it did not ask for. That is why the fleet matrix
 * generator spawns one process per package, and it is why this class is isolated here
 * -- without it, adding this file would change the outcome of the tests that were
 * already in this repo, which is precisely what an additive conversion must not do.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */

PHP;
    }

    /**
     * The extra paragraph a `type=service` package gets.
     *
     * @return string
     */
    private function serviceNote()
    {
        return " Because this is a `type=service` plugin, the base class is\n"
            .' * {@see ServicePluginTestCase}: the same eighteen inspectors, plus the assertions that drive'."\n"
            .' * getActivate()/getDeactivate()/getChangeIp()/getQueue() for a service type this plugin owns'."\n"
            .' * and again for one it does not, checking it acts on the first and stays inert for the second.';
    }

    /**
     * @param string $base
     * @return string
     */
    private function classOpening($base)
    {
        return "class ContractTest extends {$base}\n{\n";
    }

    /**
     * @param string $short
     * @return string
     */
    private function pluginClassMethod($short)
    {
        return <<<PHP
    /**
     * The class under contract.
     *
     * @return string
     */
    protected function pluginClass()
    {
        return {$short}::class;
    }


PHP;
    }

    /**
     * The one thing the shared harness cannot know: what this package is supposed to be.
     *
     * @param \MyAdmin\Plugins\Testing\Scaffold\PluginFacts $facts
     * @param string                                        $short
     * @return string
     */
    private function identityPin(PluginFacts $facts, $short)
    {
        $type = (string)$facts->type();
        $out = <<<PHP
    /**
     * Pins this plugin's identity and the shape of its hook table.
     *
     * @return void
     */
    public function testRegistersItsIdentityAndHooks(): void
    {
        // Prime FIRST, before anything touches the plugin class. A static property
        // initializer may itself reference a bare constant -- \$settings holding
        // REPEAT_BILLING_METHOD => PRORATE_BILLING is the common shape -- and that is
        // evaluated when the class loads, so even reading ::\$type fatals on an unprimed
        // class. Priming before the first mention is what keeps this pin readable.
        \$this->primeConstants();

        \$this->assertSame(
            '{$type}',
            {$short}::\$type,
            'changing \$type silently changes which contract assertions apply'
        );

PHP;

        if ($facts->module() !== null) {
            $module = (string)$facts->module();
            $out .= <<<PHP

        \$this->assertSame(
            '{$module}',
            {$short}::\$module,
            'changing \$module detaches this plugin from the {$module} events it handles'
        );

PHP;
        }

        $out .= <<<PHP

        // Read the table the way every inspector reads it. Calling getHooks() directly here
        // would be a second, independent answer to "can this plugin's hook table be
        // evaluated?" -- and a plugin whose getHooks() body references a bare constant
        // (PRORATE_BILLING and friends) throws for a direct caller while the inspectors
        // handle it. A-5 owns that question; this pin consumes its answer.
        \$hooks = TierA5HooksAreIdempotent::hookTable(\$this->contractSubject());

        \$this->assertNotNull(
            \$hooks,
            'getHooks() could not be evaluated at all -- assertion A-5 reports the root cause'
        );

PHP;

        return $out.$this->hookAssertion($facts->hookKeys());
    }

    /**
     * Pins the hook table's shape, and that every handler in it still resolves.
     *
     * An empty table is pinned as deliberately as a populated one: a plugin that registers
     * nothing today should fail this pin the day it starts registering something, because
     * that is a change to what core dispatches on its behalf.
     *
     * @param string[] $keys
     * @return string
     */
    private function hookAssertion(array $keys)
    {
        if ($keys === []) {
            return <<<PHP

        \$this->assertSame(
            [],
            array_keys(\$hooks),
            'this plugin is currently expected to register no hooks at all'
        );

PHP;
        }

        $list = '';
        foreach ($keys as $key) {
            $list .= "                '".$key."',\n";
        }

        return <<<PHP

        \$this->assertSame(
            [
{$list}            ],
            array_keys(\$hooks),
            'the hook table changed shape -- a key was added, removed or renamed'
        );

        foreach (\$hooks as \$key => \$handler) {
            \$this->assertIsCallable(\$handler, \$key.' no longer resolves to anything callable');
        }

PHP;
    }
}
