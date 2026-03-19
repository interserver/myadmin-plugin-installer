<?php

namespace Tests\MyAdmin\Plugins;

use MyAdmin\Plugins\TemplateInstallerPlugin;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Test suite for the TemplateInstallerPlugin class.
 *
 * Tests class structure, interface implementation, and event subscriptions.
 *
 * @covers \MyAdmin\Plugins\TemplateInstallerPlugin
 */
class TemplateInstallerPluginTest extends TestCase
{
    /**
     * Test that TemplateInstallerPlugin implements PluginInterface.
     */
    public function testImplementsPluginInterface(): void
    {
        $ref = new ReflectionClass(TemplateInstallerPlugin::class);
        $this->assertTrue($ref->implementsInterface(\Composer\Plugin\PluginInterface::class));
    }

    /**
     * Test that TemplateInstallerPlugin implements EventSubscriberInterface.
     */
    public function testImplementsEventSubscriberInterface(): void
    {
        $ref = new ReflectionClass(TemplateInstallerPlugin::class);
        $this->assertTrue($ref->implementsInterface(\Composer\EventDispatcher\EventSubscriberInterface::class));
    }

    /**
     * Test that getSubscribedEvents returns an array.
     */
    public function testGetSubscribedEventsReturnsArray(): void
    {
        $events = TemplateInstallerPlugin::getSubscribedEvents();
        $this->assertIsArray($events);
    }

    /**
     * Test that getSubscribedEvents includes PRE_FILE_DOWNLOAD event.
     */
    public function testGetSubscribedEventsIncludesPreFileDownload(): void
    {
        $events = TemplateInstallerPlugin::getSubscribedEvents();
        $this->assertArrayHasKey(\Composer\Plugin\PluginEvents::PRE_FILE_DOWNLOAD, $events);
    }

    /**
     * Test that PRE_FILE_DOWNLOAD event maps to onPreFileDownload handler.
     */
    public function testPreFileDownloadMapsToHandler(): void
    {
        $events = TemplateInstallerPlugin::getSubscribedEvents();
        $handlers = $events[\Composer\Plugin\PluginEvents::PRE_FILE_DOWNLOAD];

        $this->assertIsArray($handlers);
        $this->assertCount(1, $handlers);
        $this->assertSame('onPreFileDownload', $handlers[0][0]);
        $this->assertSame(0, $handlers[0][1]);
    }

    /**
     * Test that activate method exists and is public.
     */
    public function testActivateMethodIsPublic(): void
    {
        $ref = new ReflectionClass(TemplateInstallerPlugin::class);
        $method = $ref->getMethod('activate');
        $this->assertTrue($method->isPublic());
    }

    /**
     * Test that deactivate method exists and is public.
     */
    public function testDeactivateMethodIsPublic(): void
    {
        $ref = new ReflectionClass(TemplateInstallerPlugin::class);
        $method = $ref->getMethod('deactivate');
        $this->assertTrue($method->isPublic());
    }

    /**
     * Test that uninstall method exists and is public.
     */
    public function testUninstallMethodIsPublic(): void
    {
        $ref = new ReflectionClass(TemplateInstallerPlugin::class);
        $method = $ref->getMethod('uninstall');
        $this->assertTrue($method->isPublic());
    }

    /**
     * Test that onPreFileDownload method exists and is public.
     */
    public function testOnPreFileDownloadMethodIsPublic(): void
    {
        $ref = new ReflectionClass(TemplateInstallerPlugin::class);
        $method = $ref->getMethod('onPreFileDownload');
        $this->assertTrue($method->isPublic());
    }

    /**
     * Test that the class has expected protected properties.
     */
    public function testHasExpectedProperties(): void
    {
        $ref = new ReflectionClass(TemplateInstallerPlugin::class);

        $composerProp = $ref->getProperty('composer');
        $this->assertTrue($composerProp->isProtected());

        $ioProp = $ref->getProperty('io');
        $this->assertTrue($ioProp->isProtected());
    }
}
