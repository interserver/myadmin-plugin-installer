<?php
/**
 * @author Joe Huss <detain@interserver.net>
 * @copyright 2026
 * @package MyAdmin
 * @category Testing
 */

namespace Tests\MyAdmin\Plugins\Testing\Fixtures;

use MyAdmin\Plugins\Testing\PluginContractTestCase;

/**
 * The minimal legal subclass: it names a plugin and overrides nothing else.
 *
 * This is the case the {@see PluginContractTestCase::NOT_SET} sentinel exists for, so it
 * matters that the four hook methods are genuinely *not* redeclared here. A fixture that
 * configured itself through static properties would be overriding them — and would pass a
 * test that the real "overrode nothing" subclass fails.
 *
 * Lives in `tests/Testing/Fixtures/` rather than at the bottom of the test file because
 * PHPUnit collects every `TestCase` subclass declared in a `*Test.php` file; a fixture
 * subclass there would be run as a test suite of its own against the real eighteen
 * inspectors.
 *
 * The two `...ForTest()` accessors are how the protected seams are reached. A subclass
 * widening its own parent's visibility is what subclassing is for, and it keeps the tests
 * free of `ReflectionMethod::setAccessible()`, which would keep passing after the method
 * stopped being called by anything.
 */
class PctcCasePlain extends PluginContractTestCase
{
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
    public function contractSubjectForTest()
    {
        return $this->contractSubject();
    }

    /**
     * @return string
     */
    public function describeOverridesForTest()
    {
        return $this->describeOverrides($this->contractSubject());
    }

    /**
     * @param \MyAdmin\Plugins\Testing\Contract\PluginSubject $subject
     * @return array<string,mixed>
     */
    public static function overrideValuesForTest(\MyAdmin\Plugins\Testing\Contract\PluginSubject $subject)
    {
        return self::overrideValues($subject);
    }

    /**
     * {@see describeOverridesForTest()} for a subject this case does not build itself — the
     * only way to exercise a hatch the shipped {@see PluginSubject} does not yet declare.
     *
     * @param \MyAdmin\Plugins\Testing\Contract\PluginSubject $subject
     * @return string
     */
    public function describeOverridesForSubject(\MyAdmin\Plugins\Testing\Contract\PluginSubject $subject)
    {
        return $this->describeOverrides($subject);
    }

    /**
     * @param \MyAdmin\Plugins\Testing\Contract\PluginSubject $subject
     * @param string                                          $source
     * @param string                                          $assertion
     * @param string                                          $outcome
     * @return void
     */
    public static function recordOverrideUseForTest(
        \MyAdmin\Plugins\Testing\Contract\PluginSubject $subject,
        $source,
        $assertion,
        $outcome
    ) {
        self::recordOverrideUse($subject, $source, $assertion, $outcome);
    }
}
