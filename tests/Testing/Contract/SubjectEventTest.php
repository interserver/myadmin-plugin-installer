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
use MyAdmin\Plugins\Testing\Contract\SubjectEvent;
use MyAdmin\Plugins\Testing\Fakes\FakeApp;
use MyAdmin\Plugins\Testing\Harness;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * `SubjectEvent` replaced five copies of the same code that lived inside B-10, B-11, B-12,
 * B-13 and B-15. Those inspectors only ever exercised the branches their own fleet shape
 * reaches, so several arms of the event builder were covered by nobody — mutation testing
 * found three of them surviving. Having one shared helper is what makes testing them
 * directly possible; this file does that.
 *
 * Fixtures live at the bottom with a `SubjectEventFixture` prefix — unique per file, because
 * every test in this directory shares one process.
 *
 * @covers \MyAdmin\Plugins\Testing\Contract\SubjectEvent
 */
class SubjectEventTest extends TestCase
{
    /**
     * @param string $method
     * @param object $eventSubject
     * @param array<string,mixed> $extraContext
     * @return array{args:array<int,mixed>,skip:Finding|null}
     */
    private function prepare($method, $eventSubject, array $extraContext = [])
    {
        return SubjectEvent::argumentsFor(
            new ReflectionMethod(SubjectEventFixtureHandlers::class, $method),
            $eventSubject,
            new PluginSubject(SubjectEventFixtureHandlers::class),
            'B-TEST',
            $extraContext
        );
    }

    // -----------------------------------------------------------------------
    // The instance side — the B-10 / B-11 fallback event
    // -----------------------------------------------------------------------

    /**
     * @return void
     */
    public function testCarriesTheSubjectItWasBuiltWith()
    {
        $payload = new \stdClass();
        $this->assertSame($payload, (new SubjectEvent($payload))->getSubject());
    }

    /**
     * @return void
     */
    public function testDefaultsToANullSubject()
    {
        $this->assertNull((new SubjectEvent())->getSubject());
    }

    /**
     * B-10 reads the subject back after the handler runs, because a `function.requirements`
     * handler is allowed to replace it. A `setSubject()` that did not stick would make B-10
     * inspect the wrong loader.
     *
     * @return void
     */
    public function testSubjectCanBeReplacedAndTheCallIsChainable()
    {
        $event = new SubjectEvent(new \stdClass());
        $replacement = new \stdClass();

        $this->assertSame($event, $event->setSubject($replacement), 'setSubject() must be chainable');
        $this->assertSame($replacement, $event->getSubject());
    }

    /**
     * D2: nothing here may occupy Symfony's class name.
     *
     * @return void
     */
    public function testDoesNotShadowSymfonysGenericEvent()
    {
        $this->assertSame(
            'MyAdmin\\Plugins\\Testing\\Contract\\SubjectEvent',
            SubjectEvent::class,
            'the fallback event must never be declared under Symfony\'s name'
        );
    }

    // -----------------------------------------------------------------------
    // argumentsFor() — every arm
    // -----------------------------------------------------------------------

    /**
     * @return void
     */
    public function testAHandlerTakingNoParametersGetsNoArguments()
    {
        $prepared = $this->prepare('noParams', new \stdClass());
        $this->assertSame([], $prepared['args']);
        $this->assertNull($prepared['skip']);
    }

    /**
     * The harness can supply exactly one thing: the event. Anything else is a genuine
     * "could not run", and inventing a second argument would run the handler in a state no
     * dispatcher produces.
     *
     * @return void
     */
    public function testAHandlerNeedingTwoArgumentsIsSkipped()
    {
        $prepared = $this->prepare('twoRequired', new \stdClass());
        $this->assertSame([], $prepared['args']);
        $this->assertInstanceOf(Finding::class, $prepared['skip']);
        $this->assertTrue($prepared['skip']->isSkipped());
        $this->assertStringContainsString('requires 2 arguments', $prepared['skip']->message());
        $this->assertSame('B-TEST', $prepared['skip']->assertion());
    }

