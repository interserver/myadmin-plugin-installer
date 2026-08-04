<?php
/**
 * @author Joe Huss <detain@interserver.net>
 * @copyright 2026
 * @package MyAdmin
 * @category Testing
 */

namespace Tests\MyAdmin\Plugins\Testing\Fixtures;

/**
 * Names a plugin class that does not resolve.
 *
 * Used to drive a real inspector — not a fixture one — down its skip path, so the wiring
 * between the base test case and the shipped catalogue is proven against something that was
 * not written to make the test pass.
 */
class PctcCaseMissingPlugin extends PctcCasePlain
{
    /**
     * @return string
     */
    protected function pluginClass()
    {
        return 'Tests\\MyAdmin\\Plugins\\Testing\\Fixtures\\PctcPluginThatDoesNotExist';
    }
}
