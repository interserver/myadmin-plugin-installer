<?php
/**
 * @author Joe Huss <detain@interserver.net>
 * @copyright 2026
 * @package MyAdmin
 * @category Testing
 */

namespace Tests\MyAdmin\Plugins\Testing\Fixtures\Shadowed;

/**
 * A service plugin whose only observable act is a `myadmin_log()` call — in a namespace
 * that declares its own `myadmin_log()`.
 *
 * ---------------------------------------------------------------------------------
 * WHAT THIS FIXTURE IS FOR
 * ---------------------------------------------------------------------------------
 * This reproduces the shape that made assertion A give opposite verdicts for the same
 * plugin depending on how it was launched. 8 repos in the fleet ship a `tests/stubs.php`
 * declaring no-op helpers inside the plugin's own namespace, and PHP binds an unqualified
 * call to a namespaced function before the global one. The handler below therefore acts —
 * it calls `myadmin_log()` on a type it owns — while the harness's {@see
 * \MyAdmin\Plugins\Testing\Log} recorder stays empty, because the call never reaches the
 * global stub the harness installed.
 *
 * Without the shadow check, assertion A reads that empty recorder as proof and reports the
 * handler as dead code whose service "silently never gets provisioned". That sentence is
 * false here, and this fixture is what keeps it from being said again.
 *
 * It lives in its own sub-namespace so the shadowing declaration below cannot affect any
 * other fixture in this suite.
 */
class SptcShadowedPlugin
{
    /** @var string */
    public static $name = 'Shadowed';

    /** @var string */
    public static $type = 'service';

    /** @var string */
    public static $module = 'licenses';

    /**
     * Gates on a type it owns, then logs. The log is its only observable effect, which is
     * exactly the condition the shadow makes invisible.
     *
     * @param mixed $event
     * @return void
     */
    public static function getActivate($event)
    {
        if ($event['category'] == get_service_define('SPTC_SHADOWED')) {
            myadmin_log(self::$module, 'info', 'shadowed activation', __LINE__, __FILE__);
        }
    }
}

if (!function_exists(__NAMESPACE__.'\\myadmin_log')) {
    /**
     * The shadow itself: same name as the harness's global observer, declared inside the
     * plugin's namespace, doing nothing. This is a verbatim copy of the shape 8 fleet repos
     * ship in `tests/stubs.php`.
     *
     * @param mixed ...$args
     * @return void
     */
    function myadmin_log(...$args)
    {
    }
}
