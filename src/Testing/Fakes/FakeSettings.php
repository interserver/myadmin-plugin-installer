<?php
/**
 * @author Joe Huss <detain@interserver.net>
 * @copyright 2026
 * @package MyAdmin
 * @category Testing
 */

namespace MyAdmin\Plugins\Testing\Fakes;

use MyAdmin\Plugins\Testing\CallLog;
use MyAdmin\Plugins\Testing\Recorder;

/**
 * Stand-in for `\MyAdmin\Settings`, the subject handed to every plugin's
 * `getSettings(GenericEvent $event)` handler.
 *
 * **Every signature here is lifted verbatim from `include/Settings.php`.** An
 * arity mismatch would let a handler run and a test pass while proving
 * nothing, which is why gate G1 requires a line-by-line diff of this file
 * against core. `SettingsSignatureTest` automates that diff so it cannot rot.
 *
 * Call frequency across the fleet, for context on what matters:
 * `get_setting` 168 · `add_text_setting` 135 · `add_dropdown_setting` 116 ·
 * `add_password_setting` 55 · `setTarget` 52 · `add_select_master` 29 ·
 * `add_radio_setting` 16 · `add_master_label` 12 · `add_master_text_setting` 5.
 *
 * The internal `$settings` structure mirrors core's exactly, so
 * `get_settings()` can be asserted against with the same shape the real class
 * produces.
 */
class FakeSettings
{
    use Recorder;

    /**
     * Same nested shape core builds: section => cats => category => settings.
     *
     * @var array<string,array<string,mixed>>
     */
    private $settings = [];

    /**
     * @var string
     */
    private $currentTarget = 'global';

    /**
     * Values handed back by {@see FakeSettings::get_setting()}, by name.
     *
     * @var array<string,mixed>
     */
    private $settingValues = [];

    /**
     * @param \MyAdmin\Plugins\Testing\CallLog|null $log shared log, see {@see Recorder}
     */
    public function __construct(?CallLog $log = null)
    {
        $this->initRecorder($log);
    }

    // -----------------------------------------------------------------------
    // Core surface — signatures must match include/Settings.php exactly
    // -----------------------------------------------------------------------

    /**
     * @param string $target global or module
     * @return void
     */
    public function setTarget($target)
    {
        $this->record(__FUNCTION__, [$target]);
        $this->currentTarget = $target;
    }

    /**
     * Core implements this as `return constant($setting);`.
     *
     * The fake mirrors that, because `ConstantStub` has usually defined the
     * constant to a `__STUB_*__` sentinel by the time a handler asks for it,
     * and a test asserting on the sentinel is asserting on the real code path.
     * A per-test override wins over the constant; an unknown, undefined name
     * yields a sentinel rather than throwing, so a handler can keep running
     * and the test sees the whole call sequence rather than only the first
     * missing name.
     *
     * @param string $setting
     * @return mixed
     */
    public function get_setting($setting)
    {
        $this->record(__FUNCTION__, [$setting]);
        if (array_key_exists($setting, $this->settingValues)) {
            return $this->settingValues[$setting];
        }
        if (defined($setting)) {
            return constant($setting);
        }
        return sprintf('__SETTING_%s__', $setting);
    }

    /**
     * @param string       $section
     * @param string       $category
     * @param false|string $master
     * @return string[] [$slug_section, $slug_category]
     */
    public function handle_section_category($section, $category, $master = false)
    {
        $this->record(__FUNCTION__, [$section, $category, $master]);
        return $this->sectionCategory($section, $category, $master);
    }

