<?php
/**
 * @author Joe Huss <detain@interserver.net>
 * @copyright 2026
 * @package MyAdmin
 * @category Testing
 */

namespace Tests\MyAdmin\Plugins\Testing\Fixtures;

/**
 * The exact shape of the ten `*-module` fleet packages, in miniature.
 *
 * `myadmin-{backups,domains,floating-ips,licenses,mail,quickservers,servers,ssl,vps,webhosting}-module`
 * all declare a `$settings` initializer that references a bare billing constant, alongside
 * metadata that is nothing but plain literals. Because PHP evaluates **every** static
 * initializer of a class on first access to **any** of its statics, reading the harmless
 * `$type` on one of those throws `Error: Undefined constant` unless the constant was primed
 * first — which is the failure {@see \MyAdmin\Plugins\Testing\Contract\PluginSubject} has to
 * absorb without lying about it.
 *
 * The constant is named for this fixture and is **never defined anywhere in the suite**.
 * That is load-bearing: PHP constants are process-global and immutable, so a shared name
 * would let whichever test ran first decide whether this class throws, and the regression
 * would silently stop being a regression test.
 *
 * Distinct from `BareConstantPlugin`, which `ConstantOrderingTest` uses to pin the *language*
 * semantics and whose constant that test deliberately defines partway through.
 */
class UnevaluableMetadataPlugin
{
    /**
     * @var string
     */
    public static $name = 'Unevaluable Metadata Fixture';

    /**
     * @var string
     */
    public static $description = 'Metadata is plain literals; $settings is not';

    /**
     * @var string
     */
    public static $help = '';

    /**
     * @var string
     */
    public static $type = 'module';

    /**
     * @var string
     */
    public static $module = 'a9unevaluable';

    /**
     * The initializer that poisons every other static on this class.
     *
     * @var array<string,mixed>
     */
    public static $settings = [
        'REPEAT_BILLING_METHOD' => PLUGIN_SUBJECT_FIXTURE_BILLING,
    ];
}
