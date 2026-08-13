<?php
/**
 * @author Joe Huss <detain@interserver.net>
 * @copyright 2026
 * @package MyAdmin
 * @category Testing
 */

namespace Tests\MyAdmin\Plugins\Testing\Fixtures;

/**
 * A handler that type-hints an event class which is not loadable here.
 *
 * Stands in for the whole fleet as this package's own suite sees it: every real handler
 * hints `Symfony\Component\EventDispatcher\GenericEvent`, and that component is not among
 * this installer's dependencies. The required behaviour is a skip naming the missing class —
 * **not** a silent substitution of
 * {@see \MyAdmin\Plugins\Testing\ServiceLifecycleEvent}, which would run the handler against
 * a shape it never asked for and report the result as if it meant something.
 *
 * The class name is deliberately fictional rather than Symfony's, so this fixture keeps
 * testing the same thing in an environment where the component happens to be installed.
 */
class SptcTypedEventPlugin
{
    /** @var string */
    public static $name = 'Sptc Typed Event';

    /** @var string */
    public static $description = 'service plugin fixture hinting an unloadable event class';

    /** @var string */
    public static $help = 'nothing to configure';

    /** @var string */
    public static $type = 'service';

    /** @var string */
    public static $module = 'sptctyped';

    /**
     * Gated, so both assertions reach the point of needing an actual event object. An
     * ungated handler would be answered `not-applicable` straight from the source scan and
     * would never exercise the branch this fixture exists for.
     *
     * @param \Tests\MyAdmin\Plugins\Testing\Fixtures\NoSuchEventClassAnywhere $event
     * @return void
     */
    public static function getActivate(NoSuchEventClassAnywhere $event)
    {
        if ($event['category'] == get_service_define('SPTC_TYPED')) {
            myadmin_log(self::$module, 'info', 'never reached', __LINE__, __FILE__);
        }
    }
}
