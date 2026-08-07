<?php
/**
 * @author Joe Huss <detain@interserver.net>
 * @copyright 2026
 * @package MyAdmin
 * @category Testing
 */

namespace Tests\MyAdmin\Plugins\Testing\Fixtures;

/**
 * The plugin the `PluginContractTestCase` fixtures point at.
 *
 * Deliberately declares no `getHooks()`, `getSettings()` or `getMenu()`. `inspectAll()` is
 * run against it by `PluginContractTestCaseTest`, which means all eighteen real inspectors
 * touch it in one process — so it must not cause any of them to execute plugin code, define
 * a constant or emit output. Verified: inspecting this class defines zero new constants,
 * adds zero globals and prints nothing, which is what keeps that test from changing the
 * result of whatever runs after it.
 *
 * The metadata is present because absent metadata is a different test's subject; here it is
 * only ever background.
 */
class PctcFixturePlugin
{
    /** @var string */
    public static $name = 'Pctc Fixture';

    /** @var string */
    public static $description = 'plugin fixture for the contract test case';

    /** @var string */
    public static $help = 'nothing to configure';

    /** @var string */
    public static $type = 'module';

    /** @var string */
    public static $module = 'pctcfixture';
}