    /**
     * B-13 runs each handler four times and tags every finding with the panel/ACL
     * combination it happened in. A skip that dropped the tag would be unattributable.
     *
     * @return void
     */
    public function testExtraContextIsMergedIntoTheAritySkip()
    {
        $prepared = $this->prepare('twoRequired', new \stdClass(), ['combination' => 'ima=client, has_acl()=false']);
        $context = $prepared['skip']->context();
        $this->assertSame('ima=client, has_acl()=false', $context['combination']);
        $this->assertSame(SubjectEventFixtureHandlers::class, $context['class']);
        $this->assertSame('twoRequired', $context['method']);
    }

    /**
     * An optional second parameter is not a reason to skip — the dispatcher would not pass
     * it either.
     *
     * @return void
     */
    public function testAnOptionalSecondParameterIsNotASkip()
    {
        $prepared = $this->prepare('oneRequiredOneOptional', new \stdClass());
        $this->assertNull($prepared['skip']);
        $this->assertCount(1, $prepared['args']);
    }

    /**
     * @return void
     */
    public function testAnUntypedParameterGetsTheDuckEvent()
    {
        $payload = new \stdClass();
        $prepared = $this->prepare('untyped', $payload);
        $this->assertNull($prepared['skip']);
        $this->assertCount(1, $prepared['args']);
        $this->assertSame($payload, $prepared['args'][0]->getSubject());
    }

    /**
     * A scalar type hint is not an event class, so the duck stands in there too.
     *
     * @return void
     */
    public function testABuiltinTypedParameterGetsTheDuckEvent()
    {
        $payload = new \stdClass();
        $prepared = $this->prepare('builtinTyped', $payload);
        $this->assertNull($prepared['skip']);
        $this->assertSame($payload, $prepared['args'][0]->getSubject());
    }

    /**
     * The duck answers the whole `GenericEvent` argument surface a handler might touch, but
     * deliberately not `setSubject()` — the real `GenericEvent` has none, and accepting the
     * call here would hide a handler that fatals in production.
     *
     * @return void
     */
    public function testTheDuckEventCoversTheArgumentSurfaceButNotSetSubject()
    {
        $payload = new \stdClass();
        $duck = SubjectEvent::duck($payload);

        $this->assertSame($payload, $duck->getSubject());
        $this->assertFalse($duck->hasArgument('anything'));
        $this->assertNull($duck->getArgument('anything'));
        $this->assertSame($duck, $duck->setArgument('anything', 'value'), 'setArgument() must be chainable');
        $this->assertSame([], $duck->getArguments());
        $this->assertFalse(method_exists($duck, 'setSubject'), 'the duck must not be more permissive than GenericEvent');
    }

    /**
     * The situation this whole seam exists for: the handler names an event class that is not
     * installed here. Reporting it as a skip that *names the class* is what tells a reader
     * the run was incomplete rather than clean.
     *
     * @return void
     */
    public function testAnEventClassThatIsNotLoadableIsSkippedByName()
    {
        $prepared = $this->prepare('missingEventClass', new \stdClass());
        $this->assertSame([], $prepared['args']);
        $this->assertTrue($prepared['skip']->isSkipped());
        $this->assertStringContainsString('is not loadable in this environment', $prepared['skip']->message());
        $this->assertStringContainsString('SubjectEventAbsent\\Missing', $prepared['skip']->message());
        $this->assertSame('SubjectEventAbsent\\Missing', $prepared['skip']->context()['event']);
    }

    /**
     * @return void
     */
    public function testAnAbstractEventClassIsSkipped()
    {
        $prepared = $this->prepare('abstractTyped', new \stdClass());
        $this->assertSame([], $prepared['args']);
        $this->assertTrue($prepared['skip']->isSkipped());
        $this->assertStringContainsString('event class is abstract', $prepared['skip']->message());
    }

    /**
     * @return void
     */
    public function testAnEventThatDiscardsTheSubjectIsSkipped()
    {
        $prepared = $this->prepare('rejectingTyped', new \stdClass());
        $this->assertTrue($prepared['skip']->isSkipped());
        $this->assertStringContainsString('did not accept the harness subject', $prepared['skip']->message());
    }

    /**
     * @return void
     */
    public function testAnEventWithNoGetSubjectIsSkipped()
    {
        $prepared = $this->prepare('noGetSubjectTyped', new \stdClass());
        $this->assertTrue($prepared['skip']->isSkipped());
        $this->assertStringContainsString('did not accept the harness subject', $prepared['skip']->message());
    }

