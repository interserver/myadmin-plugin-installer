<?php
/**
 * @author Joe Huss <detain@interserver.net>
 * @copyright 2026
 * @package MyAdmin
 * @category Testing
 */

namespace Tests\MyAdmin\Plugins\Testing\Fixtures;

/**
 * A repo that deliberately opts out of the B-10 path check.
 *
 * The only difference from {@see PctcCasePlain} is an explicit `null`. Both subjects end up
 * with `requirementRoot() === null`; only this one is a logged escape hatch. If the sentinel
 * ever collapses, these two fixtures become indistinguishable — which is exactly the
 * regression the paired assertions in `PluginContractTestCaseTest` are watching for.
 */
class PctcCaseOptedOutOfRequirementRoot extends PctcCasePlain
{
    /**
     * @return null
     */
    protected function requirementRoot()
    {
        return null;
    }
}
