<?php

namespace Tests\MyAdmin\Plugins;

use Composer\Package\PackageInterface;
use Composer\Package\Link;
use MyAdmin\Plugins\TemplateInstaller;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Test suite for the TemplateInstaller class.
 *
 * Tests class structure, supported types, and install path logic.
 *
 * @covers \MyAdmin\Plugins\TemplateInstaller
 */
class TemplateInstallerTest extends TestCase
{
    /**
     * Test that TemplateInstaller extends LibraryInstaller.
     */
    public function testExtendsLibraryInstaller(): void
    {
        $ref = new ReflectionClass(TemplateInstaller::class);
        $this->assertSame('Composer\Installer\LibraryInstaller', $ref->getParentClass()->getName());
    }

    /**
     * Test that supports method returns true for myadmin-template.
     */
    public function testSupportsMyadminTemplate(): void
    {
        $installer = $this->createInstallerStub();
        $this->assertTrue($installer->supports('myadmin-template'));
    }

    /**
     * Test that supports method returns false for library.
     */
    public function testDoesNotSupportLibrary(): void
    {
        $installer = $this->createInstallerStub();
        $this->assertFalse($installer->supports('library'));
    }

    /**
     * Test that supports method returns false for myadmin-plugin.
     */
    public function testDoesNotSupportMyadminPlugin(): void
    {
        $installer = $this->createInstallerStub();
        $this->assertFalse($installer->supports('myadmin-plugin'));
    }

    /**
     * Test that supports method returns false for myadmin-module.
     */
    public function testDoesNotSupportMyadminModule(): void
    {
        $installer = $this->createInstallerStub();
        $this->assertFalse($installer->supports('myadmin-module'));
    }

    /**
     * Test that getInstallPath returns correct path for valid template package.
     */
    public function testGetInstallPathForValidTemplate(): void
    {
        $installer = $this->createInstallerStub();
        $package = $this->createPackageStub('myadmin/template-modern');

        $result = $installer->getInstallPath($package);
        $this->assertSame('data/templates/modern', $result);
    }

    /**
     * Test that getInstallPath throws InvalidArgumentException for invalid prefix.
     */
    public function testGetInstallPathThrowsForInvalidPrefix(): void
    {
        $installer = $this->createInstallerStub();
        $package = $this->createPackageStub('other/some-package');

        $this->expectException(\InvalidArgumentException::class);
        $installer->getInstallPath($package);
    }

    /**
     * Test that getInstallPath correctly strips the 'myadmin/template-' prefix.
     */
    public function testGetInstallPathStripsPrefix(): void
    {
        $installer = $this->createInstallerStub();
        $package = $this->createPackageStub('myadmin/template-classic-blue');

        $result = $installer->getInstallPath($package);
        $this->assertSame('data/templates/classic-blue', $result);
    }

    /**
     * Test that getInstallPath method is public.
     */
    public function testGetInstallPathIsPublic(): void
    {
        $ref = new ReflectionClass(TemplateInstaller::class);
        $method = $ref->getMethod('getInstallPath');
        $this->assertTrue($method->isPublic());
    }

    /**
     * Test that supports method is public.
     */
    public function testSupportsIsPublic(): void
    {
        $ref = new ReflectionClass(TemplateInstaller::class);
        $method = $ref->getMethod('supports');
        $this->assertTrue($method->isPublic());
    }

    /**
     * Creates a PackageInterface stub with the given pretty name.
     *
     * @param string $prettyName
     * @return PackageInterface
     */
    private function createPackageStub(string $prettyName): PackageInterface
    {
        return new class($prettyName) implements PackageInterface {
            private string $prettyName;

            public function __construct(string $prettyName)
            {
                $this->prettyName = $prettyName;
            }

            public function getName(): string { return $this->prettyName; }
            public function getPrettyName(): string { return $this->prettyName; }
            public function getNames($provides = true): array { return [$this->prettyName]; }
            public function setId(int $id): void {}
            public function getId(): int { return 0; }
            public function isDev(): bool { return false; }
            public function getType(): string { return 'myadmin-template'; }
            public function getTargetDir(): ?string { return null; }
            public function getExtra(): array { return []; }
            public function setInstallationSource(?string $type): void {}
            public function getInstallationSource(): ?string { return null; }
            public function getSourceType(): ?string { return null; }
            public function getSourceUrl(): ?string { return null; }
            public function getSourceReference(): ?string { return null; }
            public function getSourceMirrors(): ?array { return null; }
            public function getSourceUrls(): array { return []; }
            public function getDistType(): ?string { return null; }
            public function getDistUrl(): ?string { return null; }
            public function getDistReference(): ?string { return null; }
            public function getDistSha1Checksum(): ?string { return null; }
            public function getDistMirrors(): ?array { return null; }
            public function getDistUrls(): array { return []; }
            public function getVersion(): string { return '1.0.0'; }
            public function getPrettyVersion(): string { return '1.0.0'; }
            public function getFullPrettyVersion(bool $truncate = true, int $displayMode = 0): string { return '1.0.0'; }
            public function getReleaseDate(): ?\DateTimeInterface { return null; }
            public function getStability(): string { return 'stable'; }
            public function getRequires(): array { return []; }
            public function getConflicts(): array { return []; }
            public function getProvides(): array { return []; }
            public function getReplaces(): array { return []; }
            public function getDevRequires(): array { return []; }
            public function getSuggests(): array { return []; }
            public function getAutoload(): array { return []; }
            public function getDevAutoload(): array { return []; }
            public function getIncludePaths(): array { return []; }
            public function setRepository(\Composer\Repository\RepositoryInterface $repository): void {}
            public function getRepository(): ?\Composer\Repository\RepositoryInterface { return null; }
            public function getBinaries(): array { return []; }
            public function getUniqueName(): string { return $this->prettyName . '-1.0.0'; }
            public function getNotificationUrl(): ?string { return null; }
            public function __toString(): string { return $this->prettyName; }
            public function getPrettyString(): string { return $this->prettyName; }
            public function getArchiveName(): string { return $this->prettyName; }
            public function getArchiveExcludes(): array { return []; }
            public function getTransportOptions(): array { return []; }
            public function setSourceReference(?string $reference): void {}
            public function setDistUrl(?string $url): void {}
            public function setDistType(?string $type): void {}
            public function setDistReference(?string $reference): void {}
            public function setSourceDistReferences(string $reference): void {}
            public function setTransportOptions(array $options): void {}
            public function setSourceMirrors(?array $mirrors): void {}
            public function setDistMirrors(?array $mirrors): void {}
            public function isDefaultBranch(): bool { return false; }
            public function getPhpExt(): ?array { return null; }
        };
    }

    /**
     * Creates a TemplateInstaller stub bypassing the constructor.
     *
     * @return TemplateInstaller
     */
    private function createInstallerStub(): TemplateInstaller
    {
        $ref = new ReflectionClass(TemplateInstaller::class);
        /** @var TemplateInstaller $installer */
        $installer = $ref->newInstanceWithoutConstructor();
        return $installer;
    }
}
