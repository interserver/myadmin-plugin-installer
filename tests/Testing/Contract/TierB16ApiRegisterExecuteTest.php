<?php
/**
 * @author Joe Huss <detain@interserver.net>
 * @copyright 2026
 * @package MyAdmin
 * @category Testing
 */

namespace Tests\MyAdmin\Plugins\Testing\Contract;

use MyAdmin\Plugins\Testing\Bootstrap;
use MyAdmin\Plugins\Testing\Contract\Finding;
use MyAdmin\Plugins\Testing\Contract\PluginSubject;
use MyAdmin\Plugins\Testing\Contract\TierB16ApiRegisterExecute;
use MyAdmin\Plugins\Testing\Fakes\FakeApp;
use MyAdmin\Plugins\Testing\Harness;
use PHPUnit\Framework\TestCase;

// ---------------------------------------------------------------------------------
// Fixtures.
//
// Declared in this file rather than under tests/Testing/Fixtures/ so their names cannot
// collide with another Tier's — `include_once` in one process makes a duplicate class name
// fatal. Every name here is prefixed `TierB16`.
// ---------------------------------------------------------------------------------

/**
 * Stands in for `Symfony\Component\EventDispatcher\GenericEvent`, which this package does
 * not depend on. Constructible from a subject, and `getSubject()` — the two things
 * {@see \MyAdmin\Plugins\Testing\Contract\SubjectEvent::argumentsFor()} needs.
 */
class TierB16Event
{
    /** @var object */
    private $subject;

    /**
     * @param object $subject
     */
    public function __construct($subject)
    {
        $this->subject = $subject;
    }

    /**
     * @return object
     */
    public function getSubject()
    {
        return $this->subject;
    }
}

/**
 * The shape all nine fleet packages have: `api.register` wired to `apiRegister`, which
 * registers a complex type and a call against it.
 */
class TierB16GoodPlugin
{
    /**
     * @return array<string,array{0:string,1:string}>
     */
    public static function getHooks()
    {
        return ['api.register' => [self::class, 'apiRegister']];
    }

    /**
     * @param TierB16Event $event
     * @return void
     */
    public static function apiRegister(TierB16Event $event)
    {
        api_register_array_array('b16_things', 'b16_thing');
        api_register_array('b16_thing', ['id' => 'int', 'name' => 'string']);
        api_register('get_b16_things', [], ['return' => 'b16_things'], 'Lists things.', false);
        api_register('buy_b16_thing', ['id' => 'int'], ['return' => 'result_status'], 'Buys one.');
    }
}

/** Registered on the hook, and registers nothing — `myadmin-webhosting-module`'s real shape. */
class TierB16EmptyPlugin
{
    /**
     * @return array<string,array{0:string,1:string}>
     */
    public static function getHooks()
    {
        return ['api.register' => [self::class, 'apiRegister']];
    }

    /**
     * @param TierB16Event $event
     * @return void
     */
    public static function apiRegister(TierB16Event $event)
    {
    }
}

/** Declares the handler but wires no hook to it: dead code, not an empty API surface. */
class TierB16OrphanedPlugin
{
    /**
     * @return array<string,array{0:string,1:string}>
     */
    public static function getHooks()
    {
        return ['function.requirements' => [self::class, 'getRequirements']];
    }

    /**
     * @param TierB16Event $event
     * @return void
     */
    public static function getRequirements(TierB16Event $event)
    {
    }

    /**
     * @param TierB16Event $event
     * @return void
     */
    public static function apiRegister(TierB16Event $event)
    {
    }
}

/** No API surface at all — 62 of 71 fleet packages. */
class TierB16NoHandlerPlugin
{
    /**
     * @return array<string,mixed>
     */
    public static function getHooks()
    {
        return [];
    }
}

/** A handler that fatals on a helper only core provides. */
class TierB16ThrowingPlugin
{
    /**
     * @return array<string,array{0:string,1:string}>
     */
    public static function getHooks()
    {
        return ['api.register' => [self::class, 'apiRegister']];
    }

    /**
     * @param TierB16Event $event
     * @return void
     */
    public static function apiRegister(TierB16Event $event)
    {
        throw new \RuntimeException('Call to undefined function api_multi_register()');
    }
}

