<?php
/**
 * @author Joe Huss <detain@interserver.net>
 * @copyright 2026
 * @package MyAdmin
 * @category Testing
 */

namespace Tests\MyAdmin\Plugins\Testing\Fakes;

use MyAdmin\Plugins\Testing\Fakes\FakeSettings;
use PHPUnit\Framework\TestCase;

/**
 * @covers \MyAdmin\Plugins\Testing\Fakes\FakeSettings
 */
class FakeSettingsTest extends TestCase
{
    /**
     * @return void
     */
    public function testRecordsAddedSettingsInOrder()
    {
        $settings = new FakeSettings();
        $settings->add_text_setting('vps', 'General', 'vps_cost', 'Cost', 'tip', 5);
        $settings->add_password_setting('vps', 'General', 'vps_key', 'Key', 'tip', '');

        $this->assertSame(['vps_cost', 'vps_key'], $settings->settingsAdded());
        $this->assertTrue($settings->hasSetting('vps_cost'));
        $this->assertFalse($settings->hasSetting('nope'));
        $this->assertSame(2, $settings->settingCount());
    }

    /**
     * @return void
     */
    public function testCapturesTheFullDefinitionOfASetting()
    {
        $settings = new FakeSettings();
        $settings->add_dropdown_setting('vps', 'General', 'vps_os', 'OS', 'pick one', 'linux', ['linux', 'bsd'], ['Linux', 'BSD']);

        $setting = $settings->setting('vps_os');
        $this->assertSame('dropdown', $setting['type']);
        $this->assertSame('OS', $setting['label']);
        $this->assertSame('linux', $setting['initial_value']);
        $this->assertSame(['linux', 'bsd'], $setting['values']);
        $this->assertSame(['Linux', 'BSD'], $setting['labels']);
    }

    /**
     * The nested shape must match core's so an assertion written against
     * `get_settings()` means the same thing in both.
     *
     * @return void
     */
    public function testGetSettingsMirrorsCoreStructure()
    {
        $settings = new FakeSettings();
        $settings->add_text_setting('VPS Section', 'General Cat', 'a', 'A', '', 1);

        $all = $settings->get_settings();
        $this->assertArrayHasKey('vps_section', $all);
        $this->assertSame('VPS Section', $all['vps_section']['name']);
        $this->assertArrayHasKey('general_cat', $all['vps_section']['cats']);
        $this->assertSame('General Cat', $all['vps_section']['cats']['general_cat']['name']);
        $this->assertCount(1, $all['vps_section']['cats']['general_cat']['settings']);
    }

    /**
     * Core implements `get_setting()` as `return constant($setting);`, so the
     * fake reads the constant `ConstantStub` defined. That is what makes an
     * assertion on the sentinel an assertion about the real code path.
     *
     * @return void
     */
    public function testGetSettingReadsTheDefinedConstant()
    {
        if (!defined('FAKESETTINGS_PROBE')) {
            define('FAKESETTINGS_PROBE', '__STUB_FAKESETTINGS_PROBE__');
        }
        $settings = new FakeSettings();
        $this->assertSame('__STUB_FAKESETTINGS_PROBE__', $settings->get_setting('FAKESETTINGS_PROBE'));
    }

    /**
     * @return void
     */
    public function testGetSettingOverrideBeatsTheConstant()
    {
        if (!defined('FAKESETTINGS_OVERRIDE_PROBE')) {
            define('FAKESETTINGS_OVERRIDE_PROBE', 'from-constant');
        }
        $settings = new FakeSettings();
        $settings->setSettingValue('FAKESETTINGS_OVERRIDE_PROBE', 'from-test');
        $this->assertSame('from-test', $settings->get_setting('FAKESETTINGS_OVERRIDE_PROBE'));
    }

    /**
     * Core would throw on an undefined constant. The fake returns a sentinel so
     * the handler keeps running and the test sees the whole call sequence
     * rather than only the first missing name.
     *
     * @return void
     */
    public function testGetSettingOfUnknownNameReturnsSentinelRatherThanThrowing()
    {
        $settings = new FakeSettings();
        $this->assertSame('__SETTING_NEVER_DEFINED_XYZ__', $settings->get_setting('NEVER_DEFINED_XYZ'));
    }

