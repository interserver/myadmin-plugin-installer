<?php
/**
 * @author Joe Huss <detain@interserver.net>
 * @copyright 2026
 * @package MyAdmin
 * @category Testing
 */

namespace Tests\MyAdmin\Plugins\Testing\Fixtures;

/**
 * A repo leaning on all four escape hatches at once.
 *
 * Used to pin that every hatch is passed through with its declared value *and* is named in
 * the G2 log line. A hatch that silently failed to reach the subject would relax nothing and
 * would still be reported as in effect — the worst of both, since the reviewer would see a
 * disclosure where there was no deviation, and stop trusting the disclosures.
 */
class PctcCaseEveryHatch extends PctcCasePlain
{
    /**
     * @return string
     */
    protected function expectedType()
    {
        return 'module';
    }

    /**
     * @return string
     */
    protected function requirementRoot()
    {
        return '/srv/pctc-fixture';
    }

    /**
     * @return array<string,int>
     */
    protected function serviceDefines()
    {
        return ['PCTC_FIXTURE_SERVICE' => 4242];
    }

    /**
     * @return array<string,mixed>
     */
    protected function constantOverrides()
    {
        return ['PCTC_FIXTURE_BILLING' => 'prorate'];
    }
}
