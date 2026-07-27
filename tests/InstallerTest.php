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
     * The template-routing feature is gone. It branched on $this->type — the installer's own
     * constructor label, always 'library' because nothing ever passed one — rather than on
     * the package's type, so it could never fire in any configuration.
     */
    public function testTemplateRoutingSurfaceIsGone(): void
    {
        $ref = new ReflectionClass(Installer::class);
        $this->assertFalse($ref->hasProperty('templateDir'));
        $this->assertFalse($ref->hasMethod('initializeTemplateDir'));
    }

    /**
     * Every InstallerInterface method must be overridden on this class with a signature
     * PHP accepts. Loading the class at all proves signature compatibility; this pins that
     * none of them silently disappear.
     */
    public function testOverridesEveryInstallerInterfaceMethod(): void
    {
        $ref = new ReflectionClass(Installer::class);
        $interface = new ReflectionClass(\Composer\Installer\InstallerInterface::class);
        foreach ($interface->getMethods() as $method) {
            $name = $method->getName();
            $this->assertTrue($ref->hasMethod($name), $name.'() is missing');
            $this->assertSame(
                Installer::class,
                $ref->getMethod($name)->getDeclaringClass()->getName(),
                $name.'() must be declared locally, not merely inherited'
            );
        }
    }

    /**
     * Composer 2 added download(), prepare() and cleanup() to InstallerInterface. The
     * previous revision predated them and implemented only the Composer 1 method set.
     */
    public function testHasComposerTwoAsyncMethods(): void
    {
        $ref = new ReflectionClass(Installer::class);
        foreach (['download', 'prepare', 'cleanup'] as $method) {
            $this->assertTrue($ref->hasMethod($method), $method.'() is required by Composer 2');
        }
    }

    /**
     * prepare() and cleanup() must NOT type-hint $type as string. InstallerInterface declares
     * the hint but LibraryInstaller does not, and this class extends LibraryInstaller — adding
     * it would be a signature-compatibility fatal at class-load time.
     */
    public function testPrepareAndCleanupLeaveTypeParameterUnhinted(): void
    {
        $ref = new ReflectionClass(Installer::class);
        foreach (['prepare', 'cleanup'] as $name) {
            $param = $ref->getMethod($name)->getParameters()[0];
            $this->assertSame('type', $param->getName());
            $this->assertFalse($param->hasType(), $name.'($type) must stay unhinted to match LibraryInstaller');
        }
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
     * supports() claims exactly the MyAdmin package types and nothing else. This list is
     * consulted ahead of Composer's catch-all LibraryInstaller because addInstaller()
     * prepends, so widening it silently takes packages away from default handling.
     */
    public function testSupportsClaimsOnlyMyAdminTypes(): void
    {
        $installer = $this->createInstallerStub();
        foreach (Installer::MYADMIN_PACKAGE_TYPES as $type) {
            $this->assertTrue($installer->supports($type), $type.' should be claimed');
        }
        foreach (['library', 'composer-plugin', 'metapackage', 'project', ''] as $type) {
            $this->assertFalse($installer->supports($type), $type.' must not be claimed');
        }
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
