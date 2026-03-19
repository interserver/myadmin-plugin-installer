<?php

namespace Tests\MyAdmin\Plugins;

use PHPUnit\Framework\TestCase;

/**
 * Test suite for the modules.php global functions.
 *
 * Tests register_module, get_module_name, get_module_settings,
 * get_valid_module, and has_module_db functions.
 *
 * @covers ::register_module
 * @covers ::get_module_name
 * @covers ::get_module_settings
 * @covers ::get_valid_module
 * @covers ::has_module_db
 */
class ModulesTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['modules'] = [];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['modules']);
        foreach (array_keys($GLOBALS) as $key) {
            if (str_ends_with($key, '_dbh')) {
                unset($GLOBALS[$key]);
            }
        }
    }

    /**
     * Test that register_module adds a module with default settings.
     */
    public function testRegisterModuleWithDefaultSettings(): void
    {
        register_module('hosting');

        $this->assertArrayHasKey('hosting', $GLOBALS['modules']);
        $this->assertSame([], $GLOBALS['modules']['hosting']);
    }

    /**
     * Test that register_module adds a module with custom settings.
     */
    public function testRegisterModuleWithCustomSettings(): void
    {
        $settings = ['db' => 'hosting_db', 'prefix' => 'host_'];
        register_module('hosting', $settings);

        $this->assertSame($settings, $GLOBALS['modules']['hosting']);
    }

    /**
     * Test that register_module initializes $GLOBALS['modules'] if not set.
     */
    public function testRegisterModuleInitializesGlobal(): void
    {
        unset($GLOBALS['modules']);
        register_module('vps');

        $this->assertArrayHasKey('vps', $GLOBALS['modules']);
    }

    /**
     * Test that register_module with false settings defaults to empty array.
     */
    public function testRegisterModuleWithFalseSettings(): void
    {
        register_module('dns', false);

        $this->assertSame([], $GLOBALS['modules']['dns']);
    }

    /**
     * Test get_module_name returns the module name when it exists in modules.
     */
    public function testGetModuleNameReturnsExistingModule(): void
    {
        $GLOBALS['modules']['hosting'] = [];

        $this->assertSame('hosting', get_module_name('hosting'));
    }

    /**
     * Test get_module_name returns first non-default key when module is 'default'.
     */
    public function testGetModuleNameReturnsFirstNonDefaultKey(): void
    {
        $GLOBALS['modules']['hosting'] = [];
        $GLOBALS['modules']['vps'] = [];

        $this->assertSame('hosting', get_module_name('default'));
    }

    /**
     * Test get_module_name returns 'default' when only 'default' key exists.
     */
    public function testGetModuleNameReturnsDefaultWhenOnlyDefault(): void
    {
        $GLOBALS['modules']['default'] = [];

        $this->assertSame('default', get_module_name('default'));
    }

    /**
     * Test get_module_name returns name if module has a dbh global.
     */
    public function testGetModuleNameReturnsNameWhenDbhExists(): void
    {
        $GLOBALS['custom_dbh'] = new \stdClass();

        $this->assertSame('custom', get_module_name('custom'));

        unset($GLOBALS['custom_dbh']);
    }

    /**
     * Test get_module_settings returns all settings for a module.
     */
    public function testGetModuleSettingsReturnsAllSettings(): void
    {
        $settings = ['db' => 'mydb', 'prefix' => 'pre_'];
        $GLOBALS['modules']['hosting'] = $settings;

        $this->assertSame($settings, get_module_settings('hosting'));
    }

    /**
     * Test get_module_settings returns a specific setting.
     */
    public function testGetModuleSettingsReturnsSpecificSetting(): void
    {
        $GLOBALS['modules']['hosting'] = ['db' => 'mydb', 'prefix' => 'pre_'];

        $this->assertSame('mydb', get_module_settings('hosting', 'db'));
    }

    /**
     * Test get_module_settings returns false for missing setting.
     */
    public function testGetModuleSettingsReturnsFalseForMissingSetting(): void
    {
        $GLOBALS['modules']['hosting'] = ['db' => 'mydb'];

        $this->assertFalse(get_module_settings('hosting', 'nonexistent'));
    }

    /**
     * Test get_module_settings falls back to first module when given module does not exist.
     */
    public function testGetModuleSettingsFallsBackToFirstModule(): void
    {
        $GLOBALS['modules']['hosting'] = ['db' => 'hostdb'];

        $result = get_module_settings('nonexistent');
        $this->assertSame(['db' => 'hostdb'], $result);
    }

    /**
     * Test get_valid_module returns module name when it exists.
     */
    public function testGetValidModuleReturnsModuleWhenExists(): void
    {
        $GLOBALS['modules']['hosting'] = [];

        $this->assertSame('hosting', get_valid_module('hosting'));
    }

    /**
     * Test get_valid_module returns 'default' when module does not exist.
     */
    public function testGetValidModuleReturnsDefaultWhenNotExists(): void
    {
        $GLOBALS['modules']['hosting'] = [];

        $this->assertSame('default', get_valid_module('nonexistent'));
    }

    /**
     * Test get_valid_module returns 'default' when called with default.
     */
    public function testGetValidModuleWithDefault(): void
    {
        $this->assertSame('default', get_valid_module('default'));
    }

    /**
     * Test has_module_db returns true when dbh global exists.
     */
    public function testHasModuleDbReturnsTrueWhenDbhExists(): void
    {
        $GLOBALS['hosting_dbh'] = new \stdClass();

        $this->assertTrue(has_module_db('hosting'));

        unset($GLOBALS['hosting_dbh']);
    }

    /**
     * Test has_module_db returns false when dbh global does not exist.
     */
    public function testHasModuleDbReturnsFalseWhenDbhNotExists(): void
    {
        $this->assertFalse(has_module_db('hosting'));
    }
}
