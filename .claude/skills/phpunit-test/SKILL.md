---
name: phpunit-test
description: Writes PHPUnit 9 tests following the project's ReflectionClass-based pattern. Uses newInstanceWithoutConstructor() stubs, tests interface implementation, method visibility, and parameter counts. Use when user says 'add tests', 'write test', 'test coverage for'. Do NOT use for running existing tests.
---
# PHPUnit Test Writer

## Critical

- **Never mock Composer vendor classes** (`Composer\Installer\LibraryInstaller`, `Composer\Command\BaseCommand`, etc.) with `createMock()`. Use `ReflectionClass::newInstanceWithoutConstructor()` to create stubs that bypass constructor dependencies.
- **Namespace must match directory**: `Tests\MyAdmin\Plugins\` → `tests/`, `Tests\MyAdmin\Plugins\Command\` → `tests/Command/`.
- **Every test class** must extend `PHPUnit\Framework\TestCase` and include a `@covers` annotation pointing to the class under test.
- **Every test method** must have `: void` return type and a PHPDoc `/** */` block describing what it tests.
- **Run tests before finishing**: `vendor/bin/phpunit tests/YourNewTest.php` to confirm all pass.

## Instructions

### Step 1: Identify the class under test

Read the source file in `src/` to catalog:
- Parent class (if any)
- Interfaces implemented
- All public, protected, and static methods
- Constructor parameter count
- Properties and their visibility

Verify the source file exists at the expected path before proceeding.

### Step 2: Create the test file

Place the test file in `tests/` mirroring the `src/` structure:
- `src/Foo.php` → `tests/FooTest.php`
- `src/Command/Bar.php` → `tests/Command/BarTest.php`

Use this exact boilerplate:

```php
<?php

namespace Tests\MyAdmin\Plugins;

