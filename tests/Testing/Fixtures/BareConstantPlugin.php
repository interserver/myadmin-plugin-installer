<?php
/**
 * @author Joe Huss <detain@interserver.net>
 * @copyright 2026
 * @package MyAdmin
 * @category Testing
 */

namespace Tests\MyAdmin\Plugins\Testing\Fixtures;

/**
 * Reproduces the shape that makes §0.8 bite: a static property whose
 * initializer references a **bare global constant**.
 *
 * This is `myadmin-mail-module/src/Plugin.php:25` in miniature — the file that
 * currently fails 39 tests with
 * `Error: Undefined constant "Detain\MyAdminMail\PRORATE_BILLING"`.
 *
 * PHP evaluates a static property's initializer lazily, on **first access to
 * the class**, not at load time. That single fact is why the ordering in
 * `Bootstrap::init()` matters and why `ConstantStub::defineFrom()` can define
 * the constant *after* `class_exists()` has already autoloaded the file.
 *
 * Used by `ConstantOrderingTest`.
 */
class BareConstantPlugin
{
    /**
     * @var string
     */
    public static $name = 'Bare Constant Fixture';

    /**
     * @var string
     */
    public static $module = 'fixture';

    /**
     * The initializer that fatals when the constant is not yet defined.
     *
     * @var array<string,mixed>
     */
    public static $settings = [
        'PRORATE' => HARNESS_FIXTURE_PRORATE,
    ];
}
