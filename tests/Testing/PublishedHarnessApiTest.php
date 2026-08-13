<?php

namespace Tests\MyAdmin\Plugins\Testing;

use MyAdmin\Plugins\Testing\PluginContractTestCase;
use MyAdmin\Plugins\Testing\ServicePluginTestCase;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Pins the shape of the harness base classes, because they are a published API.
 *
 * ---------------------------------------------------------------------------------
 * WHY THIS EXISTS
 * ---------------------------------------------------------------------------------
 * Nothing in this repository breaks when `PluginContractTestCase` changes shape. **69 other
 * repositories do**, and they find out on their own next CI run, having changed nothing
 * themselves. Every one of them extends one of these two classes and calls into them from a
 * generated file, so renaming a protected hook, adding an abstract method, or turning a data
 * provider non-static is a fleet-wide breakage delivered by a patch release.
 *
 * The unit tests around each inspector cover *behaviour*. This one covers only the part a
 * consumer can see and depend on — and it is the one case where an assertion about a
 * signature is worth more than an assertion about a result, because a signature is exactly
 * what the 69 downstream repos are coupled to.
 *
 * ---------------------------------------------------------------------------------
 * WHAT TO DO WHEN THIS FAILS
 * ---------------------------------------------------------------------------------
 * Do not edit the table to match the code. Decide first:
 *
 *   - **A rename or a removal, or a new abstract method** — that is a major version. 69 repos
 *     need regenerating, and the fleet CI job (`.github/workflows/fleet.yml`) is what proves
 *     they still pass before it ships.
 *   - **A widening** — a new optional parameter, a new protected hook with a default — is a
 *     minor. Add the row, note it in `docs/testing-harness.md`, and bump the minor version so
 *     `^2.x` consumers pick it up.
 *
 * @covers \MyAdmin\Plugins\Testing\PluginContractTestCase
 * @covers \MyAdmin\Plugins\Testing\ServicePluginTestCase
 */
class PublishedHarnessApiTest extends TestCase
{
    /**
     * The members a downstream repo can see, with the visibility and arity it is coupled to.
     *
     * `required` is the count of parameters a consumer MUST pass; `total` is how many exist.
     * Both are pinned so that adding an optional parameter (safe) reads differently from
     * making an existing one mandatory (not safe).
     *
     * @return array<string, array{0: string, 1: string, 2: string, 3: int, 4: int}>
     */
    public function publishedMembers(): array
    {
        $base = PluginContractTestCase::class;
        $service = ServicePluginTestCase::class;

        return [
            // The single thing every consumer must implement. Adding a second abstract member
            // breaks all 69 at once.
            'pluginClass' => [$base, 'pluginClass', 'abstract protected', 0, 0],

            // Optional hooks. A repo overrides these to describe itself; renaming one turns a
            // deliberate override into a silently ignored method, which is worse than an error.
            'expectedType' => [$base, 'expectedType', 'protected', 0, 0],
            'requirementRoot' => [$base, 'requirementRoot', 'protected', 0, 0],
            'serviceDefines' => [$base, 'serviceDefines', 'protected', 0, 0],
            'constantOverrides' => [$base, 'constantOverrides', 'protected', 0, 0],

            // Called directly by every generated tests/ContractTest.php.
            'primeConstants' => [$base, 'primeConstants', 'protected', 0, 0],
            'contractSubject' => [$base, 'contractSubject', 'protected', 0, 0],

            // PHPUnit discovers these by name and calls them itself.
            'contractAssertions' => [$base, 'contractAssertions', 'public static', 0, 0],
            'testPluginSatisfiesContractAssertion' => [$base, 'testPluginSatisfiesContractAssertion', 'public', 1, 1],

            // Read by tools/fleet-matrix.php and by the escape-hatch reporting.
            'overrideLedger' => [$base, 'overrideLedger', 'public static', 0, 0],
            'clearOverrideLedger' => [$base, 'clearOverrideLedger', 'public static', 0, 0],
            'inspectAll' => [$base, 'inspectAll', 'public static', 1, 1],

            // The service half.
            'handledTypes' => [$service, 'handledTypes', 'protected', 0, 0],
            'foreignTypes' => [$service, 'foreignTypes', 'protected', 0, 0],
            'exercisesOwnedTypes' => [$service, 'exercisesOwnedTypes', 'protected', 0, 0],
            'serviceLifecycleHandlers' => [$service, 'serviceLifecycleHandlers', 'public static', 0, 0],
            'testHandlerActsOnAServiceTypeItOwns' => [$service, 'testHandlerActsOnAServiceTypeItOwns', 'public', 1, 1],
            'testHandlerIsInertForAForeignServiceType' => [$service, 'testHandlerIsInertForAForeignServiceType', 'public', 1, 1],
            'inspectLifecycle' => [$service, 'inspectLifecycle', 'public', 0, 0],
            'serviceOverrideLedger' => [$service, 'serviceOverrideLedger', 'public static', 0, 0],
            'clearServiceOverrideLedger' => [$service, 'clearServiceOverrideLedger', 'public static', 0, 0],
        ];
    }

