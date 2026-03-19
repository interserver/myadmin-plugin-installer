<?php

namespace Tests\MyAdmin\Plugins;

use PHPUnit\Framework\TestCase;

/**
 * Test suite verifying all expected source files exist in the package.
 *
 * Static analysis to ensure no files are missing from the distribution.
 */
class FileExistenceTest extends TestCase
{
    /**
     * @var string
     */
    private static $srcDir;

    public static function setUpBeforeClass(): void
    {
        self::$srcDir = dirname(__DIR__) . '/src';
    }

    /**
     * Test that Plugin.php exists.
     */
    public function testPluginFileExists(): void
    {
        $this->assertFileExists(self::$srcDir . '/Plugin.php');
    }

    /**
     * Test that Installer.php exists.
     */
    public function testInstallerFileExists(): void
    {
        $this->assertFileExists(self::$srcDir . '/Installer.php');
    }

    /**
     * Test that InstallerPlugin.php exists.
     */
    public function testInstallerPluginFileExists(): void
    {
        $this->assertFileExists(self::$srcDir . '/InstallerPlugin.php');
    }

    /**
     * Test that Loader.php exists.
     */
    public function testLoaderFileExists(): void
    {
        $this->assertFileExists(self::$srcDir . '/Loader.php');
    }

    /**
     * Test that TemplateInstaller.php exists.
     */
    public function testTemplateInstallerFileExists(): void
    {
        $this->assertFileExists(self::$srcDir . '/TemplateInstaller.php');
    }

    /**
     * Test that TemplateInstallerPlugin.php exists.
     */
    public function testTemplateInstallerPluginFileExists(): void
    {
        $this->assertFileExists(self::$srcDir . '/TemplateInstallerPlugin.php');
    }

    /**
     * Test that CommandProvider.php exists.
     */
    public function testCommandProviderFileExists(): void
    {
        $this->assertFileExists(self::$srcDir . '/CommandProvider.php');
    }

    /**
     * Test that function_requirements.php exists.
     */
    public function testFunctionRequirementsFileExists(): void
    {
        $this->assertFileExists(self::$srcDir . '/function_requirements.php');
    }

    /**
     * Test that modules.php exists.
     */
    public function testModulesFileExists(): void
    {
        $this->assertFileExists(self::$srcDir . '/modules.php');
    }

    /**
     * Test that Command/Command.php exists.
     */
    public function testCommandCommandFileExists(): void
    {
        $this->assertFileExists(self::$srcDir . '/Command/Command.php');
    }

    /**
     * Test that Command/Parse.php exists.
     */
    public function testCommandParseFileExists(): void
    {
        $this->assertFileExists(self::$srcDir . '/Command/Parse.php');
    }

    /**
     * Test that Command/CreateUser.php exists.
     */
    public function testCommandCreateUserFileExists(): void
    {
        $this->assertFileExists(self::$srcDir . '/Command/CreateUser.php');
    }

    /**
     * Test that Command/SetPermissions.php exists.
     */
    public function testCommandSetPermissionsFileExists(): void
    {
        $this->assertFileExists(self::$srcDir . '/Command/SetPermissions.php');
    }

    /**
     * Test that Command/UpdatePlugins.php exists.
     */
    public function testCommandUpdatePluginsFileExists(): void
    {
        $this->assertFileExists(self::$srcDir . '/Command/UpdatePlugins.php');
    }

    /**
     * Test that composer.json exists.
     */
    public function testComposerJsonExists(): void
    {
        $this->assertFileExists(dirname(__DIR__) . '/composer.json');
    }
}
