<?php
/**
 * @author Joe Huss <detain@interserver.net>
 * @copyright 2026
 * @package MyAdmin
 * @category Testing
 */

namespace Tests\MyAdmin\Plugins\Testing\Contract;

use MyAdmin\Plugins\Testing\Contract\Finding;
use MyAdmin\Plugins\Testing\Contract\PluginSubject;
use MyAdmin\Plugins\Testing\Contract\TierB9HookTargetsResolve;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\GenericEvent;

/**
 * Note what these fixtures do **not** do: declare a stand-in
 * `Symfony\Component\EventDispatcher\GenericEvent`. The component is not a dependency of this
 * package, and a test double occupying a production class name is the D2 failure mode. It is
 * also unnecessary — a parameter may be type-hinted on a class that cannot be loaded, and
 * reflection still reports the declared type as a string, which is exactly the property the
 * inspector's exact-match arm relies on. `TierB9CleanPlugin` below hints `GenericEvent` and
 * passes in an environment where that class does not exist, which pins that property.
 *
 * The subclass arm needs a real hierarchy, so it is exercised through the inspector's
 * `eventClass()` seam against a local base class instead.
 *
 * @covers \MyAdmin\Plugins\Testing\Contract\TierB9HookTargetsResolve
 */
class TierB9HookTargetsResolveTest extends TestCase
{
    /** @var TierB9HookTargetsResolve */
    private $inspector;