    /**
     * The happy path: a declared event class that can be built gets built, and the handler
     * receives an instance of exactly the type it asked for.
     *
     * @return void
     */
    public function testADeclaredEventClassIsConstructedAndPassedThrough()
    {
        $payload = new \stdClass();
        $prepared = $this->prepare('goodTyped', $payload);

        $this->assertNull($prepared['skip']);
        $this->assertInstanceOf(SubjectEventFixtureGoodEvent::class, $prepared['args'][0]);
        $this->assertSame($payload, $prepared['args'][0]->getSubject());
    }

    // -----------------------------------------------------------------------
    // releaseHarness()
    // -----------------------------------------------------------------------

    /**
     * `Harness::reset()` deliberately leaves the ACL allowlist and `ima` alone. B-12, B-13
     * and B-15 all change both, and 69 plugins run back-to-back in one process, so a grant
     * left behind changes some later plugin's verdict rather than failing here.
     *
     * @return void
     */
    public function testReleaseRestoresTheAclAllowlistAndThePanel()
    {
        Harness::setAcl(['client_billing']);
        FakeApp::setIma('admin');
        $this->assertTrue(Harness::hasAcl('client_billing'), 'precondition: the grant is in place');

        SubjectEvent::releaseHarness();

        $this->assertFalse(Harness::hasAcl('client_billing'), 'the ACL grant must not survive');
        $this->assertSame('client', FakeApp::ima(), 'the panel must go back to client');
    }
}

// ---------------------------------------------------------------------------
// Fixtures
// ---------------------------------------------------------------------------

/** An event class that behaves like `GenericEvent` does. */
class SubjectEventFixtureGoodEvent
{
    /** @var mixed */
    private $subject;

    /**
     * @param mixed $subject
     */
    public function __construct($subject = null)
    {
        $this->subject = $subject;
    }

    /**
     * @return mixed
     */
    public function getSubject()
    {
        return $this->subject;
    }
}

/** Cannot be instantiated, so it cannot be handed to a handler. */
abstract class SubjectEventFixtureAbstractEvent
{
    /**
     * @param mixed $subject
     */
    public function __construct($subject = null)
    {
    }

    /**
     * @return mixed
     */
    public function getSubject()
    {
        return null;
    }
}

/** Constructs fine but throws the subject away. */
class SubjectEventFixtureRejectingEvent
{
    /**
     * @param mixed $subject
     */
    public function __construct($subject = null)
    {
    }

    /**
     * @return mixed
     */
    public function getSubject()
    {
        return null;
    }
}

/** Constructs fine but is not an event at all. */
class SubjectEventFixtureNoGetSubject
{
    /**
     * @param mixed $subject
     */
    public function __construct($subject = null)
    {
    }
}

/** One handler per arm of `argumentsFor()`. None of them is ever invoked. */
class SubjectEventFixtureHandlers
{
    /**
     * @return void
     */
    public static function noParams()
    {
    }

    /**
     * @param mixed $event
     * @return void
     */
    public static function untyped($event)
    {
    }

    /**
     * @param array<int,mixed> $event
     * @return void
     */
    public static function builtinTyped(array $event)
    {
    }

    /**
     * @param mixed $event
     * @param mixed $other
     * @return void
     */
    public static function twoRequired($event, $other)
    {
    }

    /**
     * @param mixed $event
     * @param mixed $extra
     * @return void
     */
    public static function oneRequiredOneOptional($event, $extra = null)
    {
    }

    /**
     * The class named here deliberately does not exist anywhere.
     *
     * @param \SubjectEventAbsent\Missing $event
     * @return void
     */
    public static function missingEventClass(\SubjectEventAbsent\Missing $event)
    {
    }

    /**
     * @param SubjectEventFixtureAbstractEvent $event
     * @return void
     */
    public static function abstractTyped(SubjectEventFixtureAbstractEvent $event)
    {
    }

    /**
     * @param SubjectEventFixtureRejectingEvent $event
     * @return void
     */
    public static function rejectingTyped(SubjectEventFixtureRejectingEvent $event)
    {
    }

    /**
     * @param SubjectEventFixtureNoGetSubject $event
     * @return void
     */
    public static function noGetSubjectTyped(SubjectEventFixtureNoGetSubject $event)
    {
    }

    /**
     * @param SubjectEventFixtureGoodEvent $event
     * @return void
     */
    public static function goodTyped(SubjectEventFixtureGoodEvent $event)
    {
    }
}
