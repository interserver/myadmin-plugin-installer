<?php

namespace Tests\MyAdmin\Plugins;

use MyAdmin\Plugins\InstallerPlugin;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Test suite for the InstallerPlugin class.
 *
 * Tests class structure and interface implementation.
 *
 * @covers \MyAdmin\Plugins\InstallerPlugin
 */
class InstallerPluginTest extends TestCase
{
    /**
     * Test that InstallerPlugin implements PluginInterface.
     */
    public function testImplementsPluginInterface(): void
    {
        $ref = new ReflectionClass(InstallerPlugin::class);
        $this->assertTrue($ref->implementsInterface(\Composer\Plugin\PluginInterface::class));
    }

    /**
     * Test that activate method exists and is public.
     */
    public function testActivateMethodIsPublic(): void
    {
        $ref = new ReflectionClass(InstallerPlugin::class);
        $method = $ref->getMethod('activate');
        $this->assertTrue($method->isPublic());
    }

    /**
     * Test that activate method signature accepts Composer and IOInterface.
     */
    public function testActivateMethodSignature(): void
    {
        $ref = new ReflectionClass(InstallerPlugin::class);
        $method = $ref->getMethod('activate');
        $params = $method->getParameters();

        $this->assertCount(2, $params);
        $this->assertSame('composer', $params[0]->getName());
        $this->assertSame('io', $params[1]->getName());
    }

    /**
     * Test that the class does not implement EventSubscriberInterface (unlike Plugin).
     */
    public function testDoesNotImplementEventSubscriber(): void
    {
        $ref = new ReflectionClass(InstallerPlugin::class);
        $this->assertFalse($ref->implementsInterface(\Composer\EventDispatcher\EventSubscriberInterface::class));
    }

    /**
     * Test that the class does not implement Capable (unlike Plugin).
     */
    public function testDoesNotImplementCapable(): void
    {
        $ref = new ReflectionClass(InstallerPlugin::class);
        $this->assertFalse($ref->implementsInterface(\Composer\Plugin\Capable::class));
    }

    /**
     * Test that InstallerPlugin has deactivate method for Composer 2 compatibility.
     */
    public function testHasDeactivateMethod(): void
    {
        $ref = new ReflectionClass(InstallerPlugin::class);
        $this->assertTrue($ref->hasMethod('deactivate'));
        $this->assertTrue($ref->getMethod('deactivate')->isPublic());
    }

    /**
     * Test that InstallerPlugin has uninstall method for Composer 2 compatibility.
     */
    public function testHasUninstallMethod(): void
    {
        $ref = new ReflectionClass(InstallerPlugin::class);
        $this->assertTrue($ref->hasMethod('uninstall'));
        $this->assertTrue($ref->getMethod('uninstall')->isPublic());
    }
}