    /** @var string|null */
    private $scratchDir;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $this->inspector = new TierB9HookTargetsResolve();
        $this->scratchDir = null;
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        if ($this->scratchDir !== null && is_dir($this->scratchDir)) {
            foreach (glob($this->scratchDir.'/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($this->scratchDir);
        }
        $this->scratchDir = null;
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
     * @param array<int,Finding> $findings
     * @return array<int,string>
     */
    private function messages(array $findings)
    {
        $messages = [];
        foreach ($findings as $finding) {
            $messages[] = $finding->message();
        }
        return $messages;
    }

    // -------------------------------------------------------------------
    // Identity
    // -------------------------------------------------------------------

    /**
     * @return void
     */
    public function testReportsItsCatalogueId()
    {
        $this->assertSame('B-9', $this->inspector->id());
        $this->assertNotSame('', $this->inspector->title());
    }

    // -------------------------------------------------------------------
    // Pass path
    // -------------------------------------------------------------------

    /**
     * Also pins that the check needs no autoloading to accept the canonical hint: these
     * handlers are typed on a class this repo cannot load.
     *
     * @return void
     */
    public function testWellFormedTargetsProduceNoFindings()
    {
        $this->assertSame([], $this->inspect(TierB9CleanPlugin::class));
    }

    /**
     * @return void
     */
    public function testSubclassOfTheEventClassIsAccepted()
    {
        $inspector = new TierB9RetargetedInspector();
        $this->assertSame(
            [],
            $inspector->inspect(new PluginSubject(TierB9SubclassHintPlugin::class))
        );
    }

    /**
     * The same seam, pointed at a plugin whose hint is an unrelated class, must still fail —
     * otherwise the test above would pass for the wrong reason.
     *
     * @return void
     */
    public function testRetargetedInspectorStillRejectsAnUnrelatedClass()
    {
        $inspector = new TierB9RetargetedInspector();
        $findings = $inspector->inspect(new PluginSubject(TierB9WrongClassHintPlugin::class));
        $this->assertCount(1, $findings);
        $this->assertTrue($findings[0]->isFailure());
    }

    // -------------------------------------------------------------------
    // Gate G2: "validated by deliberately renaming a handler in a scratch
    // copy and confirming the assertion fires"
    // -------------------------------------------------------------------

    /**
     * Writes the same plugin twice — once intact, once with the handler's **declaration**
     * renamed and the hook entry left pointing at the old name — and asserts the check is
     * silent on the first and fires on the second.
     *
     * Doing it on disk from one shared source, rather than hand-writing two fixture classes,
     * is what makes it evidence: the two inputs differ by exactly the rename.
     *
     * @return void
     */
    public function testRenamingAHandlerInAScratchCopyFiresTheAssertion()
    {
        $this->scratchDir = sys_get_temp_dir().'/tierb9-scratch-'.getmypid().'-'.mt_rand();
        mkdir($this->scratchDir, 0777, true);

        $template = "<?php\n"
            ."namespace Tests\\MyAdmin\\Plugins\\Testing\\Contract\\ScratchB9;\n"
            ."use Symfony\\Component\\EventDispatcher\\GenericEvent;\n"
            ."class __CLASSNAME__\n"
            ."{\n"
            ."    public static \$module = 'scratch';\n"
            ."    public static function getHooks()\n"
            ."    {\n"
            ."        return ['scratch.settings' => [__CLASS__, 'getSettings']];\n"
            ."    }\n"
            ."    public static function getSettings(GenericEvent \$event)\n"
            ."    {\n"
            ."    }\n"
            ."}\n";

        $intact = str_replace('__CLASSNAME__', 'IntactPlugin', $template);
        $renamed = str_replace(
            ['__CLASSNAME__', 'function getSettings(GenericEvent'],
            ['RenamedPlugin', 'function getSettingsHandler(GenericEvent'],
            $template
        );

        // The rename must be the *only* difference, and it must not have touched the hook.
        $this->assertStringContainsString("'getSettings'", $renamed);
        $this->assertStringNotContainsString('function getSettings(', $renamed);

        file_put_contents($this->scratchDir.'/intact.php', $intact);
        file_put_contents($this->scratchDir.'/renamed.php', $renamed);
        require_once $this->scratchDir.'/intact.php';
        require_once $this->scratchDir.'/renamed.php';

        $this->assertSame(
            [],
            $this->inspect('Tests\MyAdmin\Plugins\Testing\Contract\ScratchB9\IntactPlugin'),
            'the unmodified scratch copy must pass, or the rename proves nothing'
        );

        $findings = $this->inspect('Tests\MyAdmin\Plugins\Testing\Contract\ScratchB9\RenamedPlugin');
        $this->assertCount(1, $findings);
        $this->assertTrue($findings[0]->isFailure());
        $this->assertStringContainsString('does not exist', $findings[0]->message());
        $this->assertStringContainsString('getSettings', $findings[0]->message());
        $context = $findings[0]->context();
        $this->assertSame('scratch.settings', $context['hook']);
        $this->assertSame('getSettings', $context['method']);
    }

    // -------------------------------------------------------------------
    // Individual defects
    // -------------------------------------------------------------------

    /**
     * @return void
     */
    public function testMissingMethodIsReportedWithHookClassAndMethod()
    {
        $findings = $this->inspect(TierB9MissingMethodPlugin::class);
        $this->assertCount(1, $findings);
        $this->assertTrue($findings[0]->isFailure());
        $context = $findings[0]->context();
        $this->assertSame('scratch.gone', $context['hook']);
        $this->assertSame(TierB9MissingMethodPlugin::class, $context['class']);
        $this->assertSame('handlerThatWasDeleted', $context['method']);
    }

    /**
     * A missing *class* and a missing *method* are different fixes, so they must not share a
     * message. Asserting only "does not exist" would pass either way — and did, until a
     * mutation of the `class_exists()` guard survived.
     *
     * @return void
     */
    public function testMissingTargetClassIsReportedAsAMissingClass()
    {
        $findings = $this->inspect(TierB9MissingClassPlugin::class);
        $this->assertCount(1, $findings);
        $this->assertTrue($findings[0]->isFailure());
        $this->assertStringContainsString(
            'class Tests\MyAdmin\Plugins\NoSuchHandlerClass does not exist',
            $findings[0]->message()
        );

        $missingMethod = $this->inspect(TierB9MissingMethodPlugin::class);
        $this->assertStringNotContainsString('class ', $missingMethod[0]->message());
    }

    /**
     * @return void
     */
    public function testNonStaticHandlerIsReported()
    {
        $findings = $this->inspect(TierB9NonStaticPlugin::class);
        $this->assertCount(1, $findings);
        $this->assertTrue($findings[0]->isFailure());
        $this->assertStringContainsString('not static', $findings[0]->message());
    }

    /**
     * @return void
     */
    public function testNonPublicHandlerIsReported()
    {
        $findings = $this->inspect(TierB9PrivateHandlerPlugin::class);
        $this->assertCount(1, $findings);
        $this->assertTrue($findings[0]->isFailure());
        $this->assertStringContainsString('not public', $findings[0]->message());
        $this->assertSame('private', $findings[0]->context()['found']);
    }

    /**
     * @return void
     */
    public function testTooManyParametersIsReported()
    {
        $findings = $this->inspect(TierB9TwoParameterPlugin::class);
        $this->assertCount(1, $findings);
        $this->assertTrue($findings[0]->isFailure());
        $this->assertStringContainsString('takes 2 parameters', $findings[0]->message());
        $this->assertSame(2, $findings[0]->context()['found']);
    }

    /**
     * An optional second parameter still deviates from the contract: the handler is no longer
     * interchangeable with every other listener, and the extra argument can only ever take
     * its default.
     *
     * @return void
     */
    public function testOptionalSecondParameterIsStillWrongArity()
    {
        $findings = $this->inspect(TierB9OptionalSecondParameterPlugin::class);
        $this->assertCount(1, $findings);
        $this->assertStringContainsString('takes 2 parameters', $findings[0]->message());
    }

    /**
     * @return void
     */
    public function testZeroParameterHandlerIsReportedOnceForArityOnly()
    {
        $findings = $this->inspect(TierB9NoParameterPlugin::class);
        $this->assertCount(1, $findings);
        $this->assertStringContainsString('takes 0 parameters', $findings[0]->message());
    }

    /**
     * @return void
     */
    public function testUntypedParameterIsDistinguishedFromAWronglyTypedOne()
    {
        $untyped = $this->inspect(TierB9UntypedParameterPlugin::class);
        $wrong = $this->inspect(TierB9WrongClassHintPlugin::class);

        $this->assertCount(1, $untyped);
        $this->assertCount(1, $wrong);
        $this->assertTrue($untyped[0]->isFailure());
        $this->assertTrue($wrong[0]->isFailure());

        // The two messages must not merely differ, they must say different things: an
        // untyped parameter is "has no type declaration", a wrong one is "is type-hinted X".
        $this->assertStringContainsString('has no type declaration', $untyped[0]->message());
        $this->assertStringNotContainsString('is type-hinted', $untyped[0]->message());
        $this->assertSame('no type declaration', $untyped[0]->context()['found']);

        $this->assertStringContainsString('is type-hinted', $wrong[0]->message());
        $this->assertStringNotContainsString('has no type declaration', $wrong[0]->message());
        $this->assertStringContainsString('TierB9NotAnEvent', $wrong[0]->context()['found']);
    }

    /**
     * @return void
     */
    public function testBuiltinTypeHintIsReported()
    {
        $findings = $this->inspect(TierB9BuiltinHintPlugin::class);
        $this->assertCount(1, $findings);
        $this->assertSame('array', $findings[0]->context()['found']);
    }

    /**
     * @return void
     */
    public function testEachDistinctProblemGetsItsOwnFinding()
    {
        $findings = $this->inspect(TierB9ThreeProblemsPlugin::class);
        $this->assertCount(3, $findings);

        $joined = implode("\n", $this->messages($findings));
        $this->assertStringContainsString('not static', $joined);
        $this->assertStringContainsString('takes 2 parameters', $joined);
        $this->assertStringContainsString('no type declaration', $joined);
    }

    /**
     * @return void
     */
    public function testEveryHookEntryIsWalkedNotJustTheFirst()
    {
        $findings = $this->inspect(TierB9TwoBadHooksPlugin::class);
        $this->assertCount(2, $findings);
        $hooks = [$findings[0]->context()['hook'], $findings[1]->context()['hook']];
        sort($hooks);
        $this->assertSame(['scratch.one', 'scratch.two'], $hooks);
    }

    // -------------------------------------------------------------------
    // Skips — "never ran" must not read as "passed"
    // -------------------------------------------------------------------

    /**
     * @return void
     */
    public function testUnloadablePluginIsSkippedNotPassed()
    {
        $findings = $this->inspect('Tests\MyAdmin\Plugins\Testing\Contract\NoSuchPluginAtAll');
        $this->assertCount(1, $findings);
        $this->assertTrue($findings[0]->isSkipped());
        $this->assertFalse($findings[0]->isFailure());
    }

    /**
     * @return void
     */
    public function testMalformedTargetIsSkippedAndDefersToTierA8()
    {
        $findings = $this->inspect(TierB9MalformedTargetPlugin::class);
        $this->assertCount(1, $findings);
        $this->assertTrue($findings[0]->isSkipped());
        $this->assertStringContainsString('A-8', $findings[0]->message());
    }

    /**
     * @return void
     */
    public function testThrowingGetHooksIsSkippedRatherThanPropagated()
    {
        $findings = $this->inspect(TierB9ThrowingHooksPlugin::class);
        $this->assertCount(1, $findings);
        $this->assertTrue($findings[0]->isSkipped());
        $this->assertStringContainsString('boom', $findings[0]->message());
    }

    /**
     * @return void
     */
    public function testPluginWithoutGetHooksIsSkipped()
    {
        $findings = $this->inspect(TierB9NoHooksMethodPlugin::class);
        $this->assertCount(1, $findings);
        $this->assertTrue($findings[0]->isSkipped());
    }

    /**
     * @return void
     */
    public function testNonArrayHookTableIsSkipped()
    {
        $findings = $this->inspect(TierB9NonArrayHooksPlugin::class);
        $this->assertCount(1, $findings);
        $this->assertTrue($findings[0]->isSkipped());
        $this->assertSame('string', $findings[0]->context()['returned']);
    }

    /**
     * @return void
     */
    public function testEmptyHookTablePassesVacuously()
    {
        $this->assertSame([], $this->inspect(TierB9EmptyHooksPlugin::class));
    }
}

// -----------------------------------------------------------------------
// Fixtures. Names are unique to this file: colliding class names in one
// process are fatal, and the whole suite runs in one process.
// -----------------------------------------------------------------------

/**
 * Retargets the accepted base class so the subclass arm can be exercised without
 * symfony/event-dispatcher. See `TierB9HookTargetsResolve::eventClass()`.
 */
class TierB9RetargetedInspector extends TierB9HookTargetsResolve
{
    /**
     * @return string
     */
    protected function eventClass()
    {
        return TierB9LocalBaseEvent::class;
    }
}

/** Stands in the role GenericEvent plays, for the retargeted inspector only. */
class TierB9LocalBaseEvent
{
}

/** A legitimate subclass of the retargeted base. */
class TierB9LocalDerivedEvent extends TierB9LocalBaseEvent
{
}

/** A handler class that is not the plugin itself, to prove cross-class targets resolve. */
class TierB9Handlers
{
    /**
     * @param GenericEvent $event
     * @return void
     */
    public static function handle(GenericEvent $event)
    {
    }
}

/** Related to neither event class, used for the wrong-type-hint fixtures. */
class TierB9NotAnEvent
{
}

class TierB9CleanPlugin
{
    /**
     * @return array<string,array<int,string>>
     */
    public static function getHooks()
    {
        return [
            'scratch.settings' => [__CLASS__, 'getSettings'],
            'scratch.other' => [TierB9Handlers::class, 'handle'],
        ];
    }

    /**
     * @param GenericEvent $event
     * @return void
     */
    public static function getSettings(GenericEvent $event)
    {
    }
}

class TierB9SubclassHintPlugin
{
    /**
     * @return array<string,array<int,string>>
     */
    public static function getHooks()
    {
        return ['scratch.settings' => [__CLASS__, 'getSettings']];
    }

    /**
     * @param TierB9LocalDerivedEvent $event
     * @return void
     */
    public static function getSettings(TierB9LocalDerivedEvent $event)
    {
    }
}

class TierB9MissingMethodPlugin
{
    /**
     * @return array<string,array<int,string>>
     */
    public static function getHooks()
    {
        return ['scratch.gone' => [__CLASS__, 'handlerThatWasDeleted']];
    }
}

class TierB9MissingClassPlugin
{
    /**
     * @return array<string,array<int,string>>
     */
    public static function getHooks()
    {
        return ['scratch.gone' => ['Tests\MyAdmin\Plugins\NoSuchHandlerClass', 'handle']];
    }
}

class TierB9NonStaticPlugin
{
    /**
     * @return array<string,array<int,string>>
     */
    public static function getHooks()
    {
        return ['scratch.settings' => [__CLASS__, 'getSettings']];
    }

    /**
     * @param GenericEvent $event
     * @return void
     */
    public function getSettings(GenericEvent $event)
    {
    }
}

class TierB9PrivateHandlerPlugin
{
    /**
     * @return array<string,array<int,string>>
     */
    public static function getHooks()
    {
        return ['scratch.settings' => [__CLASS__, 'getSettings']];
    }

    /**
     * @param GenericEvent $event
     * @return void
     */
    private static function getSettings(GenericEvent $event)
    {
    }
}

class TierB9TwoParameterPlugin
{
    /**
     * @return array<string,array<int,string>>
     */
    public static function getHooks()
    {
        return ['scratch.settings' => [__CLASS__, 'getSettings']];
    }

    /**
     * @param GenericEvent $event
     * @param string       $extra
     * @return void
     */
    public static function getSettings(GenericEvent $event, $extra)
    {
    }
}

class TierB9OptionalSecondParameterPlugin
{
    /**
     * @return array<string,array<int,string>>
     */
    public static function getHooks()
    {
        return ['scratch.settings' => [__CLASS__, 'getSettings']];
    }

    /**
     * @param GenericEvent $event
     * @param string|null  $extra
     * @return void
     */
    public static function getSettings(GenericEvent $event, $extra = null)
    {
    }
}

class TierB9NoParameterPlugin
{
    /**
     * @return array<string,array<int,string>>
     */
    public static function getHooks()
    {
        return ['scratch.settings' => [__CLASS__, 'getSettings']];
    }

    /**
     * @return void
     */
    public static function getSettings()
    {
    }
}

class TierB9UntypedParameterPlugin
{
    /**
     * @return array<string,array<int,string>>
     */
    public static function getHooks()
    {
        return ['scratch.settings' => [__CLASS__, 'getSettings']];
    }

    /**
     * @param mixed $event
     * @return void
     */
    public static function getSettings($event)
    {
    }
}

class TierB9WrongClassHintPlugin
{
    /**
     * @return array<string,array<int,string>>
     */
    public static function getHooks()
    {
        return ['scratch.settings' => [__CLASS__, 'getSettings']];
    }

    /**
     * @param TierB9NotAnEvent $event
     * @return void
     */
    public static function getSettings(TierB9NotAnEvent $event)
    {
    }
}

class TierB9BuiltinHintPlugin
{
    /**
     * @return array<string,array<int,string>>
     */
    public static function getHooks()
    {
        return ['scratch.settings' => [__CLASS__, 'getSettings']];
    }

    /**
     * @param array<string,mixed> $event
     * @return void
     */
    public static function getSettings(array $event)
    {
    }
}

class TierB9ThreeProblemsPlugin
{
    /**
     * @return array<string,array<int,string>>
     */
    public static function getHooks()
    {
        return ['scratch.settings' => [__CLASS__, 'getSettings']];
    }

    /**
     * @param mixed $event
     * @param mixed $extra
     * @return void
     */
    public function getSettings($event, $extra)
    {
    }
}

class TierB9TwoBadHooksPlugin
{
    /**
     * @return array<string,array<int,string>>
     */
    public static function getHooks()
    {
        return [
            'scratch.one' => [__CLASS__, 'goneOne'],
            'scratch.two' => [__CLASS__, 'goneTwo'],
        ];
    }
}

class TierB9MalformedTargetPlugin
{
    /**
     * @return array<string,mixed>
     */
    public static function getHooks()
    {
        return ['scratch.settings' => 'someGlobalFunction'];
    }
}

class TierB9ThrowingHooksPlugin
{
    /**
     * @return array<string,mixed>
     */
    public static function getHooks()
    {
        throw new \RuntimeException('boom');
    }
}

class TierB9NoHooksMethodPlugin
{
}

class TierB9NonArrayHooksPlugin
{
    /**
     * @return string
     */
    public static function getHooks()
    {
        return 'not an array';
    }
}

class TierB9EmptyHooksPlugin
{
    /**
     * @return array<string,mixed>
     */
    public static function getHooks()
    {
        return [];
    }
}