    /**
     * @param string $slug_section
     * @param string $slug_category
     * @param string $name
     * @return bool
     */
    public function setting_exists($slug_section, $slug_category, $name)
    {
        $this->record(__FUNCTION__, [$slug_section, $slug_category, $name]);
        if (!isset($this->settings[$slug_section]['cats'][$slug_category])) {
            return false;
        }
        foreach ($this->settings[$slug_section]['cats'][$slug_category]['settings'] as $data) {
            if ($data['name'] == $name) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param string       $section
     * @param string       $category
     * @param string       $name
     * @param string       $label
     * @param string       $tip
     * @param mixed        $initial_value
     * @param false|string $master
     * @return void
     */
    public function add_label($section, $category, $name, $label, $tip, $initial_value, $master = false)
    {
        $this->record(__FUNCTION__, func_get_args());
        $this->store('label', $section, $category, $name, $label, $tip, $initial_value, $master);
    }

    /**
     * @param string       $section
     * @param string       $category
     * @param string       $name
     * @param string       $label
     * @param string       $tip
     * @param mixed        $initial_value
     * @param false|string $master
     * @return void
     */
    public function add_text_setting($section, $category, $name, $label, $tip, $initial_value, $master = false)
    {
        $this->record(__FUNCTION__, func_get_args());
        $this->store('text', $section, $category, $name, $label, $tip, $initial_value, $master);
    }

    /**
     * @param string       $section
     * @param string       $category
     * @param string       $name
     * @param string       $label
     * @param string       $tip
     * @param mixed        $initial_value
     * @param false|string $master
     * @return void
     */
    public function add_integer_setting($section, $category, $name, $label, $tip, $initial_value, $master = false)
    {
        $this->record(__FUNCTION__, func_get_args());
        $this->store('integer', $section, $category, $name, $label, $tip, $initial_value, $master);
    }

    /**
     * @param string       $section
     * @param string       $category
     * @param string       $name
     * @param string       $label
     * @param string       $tip
     * @param mixed        $initial_value
     * @param false|string $master
     * @return void
     */
    public function add_float_setting($section, $category, $name, $label, $tip, $initial_value, $master = false)
    {
        $this->record(__FUNCTION__, func_get_args());
        $this->store('float', $section, $category, $name, $label, $tip, $initial_value, $master);
    }

    /**
     * @param string       $section
     * @param string       $category
     * @param string       $name
     * @param string       $label
     * @param string       $tip
     * @param mixed        $initial_value
     * @param false|string $master
     * @return void
     */
    public function add_password_setting($section, $category, $name, $label, $tip, $initial_value, $master = false)
    {
        $this->record(__FUNCTION__, func_get_args());
        $this->store('password', $section, $category, $name, $label, $tip, $initial_value, $master);
    }

    /**
     * @param string       $section
     * @param string       $category
     * @param string       $name
     * @param string       $label
     * @param string       $tip
     * @param mixed        $initial_value
     * @param array        $values
     * @param array        $labels
     * @param false|string $master
     * @return void
     */
    public function add_dropdown_setting($section, $category, $name, $label, $tip, $initial_value, $values, $labels, $master = false)
    {
        $this->record(__FUNCTION__, func_get_args());
        $this->store('dropdown', $section, $category, $name, $label, $tip, $initial_value, $master, $values, $labels);
    }

    /**
     * @param string       $section
     * @param string       $category
     * @param string       $name
     * @param string       $label
     * @param string       $tip
     * @param mixed        $initial_value
     * @param array        $values
     * @param array        $labels
     * @param false|string $master
     * @return void
     */
    public function add_radio_setting($section, $category, $name, $label, $tip, $initial_value, $values, $labels, $master = false)
    {
        $this->record(__FUNCTION__, func_get_args());
        $this->store('radio', $section, $category, $name, $label, $tip, $initial_value, $master, $values, $labels);
    }

    /**
     * @param string       $section
     * @param string       $category
     * @param string       $name
     * @param string       $label
     * @param string       $tip
     * @param mixed        $initial_value
     * @param array        $values
     * @param array        $labels
     * @param false|string $master
     * @return void
     */
    public function add_checkbox_setting($section, $category, $name, $label, $tip, $initial_value, $values, $labels, $master = false)
    {
        $this->record(__FUNCTION__, func_get_args());
        $this->store('checkbox', $section, $category, $name, $label, $tip, $initial_value, $master, $values, $labels);
    }

    /**
     * @param string       $section
     * @param string       $category
     * @param string       $module
     * @param string       $name
     * @param string       $label
     * @param false|mixed  $initial_value
     * @param false|string $type
     * @param false|string $location
     * @return void
     */
    public function add_select_master($section, $category, $module, $name, $label, $initial_value = false, $type = false, $location = false)
    {
        $this->record(__FUNCTION__, func_get_args());
        $this->store('select_master', $section, $category, $name, $label, '', $initial_value, false, [], [], $module);
    }

    /**
     * @param string $section
     * @param string $category
     * @param string $module
     * @param string $name
     * @param string $label
     * @param string $tip
     * @return void
     */
    public function add_select_master_autosetup($section, $category, $module, $name, $label, $tip)
    {
        $this->record(__FUNCTION__, func_get_args());
        $this->store('select_master_autosetup', $section, $category, $name, $label, $tip, false, false, [], [], $module);
    }

    /**
     * @param string $section
     * @param string $category
     * @param string $module
     * @param string $field
     * @param string $name
     * @param string $label
     * @param string $tip
     * @return void
     */
    public function add_master_checkbox_setting($section, $category, $module, $field, $name, $label, $tip)
    {
        $this->record(__FUNCTION__, func_get_args());
        $this->store('master_checkbox', $section, $category, $name, $label, $tip, false, false, [], [], $module, $field);
    }

    /**
     * @param string $section
     * @param string $category
     * @param string $module
     * @param string $field
     * @param string $name
     * @param string $label
     * @param string $tip
     * @return void
     */
    public function add_master_status_label($section, $category, $module, $field, $name, $label, $tip)
    {
        $this->record(__FUNCTION__, func_get_args());
        $this->store('master_status_label', $section, $category, $name, $label, $tip, false, false, [], [], $module, $field);
    }

    /**
     * @param string $section
     * @param string $category
     * @param string $module
     * @param string $field
     * @param string $name
     * @param string $label
     * @param string $tip
     * @return void
     */
    public function add_master_text_setting($section, $category, $module, $field, $name, $label, $tip)
    {
        $this->record(__FUNCTION__, func_get_args());
        $this->store('master_text', $section, $category, $name, $label, $tip, false, false, [], [], $module, $field);
    }

    /**
     * Note the shape difference from its siblings: there is no `$name`, the
     * `$label` sits where `$name` sits elsewhere, and the trailing parameter is
     * `$code`. Copied from core deliberately, not normalised.
     *
     * @param string     $section
     * @param string     $category
     * @param string     $module
     * @param string     $field
     * @param string     $label
     * @param string     $tip
     * @param false|string $code
     * @return void
     */
    public function add_master_label($section, $category, $module, $field, $label, $tip, $code = false)
    {
        $this->record(__FUNCTION__, func_get_args());
        $this->store('master_label', $section, $category, $field, $label, $tip, false, false, [], [], $module, $field);
    }

    /**
     * @return array<string,array<string,mixed>> the same shape core returns
     */
    public function get_settings()
    {
        $this->record(__FUNCTION__, []);
        return $this->settings;
    }

    // -----------------------------------------------------------------------
    // Test-facing readers (D5) — not part of the core surface
    // -----------------------------------------------------------------------

    /**
     * Flat list of every setting name registered, in order.
     *
     * @return array<int,string>
     */
    public function settingsAdded()
    {
        $names = [];
        foreach ($this->settings as $section) {
            foreach ($section['cats'] as $category) {
                foreach ($category['settings'] as $setting) {
                    $names[] = $setting['name'];
                }
            }
        }
        return $names;
    }

    /**
     * @param string $name
     * @return bool
     */
    public function hasSetting($name)
    {
        return in_array($name, $this->settingsAdded(), true);
    }

    /**
     * The recorded definition of one setting, or null.
     *
     * @param string $name
     * @return array<string,mixed>|null
     */
    public function setting($name)
    {
        foreach ($this->settings as $section) {
            foreach ($section['cats'] as $category) {
                foreach ($category['settings'] as $setting) {
                    if ($setting['name'] === $name) {
                        return $setting;
                    }
                }
            }
        }
        return null;
    }

    /**
     * Section names, as passed in (not slugified).
     *
     * @return array<int,string>
     */
    public function sections()
    {
        return array_values(array_map(static function (array $section) {
            return $section['name'];
        }, $this->settings));
    }

    /**
     * @return int total settings registered across all sections
     */
    public function settingCount()
    {
        return count($this->settingsAdded());
    }

    /**
     * @return string the current target set by setTarget()
     */
    public function target()
    {
        return $this->currentTarget;
    }

    /**
     * Pins the value `get_setting()` hands back for one name, overriding any
     * defined constant. Use for the value-dependent branch a stub sentinel
     * cannot reach.
     *
     * @param string $name
     * @param mixed  $value
     * @return $this
     */
    public function setSettingValue($name, $value)
    {
        $this->settingValues[$name] = $value;
        return $this;
    }

    /**
     * Clears registered settings, recorded calls and pinned values.
     *
     * @return void
     */
    public function reset()
    {
        $this->settings = [];
        $this->settingValues = [];
        $this->currentTarget = 'global';
        $this->resetCalls();
    }

    // -----------------------------------------------------------------------
    // internals
    // -----------------------------------------------------------------------

    /**
     * @param string       $section
     * @param string       $category
     * @param false|string $master
     * @return string[]
     */
    private function sectionCategory($section, $category, $master = false)
    {
        $slugSection = self::slug($section);
        $slugCategory = self::slug($category);
        if (!isset($this->settings[$slugSection])) {
            $this->settings[$slugSection] = ['name' => $section, 'desc' => '', 'cats' => [], 'master' => false];
        }
        if (!isset($this->settings[$slugSection]['cats'][$slugCategory])) {
            $this->settings[$slugSection]['cats'][$slugCategory] = ['name' => $category, 'settings' => [], 'master' => false];
        }
        if ($master !== false) {
            $this->settings[$slugSection]['master'] = true;
            $this->settings[$slugSection]['cats'][$slugCategory]['master'] = true;
        }
        return [$slugSection, $slugCategory];
    }

    /**
     * @param string       $type
     * @param string       $section
     * @param string       $category
     * @param string       $name
     * @param string       $label
     * @param string       $tip
     * @param mixed        $initialValue
     * @param false|string $master
     * @param array        $values
     * @param array        $labels
     * @param string|null  $module
     * @param string|null  $field
     * @return void
     */
    private function store($type, $section, $category, $name, $label, $tip, $initialValue, $master = false, array $values = [], array $labels = [], $module = null, $field = null)
    {
        list($slugSection, $slugCategory) = $this->sectionCategory($section, $category, $master);
        $this->settings[$slugSection]['cats'][$slugCategory]['settings'][] = [
            'type'          => $type,
            'name'          => $name,
            'label'         => $label,
            'tip'           => $tip,
            'initial_value' => $initialValue,
            'master'        => $master,
            'values'        => $values,
            'labels'        => $labels,
            'module'        => $module,
            'field'         => $field,
        ];
    }

    /**
     * Local stand-in for core's global `slugify()`, which the harness must not
     * depend on.
     *
     * @param string $text
     * @return string
     */
    private static function slug($text)
    {
        $slug = strtolower((string)$text);
        $slug = preg_replace('/[^a-z0-9]+/', '_', $slug);
        return trim((string)$slug, '_');
    }
}
