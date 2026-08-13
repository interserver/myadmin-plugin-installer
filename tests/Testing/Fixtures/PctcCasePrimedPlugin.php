<?php
/**
 * @author Joe Huss <detain@interserver.net>
 * @copyright 2026
 * @package MyAdmin
 * @category Testing
 */

namespace Tests\MyAdmin\Plugins\Testing\Fixtures;

/**
 * Points the base test case at the constant-poisoned fixture.
 *
 * Overrides nothing but the plugin name, on purpose: a repo that has to reach for
 * `constantOverrides()` to make its own metadata readable is a repo where the harness has
 * failed, and the whole fleet would need the hatch.
 */
class PctcCasePrimedPlugin extends PctcCasePlain
{
    /**
     * @return string
     */
    protected function pluginClass()
    {
        return PctcPrimedPlugin::class;
    }
}
