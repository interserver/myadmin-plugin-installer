<?php
/**
 * @author Joe Huss <detain@interserver.net>
 * @copyright 2026
 * @package MyAdmin
 * @category Testing
 */

namespace MyAdmin\Plugins\Testing\Contract;

use ReflectionClass;

/**
 * The plugin under inspection, plus the per-repo overrides that apply to it.
 *
 * Every inspector receives one of these rather than a bare class-string, so that the
 * reflection and the override resolution happen once instead of fifteen times, and so an
 * inspector cannot quietly disagree with another about what `$module` or `$type` is.
 *
 * ---------------------------------------------------------------------------------
 * ON THE OVERRIDES
 * ---------------------------------------------------------------------------------
 * Gate G2 requires that no assertion is weakened just to make a plugin pass, and that every
 * escape hatch used is logged. That is enforceable only if the hatches are *data* rather
 * than scattered `if` statements — {@see overridesInUse()} reports exactly which ones a
 * repo set, so the triage matrix can list them without trusting anyone to self-report.
 *
 * Deliberately holds no PHPUnit types. The Phase 2 self-check runs these inspectors over
 * all 69 plugins outside a PHPUnit process.
 */
class PluginSubject
{
    /** @var class-string */
    private $pluginClass;

    /** @var string|null expected `$type`, or null to accept any valid one */
    private $expectedType;

    /**
     * Filesystem root that `getRequirements()` sources must resolve under, or null to skip
     * the path check entirely (Tier-B-10 escape hatch).
     *
     * @var string|null
     */
    private $requirementRoot;

    /** @var array<string,int> service defines to seed before executing plugin code */
    private $serviceDefines;

    /** @var array<string,mixed> constants to force before the plugin class is touched */
    private $constantOverrides;

    /** @var array<string,bool> which overrides were explicitly set by the repo */
    private $explicit = [];

    /** @var ReflectionClass|null */
    private $reflection;

    /**
     * @param class-string        $pluginClass
     * @param array<string,mixed> $options expectedType, requirementRoot, serviceDefines,
     *                                     constantOverrides — omit to take the default
     */
    public function __construct($pluginClass, array $options = [])
    {
        $this->pluginClass = (string)$pluginClass;

        foreach (['expectedType', 'requirementRoot', 'serviceDefines', 'constantOverrides'] as $key) {
            $this->explicit[$key] = array_key_exists($key, $options);
        }

        $this->expectedType = isset($options['expectedType']) ? (string)$options['expectedType'] : null;
        $this->requirementRoot = array_key_exists('requirementRoot', $options)
            ? ($options['requirementRoot'] === null ? null : (string)$options['requirementRoot'])
            : null;
        $this->serviceDefines = isset($options['serviceDefines']) && is_array($options['serviceDefines'])
            ? $options['serviceDefines']
            : [];
        $this->constantOverrides = isset($options['constantOverrides']) && is_array($options['constantOverrides'])
            ? $options['constantOverrides']
            : [];
    }

    /**
     * @return class-string
     */
    public function pluginClass()
    {
        return $this->pluginClass;
    }

    /**
     * @return bool
     */
    public function isLoadable()
    {
        return class_exists($this->pluginClass);
    }

    /**
     * @return ReflectionClass
     */
    public function reflection()
    {
        if ($this->reflection === null) {
            $this->reflection = new ReflectionClass($this->pluginClass);
        }
        return $this->reflection;
    }

    /**
     * Value of a `public static` property, or null when it is absent.
     *
     * Absence and a null value are deliberately not distinguished here — callers that need
     * the difference should ask {@see hasStaticProperty()}. Most do not: a missing `$module`
     * and a `$module = null` mean the same thing to the hook-scoping rule (§0.7).
     *
     * @param string $name
     * @return mixed
     */
    public function staticProperty($name)
    {
        if (!$this->hasStaticProperty($name)) {
            return null;
        }
        $property = $this->reflection()->getProperty($name);
        $property->setAccessible(true);
        return $property->getValue();
    }

    /**
     * @param string $name
     * @return bool
     */
    public function hasStaticProperty($name)
    {
        return $this->reflection()->hasProperty($name)
            && $this->reflection()->getProperty($name)->isStatic();
    }

    /**
     * The plugin's declared module, or null/'' when it declares none.
     *
     * @return string|null
     */
    public function module()
    {
        $module = $this->staticProperty('module');
        return is_string($module) ? $module : null;
    }

    /**
     * The plugin's declared type.
     *
     * @return string|null
     */
    public function type()
    {
        $type = $this->staticProperty('type');
        return is_string($type) ? $type : null;
    }

    /**
     * @return string|null
     */
    public function expectedType()
    {
        return $this->expectedType;
    }

    /**
     * @return string|null
     */
    public function requirementRoot()
    {
        return $this->requirementRoot;
    }

    /**
     * Whether the repo explicitly opted out of the Tier-B-10 path check.
     *
     * Distinct from "requirementRoot happens to be null": an unset root means the check
     * falls back to a default, while an explicit null is a logged opt-out.
     *
     * @return bool
     */
    public function skipsRequirementCheck()
    {
        return $this->explicit['requirementRoot'] && $this->requirementRoot === null;
    }

    /**
     * @return array<string,int>
     */
    public function serviceDefines()
    {
        return $this->serviceDefines;
    }

    /**
     * @return array<string,mixed>
     */
    public function constantOverrides()
    {
        return $this->constantOverrides;
    }

    /**
     * Names of the overrides this repo set explicitly, for the G2 escape-hatch log.
     *
     * @return array<int,string>
     */
    public function overridesInUse()
    {
        $used = [];
        foreach ($this->explicit as $name => $wasSet) {
            if ($wasSet) {
                $used[] = $name;
            }
        }
        return $used;
    }

    /**
     * Directory the plugin class lives in — the anchor for on-disk checks (templates,
     * requirement paths) that must not depend on the current working directory.
     *
     * @return string|null
     */
    public function packageDir()
    {
        $file = $this->reflection()->getFileName();
        if ($file === false) {
            return null;
        }
        return dirname(dirname($file));
    }
}