    /**
     * @dataProvider publishedMembers
     */
    public function testPublishedMemberKeepsItsShape(
        string $class,
        string $method,
        string $modifiers,
        int $required,
        int $total
    ): void {
        $this->assertTrue(
            method_exists($class, $method),
            $class.'::'.$method.'() is published API — 69 repos extend this class'
        );

        $reflected = new ReflectionMethod($class, $method);
        $actual = implode(' ', \Reflection::getModifierNames($reflected->getModifiers()));

        $this->assertSame($modifiers, $actual, $class.'::'.$method.'() changed visibility');
        $this->assertSame(
            $required,
            $reflected->getNumberOfRequiredParameters(),
            $class.'::'.$method.'() changed how many arguments a caller must pass'
        );
        $this->assertSame(
            $total,
            $reflected->getNumberOfParameters(),
            $class.'::'.$method.'() changed its parameter count'
        );
    }

    /**
     * The pin that catches the worst change, which is also the easiest one to make by
     * accident: a new `abstract` member. Every one of the 69 repos then fails to instantiate
     * its own ContractTest, with an error that names the harness rather than the change.
     */
    public function testPluginClassIsTheOnlyThingAConsumerMustImplement(): void
    {
        foreach ([PluginContractTestCase::class, ServicePluginTestCase::class] as $class) {
            $abstract = [];
            foreach ((new ReflectionClass($class))->getMethods() as $method) {
                if ($method->isAbstract() && $method->getDeclaringClass()->getName() !== TestCase::class) {
                    $abstract[] = $method->getName();
                }
            }
            sort($abstract);
            $this->assertSame(
                ['pluginClass'],
                $abstract,
                $class.' gained an abstract member; every downstream ContractTest stops instantiating'
            );
        }
    }

    /**
     * Both classes must stay abstract. A concrete base gets collected by PHPUnit as a test
     * class in its own right in every consumer, and then fails there with no plugin to inspect.
     */
    public function testTheBaseClassesStayAbstract(): void
    {
        $this->assertTrue((new ReflectionClass(PluginContractTestCase::class))->isAbstract());
        $this->assertTrue((new ReflectionClass(ServicePluginTestCase::class))->isAbstract());
    }

    public function testTheServiceCaseStillExtendsTheContractCase(): void
    {
        $this->assertTrue(
            is_subclass_of(ServicePluginTestCase::class, PluginContractTestCase::class),
            'a type=service package must be held to the eighteen shared inspectors as well'
        );
        $this->assertTrue(is_subclass_of(PluginContractTestCase::class, TestCase::class));
    }

    /**
     * A data provider that stops being `public static` is not an error — PHPUnit 9 simply
     * finds no data and reports the test as risky, or skips it. Either way the assertions
     * stop running in 69 repos without anything going red for the reason that matters.
     *
     * @dataProvider dataProviders
     */
    public function testDataProvidersStayPublicStatic(string $class, string $provider): void
    {
        $reflected = new ReflectionMethod($class, $provider);

        $this->assertTrue($reflected->isPublic(), $provider.'() must be public to be a data provider');
        $this->assertTrue($reflected->isStatic(), $provider.'() must be static');
        $this->assertSame(0, $reflected->getNumberOfRequiredParameters());
        $this->assertNotSame([], $class::$provider(), $provider.'() must actually yield cases');
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public function dataProviders(): array
    {
        return [
            'contract assertions' => [PluginContractTestCase::class, 'contractAssertions'],
            'service lifecycle handlers' => [ServicePluginTestCase::class, 'serviceLifecycleHandlers'],
        ];
    }

    /**
     * The catalogue's own size is part of the contract: a repo's suite going from 19 tests to
     * 17 because two inspectors were quietly dropped is a coverage loss no consumer would
     * notice, since the build stays green and just does less.
     */
    public function testTheInspectorCatalogueDoesNotShrinkSilently(): void
    {
        $ids = [];
        foreach (PluginContractTestCase::contractAssertions() as $case) {
            $ids[] = (new $case[0]())->id();
        }
        sort($ids);

        $this->assertSame(
            ['A-1', 'A-2', 'A-3', 'A-4', 'A-5', 'A-6', 'A-7', 'A-8', 'A-9',
             'B-10', 'B-11', 'B-12', 'B-13', 'B-14', 'B-15', 'B-16', 'B-9', 'B-9b'],
            $ids,
            'the published catalogue changed; removing an inspector silently reduces what 69 repos check'
        );
    }

    /**
     * The generated file names these three exactly. A rename here is invisible until 66
     * regenerated files are run, and the error then points at the plugin repo.
     */
    public function testTheMembersTheGeneratedFileCallsAllExist(): void
    {
        $generated = (new \MyAdmin\Plugins\Testing\Scaffold\ContractTestGenerator())->render(
            new \MyAdmin\Plugins\Testing\Scaffold\PluginFacts(
                'Detain\\MyAdminThing\\Plugin',
                'Detain\\MyAdminThing\\Tests',
                'Thing',
                'service',
                'licenses',
                ['licenses.activate']
            )
        );

        foreach (['primeConstants', 'contractSubject'] as $method) {
            $this->assertStringContainsString(
                '$this->'.$method.'(',
                $generated,
                'the generator calls '.$method.'(), so it is published API'
            );
            $this->assertTrue(method_exists(PluginContractTestCase::class, $method));
        }

        $this->assertStringContainsString('extends ServicePluginTestCase', $generated);
        $this->assertTrue(
            method_exists(
                \MyAdmin\Plugins\Testing\Contract\TierA5HooksAreIdempotent::class,
                'hookTable'
            ),
            'the generated pin reads the hook table through A-5; that accessor is published API too'
        );
    }
}
