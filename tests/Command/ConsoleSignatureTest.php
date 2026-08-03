<?php

namespace Tests\MyAdmin\Plugins\Command;

use MyAdmin\Plugins\Command\Command;
use MyAdmin\Plugins\Command\SetPermissions;
use MyAdmin\Plugins\Command\UpdatePlugins;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Pins the console-command signatures against symfony/console's typed base class.
 *
 * ---------------------------------------------------------------------------------
 * WHY THIS EXISTS
 * ---------------------------------------------------------------------------------
 * symfony/console 8.0 added native return types to `Command::configure()`. An untyped
 * override is a fatal signature mismatch against that base class, and a fatal on class
 * *load* takes the entire PHPUnit process down before a single test runs:
 *
 *     PHP Fatal error: Declaration of MyAdmin\Plugins\Command\Command::configure() must be
 *     compatible with Symfony\Component\Console\Command\Command::configure(): void
 *
 * The trap is that this is invisible on most CI legs. symfony/console 8 requires PHP >= 8.4,
 * so the 8.2 and 8.3 legs resolve 7.4.x — whose `configure()` is untyped — and stay green
 * while the 8.4 leg dies. Reproducing it locally requires both PHP 8.4 and a resolver that
 * picks Symfony 8.
 *
 * These assertions read the declaration directly instead of waiting for a version bump to
 * enforce it, so the invariant is checked on *every* leg regardless of which symfony/console
 * happens to be installed.
 *
 * @covers \MyAdmin\Plugins\Command\Command
 * @covers \MyAdmin\Plugins\Command\SetPermissions
 * @covers \MyAdmin\Plugins\Command\UpdatePlugins
 */
class ConsoleSignatureTest extends TestCase
{
    /**
     * @return array<string, array<int, string>>
     */
    public function commandClasses(): array
    {
        return [
            'myadmin' => [Command::class],
            'myadmin:set-permissions' => [SetPermissions::class],
            'myadmin:update-plugins' => [UpdatePlugins::class],
        ];
    }

    /**
     * @dataProvider commandClasses
     */
    public function testConfigureDeclaresVoid(string $class): void
    {
        $method = new ReflectionMethod($class, 'configure');

        $this->assertTrue(
            $method->hasReturnType(),
            $class.'::configure() must declare a return type; symfony/console 8 declares `: void`'
                .' on the parent and an untyped override is a load-time fatal.'
        );
        $this->assertSame('void', (string)$method->getReturnType(), $class.'::configure()');
    }

    /**
     * `execute()` is already typed, but it is the other method this package overrides from
     * the console base class, so it is pinned for the same reason.
     *
     * @dataProvider commandClasses
     */
    public function testExecuteDeclaresInt(string $class): void
    {
        $method = new ReflectionMethod($class, 'execute');

        $this->assertTrue($method->hasReturnType(), $class.'::execute() must declare a return type');
        $this->assertSame('int', (string)$method->getReturnType(), $class.'::execute()');
    }

    /**
     * Guards the assumption the fix rests on: overriding with a return type the parent does
     * not declare is legal, which is what makes one signature correct against both
     * symfony/console 7 (untyped parent) and 8 (`: void` parent).
     *
     * If this ever fails, the fix above is not portable and the package needs a version
     * constraint on symfony/console instead.
     */
    public function testAddingAReturnTypeToAnUntypedParentIsLegal(): void
    {
        $this->assertTrue(
            class_exists(VoidOverridesUntypedParent::class),
            'PHP must allow a child to add `: void` where the parent declares no return type'
        );
    }
}

// phpcs:disable PSR1.Classes.ClassDeclaration.MultipleClasses
/** Fixture for testAddingAReturnTypeToAnUntypedParentIsLegal(). */
class UntypedParent
{
    protected function configure()
    {
    }
}

/** Fixture for testAddingAReturnTypeToAnUntypedParentIsLegal(). */
class VoidOverridesUntypedParent extends UntypedParent
{
    protected function configure(): void
    {
    }
}
