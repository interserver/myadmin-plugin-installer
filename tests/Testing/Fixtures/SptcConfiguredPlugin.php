<?php
/**
 * @author Joe Huss <detain@interserver.net>
 * @copyright 2026
 * @package MyAdmin
 * @category Testing
 */

namespace Tests\MyAdmin\Plugins\Testing\Fixtures;

/**
 * A plugin whose source names both an application constant and one of PHP's own, so the
 * assertion-A safety guard can be tested in both directions at once.
 *
 * {@see \MyAdmin\Plugins\Testing\ServiceHandlerProbe::unownedConstants()} must report
 * `SPTC_REAL_CONFIG` — a `define()`d value the harness did not put there, the signature of
 * running inside a configured MyAdmin checkout where a lifecycle handler could reach real
 * infrastructure — and must **not** report `PHP_EOL`, which is the engine's.
 *
 * That second half is not hypothetical tidiness. The guard's first revision compared against
 * `defined()` alone, reported `PHP_EOL` as real configuration, and skipped assertion A for
 * five entire fleet packages on the strength of it. A safety guard that fires on `PHP_EOL`
 * is not a safety guard.
 *
 * `SPTC_REAL_CONFIG` is defined by the test rather than here, because a constant defined at
 * class-load time would be process-global and irreversible and this fixture is loaded
 * alongside everything else.
 */
class SptcConfiguredPlugin
{
    /** @var string */
    public static $name = 'Sptc Configured';

    /** @var string */
    public static $description = 'service plugin fixture that reads real configuration';

    /** @var string */
    public static $help = 'nothing to configure';

    /** @var string */
    public static $type = 'service';

    /** @var string */
    public static $module = 'sptcconfigured';

    /**
     * @param object $event
     * @return void
     */
    public static function getActivate($event)
    {
        if ($event['category'] == get_service_define('SPTC_CONFIGURED')) {
            myadmin_log(self::$module, 'info', 'talking to ' . SPTC_REAL_CONFIG . PHP_EOL, __LINE__, __FILE__);
        }
    }
}
