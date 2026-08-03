<?php
/**
 * @author Joe Huss <detain@interserver.net>
 * @copyright 2026
 * @package MyAdmin
 * @category Testing
 */

namespace MyAdmin\Plugins\Testing;

/**
 * The PSR-11-shaped container {@see TestContainerBuilder::build()} produces.
 *
 * Does **not** `implements Psr\Container\ContainerInterface`: psr/container is
 * not a dependency of the installer, and implementing an unloadable interface
 * is fatal. `get()`/`has()` match the interface's shape, which is all core's
 * `App` ever uses.
 */
class TestContainer
{
    /**
     * @var array<string,mixed>
     */
    private $services;

    /**
     * @param array<string,mixed> $services
     */
    public function __construct(array $services = [])
    {
        $this->services = $services;
    }

    /**
     * @param string $id
     * @return bool
     */
    public function has($id)
    {
        return array_key_exists($id, $this->services);
    }

    /**
     * @param string $id
     * @return mixed null rather than throwing, so a handler reaching for an
     *               unbound service still runs and the test sees the whole path
     */
    public function get($id)
    {
        return array_key_exists($id, $this->services) ? $this->services[$id] : null;
    }

    /**
     * Every bound id, for assertions.
     *
     * @return array<int,string>
     */
    public function ids()
    {
        return array_keys($this->services);
    }
}
