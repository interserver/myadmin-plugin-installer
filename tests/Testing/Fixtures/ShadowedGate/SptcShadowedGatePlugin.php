<?php
/**
 * @author Joe Huss <detain@interserver.net>
 * @copyright 2026
 * @package MyAdmin
 * @category Testing
 */

namespace Tests\MyAdmin\Plugins\Testing\Fixtures\ShadowedGate {

/**
 * A service plugin whose namespace redefines `get_service_define()` incompatibly.
 *
 * ---------------------------------------------------------------------------------
 * WHAT THIS FIXTURE IS FOR
 * ---------------------------------------------------------------------------------
 * `myadmin-whmsonic-licensing` declares `get_service_define()` in the plugin's own namespace
 * returning the fixed string `'WHMSONIC_TYPE'`, so its own tests can seed that literal into
 * the event. The harness seeds `$event['category']` from *its* `get_service_define()`, so the
 * two disagree, the handler's gate never matches, and the body never runs.
 *
 * S-1 then reported the handler as dead code whose service "silently never gets provisioned".
 * That is the H-1 false accusation arriving through the gate instead of through the observers
 * — and the two were hiding each other: it only became visible once the observer shadow was
 * cleared.
 *
 * The handler below acts unmistakably when its gate matches, so a verdict of "changed nothing"
 * can only ever mean the gate was broken.
 */
class SptcShadowedGatePlugin
{
    /** @var string */
    public static $name = 'ShadowedGate';

    /** @var string */
    public static $type = 'service';

    /** @var string */
    public static $module = 'licenses';

    /**
     * @param mixed $event
     * @return void
     */
    public static function getActivate($event)
    {
        if ($event['category'] == get_service_define('SHADOWGATE')) {
            myadmin_log(self::$module, 'info', 'ShadowedGate activation', __LINE__, __FILE__);
            $event->stopPropagation();
        }
    }
}

    /**
     * The incompatible redefinition. Returns a literal the harness's seeding never produces,
     * which is exactly the whmsonic shape.
     *
     * @param string $name
     * @return string
     */
    function get_service_define($name)
    {
        return $name.'_LITERAL';
    }
}