    /**
     * @return void
     */
    public function testSettingExistsInBothDirections()
    {
        $settings = new FakeSettings();
        $settings->add_text_setting('vps', 'General', 'known', 'K', '', 1);
        $this->assertTrue($settings->setting_exists('vps', 'general', 'known'));
        $this->assertFalse($settings->setting_exists('vps', 'general', 'unknown'));
    }

    /**
     * @return void
     */
    public function testHandleSectionCategoryReturnsSlugPair()
    {
        $settings = new FakeSettings();
        $this->assertSame(['vps_section', 'general_cat'], $settings->handle_section_category('VPS Section', 'General Cat'));
    }

    /**
     * @return void
     */
    public function testSetTargetIsRecorded()
    {
        $settings = new FakeSettings();
        $settings->setTarget('module');
        $this->assertSame('module', $settings->target());
        $this->assertTrue($settings->wasCalled('setTarget'));
    }

    /**
     * @return void
     */
    public function testMasterFlagPropagatesToSectionAndCategory()
    {
        $settings = new FakeSettings();
        $settings->add_text_setting('vps', 'General', 'a', 'A', '', 1, 'master_field');
        $all = $settings->get_settings();
        $this->assertTrue($all['vps']['master']);
        $this->assertTrue($all['vps']['cats']['general']['master']);
    }

    /**
     * @return void
     */
    public function testResetClearsEverything()
    {
        $settings = new FakeSettings();
        $settings->add_text_setting('vps', 'General', 'a', 'A', '', 1);
        $settings->reset();
        $this->assertSame([], $settings->settingsAdded());
        $this->assertSame([], $settings->calls());
        $this->assertSame('global', $settings->target());
    }

    /**
     * Every add_* variant must land in the same readable place, or a plugin
     * using an uncommon one would look like it registered nothing.
     *
     * @param string           $method
     * @param array<int,mixed> $args
     * @return void
     * @dataProvider adderProvider
     */
    public function testEveryAdderRegistersAReadableSetting($method, array $args)
    {
        $settings = new FakeSettings();
        call_user_func_array([$settings, $method], $args);
        $this->assertNotSame([], $settings->settingsAdded(), $method . '() registered nothing readable');
    }

    /**
     * @return array<string,array<int,mixed>>
     */
    public function adderProvider()
    {
        $basic = ['vps', 'General', 'name', 'Label', 'tip', 'value'];
        $choice = ['vps', 'General', 'name', 'Label', 'tip', 'value', ['a'], ['A']];
        return [
            'add_label'                     => ['add_label', $basic],
            'add_text_setting'              => ['add_text_setting', $basic],
            'add_integer_setting'           => ['add_integer_setting', $basic],
            'add_float_setting'             => ['add_float_setting', $basic],
            'add_password_setting'          => ['add_password_setting', $basic],
            'add_dropdown_setting'          => ['add_dropdown_setting', $choice],
            'add_radio_setting'             => ['add_radio_setting', $choice],
            'add_checkbox_setting'          => ['add_checkbox_setting', $choice],
            'add_select_master'             => ['add_select_master', ['vps', 'General', 'vps', 'name', 'Label']],
            'add_select_master_autosetup'   => ['add_select_master_autosetup', ['vps', 'General', 'vps', 'name', 'Label', 'tip']],
            'add_master_checkbox_setting'   => ['add_master_checkbox_setting', ['vps', 'General', 'vps', 'field', 'name', 'Label', 'tip']],
            'add_master_status_label'       => ['add_master_status_label', ['vps', 'General', 'vps', 'field', 'name', 'Label', 'tip']],
            'add_master_text_setting'       => ['add_master_text_setting', ['vps', 'General', 'vps', 'field', 'name', 'Label', 'tip']],
            'add_master_label'              => ['add_master_label', ['vps', 'General', 'vps', 'field', 'Label', 'tip']],
        ];
    }
}
