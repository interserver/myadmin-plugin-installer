<?php
/**
 * @author Joe Huss <detain@interserver.net>
 * @copyright 2026
 * @package MyAdmin
 * @category Testing
 */

namespace Tests\MyAdmin\Plugins\Testing\Fixtures;

use MyAdmin\Plugins\Testing\Contract\PluginSubject;
use MyAdmin\Plugins\Testing\ServicePluginTestCase;

/**
 * The concrete {@see ServicePluginTestCase} subclass the tests drive, with every hook
 * settable from outside.
 *
 * One configurable fixture rather than six near-identical subclasses, because the thing
 * under test is the *base class*, and a fixture per permutation would mean the next hook
 * needs another six files. The trade is that the statics are process-global, so
 * {@see reset()} exists and every test that touches one must call it from `tearDown()`.
 *
 * Lives in `Fixtures/` rather than beside the tests for the reason {@see PctcCasePlain}
 * records: PHPUnit collects every `TestCase` subclass declared in a `*Test.php` file, and a
 * fixture subclass there would be run as a test suite of its own — here, one that executes
 * fleet handlers.
 *
 * The `...ForTest()` accessors widen protected seams rather than reaching through
 * `ReflectionMethod::setAccessible()`, which would keep passing after the method stopped
 * being called by anything.
 */
class SptcCase extends ServicePluginTestCase
{
    /** @var string the plugin under inspection */
    public static $target = SptcGatedPlugin::class;

    /** @var array<int|string,mixed>|string handledTypes(), or NOT_SET */
    public static $types = ServicePluginTestCase::NOT_SET;

    /** @var array<int,int|string>|null foreignTypes(), or null for the default */
    public static $foreign = null;

    /** @var bool exercisesOwnedTypes() */
    public static $exercise = true;

    /**
     * @return string
     */
    protected function pluginClass()
    {
        return self::$target;
    }

    /**
     * @return array<int|string,mixed>|string
     */
    protected function handledTypes()
    {
        return self::$types;
    }

    /**
     * @return array<int,int|string>
     */
    protected function foreignTypes()
    {
        return self::$foreign === null ? parent::foreignTypes() : self::$foreign;
    }

    /**
     * @return bool
     */
    protected function exercisesOwnedTypes()
    {
        return self::$exercise;
    }

    /**
     * @param string $handler
     * @return array<int,\MyAdmin\Plugins\Testing\Contract\Finding>
     */
    public function actsForTest($handler)
    {
        return $this->inspectActs($handler);
    }

    /**
     * @param string $handler
     * @return array<int,\MyAdmin\Plugins\Testing\Contract\Finding>
     */
    public function inertForTest($handler)
    {
        return $this->inspectInert($handler);
    }

    /**
     * @param string $handler
     * @return array{key:string|null,defines:array<int,string>,stops:bool,source:string,readKeys:array<int,string>}
     */
    public function gateForTest($handler)
    {
        return $this->gateFor($this->contractSubject(), $handler);
    }

    /**
     * @return \MyAdmin\Plugins\Testing\Contract\PluginSubject
     */
    public function subjectForTest()
    {
        return $this->contractSubject();
    }

    /**
     * @param string                                               $id
     * @param string                                               $handler
     * @param array<int,\MyAdmin\Plugins\Testing\Contract\Finding> $findings
     * @return void
     */
    public function reportForTest($id, $handler, array $findings)
    {
        $this->reportFindings($id, $handler, $findings);
    }

    /**
     * Restores every static to its default. Call from `tearDown()`.
     *
     * @return void
     */
    public static function reset()
    {
        self::$target = SptcGatedPlugin::class;
        self::$types = ServicePluginTestCase::NOT_SET;
        self::$foreign = null;
        self::$exercise = true;
    }
}
