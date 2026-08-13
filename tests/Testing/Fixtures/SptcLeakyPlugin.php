<?php
/**
 * @author Joe Huss <detain@interserver.net>
 * @copyright 2026
 * @package MyAdmin
 * @category Testing
 */

namespace Tests\MyAdmin\Plugins\Testing\Fixtures;

/**
 * The bug assertion B exists to catch, in each of its three shapes.
 *
 * This is the synthetic counterpart to the gate-G3 validation, which plants the same defect
 * in a copy of a real fleet package. Both are kept: the copied package proves the assertion
 * fires against production code it was not written against, and this fixture pins the three
 * shapes so a later refactor cannot quietly stop detecting one of them.
 */
class SptcLeakyPlugin
{
    /** @var string */
    public static $name = 'Sptc Leaky';

    /** @var string */
    public static $description = 'service plugin fixture that mishandles foreign service types';

    /** @var string */
    public static $help = 'nothing to configure';

    /** @var string */
    public static $type = 'service';

    /** @var string */
    public static $module = 'sptcleaky';

    /**
     * Shape 1, the dangerous one: gated for its *work*, but `stopPropagation()` sits outside
     * the gate, so every co-listener on the hook key is silenced for every service type. This
     * is the production symptom "product X stopped activating after we installed plugin Y".
     *
     * @param object $event
     * @return void
     */
    public static function getActivate($event)
    {
        if ($event['category'] == get_service_define('SPTC_LEAK')) {
            myadmin_log(self::$module, 'info', 'leaky activation', __LINE__, __FILE__);
        }
        $event->stopPropagation();
    }

    /**
     * Shape 2: the gate guards only part of the body, so a foreign type still writes a
     * history row. No propagation is stopped, so only the effects sweep catches it — which is
     * why that sweep has to cover every recorder and not just the log.
     *
     * @param object $event
     * @return void
     */
    public static function getDeactivate($event)
    {
        if ($event['category'] == get_service_define('SPTC_LEAK')) {
            myadmin_log(self::$module, 'info', 'leaky deactivation', __LINE__, __FILE__);
        }
        \MyAdmin\App::history()->add('sptcleaky', 'delete', '', '', 9);
    }

    /**
     * Shape 3: a foreign type reaches code that throws. Nothing is recorded and nothing is
     * stopped, so this is invisible to both of the other checks — the handler simply should
     * never have got that far.
     *
     * @param object $event
     * @return void
     */
    public static function getTerminate($event)
    {
        if ($event['category'] == get_service_define('SPTC_LEAK')) {
            return;
        }
        throw new \RuntimeException('reached code that a foreign service type should never reach');
    }
}
