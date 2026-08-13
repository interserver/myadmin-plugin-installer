<?php
/**
 * @author Joe Huss <detain@interserver.net>
 * @copyright 2026
 * @package MyAdmin
 * @category Testing
 */

namespace Tests\MyAdmin\Plugins\Testing\Fixtures;

/**
 * Handlers with no `get_service_define()` gate at all — eight of the fleet's 92 are like
 * this, and they split into two groups that must be reported differently.
 *
 * The distinction is the whole content of
 * {@see \MyAdmin\Plugins\Testing\ServicePluginTestCase::ungatedFinding()}: an ungated handler
 * that never stops propagation cannot harm a co-listener and is genuinely not-applicable,
 * while one that stops unconditionally is a latent hazard worth disclosing even though
 * nothing is broken while it is alone on its key.
 */
class SptcUngatedPlugin
{
    /** @var string */
    public static $name = 'Sptc Ungated';

    /** @var string */
    public static $description = 'service plugin fixture with no type gates';

    /** @var string */
    public static $help = 'nothing to configure';

    /** @var string */
    public static $type = 'module';

    /** @var string */
    public static $module = 'sptcungated';

    /**
     * Ungated **and** stops — `servers-module::getActivate()`'s shape. Disclosed as a notice.
     *
     * @param object $event
     * @return void
     */
    public static function getActivate($event)
    {
        myadmin_log(self::$module, 'info', 'ungated activation', __LINE__, __FILE__);
        $event->stopPropagation();
    }

    /**
     * Ungated and never stops — `mail-module::getDeactivate()`'s shape. Not applicable.
     *
     * @param object $event
     * @return void
     */
    public static function getDeactivate($event)
    {
        myadmin_log(self::$module, 'info', 'ungated deactivation', __LINE__, __FILE__);
    }

    /**
     * The gate is present in the source but **commented out**, directly above a
     * `stopPropagation()` that still runs — `quickservers-module::getQueue()` exactly.
     *
     * A regex-based census of this fleet reported that handler as gated on `type` and so
     * missed it entirely. The token scan must read this as ungated.
     *
     * @param object $event
     * @return void
     */
    public static function getQueue($event)
    {
        //if (in_array($event['type'], [get_service_define('SPTC_COMMENTED')])) {
        myadmin_log(self::$module, 'info', 'queue with a commented-out gate', __LINE__, __FILE__);
        $event->stopPropagation();
        //}
    }
}
