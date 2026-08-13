<?php

namespace Tests\MyAdmin\Plugins\Testing\Scaffold;

use MyAdmin\Plugins\Testing\Scaffold\ContractTestGenerator;
use MyAdmin\Plugins\Testing\Scaffold\PluginFacts;
use PHPUnit\Framework\TestCase;

/**
 * Pins the generated contract test against the three mistakes that were actually made.
 *
 * ---------------------------------------------------------------------------------
 * WHY THESE ASSERTIONS AND NOT "IT PRODUCES THE EXPECTED STRING"
 * ---------------------------------------------------------------------------------
 * A golden-file comparison would pass for the wrong reasons: it pins prose, so any wording
 * change breaks it, while a genuinely dangerous reordering that happens to keep the same
 * characters would slip through. The 66 packages already in the fleet were written by two
 * earlier throwaway generators, and the difference between them was exactly three
 * structural properties. Those properties are what these tests assert.
 *
 * @covers \MyAdmin\Plugins\Testing\Scaffold\ContractTestGenerator
 */
class ContractTestGeneratorTest extends TestCase
{
    /**
     * @param string   $type
     * @param string|null $module
     * @param string[] $hookKeys
     * @return string
     */
    private function render($type = 'service', $module = 'licenses', array $hookKeys = ['licenses.activate'])
    {
        $facts = new PluginFacts(
            'Detain\\MyAdminThing\\Plugin',
            'Detain\\MyAdminThing\\Tests',
            'Thing',
            $type,
            $module,
            $hookKeys
        );
        return (new ContractTestGenerator())->render($facts);
    }

    /**
     * The first generation asserted `::$type` and primed afterwards. That is fine until a
     * package has a static property initializer referencing a bare constant, because the
     * initializer runs on class *load* — so the fatal happens before the assertion it was
     * supposed to make. mail-module has exactly that shape.
     */
    public function testPrimesConstantsBeforeTheClassIsMentioned(): void
    {
        $code = $this->render();

        $prime = strpos($code, '$this->primeConstants();');
        $firstMention = strpos($code, 'Plugin::$type');

        $this->assertNotFalse($prime, 'the generated test must prime constants at all');
        $this->assertNotFalse($firstMention);
        $this->assertLessThan(
            $firstMention,
            $prime,
            'primeConstants() must come before the first mention of the plugin class, or a class whose'
                .' static initializer touches a bare constant fatals while being read'
        );
    }

    /**
     * A direct getHooks() call is a second, independent answer to a question assertion A-5
     * already owns, and the two disagree for any plugin whose body touches a bare constant:
     * the inspector handles it, the direct caller throws.
     */
    public function testReadsTheHookTableThroughTheSharedAccessor(): void
    {
        $code = $this->render();

        $this->assertStringContainsString(
            'TierA5HooksAreIdempotent::hookTable($this->contractSubject())',
            $code
        );
        $this->assertStringNotContainsString(
            'Plugin::getHooks()',
            $code,
            'calling getHooks() directly bypasses A-5 and gives a different answer for'
                .' constant-referencing plugins'
        );
    }

    /**
     * The first generation called getHooks() twice — once for the key list, once for the
     * callable loop. That asserts idempotence by accident and doubles any side effect the
     * body has.
     */
    public function testEvaluatesTheHookTableExactlyOnce(): void
    {
        $code = $this->render();

        $this->assertSame(
            1,
            substr_count($code, 'hookTable('),
            'the hook table must be evaluated once and reused'
        );
    }

    public function testAServicePluginExtendsTheBehaviouralCase(): void
    {
        $code = $this->render('service');

        $this->assertStringContainsString('class ContractTest extends ServicePluginTestCase', $code);
        $this->assertStringContainsString('use MyAdmin\\Plugins\\Testing\\ServicePluginTestCase;', $code);
        $this->assertStringContainsString('getActivate()/getDeactivate()/getChangeIp()/getQueue()', $code);
    }

    public function testANonServicePluginExtendsTheContractCaseOnly(): void
    {
        $code = $this->render('plugin');

        $this->assertStringContainsString('class ContractTest extends PluginContractTestCase', $code);
        $this->assertStringNotContainsString('ServicePluginTestCase', $code);
        $this->assertStringNotContainsString('getChangeIp()', $code);
    }

