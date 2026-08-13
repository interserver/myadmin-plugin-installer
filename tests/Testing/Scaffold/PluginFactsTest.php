<?php

namespace Tests\MyAdmin\Plugins\Testing\Scaffold;

use MyAdmin\Plugins\Testing\Scaffold\PluginFacts;
use PHPUnit\Framework\TestCase;

/**
 * @covers \MyAdmin\Plugins\Testing\Scaffold\PluginFacts
 */
class PluginFactsTest extends TestCase
{
    public function testRebuildsFromTheProbePayload(): void
    {
        $facts = PluginFacts::fromJson(json_encode([
            'class' => 'Detain\\MyAdminKsplice\\Plugin',
            'testNamespace' => 'Detain\\MyAdminKsplice\\Tests',
            'name' => 'Ksplice',
            'type' => 'service',
            'module' => 'licenses',
            'hookKeys' => ['licenses.activate'],
            'hookError' => null,
        ]));

        $this->assertSame('Detain\\MyAdminKsplice\\Plugin', $facts->pluginClass());
        $this->assertSame('Plugin', $facts->shortClass());
        $this->assertSame('Detain\\MyAdminKsplice\\Tests', $facts->testNamespace());
        $this->assertSame('Ksplice', $facts->name());
        $this->assertSame('licenses', $facts->module());
        $this->assertSame(['licenses.activate'], $facts->hookKeys());
        $this->assertTrue($facts->isServicePlugin());
    }

    public function testOnlyTypeServiceGetsTheBehaviouralAssertions(): void
    {
        $facts = new PluginFacts('A\\Plugin', 'A\\Tests', 'A', 'plugin', 'licenses', []);

        $this->assertFalse($facts->isServicePlugin());
    }

    /**
     * A trailing separator on the prefix is what composer.json actually contains
     * ("Detain\\MyAdminKsplice\\Tests\\"), and it would emit `namespace X\;`.
     */
    public function testTheTestNamespaceLosesItsTrailingSeparator(): void
    {
        $facts = new PluginFacts('A\\Plugin', 'A\\Tests\\', 'A', 'plugin', null, []);

        $this->assertSame('A\\Tests', $facts->testNamespace());
    }

    /**
     * The probe writes its diagnosis to stderr and dies; stdout is then empty or partial.
     * Failing loudly here is what stops a half-measured package being scaffolded from
     * garbage.
     */
    public function testRefusesAPayloadThatCarriesNoPluginClass(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        PluginFacts::fromJson('');
    }

    /**
     * Without a psr-4 prefix mapped to tests/ there is no namespace the generated class
     * could live in, and the resulting file would not autoload. Better to say so than to
     * emit something that silently never runs.
     */
    public function testRefusesAPackageWithNoTestNamespace(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/autoload-dev/');

        PluginFacts::fromJson(json_encode(['class' => 'A\\Plugin', 'testNamespace' => '']));
    }

    /**
     * A getHooks() that throws is a finding, not a scaffolding failure: assertion A-5 owns
     * it, and the package still needs the test file for that finding to appear in.
     */
    public function testAThrowingHookTableIsCarriedRatherThanFatal(): void
    {
        $facts = PluginFacts::fromJson(json_encode([
            'class' => 'A\\Plugin',
            'testNamespace' => 'A\\Tests',
            'type' => 'plugin',
            'hookKeys' => [],
            'hookError' => 'Error: Undefined constant "PRORATE_BILLING"',
        ]));

        $this->assertSame('Error: Undefined constant "PRORATE_BILLING"', $facts->hookError());
        $this->assertSame([], $facts->hookKeys());
    }
}
