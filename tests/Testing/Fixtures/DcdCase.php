<?php
/**
 * @author Joe Huss <detain@interserver.net>
 * @copyright 2026
 * @package MyAdmin
 * @category Testing
 */

namespace Tests\MyAdmin\Plugins\Testing\Fixtures;

use MyAdmin\Plugins\Testing\Contract\PluginSubject;
use MyAdmin\Plugins\Testing\DeferredContractDefects;
use MyAdmin\Plugins\Testing\PluginContractTestCase;

/**
 * A repo that has opted into {@see DeferredContractDefects} and whose package directory is
 * whatever the current test created on disk.
 *
 * The register itself is **not** overridden here, and that is the point of this fixture: the
 * trait's real reading path — `contractSubject()` → `PluginSubject::packageDir()` →
 * `composer.json` → `extra.myadmin-deferred-contract-defects` — is the one the fleet matrix
 * also reads, so a test that supplied the array directly would prove the guards work and
 * prove nothing about the declaration channel they are guarding.
 *
 * Only `packageDir()` is redirected, through {@see DcdSubject}. Everything else is the
 * shipped base class.
 *
 * Lives in `tests/Testing/Fixtures/` rather than at the bottom of the test file for the
 * reason {@see PctcCasePlain} records: PHPUnit collects every `TestCase` subclass declared
 * in a `*Test.php` file, and this one would be run as a suite of its own.
 */
class DcdCase extends PluginContractTestCase
{
    use DeferredContractDefects;

    /**
     * Package directory the next run should read its register from. Set by the test.
     *
     * @var string
     */
    public static $packageDir = '';

    /**
     * @return string
     */
    protected function pluginClass()
    {
        return PctcFixturePlugin::class;
    }

    /**
     * @return \MyAdmin\Plugins\Testing\Contract\PluginSubject
     */
    protected function contractSubject()
    {
        return new DcdSubject($this->pluginClass());
    }
}

/**
 * A subject whose package directory is the scratch tree the test just wrote, rather than
 * wherever the fixture plugin class happens to live.
 */
class DcdSubject extends PluginSubject
{
    /**
     * @return string|null
     */
    public function packageDir()
    {
        return DcdCase::$packageDir === '' ? null : DcdCase::$packageDir;
    }
}
