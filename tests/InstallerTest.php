<?php

namespace Tests\MyAdmin\Plugins;

use MyAdmin\Plugins\Installer;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Test suite for the Installer class.
 *
 * Tests class structure, supported package types, and install path logic
 * using reflection and anonymous subclasses (no createMock on vendor classes).
 *
 * @covers \MyAdmin\Plugins\Installer
 */
class InstallerTest extends TestCase
{
    /**
     * Test that Installer extends LibraryInstaller.
     */
    public function testExtendsLibraryInstaller(): void
    {
        $ref = new ReflectionClass(Installer::class);
        $this->assertSame('Composer\Installer\LibraryInstaller', $ref->getParentClass()->getName());
    }

    /**
     * Test that Installer has a protected templateDir property.
     */
    public function testHasTemplateDirProperty(): void
    {
        $ref = new ReflectionClass(Installer::class);
        $prop = $ref->getProperty('templateDir');
        $this->assertTrue($prop->isProtected());
    }

    /**
     * Test that supports method exists and is public.
     */
    public function testSupportsMethodIsPublic(): void
    {
        $ref = new ReflectionClass(Installer::class);
        $method = $ref->getMethod('supports');
        $this->assertTrue($method->isPublic());
    }

    /**
     * Test supports returns true for myadmin-template type.
     *
     * Uses an anonymous class to bypass constructor dependencies.
     */
    public function testSupportsMyadminTemplate(): void
    {
        $installer = $this->createInstallerStub();
        $this->assertTrue($installer->supports('myadmin-template'));
    }

    /**
     * Test supports returns true for myadmin-module type.
     */
    public function testSupportsMyadminModule(): void
    {
        $installer = $this->createInstallerStub();
        $this->assertTrue($installer->supports('myadmin-module'));
    }

    /**
     * Test supports returns true for myadmin-plugin type.
     */
    public function testSupportsMyadminPlugin(): void
    {
        $installer = $this->createInstallerStub();
        $this->assertTrue($installer->supports('myadmin-plugin'));
    }

    /**
     * Test supports returns true for myadmin-menu type.
     */
    public function testSupportsMyadminMenu(): void
    {
        $installer = $this->createInstallerStub();
        $this->assertTrue($installer->supports('myadmin-menu'));
    }

    /**
     * Test supports returns false for standard library type.
     */
    public function testDoesNotSupportLibrary(): void
    {
        $installer = $this->createInstallerStub();
        $this->assertFalse($installer->supports('library'));
    }

    /**
     * Test supports returns false for arbitrary types.
     */
    public function testDoesNotSupportArbitraryType(): void
    {
        $installer = $this->createInstallerStub();
        $this->assertFalse($installer->supports('some-random-type'));
    }

    /**
     * Test supports returns false for empty string type.
     */
    public function testDoesNotSupportEmptyString(): void
    {
        $installer = $this->createInstallerStub();
        $this->assertFalse($installer->supports(''));
    }

    /**
     * Test that install method exists and is public.
     */
    public function testInstallMethodIsPublic(): void
    {
        $ref = new ReflectionClass(Installer::class);
        $method = $ref->getMethod('install');
        $this->assertTrue($method->isPublic());
    }

    /**
     * Test that update method exists and is public.
     */
    public function testUpdateMethodIsPublic(): void
    {
        $ref = new ReflectionClass(Installer::class);
        $method = $ref->getMethod('update');
        $this->assertTrue($method->isPublic());
    }

    /**
     * Test that uninstall method exists and is public.
     */
    public function testUninstallMethodIsPublic(): void
    {
        $ref = new ReflectionClass(Installer::class);
        $method = $ref->getMethod('uninstall');
        $this->assertTrue($method->isPublic());
    }

    /**
     * Test that getInstallPath method exists and is public.
     */
    public function testGetInstallPathMethodIsPublic(): void
    {
        $ref = new ReflectionClass(Installer::class);
        $method = $ref->getMethod('getInstallPath');
        $this->assertTrue($method->isPublic());
    }

    /**
     * Test that ensureBinariesPresence method exists and is public.
     */
    public function testEnsureBinariesPresenceMethodIsPublic(): void
    {
        $ref = new ReflectionClass(Installer::class);
        $method = $ref->getMethod('ensureBinariesPresence');
        $this->assertTrue($method->isPublic());
    }

    /**
     * Test that getPackageBasePath method exists and is protected.
     */
    public function testGetPackageBasePathIsProtected(): void
    {
        $ref = new ReflectionClass(Installer::class);
        $method = $ref->getMethod('getPackageBasePath');
        $this->assertTrue($method->isProtected());
    }

    /**
     * Test that installCode method exists and is protected.
     */
    public function testInstallCodeIsProtected(): void
    {
        $ref = new ReflectionClass(Installer::class);
        $method = $ref->getMethod('installCode');
        $this->assertTrue($method->isProtected());
    }

    /**
     * Test that updateCode method exists and is protected.
     */
    public function testUpdateCodeIsProtected(): void
    {
        $ref = new ReflectionClass(Installer::class);
        $method = $ref->getMethod('updateCode');
        $this->assertTrue($method->isProtected());
    }

    /**
     * Test that removeCode method exists and is protected.
     */
    public function testRemoveCodeIsProtected(): void
    {
        $ref = new ReflectionClass(Installer::class);
        $method = $ref->getMethod('removeCode');
        $this->assertTrue($method->isProtected());
    }

    /**
     * Test that initializeVendorDir method exists and is protected.
     */
    public function testInitializeVendorDirIsProtected(): void
    {
        $ref = new ReflectionClass(Installer::class);
        $method = $ref->getMethod('initializeVendorDir');
        $this->assertTrue($method->isProtected());
    }

    /**
     * Test that initializeTemplateDir method exists and is protected.
     */
    public function testInitializeTemplateDirIsProtected(): void
    {
        $ref = new ReflectionClass(Installer::class);
        $method = $ref->getMethod('initializeTemplateDir');
        $this->assertTrue($method->isProtected());
    }

    /**
     * Test that constructor expects specific parameter count.
     */
    public function testConstructorParameterCount(): void
    {
        $ref = new ReflectionClass(Installer::class);
        $constructor = $ref->getConstructor();
        $this->assertCount(5, $constructor->getParameters());
    }

    /**
     * Test that the 4 supported types are exactly as expected.
     */
    public function testAllSupportedTypes(): void
    {
        $installer = $this->createInstallerStub();
        $expected = ['myadmin-template', 'myadmin-module', 'myadmin-plugin', 'myadmin-menu'];

        foreach ($expected as $type) {
            $this->assertTrue($installer->supports($type), "Should support: {$type}");
        }
    }

    /**
     * Creates an Installer stub that bypasses the constructor.
     *
     * @return Installer
     */
    private function createInstallerStub(): Installer
    {
        $ref = new ReflectionClass(Installer::class);
        /** @var Installer $installer */
        $installer = $ref->newInstanceWithoutConstructor();
        return $installer;
    }
}
