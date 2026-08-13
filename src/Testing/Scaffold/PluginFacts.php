<?php
/**
 * Plugins Management
 * @author Joe Huss <detain@interserver.net>
 * @copyright 2026
 * @package MyAdmin
 * @category Testing
 */

namespace MyAdmin\Plugins\Testing\Scaffold;

/**
 * What one plugin package actually is, measured rather than parsed.
 *
 * ---------------------------------------------------------------------------------
 * WHY THESE FACTS ARE MEASURED AND NOT READ
 * ---------------------------------------------------------------------------------
 * A scaffolder could get most of this by reading `src/Plugin.php` with a tokenizer, and
 * it would be wrong in the two places that matter. `getHooks()` in this fleet is a method
 * body, not a literal: it builds its table from `self::$module`, from bare constants the
 * host defines, and in several packages from a conditional. The only honest answer to
 * "which hooks does this plugin register" comes from calling it, under the harness, with
 * those constants primed — which is what {@see probe.php} does, in a separate process,
 * using the *plugin repo's* autoloader rather than this one's.
 *
 * This class is the boundary between that measurement and the generator: everything
 * downstream of here is pure, so {@see ContractTestGenerator} can be tested without a
 * plugin repo on disk.
 *
 * ---------------------------------------------------------------------------------
 * hookError IS NOT A FAILURE OF THE PROBE
 * ---------------------------------------------------------------------------------
 * A plugin whose `getHooks()` throws is a plugin the harness has something to say about —
 * assertion A-5 owns that finding. The probe records the throw and carries on, so the
 * scaffold can still be generated and the resulting suite reports the real cause. Refusing
 * to scaffold there would hide exactly the defect the harness exists to surface.
 */
class PluginFacts
{
    /** @var string fully-qualified plugin class, e.g. Detain\MyAdminKvm\Plugin */
    private $class;

    /** @var string PSR-4 prefix mapped to tests/, without the trailing separator */
    private $testNamespace;

    /** @var string|null value of Plugin::$name */
    private $name;

    /** @var string|null value of Plugin::$type */
    private $type;

    /** @var string|null value of Plugin::$module, null when the class does not declare one */
    private $module;

    /** @var string[] keys of the table getHooks() returned */
    private $hookKeys;

    /** @var string|null the throw getHooks() produced, if it produced one */
    private $hookError;

    /**
     * @param string      $class
     * @param string      $testNamespace
     * @param string|null $name
     * @param string|null $type
     * @param string|null $module
     * @param string[]    $hookKeys
     * @param string|null $hookError
     */
    public function __construct($class, $testNamespace, $name, $type, $module, array $hookKeys, $hookError = null)
    {
        $this->class = ltrim((string)$class, '\\');
        $this->testNamespace = rtrim((string)$testNamespace, '\\');
        $this->name = $name;
        $this->type = $type;
        $this->module = $module;
        $this->hookKeys = array_values($hookKeys);
        $this->hookError = $hookError;
    }

    /**
     * Rebuilds the facts from the probe's JSON.
     *
     * INPUT:   $json — one line of JSON as emitted by src/Testing/Scaffold/probe.php.
     * RETURNS: PluginFacts
     * THROWS:  \InvalidArgumentException when the payload is not the probe's, which in
     *          practice means the probe died and its stderr is the thing worth reading.
     *
     * @param string $json
     * @return self
     */
    public static function fromJson($json)
    {
        $decoded = json_decode((string)$json, true);
        if (!is_array($decoded) || !isset($decoded['class']) || $decoded['class'] === '') {
            throw new \InvalidArgumentException(
                'probe produced no usable facts; its stderr carries the reason'
            );
        }
        if (!isset($decoded['testNamespace']) || $decoded['testNamespace'] === '') {
            throw new \InvalidArgumentException(
                'this package has no autoload-dev psr-4 prefix mapped to tests/, so a generated'
                .' test class would have no namespace to live in. Add one to composer.json first.'
            );
        }
        return new self(
            $decoded['class'],
            $decoded['testNamespace'],
            isset($decoded['name']) ? $decoded['name'] : null,
            isset($decoded['type']) ? $decoded['type'] : null,
            isset($decoded['module']) ? $decoded['module'] : null,
            isset($decoded['hookKeys']) && is_array($decoded['hookKeys']) ? $decoded['hookKeys'] : [],
            isset($decoded['hookError']) ? $decoded['hookError'] : null
        );
    }

    /** @return string */
    public function pluginClass()
    {
        return $this->class;
    }

    /**
     * The class name without its namespace — what the generated file imports and refers to.
     *
     * @return string
     */
    public function shortClass()
    {
        $cut = strrpos($this->class, '\\');
        return $cut === false ? $this->class : substr($this->class, $cut + 1);
    }

    /** @return string */
    public function testNamespace()
    {
        return $this->testNamespace;
    }

    /** @return string|null */
    public function name()
    {
        return $this->name;
    }

    /** @return string|null */
    public function type()
    {
        return $this->type;
    }

    /** @return string|null */
    public function module()
    {
        return $this->module;
    }

    /** @return string[] */
    public function hookKeys()
    {
        return $this->hookKeys;
    }

    /** @return string|null */
    public function hookError()
    {
        return $this->hookError;
    }

    /**
     * Whether the behavioural service assertions apply.
     *
     * `type` is the only thing that decides which base class the scaffold extends, which is
     * why the generated identity pin asserts it: changing `$type` silently changes which
     * contract assertions a package is held to.
     *
     * @return bool
     */
    public function isServicePlugin()
    {
        return $this->type === 'service';
    }
}
