---
name: composer-command
description: Creates a new Composer command extending BaseCommand in src/Command/, registers it in CommandProvider.php, and scaffolds a matching PHPUnit test. Use when user says 'add command', 'new composer command', 'create myadmin command'. Do NOT use for modifying existing commands or non-command classes.
---
# Composer Command

Create new Composer CLI commands for the MyAdmin plugin installer, following the existing `BaseCommand` pattern in `src/Command/`.

## Critical

- Command name MUST follow the `myadmin:<kebab-case-name>` convention (e.g., `myadmin:rebuild-cache`). The only exception is the base `myadmin` command in `Command.php`.
- Every new command class MUST be registered in `src/CommandProvider.php` — both as a `use` import and as a `new ClassName()` entry in the `getCommands()` array.
- The `CommandProviderTest.php` test that asserts the command count MUST be updated to match the new total.
- All command classes use `protected function execute(...)` returning `int` (0 for success).
- Namespace is always `MyAdmin\Plugins\Command`.

## Instructions

1. **Choose the command name and class name.**
   - Command name: `myadmin:<kebab-case-verb-noun>` (e.g., `myadmin:clear-cache`)
   - Class name: PascalCase matching the command (e.g., `ClearCache`)
   - Verify the name doesn't conflict with existing commands: `Command`, `Parse`, `CreateUser`, `UpdatePlugins`, `SetPermissions`.

2. **Create the command class at `src/Command/<ClassName>.php`.**
   Use this exact template:

   ```php
   <?php
   /**
    * Plugins Management
    * @author Joe Huss <detain@interserver.net>
    * @copyright 2025
    * @package MyAdmin
    * @category Plugins
    */

   namespace MyAdmin\Plugins\Command;

   use Symfony\Component\Console\Input\InputInterface;
   use Symfony\Component\Console\Output\OutputInterface;
   use Composer\Command\BaseCommand;

   /**
    * Class <ClassName>
    *
    * @package MyAdmin\Plugins\Command
    */
   class <ClassName> extends BaseCommand
   {
       protected function configure()
       {
           $this
               ->setName('myadmin:<kebab-name>')
               ->setDescription('<Short description of what the command does>')
               ->setHelp('<Longer help text explaining usage>');
       }

       protected function execute(InputInterface $input, OutputInterface $output): int
       {
           // Command logic here

           return 0;
       }
   }
   ```

   - If the command needs arguments, add `use Symfony\Component\Console\Input\InputArgument;` and chain `->addArgument('name', InputArgument::REQUIRED, 'Description.')` in `configure()`.
   - If the command needs options, add `use Symfony\Component\Console\Input\InputOption;` and chain `->addOption(...)` in `configure()`.
   - Only include `initialize()` and `interact()` methods if the command actually needs them. The minimal pattern (see `SetPermissions.php`) omits them.
   - Verify the file exists and has correct namespace before proceeding.

3. **Register the command in `src/CommandProvider.php`.**
   - Add a `use` import: `use MyAdmin\Plugins\Command\<ClassName>;`
   - Add `new <ClassName>()` to the end of the array in `getCommands()`.
   - Verify the import is alphabetically consistent with existing imports and the array entry is added.

4. **Create the test file at `tests/Command/<ClassName>Test.php`.**
   Follow this template matching the project's ReflectionClass-based testing pattern:

   ```php
   <?php

   namespace Tests\MyAdmin\Plugins\Command;

   use MyAdmin\Plugins\Command\<ClassName>;
   use PHPUnit\Framework\TestCase;
   use ReflectionClass;

   /**
    * Test suite for the <ClassName> command class.
    *
    * Tests class structure and command configuration.
    *
    * @covers \MyAdmin\Plugins\Command\<ClassName>
    */
   class <ClassName>Test extends TestCase
   {
       public function testExtendsBaseCommand(): void
       {
           $ref = new ReflectionClass(<ClassName>::class);
           $this->assertSame('Composer\Command\BaseCommand', $ref->getParentClass()->getName());
       }

       public function testCommandNameIsMyadmin<PascalName>(): void
       {
           $command = new <ClassName>();
           $this->assertSame('myadmin:<kebab-name>', $command->getName());
       }

       public function testCommandHasDescription(): void
       {
           $command = new <ClassName>();
           $this->assertNotEmpty($command->getDescription());
       }

       public function testCommandHasHelp(): void
       {
           $command = new <ClassName>();
           $this->assertNotEmpty($command->getHelp());
       }

       public function testExecuteIsProtected(): void
       {
           $ref = new ReflectionClass(<ClassName>::class);
           $method = $ref->getMethod('execute');
           $this->assertTrue($method->isProtected());
       }
   }
   ```

   - If the command has arguments, add tests for each argument (see `CreateUserTest.php` for the pattern: `testCommandHas<Arg>Argument`, `test<Arg>ArgumentIsRequired`, `test<Arg>ArgumentHasDescription`).
   - Verify the test file exists before proceeding.