use MyAdmin\Plugins\ClassName;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Test suite for the ClassName class.
 *
 * Tests class structure, [brief description of what's tested].
 *
 * @covers \MyAdmin\Plugins\ClassName
 */
class ClassNameTest extends TestCase
{
}
```

For classes in `src/Command/`, use namespace `Tests\MyAdmin\Plugins\Command`.

Verify the namespace matches the directory before proceeding.

### Step 3: Write class hierarchy tests

If the class extends another class, write a test verifying the parent:

```php
public function testExtendsParentClass(): void
{
    $ref = new ReflectionClass(ClassName::class);
    $this->assertSame('Fully\Qualified\ParentClass', $ref->getParentClass()->getName());
}
```

For each interface implemented, write a separate test:

```php
public function testImplementsInterfaceName(): void
{
    $ref = new ReflectionClass(ClassName::class);
    $this->assertTrue($ref->implementsInterface(\Full\Interface\Name::class));
}
```

Verify you have one test per interface before proceeding.

### Step 4: Write method visibility tests

For every public method, write:

```php
public function testMethodNameIsPublic(): void
{
    $ref = new ReflectionClass(ClassName::class);
    $method = $ref->getMethod('methodName');
    $this->assertTrue($method->isPublic());
}
```

For every protected method:

```php
public function testMethodNameIsProtected(): void
{
    $ref = new ReflectionClass(ClassName::class);
    $method = $ref->getMethod('methodName');
    $this->assertTrue($method->isProtected());
}
```

For static methods, add a second assertion:

```php
public function testMethodNameIsPublicStatic(): void
{
    $ref = new ReflectionClass(ClassName::class);
    $method = $ref->getMethod('methodName');
    $this->assertTrue($method->isPublic());
    $this->assertTrue($method->isStatic());
}
```

Verify you've covered all methods from Step 1 before proceeding.

### Step 5: Write property visibility tests

For each class property:

```php
public function testHasPropertyNameProperty(): void
{
    $ref = new ReflectionClass(ClassName::class);
    $prop = $ref->getProperty('propertyName');
    $this->assertTrue($prop->isProtected()); // or isPublic(), isPrivate()
}
```

### Step 6: Write constructor parameter count test

If the class has a constructor:

```php
public function testConstructorParameterCount(): void
{
    $ref = new ReflectionClass(ClassName::class);
    $constructor = $ref->getConstructor();
    $this->assertCount(N, $constructor->getParameters());
}
```

### Step 7: Write method signature tests (where relevant)

For methods with well-known signatures (e.g., `activate($composer, $io)`):

```php
public function testActivateMethodSignature(): void
{
    $ref = new ReflectionClass(ClassName::class);
    $method = $ref->getMethod('activate');
    $params = $method->getParameters();

    $this->assertCount(2, $params);
    $this->assertSame('composer', $params[0]->getName());
    $this->assertSame('io', $params[1]->getName());
}
```

### Step 8: Write behavioral tests using stubs

For classes with methods that can be tested without full construction (e.g., `supports()`):

1. Add a private helper that creates a stub via `newInstanceWithoutConstructor()`:

```php
private function createClassNameStub(): ClassName
{
    $ref = new ReflectionClass(ClassName::class);
    /** @var ClassName $instance */
    $instance = $ref->newInstanceWithoutConstructor();
    return $instance;
}
```

2. Write tests calling the method on the stub:

```php
public function testSupportsExpectedType(): void
{
    $stub = $this->createClassNameStub();
    $this->assertTrue($stub->supports('myadmin-template'));
}

public function testDoesNotSupportUnknownType(): void
{
    $stub = $this->createClassNameStub();
    $this->assertFalse($stub->supports('library'));
}
```

For classes that CAN be instantiated directly (no complex constructor deps, like `Loader`, `CommandProvider`, or `Command` classes), instantiate normally instead:

```php
private $loader;

protected function setUp(): void
{
    $this->loader = new Loader();
}
```

### Step 9: Write return value tests for simple methods

For methods returning arrays, strings, or booleans that can be tested via stub or direct instantiation:

```php
public function testGetCommandsReturnsArray(): void
{
    $provider = new CommandProvider();
    $commands = $provider->getCommands();
    $this->assertIsArray($commands);
}

public function testGetCommandsReturnsFiveCommands(): void
{
    $provider = new CommandProvider();
    $commands = $provider->getCommands();
    $this->assertCount(5, $commands);
}
```

### Step 10: Run tests

Run `vendor/bin/phpunit tests/YourNewTest.php` and verify all tests pass.

If any test fails, diagnose via the error output — do not blindly remove the test. Fix the assertion or the stub approach.

## Examples

### Example: User says "add tests for TemplateInstallerPlugin"

**Actions taken:**
1. Read `src/TemplateInstallerPlugin.php` — implements `PluginInterface`, subscribes to `PRE_FILE_DOWNLOAD` event
2. Create `tests/TemplateInstallerPluginTest.php`
3. Write tests:
   - `testImplementsPluginInterface()` — reflection `implementsInterface()`
   - `testActivateMethodExists()` — reflection `getMethod('activate')`, assert `isPublic()`
   - `testDeactivateMethodExists()` — same pattern
   - `testUninstallMethodExists()` — same pattern
   - `testGetSubscribedEventsReturnsArray()` — call static method, `assertIsArray()`
   - `testGetSubscribedEventsContainsPreFileDownload()` — `assertArrayHasKey()`
4. Run `vendor/bin/phpunit tests/TemplateInstallerPluginTest.php` — all pass

**Result:** 6 tests, 6 assertions, matching the project's reflection-based pattern.

### Example: User says "write test for Command/CreateUser"

**Actions taken:**
1. Read `src/Command/CreateUser.php` — extends `BaseCommand`, has `configure()` and `execute()` methods, defines `username` argument
2. Create `tests/Command/CreateUserTest.php` with namespace `Tests\MyAdmin\Plugins\Command`
3. Write tests:
   - `testExtendsBaseCommand()` — reflection parent class check
   - `testCommandName()` — `new CreateUser()`, assert `getName()` returns `'myadmin:create-user'`
   - `testCommandHasDescription()` — `assertNotEmpty($command->getDescription())`
   - `testConfigureIsProtected()` — reflection visibility
   - `testExecuteIsProtected()` — reflection visibility
   - `testHasUsernameArgument()` — `$command->getDefinition()->hasArgument('username')`
4. Run `vendor/bin/phpunit tests/Command/CreateUserTest.php` — all pass

## Common Issues

**Error: `ReflectionException: Class MyAdmin\Plugins\Foo does not exist`**
1. Verify the class exists in `src/` and the namespace matches `composer.json` autoload (`MyAdmin\Plugins\` → `src/`)
2. Run `composer dump-autoload` to regenerate the autoloader
3. Check `phpunit.xml.dist` has `bootstrap="vendor/autoload.php"`

**Error: `Cannot instantiate abstract class` or `Constructor requires arguments`**
Do NOT use `new ClassName()`. Use the stub pattern:
```php
$ref = new ReflectionClass(ClassName::class);
$instance = $ref->newInstanceWithoutConstructor();
```

**Error: `Call to undefined method` when testing via stub**
Methods that depend on constructor-initialized state (e.g., properties set by `__construct`) will fail when called on a `newInstanceWithoutConstructor()` stub. Only test methods that don't rely on initialized state (like `supports()` which checks a hardcoded list). For methods needing state, test only their visibility/existence via reflection.

**Error: `Failed asserting that false is true` on `implementsInterface()`**
Double-check the fully qualified interface name. Common Composer interfaces:
- `Composer\Plugin\PluginInterface`
- `Composer\EventDispatcher\EventSubscriberInterface`
- `Composer\Plugin\Capable`
- `Composer\Plugin\Capability\CommandProvider`

**Test naming convention mismatch**
Test method names must follow `testDescriptiveAction` in camelCase. Match existing patterns:
- `testExtendsLibraryInstaller` (not `test_extends_library_installer`)
- `testSupportsMethodIsPublic` (not `testSupportsIsPublicMethod`)
- `testDoesNotSupportLibrary` (not `testNotSupportsLibrary`)