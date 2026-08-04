<?php
/**
 * @author Joe Huss <detain@interserver.net>
 * @copyright 2026
 * @package MyAdmin
 * @category Testing
 */

namespace Tests\MyAdmin\Plugins\Testing\Fixtures;

/**
 * A well-behaved service plugin: every handler gates on a service type before doing
 * anything, and none of them touches the event when the type is not theirs.
 *
 * ---------------------------------------------------------------------------------
 * WHY THE EVENT PARAMETER CARRIES NO TYPE HINT
 * ---------------------------------------------------------------------------------
 * Every real fleet handler is declared `getActivate(GenericEvent $event)`, and
 * `symfony/event-dispatcher` is **not** a dependency of this installer package — verified:
 * it is absent from its vendor tree. A fixture that hinted `GenericEvent` would be skipped
 * by {@see \MyAdmin\Plugins\Testing\ServiceHandlerProbe::buildEvent()} in this repo's own
 * suite, which is correct behaviour and useless as a fixture: every test would go grey and
 * prove nothing.
 *
 * Untyped, the probe supplies {@see \MyAdmin\Plugins\Testing\ServiceLifecycleEvent}, whose
 * array access, `getSubject()` and propagation semantics match `GenericEvent`'s — including
 * throwing on an unset argument. So these fixtures exercise the same code paths a real
 * plugin does, and the `GenericEvent` branch is covered separately by a fixture that hints a
 * class name on purpose.
 *
 * ---------------------------------------------------------------------------------
 * NO BARE CONSTANTS
 * ---------------------------------------------------------------------------------
 * Deliberately references no bare constant. Constants are process-global and irreversible,
 * and this file is inspected in the same process as everything else in the suite; a stub
 * defined here would still be defined for whatever ran next. Same reasoning as
 * {@see PctcFixturePlugin}.
 */
class SptcGatedPlugin
{
    /** @var string */
    public static $name = 'Sptc Gated';

    /** @var string */
    public static $description = 'service plugin fixture whose handlers all gate correctly';

    /** @var string */
    public static $help = 'nothing to configure';

    /** @var string */
    public static $type = 'service';

    /** @var string */
    public static $module = 'sptcgated';

    /**
     * The `==` gate form, 46 of the fleet's handlers, on `category`. Acts and stops.
     *
     * @param object $event
     * @return void
     */
    public static function getActivate($event)
    {
        if ($event['category'] == get_service_define('SPTC_ONE')) {
            myadmin_log(self::$module, 'info', 'Sptc activation', __LINE__, __FILE__);
            $event->stopPropagation();
        }
    }

    /**
     * The `in_array` gate form, 38 of the fleet's handlers, on `type`. Acts and deliberately
     * does **not** stop — the shape all seven `*-vps::getDeactivate()` handlers share, and the
     * reason assertion A does not demand `isPropagationStopped()`.
     *
     * @param object $event
     * @return void
     */
    public static function getDeactivate($event)
    {
        if (in_array($event['type'], [get_service_define('SPTC_TWO'), get_service_define('SPTC_THREE')])) {
            \MyAdmin\App::history()->add('sptc', 'delete', '', '', 7);
        }
    }

    /**
     * Gated, matches, and then does nothing observable at all — the dead-lifecycle-handler
     * shape assertion A exists to catch.
     *
     * @param object $event
     * @return void
     */
    public static function getChangeIp($event)
    {
        if ($event['category'] == get_service_define('SPTC_ONE')) {
            $unused = 1 + 1;
        }
    }

    /**
     * Gated, matches, and dies on a symbol no environment here provides before doing
     * anything observable — the honest-skip shape.
     *
     * @param object $event
     * @return void
     */
    public static function getTerminate($event)
    {
        if ($event['category'] == get_service_define('SPTC_ONE')) {
            sptc_a_function_that_does_not_exist_anywhere();
        }
    }

    /**
     * Gated, matches, and fails on its own logic before doing anything observable — the
     * `xen-vps::getDeactivate()` shape, which must be a failure and not a skip.
     *
     * @param object $event
     * @return void
     */
    public static function getQueue($event)
    {
        if ($event['category'] == get_service_define('SPTC_ONE')) {
            $notAnObject = null;
            $notAnObject->getId();
        }
    }

    /**
     * A gate with comments sitting *between* its tokens.
     *
     * Every match in {@see \MyAdmin\Plugins\Testing\ServiceHandlerProbe::analyse()} is
     * positional, so an interstitial comment shifts the offsets and the gate is missed —
     * and a missed gate makes assertion B pass vacuously, which is the silent failure.
     * The commented-out gate on {@see SptcUngatedPlugin::getQueue()} does *not* exercise
     * this: PHP returns a whole `//` comment as one opaque token, so that case is safe
     * whether or not comments are filtered. This one is the case that is not.
     *
     * @param object $event
     * @return void
     */
    public static function getReactivate($event)
    {
        if ($event['category'] /* the service type */ == /* compared to */ get_service_define('SPTC_ONE')) {
            myadmin_log(self::$module, 'info', 'Sptc reactivation', __LINE__, __FILE__);
        }
    }

    /**
     * Two gates on two different event keys in one body.
     *
     * No fleet handler does this today. The harness still has to be deterministic about it,
     * because "whichever the scanner happened to see last" is the kind of behaviour that
     * changes silently when an unrelated line is edited. First gate wins.
     *
     * @param object $event
     * @return void
     */
    public static function getDeactivateIp($event)
    {
        if ($event['category'] == get_service_define('SPTC_FIRST')) {
            myadmin_log(self::$module, 'info', 'first gate', __LINE__, __FILE__);
        }
        if ($event['type'] == get_service_define('SPTC_SECOND')) {
            myadmin_log(self::$module, 'info', 'second gate', __LINE__, __FILE__);
        }
    }
}