5. **Update `tests/CommandProviderTest.php`.**
   - Update the `testGetCommandsReturns*Commands` test: change the `assertCount` value from the current count to current + 1.
   - Add a new test method for the new command instance:
     ```php
     public function testContains<ClassName>Instance(): void
     {
         $provider = new CommandProvider();
         $commands = $provider->getCommands();
         $this->assertInstanceOf(\MyAdmin\Plugins\Command\<ClassName>::class, $commands[<new_index>]);
     }
     ```
   - The index is the position in the `getCommands()` array (0-based). Currently indices 0-4 are used, so the next is 5.
   - Verify the count assertion matches the actual number of commands.

6. **Run tests to verify everything passes.**
   ```bash
   vendor/bin/phpunit tests/Command/<ClassName>Test.php
   vendor/bin/phpunit tests/CommandProviderTest.php
   ```
   - All tests must pass. If `CommandProviderTest` fails on count, check that you updated both the array in `CommandProvider.php` and the count assertion in the test.

## Examples

**User says:** "Add a new composer command called rebuild-cache that clears and rebuilds the plugin cache"

**Actions taken:**
1. Create `src/Command/RebuildCache.php` with class `RebuildCache extends BaseCommand`, command name `myadmin:rebuild-cache`.
2. Add `use MyAdmin\Plugins\Command\RebuildCache;` and `new RebuildCache()` to `src/CommandProvider.php`.
3. Create `tests/Command/RebuildCacheTest.php` with tests for: extends BaseCommand, command name, description, help, execute visibility.
4. Update `tests/CommandProviderTest.php`: change `assertCount(5, ...)` to `assertCount(6, ...)`, add `testContainsRebuildCacheInstance` checking `$commands[5]`.
5. Run `vendor/bin/phpunit tests/Command/RebuildCacheTest.php && vendor/bin/phpunit tests/CommandProviderTest.php` — all green.

**Result:** New `myadmin:rebuild-cache` command available via `composer myadmin:rebuild-cache`, fully tested.

## Common Issues

- **`CommandProviderTest::testGetCommandsReturnsFiveCommands` fails with "Failed asserting that 6 matches expected 5"**: You added the command to `CommandProvider.php` but forgot to update the count assertion in `tests/CommandProviderTest.php`. Change `assertCount(5, ...)` to `assertCount(6, ...)`.

- **`Error: Class 'MyAdmin\Plugins\Command\NewCommand' not found`**: The class file exists but the namespace or class name doesn't match. Verify the file has `namespace MyAdmin\Plugins\Command;` and the class name matches the filename exactly (PascalCase).

- **`The command "myadmin:foo" does not exist`**: The command was not registered in `src/CommandProvider.php`. Add both the `use` import and the `new ClassName()` entry in `getCommands()`.

- **`Symfony\Component\Console\Exception\LogicException: The command name "" must not be empty`**: The `configure()` method is missing `->setName('myadmin:<name>')`. Ensure the `configure` method chains `setName()` first.

- **Test instantiation fails with constructor errors**: Do NOT pass constructor arguments to command classes in tests. All existing commands are instantiated with `new ClassName()` (no arguments). The `BaseCommand` constructor is parameterless.