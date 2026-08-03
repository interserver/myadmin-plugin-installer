<?php
/**
 * @author Joe Huss <detain@interserver.net>
 * @copyright 2026
 * @package MyAdmin
 * @category Testing
 */

namespace Tests\MyAdmin\Plugins\Testing;

use MyAdmin\Plugins\Testing\Fakes\FakeMenu;
use MyAdmin\Plugins\Testing\Fakes\FakeSettings;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Risk R5: a fake whose signature has drifted from core lets a handler run and
 * a test pass while proving nothing. That is worse than no test at all.
 *
 * The installer package cannot see the MyAdmin core tree, so this cannot diff
 * against `include/Settings.php` directly. Instead it **pins** each signature
 * as data. The table below is what a reviewer diffs against core by hand at
 * gate G1; once pinned, any later drift in the *fake* fails the build
 * automatically, so the manual check only has to be done when the table
 * itself changes.
 *
 * The table was produced by reading `include/Settings.php` and
 * `include/Menu.php` at commit time. Three entries differ from the signature
 * list in an earlier revision of the plan, and follow **core**:
 *
 *   - `add_checkbox_setting` takes `$values, $labels` (like dropdown), not the
 *     text-setting shape the plan implied.
 *   - `add_master_label` has no `$name` parameter and ends in `$code`.
 *   - `get_setting` returns `constant($setting)` in core, which is why
 *     `FakeSettings` reads the constant rather than a canned value.
 *
 * @coversNothing
 */
class SignaturePinTest extends TestCase
{
    /**
     * method => "param=default, param=default" as read from core.
     *
     * @return array<string,string>
     */
    private function settingsSignatures()
    {
        return [
            'setTarget'                   => 'target',
            'get_setting'                 => 'setting',
            'handle_section_category'     => 'section, category, master=false',
            'setting_exists'              => 'slug_section, slug_category, name',
            'add_label'                   => 'section, category, name, label, tip, initial_value, master=false',
            'add_text_setting'            => 'section, category, name, label, tip, initial_value, master=false',
            'add_integer_setting'         => 'section, category, name, label, tip, initial_value, master=false',
            'add_float_setting'           => 'section, category, name, label, tip, initial_value, master=false',
            'add_password_setting'        => 'section, category, name, label, tip, initial_value, master=false',
            'add_dropdown_setting'        => 'section, category, name, label, tip, initial_value, values, labels, master=false',
            'add_radio_setting'           => 'section, category, name, label, tip, initial_value, values, labels, master=false',
            'add_checkbox_setting'        => 'section, category, name, label, tip, initial_value, values, labels, master=false',
            'add_select_master'           => 'section, category, module, name, label, initial_value=false, type=false, location=false',
            'add_select_master_autosetup' => 'section, category, module, name, label, tip',
            'add_master_checkbox_setting' => 'section, category, module, field, name, label, tip',
            'add_master_status_label'     => 'section, category, module, field, name, label, tip',
            'add_master_text_setting'     => 'section, category, module, field, name, label, tip',
            'add_master_label'            => 'section, category, module, field, label, tip, code=false',
            'get_settings'                => '',
        ];
    }

    /**
     * @return array<string,string>
     */
    private function menuSignatures()
    {
        return [
            'add_link'          => 'category, params, image, text, target=false, link_names=array',
            'add_external_link' => 'category, url, image, text',
            'add_menu'          => 'parent, category, text, image=\'\', link_names=array',
        ];
    }

    /**
     * Renders a method's signature in the same notation the tables above use.
     *
     * @param string $class
     * @param string $method
     * @return string
     */
    private function render($class, $method)
    {
        $reflection = new ReflectionMethod($class, $method);
        $parts = [];
        foreach ($reflection->getParameters() as $parameter) {
            $part = $parameter->getName();
            if ($parameter->isDefaultValueAvailable()) {
                $default = $parameter->getDefaultValue();
                if (is_array($default)) {
                    $rendered = 'array';
                } elseif ($default === false) {
                    $rendered = 'false';
                } elseif ($default === true) {
                    $rendered = 'true';
                } elseif ($default === null) {
                    $rendered = 'null';
                } elseif (is_string($default)) {
                    $rendered = "'" . $default . "'";
                } else {
                    $rendered = (string)$default;
                }
                $part .= '=' . $rendered;
            }
            $parts[] = $part;
        }
        return implode(', ', $parts);
    }

    /**
     * @return void
     */
    public function testFakeSettingsMatchesThePinnedCoreSignatures()
    {
        foreach ($this->settingsSignatures() as $method => $expected) {
            $this->assertTrue(method_exists(FakeSettings::class, $method), 'FakeSettings is missing ' . $method . '()');
            $this->assertSame(
                $expected,
                $this->render(FakeSettings::class, $method),
                'FakeSettings::' . $method . '() has drifted from the signature pinned against include/Settings.php. '
                . 'An arity mismatch makes a passing test meaningless (R5) — re-diff against core before changing this.'
            );
        }
    }

    /**
     * @return void
     */
    public function testFakeMenuMatchesThePinnedCoreSignatures()
    {
        foreach ($this->menuSignatures() as $method => $expected) {
            $this->assertTrue(method_exists(FakeMenu::class, $method), 'FakeMenu is missing ' . $method . '()');
            $this->assertSame(
                $expected,
                $this->render(FakeMenu::class, $method),
                'FakeMenu::' . $method . '() has drifted from the signature pinned against include/Menu.php.'
            );
        }
    }

    /**
     * The pinned tables must cover the whole public core surface of each fake,
     * so a newly added method cannot slip in unpinned.
     *
     * @return void
     */
    public function testEveryPinnedMethodIsStillPresentAndNoCoreMethodIsUnpinned()
    {
        $harnessOnly = [
            // Recorder trait + D5 readers — not part of the core surface.
            'callLog', 'calls', 'lastCall', 'callCount', 'wasCalled', 'argsFor', 'resetCalls',
            'reset', '__construct',
            'settingsAdded', 'hasSetting', 'setting', 'sections', 'settingCount', 'target', 'setSettingValue',
            'links', 'menu', 'categories', 'linkTexts', 'hasLinkText', 'linkCount',
        ];

        foreach ([FakeSettings::class => $this->settingsSignatures(), FakeMenu::class => $this->menuSignatures()] as $class => $pinned) {
            $reflection = new \ReflectionClass($class);
            foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                if ($method->isStatic() || in_array($method->getName(), $harnessOnly, true)) {
                    continue;
                }
                $this->assertArrayHasKey(
                    $method->getName(),
                    $pinned,
                    $class . '::' . $method->getName() . '() is a public core-surface method with no pinned signature. '
                    . 'Add it to the table in ' . __CLASS__ . ' after diffing it against core.'
                );
            }
        }
    }
}