/** A handler that prints. B-15 never runs this method, so B-16 has to report the bytes. */
class TierB16PrintingPlugin
{
    /**
     * @return array<string,array{0:string,1:string}>
     */
    public static function getHooks()
    {
        return ['api.register' => [self::class, 'apiRegister']];
    }

    /**
     * @param TierB16Event $event
     * @return void
     */
    public static function apiRegister(TierB16Event $event)
    {
        echo 'registering the api';
        api_register('b16_printer', [], ['return' => 'string'], 'Prints.', false);
    }
}

/** Not public static, so the callable core dispatches can never invoke it. */
class TierB16PrivateHandlerPlugin
{
    /**
     * @return array<string,array{0:string,1:string}>
     */
    public static function getHooks()
    {
        return ['api.register' => [self::class, 'apiRegister']];
    }

    /**
     * @param TierB16Event $event
     * @return void
     */
    protected static function apiRegister(TierB16Event $event)
    {
    }
}

/** Every shape defect the inspector knows how to name, in one handler. */
class TierB16MalformedPlugin
{
    /**
     * @return array<string,array{0:string,1:string}>
     */
    public static function getHooks()
    {
        return ['api.register' => [self::class, 'apiRegister']];
    }

    /**
     * @param TierB16Event $event
     * @return void
     */
    public static function apiRegister(TierB16Event $event)
    {
        api_register('', [], ['return' => 'string'], 'Nameless.');
        api_register('b16_bad_input', 'not an array', ['return' => 'string'], 'Bad input.');
        api_register('b16_bad_type', ['id' => 42], ['return' => 'string'], 'Non-string type.');
        api_register('b16_bad_return', [], ['return' => null], 'Non-string return.');
    }
}

/** Registers the same names twice — the silent-overwrite and double-register defects. */
class TierB16DuplicatePlugin
{
    /**
     * @return array<string,array{0:string,1:string}>
     */
    public static function getHooks()
    {
        return ['api.register' => [self::class, 'apiRegister']];
    }

    /**
     * @param TierB16Event $event
     * @return void
     */
    public static function apiRegister(TierB16Event $event)
    {
        api_register_array('b16_dup_type', ['a' => 'int']);
        api_register_array('b16_dup_type', ['b' => 'string']);
        api_register('b16_dup_call', [], ['return' => 'string'], 'One.');
        api_register('b16_dup_call', [], ['return' => 'int'], 'Two.');
    }
}

/**
 * A {@see \MyAdmin\Plugins\Testing\Fakes\FakeApi} that also mirrors every registration into
 * statics the harness reset cannot clear.
 *
 * Needed because the inspector is *correctly* isolated: it calls
 * {@see \MyAdmin\Plugins\Testing\Contract\SubjectEvent::releaseHarness()} before returning, so
 * by the time a test looks at `Harness::api()` the registry is empty again — which is the
 * property that stops package *n* from satisfying package *n+1*'s assertion. Without a mirror,
 * "the handler really ran and the stubs really reached the harness" would be unobservable, and
 * an inspector that quietly stopped invoking anything would pass every other test in this file.
 */
class TierB16SpyApi extends \MyAdmin\Plugins\Testing\Fakes\FakeApi
{
    /** @var array<int,string> */
    public static $calls = [];

    /** @var array<int,string> */
    public static $arrays = [];

    /** @var array<int,string> */
    public static $arrayArrays = [];

    /**
     * @return void
     */
    public static function forget()
    {
        self::$calls = [];
        self::$arrays = [];
        self::$arrayArrays = [];
    }

    /**
     * @param string $function
     * @param mixed  $input
     * @param mixed  $output
     * @param string $label
     * @param bool   $logged_in
     * @param bool   $wrap
     * @return void
     */
    public function api_register($function, $input, $output, $label = '', $logged_in = true, $wrap = true)
    {
        self::$calls[] = (string)$function;
        parent::api_register($function, $input, $output, $label, $logged_in, $wrap);
    }

    /**
     * @param string $function
     * @param mixed  $data
     * @return void
     */
    public function api_register_array($function, $data)
    {
        self::$arrays[] = (string)$function;
        parent::api_register_array($function, $data);
    }

