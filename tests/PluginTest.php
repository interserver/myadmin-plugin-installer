<?php

namespace Tests\MyAdmin\Plugins;

use MyAdmin\Plugins\Plugin;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Test suite for the Plugin class.
 *
 * Tests class structure, interface implementations, event subscriptions,
 * capabilities, and static helper methods.
 *
 * @covers \MyAdmin\Plugins\Plugin
 */
class PluginTest extends TestCase
{
    /**
     * Test that Plugin implements PluginInterface.
     */
    public function testImplementsPluginInterface(): void
    {
        $ref = new ReflectionClass(Plugin::class);
        $this->assertTrue($ref->implementsInterface(\Composer\Plugin\PluginInterface::class));
    }

    /**
     * Test that Plugin implements EventSubscriberInterface.
     */
    public function testImplementsEventSubscriberInterface(): void
    {
        $ref = new ReflectionClass(Plugin::class);
        $this->assertTrue($ref->implementsInterface(\Composer\EventDispatcher\EventSubscriberInterface::class));
    }

    /**
     * Test that Plugin implements Capable interface.
     */
    public function testImplementsCapable(): void
    {
        $ref = new ReflectionClass(Plugin::class);
        $this->assertTrue($ref->implementsInterface(\Composer\Plugin\Capable::class));
    }

    /**
     * Test that getSubscribedEvents returns an array.
     */
    public function testGetSubscribedEventsReturnsArray(): void
    {
        $events = Plugin::getSubscribedEvents();
        $this->assertIsArray($events);
    }

    /**
     * Test that getSubscribedEvents currently returns empty array (all commented out).
     */
    public function testGetSubscribedEventsIsCurrentlyEmpty(): void
    {
        $events = Plugin::getSubscribedEvents();
        $this->assertEmpty($events);
    }

    /**
     * Test that getCapabilities returns the CommandProvider mapping.
     */
    public function testGetCapabilitiesReturnsCommandProvider(): void
    {
        $plugin = new Plugin();
        $capabilities = $plugin->getCapabilities();

        $this->assertIsArray($capabilities);
        $this->assertArrayHasKey('Composer\Plugin\Capability\CommandProvider', $capabilities);
        $this->assertSame('MyAdmin\Plugins\CommandProvider', $capabilities['Composer\Plugin\Capability\CommandProvider']);
    }

    /**
     * Test that activate method exists and is public.
     */
    public function testActivateMethodExists(): void
    {
        $ref = new ReflectionClass(Plugin::class);
        $method = $ref->getMethod('activate');
        $this->assertTrue($method->isPublic());
    }

    /**
     * Test that deactivate method exists and is public.
     */
    public function testDeactivateMethodExists(): void
    {
        $ref = new ReflectionClass(Plugin::class);
        $method = $ref->getMethod('deactivate');
        $this->assertTrue($method->isPublic());
    }

    /**
     * Test that uninstall method exists and is public.
     */
    public function testUninstallMethodExists(): void
    {
        $ref = new ReflectionClass(Plugin::class);
        $method = $ref->getMethod('uninstall');
        $this->assertTrue($method->isPublic());
    }

    /**
     * Test that setPermissions is a public static method.
     */
    public function testSetPermissionsIsPublicStatic(): void
    {
        $ref = new ReflectionClass(Plugin::class);
        $method = $ref->getMethod('setPermissions');
        $this->assertTrue($method->isPublic());
        $this->assertTrue($method->isStatic());
    }

    /**
     * Test that getWritableDirs is a public static method.
     */
    public function testGetWritableDirsIsPublicStatic(): void
    {
        $ref = new ReflectionClass(Plugin::class);
        $method = $ref->getMethod('getWritableDirs');
        $this->assertTrue($method->isPublic());
        $this->assertTrue($method->isStatic());
    }

    /**
     * Test that getWritableFiles is a public static method.
     */
    public function testGetWritableFilesIsPublicStatic(): void
    {
        $ref = new ReflectionClass(Plugin::class);
        $method = $ref->getMethod('getWritableFiles');
        $this->assertTrue($method->isPublic());
        $this->assertTrue($method->isStatic());
    }

    /**
     * Test that getHttpdUser is a public static method.
     */
    public function testGetHttpdUserIsPublicStatic(): void
    {
        $ref = new ReflectionClass(Plugin::class);
        $method = $ref->getMethod('getHttpdUser');
        $this->assertTrue($method->isPublic());
        $this->assertTrue($method->isStatic());
    }

    /**
     * Test that runProcess is a public static method.
     */
    public function testRunProcessIsPublicStatic(): void
    {
        $ref = new ReflectionClass(Plugin::class);
        $method = $ref->getMethod('runProcess');
        $this->assertTrue($method->isPublic());
        $this->assertTrue($method->isStatic());
    }

    /**
     * Test that onPreFileDownload method exists and is public.
     */
    public function testOnPreFileDownloadMethodExists(): void
    {
        $ref = new ReflectionClass(Plugin::class);
        $method = $ref->getMethod('onPreFileDownload');
        $this->assertTrue($method->isPublic());
    }

    /**
     * Test that the class has the expected protected properties.
     */
    public function testHasExpectedProperties(): void
    {
        $ref = new ReflectionClass(Plugin::class);

        $composerProp = $ref->getProperty('composer');
        $this->assertTrue($composerProp->isProtected());

        $ioProp = $ref->getProperty('io');
        $this->assertTrue($ioProp->isProtected());
    }

    /**
     * Test that EnsureDirExists is a public static method.
     */
    public function testEnsureDirExistsIsPublicStatic(): void
    {
        $ref = new ReflectionClass(Plugin::class);
        $method = $ref->getMethod('EnsureDirExists');
        $this->assertTrue($method->isPublic());
        $this->assertTrue($method->isStatic());
    }

    /**
     * Test that EnsureFileExists is a public static method.
     */
    public function testEnsureFileExistsIsPublicStatic(): void
    {
        $ref = new ReflectionClass(Plugin::class);
        $method = $ref->getMethod('EnsureFileExists');
        $this->assertTrue($method->isPublic());
        $this->assertTrue($method->isStatic());
    }

    /**
     * Test that SetfaclPermissionsSetter is a public static method.
     */
    public function testSetfaclPermissionsSetterIsPublicStatic(): void
    {
        $ref = new ReflectionClass(Plugin::class);
        $method = $ref->getMethod('SetfaclPermissionsSetter');
        $this->assertTrue($method->isPublic());
        $this->assertTrue($method->isStatic());
    }

    /**
     * Test that ChmodPermissionsSetter is a public static method.
     */
    public function testChmodPermissionsSetterIsPublicStatic(): void
    {
        $ref = new ReflectionClass(Plugin::class);
        $method = $ref->getMethod('ChmodPermissionsSetter');
        $this->assertTrue($method->isPublic());
        $this->assertTrue($method->isStatic());
    }

    /**
     * Test that activate method signature accepts Composer and IOInterface.
     */
    public function testActivateMethodSignature(): void
    {
        $ref = new ReflectionClass(Plugin::class);
        $method = $ref->getMethod('activate');
        $params = $method->getParameters();

        $this->assertCount(2, $params);
        $this->assertSame('composer', $params[0]->getName());
        $this->assertSame('io', $params[1]->getName());
    }
}
