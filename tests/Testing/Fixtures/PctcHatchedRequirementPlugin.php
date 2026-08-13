<?php
/**
 * @author Joe Huss <detain@interserver.net>
 * @copyright 2026
 * @package MyAdmin
 * @category Testing
 */

namespace Tests\MyAdmin\Plugins\Testing\Fixtures;

/**
 * A plugin that registers exactly one requirement, so that a `requirementRoot()` hatch is
 * the only thing standing between Tier-B-10 and a verdict.
 *
 * The source is a fixed relative path. Whether it resolves to a file is therefore decided
 * entirely by the root the repo names — which is the property the escape-hatch tests need:
 * one root makes this plugin green, another makes it report a dangling path, and neither
 * verdict is about the plugin. That asymmetry is what makes the hatch worth auditing.
 *
 * Lives beside the other fixtures rather than at the bottom of a test file: a
 * `PluginContractTestCase` subclass in a `*Test.php` file is collected and run by PHPUnit.
 */
class PctcHatchedRequirementPlugin
{
    /** The one source registered, relative to whatever root is in effect. */
    const SOURCE = '/src/hatched.php';

    /** @var string */
    public static $name = 'Pctc Hatched Requirement Fixture';

    /** @var string */
    public static $description = 'plugin fixture with exactly one requirement path';

    /** @var string */
    public static $help = 'nothing to configure';

    /** @var string */
    public static $type = 'module';

    /** @var string */
    public static $module = 'pctchatched';

    /**
     * @return array<string,array<int,string>>
     */
    public static function getHooks()
    {
        return ['function.requirements' => [__CLASS__, 'getRequirements']];
    }

    /**
     * Untyped `$event` on purpose: the inspector passes Symfony's real `GenericEvent` where
     * the component is installed and a `SubjectEvent` where it is not, and this fixture has
     * to behave identically in both.
     *
     * @param mixed $event
     * @return void
     */
    public static function getRequirements($event)
    {
        $event->getSubject()->add_requirement('hatched_thing', self::SOURCE);
    }
}