    /**
     * @param string $arraysName
     * @param mixed  $targetArray
     * @return void
     */
    public function api_register_array_array($arraysName, $targetArray)
    {
        self::$arrayArrays[] = (string)$arraysName;
        parent::api_register_array_array($arraysName, $targetArray);
    }
}

/**
 * Pins B-16 — the catalogue's only executor of `apiRegister()`.
 *
 * ---------------------------------------------------------------------------------
 * WHY THE FIXTURES REGISTER THROUGH THE GLOBALS
 * ---------------------------------------------------------------------------------
 * Every fixture handler calls the bare `api_register*()` functions, exactly as all nine
 * fleet handlers do, rather than reaching through the event subject. That is the whole
 * mechanism under test: if `Bootstrap::init()` stopped loading the stubs, or the stubs
 * stopped writing into `Harness::api()`, these handlers would fatal with "Call to undefined
 * function" and the inspector would report nine plugin defects instead of one harness gap.
 *
 * @covers \MyAdmin\Plugins\Testing\Contract\TierB16ApiRegisterExecute
 * @covers \MyAdmin\Plugins\Testing\Fakes\FakeApi
 */
class TierB16ApiRegisterExecuteTest extends TestCase
{
    /** @var TierB16ApiRegisterExecute */
    private $inspector;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        Bootstrap::init(['module' => 'b16harness']);
        Harness::reset();
        $this->inspector = new TierB16ApiRegisterExecute();
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        Harness::reset();
        Harness::setAcl([]);
        Harness::set('api', new \MyAdmin\Plugins\Testing\Fakes\FakeApi());
        TierB16SpyApi::forget();
        FakeApp::setIma('client');
    }

    /**
     * Installs the mirroring fake and clears what it saw last time.
     *
     * @return void
     */
    private function spyOnRegistrations()
    {
        TierB16SpyApi::forget();
        Harness::set('api', new TierB16SpyApi());
    }

    /**
     * @param array<int,Finding> $findings
     * @return string
     */
    private function describe(array $findings)
    {
        $lines = [];
        foreach ($findings as $finding) {
            $lines[] = $finding->describe();
        }
        return implode("\n", $lines);
    }

    /**
     * @param string $class
     * @return array<int,Finding>
     */
    private function inspect($class)
    {
        return $this->inspector->inspect(new PluginSubject($class));
    }

    /**
     * @return void
     */
    public function testIdentifiesItselfWithTheCatalogueId()
    {
        $this->assertSame('B-16', $this->inspector->id());
        $this->assertNotSame('', $this->inspector->title());
    }

    // -----------------------------------------------------------------------
    // Pass path
    // -----------------------------------------------------------------------

    /**
     * @return void
     */
    public function testPassesForAHandlerThatRegistersAWellFormedSurface()
    {
        $findings = $this->inspect(TierB16GoodPlugin::class);

        $this->assertSame([], $findings, $this->describe($findings));
    }

    /**
     * The assertion is worthless unless the handler really ran and the registrations really
     * landed. Without this, an inspector that never invoked anything would pass every test
     * above.
     *
     * @return void
     */
    public function testTheHandlerIsActuallyExecutedAndItsRegistrationsObserved()
    {
        $this->spyOnRegistrations();

        $this->inspect(TierB16GoodPlugin::class);

        $this->assertSame(
            ['get_b16_things', 'buy_b16_thing'],
            TierB16SpyApi::$calls,
            'the handler must have run and the api_register() stub must reach the harness'
        );
        $this->assertSame(['b16_thing'], TierB16SpyApi::$arrays);
        $this->assertSame(['b16_things'], TierB16SpyApi::$arrayArrays);
    }

    /**
     * Package *n* must not satisfy assertion 2 on package *n-1*'s registrations. The fleet
     * self-check runs 71 packages back to back in one process, so the inspector both starts
     * and finishes with an empty registry.
     *
     * @return void
     */
    public function testOnePackagesRegistrationsCannotCarryIntoTheNext()
    {
        $this->spyOnRegistrations();

        $this->assertSame([], $this->inspect(TierB16GoodPlugin::class), 'the premise is a package that registers');
        $this->assertCount(2, TierB16SpyApi::$calls, 'and that really did register');
        $this->assertSame(
            0,
            Harness::api()->registrationCount(),
            'the inspector must leave the registry empty for whatever runs next'
        );

        $findings = $this->inspect(TierB16EmptyPlugin::class);

        $this->assertCount(1, $findings, $this->describe($findings));
        $this->assertTrue($findings[0]->isFailure(), 'the previous package\'s calls must not carry over');
        $this->assertSame(0, $findings[0]->context()['registrations']);
    }

    // -----------------------------------------------------------------------
    // Assertion 1 — it does not throw
    // -----------------------------------------------------------------------

    /**
     * @return void
     */
    public function testAThrowingHandlerIsAFailureRatherThanAnEscapedException()
    {
        $findings = $this->inspect(TierB16ThrowingPlugin::class);

        $this->assertCount(1, $findings);
        $this->assertTrue($findings[0]->isFailure());
        $this->assertStringContainsString('threw RuntimeException', $findings[0]->message());
        $this->assertStringContainsString('api_multi_register', $findings[0]->message());
    }

    /**
     * B-15 executes `getSettings()` and `getMenu()` only, so under R-8's rule this inspector
     * may not discard its bytes — nothing else in the catalogue would ever see them.
     *
     * @return void
     */
    public function testPrintedBytesAreReportedHereBecauseNoOtherInspectorRunsThisHandler()
    {
        $findings = $this->inspect(TierB16PrintingPlugin::class);

        $this->assertCount(1, $findings, $this->describe($findings));
        $this->assertTrue($findings[0]->isFailure());
        $this->assertStringContainsString('registering the api', $findings[0]->message());
        $this->assertStringContainsString('B-15 executes getSettings() and getMenu() only', $findings[0]->message());
        $this->assertSame('apiRegister', $findings[0]->context()['site']);
    }

    // -----------------------------------------------------------------------
    // Assertion 2 — it registers something, unless nothing dispatches it
    // -----------------------------------------------------------------------

    /**
     * @return void
     */
    public function testAHandlerThatIsRegisteredAndRegistersNothingIsAFailure()
    {
        $findings = $this->inspect(TierB16EmptyPlugin::class);

        $this->assertCount(1, $findings);
        $this->assertTrue($findings[0]->isFailure());
        $this->assertStringContainsString('registered no API calls', $findings[0]->message());
        $this->assertSame('api.register', $findings[0]->context()['hookKeys']);
    }

    /**
     * The reachability gate, and the whole reason it exists: with no hook naming the handler
     * there is no surface for core to ask for, so "it registered nothing" is inconsequential
     * rather than defective. Not a pass either — nothing was verified.
     *
     * @return void
     */
    public function testAnOrphanedHandlerIsNotApplicableRatherThanFailedOrPassed()
    {
        $findings = $this->inspect(TierB16OrphanedPlugin::class);

        $this->assertCount(1, $findings);
        $this->assertTrue($findings[0]->isNotApplicable(), $this->describe($findings));
        $this->assertStringContainsString('is ORPHANED', $findings[0]->message());
        $this->assertTrue($findings[0]->context()['orphaned']);
        $this->assertTrue($findings[0]->context()['executed'], 'the handler is still executed');
    }

    /**
     * 62 of 71 packages. Reflection answered the question outright, so this is not a skip.
     *
     * @return void
     */
    public function testAPackageWithNoHandlerIsNotApplicable()
    {
        $findings = $this->inspect(TierB16NoHandlerPlugin::class);

        $this->assertCount(1, $findings);
        $this->assertTrue($findings[0]->isNotApplicable());
        $this->assertStringContainsString('registers no API surface', $findings[0]->message());
    }

    /**
     * @return void
     */
    public function testANonPublicStaticHandlerIsAFailure()
    {
        $findings = $this->inspect(TierB16PrivateHandlerPlugin::class);

        $this->assertCount(1, $findings);
        $this->assertTrue($findings[0]->isFailure());
        $this->assertStringContainsString('is not public static', $findings[0]->message());
    }

    /**
     * @return void
     */
    public function testAnUnloadableClassIsSkipped()
    {
        $findings = $this->inspect('Tests\\MyAdmin\\Plugins\\Testing\\Contract\\TierB16NoSuchPlugin');

        $this->assertCount(1, $findings);
        $this->assertTrue($findings[0]->isSkipped());
    }

    // -----------------------------------------------------------------------
    // Assertion 3 — what was registered is well-formed
    // -----------------------------------------------------------------------

    /**
     * Every shape problem is reported, not just the first: a handler registering fourteen
     * calls can have more than one wrong, and a reader fixing them one re-run at a time is
     * how a nine-package sweep turns into a week.
     *
     * @return void
     */
    public function testEveryShapeProblemIsReportedAtOnce()
    {
        $findings = $this->inspect(TierB16MalformedPlugin::class);
        $messages = $this->describe($findings);

        $this->assertCount(4, $findings, $messages);
        foreach ($findings as $finding) {
            $this->assertTrue($finding->isFailure());
        }
        $this->assertStringContainsString('names its function as', $messages);
        $this->assertStringContainsString('passes string as its input', $messages);
        $this->assertStringContainsString('declares input["id"] as 42', $messages);
        $this->assertStringContainsString('declares output["return"] as NULL', $messages);
    }

    /**
     * A duplicate `api_register()` reaches `api_prepare()` twice; a duplicate
     * `api_register_array()` silently discards the first definition. Neither produces any
     * diagnostic in production, and both are inside one package's own control.
     *
     * @return void
     */
    public function testDuplicateRegistrationsAreReported()
    {
        $findings = $this->inspect(TierB16DuplicatePlugin::class);
        $messages = $this->describe($findings);

        $this->assertCount(2, $findings, $messages);
        $this->assertStringContainsString('registers "b16_dup_call" via api_register() 2 times', $messages);
        $this->assertStringContainsString('registered twice under one name', $messages);
        $this->assertStringContainsString('registers "b16_dup_type" via api_register_array() 2 times', $messages);
        $this->assertStringContainsString('silently replaces the earlier one', $messages);
    }

    /**
     * The duplicate check has to read the call log, not the stored map — the map is exactly
     * where the first definition has already been lost.
     *
     * @return void
     */
    public function testTheDuplicateArrayIsInvisibleInTheStoredMapItIsDetectedFrom()
    {
        $api = new \MyAdmin\Plugins\Testing\Fakes\FakeApi();
        $api->api_register_array('b16_dup_type', ['a' => 'int']);
        $api->api_register_array('b16_dup_type', ['b' => 'string']);

        $this->assertCount(
            1,
            $api->apiArrays(),
            'the map holds one entry for the two registrations — which is the defect'
        );
        $this->assertSame(['b' => 'string'], $api->apiArrays()['b16_dup_type'], 'the first is lost');
        $this->assertCount(2, $api->argsFor('api_register_array'), 'and only the call log still has both');
    }

    /**
     * The fake stores what core stores, in the shape core stores it — an append-only list for
     * calls and name-keyed maps for the two type registries. A fake that deduplicated the list
     * would make the duplicate-call defect undetectable by construction.
     *
     * @return void
     */
    public function testTheFakeMirrorsCoresStorageShape()
    {
        $api = new \MyAdmin\Plugins\Testing\Fakes\FakeApi();
        $api->api_register('one', ['id' => 'int'], ['return' => 'string'], 'first', false, false);
        $api->api_register('one', [], ['return' => 'int'], 'second');
        $api->api_register_array_array('things', 'thing');

        $this->assertSame(['one', 'one'], $api->registeredFunctions(), 'api_calls is a list, not a set');
        $this->assertSame(
            [
                'function' => 'one',
                'input' => ['id' => 'int'],
                'output' => ['return' => 'string'],
                'label' => 'first',
                'logged_in' => false,
                'wrap' => false,
            ],
            $api->apiCalls()[0],
            'the six keys are core\'s, including the defaults an omitted argument means'
        );
        $this->assertTrue($api->apiCalls()[1]['logged_in'], 'omitted $logged_in means session-checked');
        $this->assertTrue($api->apiCalls()[1]['wrap'], 'omitted $wrap means prefixed with api_');
        $this->assertSame(['things' => 'thing'], $api->apiArrayArrays());
        $this->assertSame(3, $api->registrationCount());

        $api->reset();
        $this->assertSame(0, $api->registrationCount());
        $this->assertSame([], $api->calls(), 'reset drops the call log too');
    }
}
