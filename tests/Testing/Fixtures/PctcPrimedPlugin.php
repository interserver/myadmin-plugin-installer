<?php
/**
 * @author Joe Huss <detain@interserver.net>
 * @copyright 2026
 * @package MyAdmin
 * @category Testing
 */

namespace Tests\MyAdmin\Plugins\Testing\Fixtures;

/**
 * A constant-poisoned plugin, in miniature.
 *
 * Ten of the sixty-nine fleet packages have exactly this shape: an unrelated static whose
 * initializer names a bare global constant. PHP evaluates *every* static initializer of a
 * class on the first access to *any* of its statics, so reading `$type` off one of these
 * throws `Error: Undefined constant` until something has defined `PRORATE_BILLING`.
 *
 * `PCTC_FIXTURE_PRORATE_BILLING` is referenced by nothing else in this suite and is defined
 * by nothing except a `ConstantStub` scan of this file, which is what makes it usable as a
 * detector: if the constant is defined, priming ran; if it is not, priming did not.
 */
class PctcPrimedPlugin
{
    /** @var string */
    public static $name = 'Pctc Primed Fixture';

    /** @var string */
    public static $description = 'plugin fixture whose statics need a primed constant';

    /** @var string */
    public static $help = 'nothing to configure';

    /** @var string */
    public static $type = 'module';

    /** @var string */
    public static $module = 'pctcprimed';

    /**
     * The initializer that fatals until the constant exists.
     *
     * @var array<string,mixed>
     */
    public static $settings = [
        'REPEAT_BILLING_METHOD' => PCTC_FIXTURE_PRORATE_BILLING,
    ];
}