    /**
     * The class must be isolated. Inspecting a plugin defines constants and registers a
     * module, neither of which can be undone, so without this the new file changes the
     * outcome of the tests the package already had — the one thing an additive conversion
     * must not do.
     */
    public function testTheGeneratedClassRunsInItsOwnProcess(): void
    {
        $code = $this->render();

        $this->assertStringContainsString('@runTestsInSeparateProcesses', $code);
        $this->assertStringContainsString('@preserveGlobalState disabled', $code);
    }

    public function testPinsTheMeasuredHookKeysInOrder(): void
    {
        $code = $this->render('service', 'licenses', ['function.requirements', 'licenses.activate']);

        $this->assertStringContainsString("                'function.requirements',\n", $code);
        $this->assertStringContainsString("                'licenses.activate',\n", $code);
        $this->assertLessThan(
            strpos($code, "'licenses.activate',"),
            strpos($code, "'function.requirements',"),
            'the pin records the order core sees, so the order must survive generation'
        );
    }

    /**
     * Registering nothing is a fact worth pinning too: the day the plugin starts
     * registering something, that is a change to what core dispatches on its behalf.
     */
    public function testAnEmptyHookTableIsPinnedAsDeliberate(): void
    {
        $code = $this->render('plugin', 'licenses', []);

        $this->assertStringContainsString('this plugin is currently expected to register no hooks at all', $code);
        $this->assertStringNotContainsString('assertIsCallable', $code);
    }

    public function testOmitsTheModulePinWhenTheClassDeclaresNoModule(): void
    {
        $code = $this->render('plugin', null, []);

        $this->assertStringNotContainsString('::$module', $code);
    }

    public function testPinsTheModuleWhenThereIsOne(): void
    {
        $code = $this->render('service', 'vps', []);

        $this->assertStringContainsString("'vps',\n            Plugin::\$module,", $code);
        $this->assertStringContainsString('detaches this plugin from the vps events it handles', $code);
    }

    /**
     * The generator builds PHP by string concatenation, so "is it even parseable" is a real
     * question and not a formality. Checked with the parser rather than by eye.
     *
     * @dataProvider shapes
     */
    public function testTheOutputIsSyntacticallyValidPhp(string $type, ?string $module, array $keys): void
    {
        $code = $this->render($type, $module, $keys);
        $file = tempnam(sys_get_temp_dir(), 'scaffold').'.php';
        file_put_contents($file, $code);

        $output = [];
        $status = 0;
        exec(escapeshellarg(PHP_BINARY).' -l '.escapeshellarg($file).' 2>&1', $output, $status);
        unlink($file);

        $this->assertSame(0, $status, implode("\n", $output));
    }

    /**
     * @return array<string, array{0: string, 1: ?string, 2: string[]}>
     */
    public function shapes(): array
    {
        return [
            'service with hooks' => ['service', 'licenses', ['licenses.activate', 'licenses.deactivate']],
            'plugin with hooks' => ['plugin', 'licenses', ['system.settings']],
            'no module' => ['plugin', null, ['system.settings']],
            'no hooks' => ['plugin', 'licenses', []],
        ];
    }

    /**
     * The docblock explains why the class is isolated and why priming comes first. It is
     * read by whoever's build just went red, so a generator that interpolated a value into
     * the explanation — "even reading ::service fatals", which is what the second throwaway
     * generator emitted into 25 packages — makes the sentence meaningless.
     */
    public function testTheExplanationIsNotCorruptedByInterpolation(): void
    {
        $code = $this->render('service', 'licenses', []);

        $this->assertStringContainsString('even reading ::$type fatals on an unprimed', $code);
        $this->assertStringNotContainsString('even reading ::service', $code);
    }

    /**
     * The generated file is committed into a repository whose every other file is LF. On a
     * Windows checkout with core.autocrlf the heredocs in the generator inherit CRLF, and
     * the result would be a whole-file diff the first time anyone regenerated it on Linux.
     */
    public function testTheOutputIsLineFeedTerminatedWhateverTheHostDoes(): void
    {
        $this->assertStringNotContainsString("\r", $this->render());
    }
}
