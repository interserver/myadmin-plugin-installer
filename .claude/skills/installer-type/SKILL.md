---
name: installer-type
description: Adds a new MyAdmin package type to Installer.php supports() and configures install path routing. Use when user says 'add package type', 'support new type', 'new installer type', 'add myadmin- type'. Do NOT use for changing existing type behavior or modifying TemplateInstaller standalone logic.
---
# Add New MyAdmin Package Type

## Critical

- All MyAdmin package types MUST use the `myadmin-` prefix (e.g., `myadmin-widget`, `myadmin-theme`). Never add a type without this prefix.
- The `supports()` method in `src/Installer.php` uses `in_array()` with a hardcoded array. The new type must be added to this array — do not change the method signature or control flow.
- If the new type needs a custom install path (not `vendor/`), you must also update `getInstallPath()` in `src/Installer.php`. If it routes to a special directory like templates do, add an `initialize*Dir()` method and a corresponding `protected` property.
- Tests use `ReflectionClass::newInstanceWithoutConstructor()` to create stubs — never mock Composer classes directly.

## Instructions

### Step 1: Add the type string to `src/Installer.php` supports()

Open `src/Installer.php` and add the new type to the array in `supports()` (line ~71):

```php
public function supports($packageType)
{
    return in_array($packageType, [
        'myadmin-template',
        'myadmin-module',
        'myadmin-plugin',
        'myadmin-menu',
        'myadmin-NEW_TYPE',  // <-- add here
    ]);
}
```

**Verify:** Run `vendor/bin/phpunit tests/InstallerTest.php` — existing tests must still pass.

### Step 2: Configure install path routing in `getInstallPath()`

Decide where the new type should be installed:

**Option A — Standard vendor path (like modules/plugins/menus):** No changes needed to `getInstallPath()`. Packages install to `vendor/{package-name}`.

**Option B — Custom directory (like templates → `include/templates/`):**

1. Add a protected property for the directory:
   ```php
   protected $newTypeDir;
   ```

2. Initialize it in the constructor, following the `templateDir` pattern:
   ```php
   $this->newTypeDir = $this->vendorDir.'/../path/to/new_type';
   ```

3. Add an `initializeNewTypeDir()` method:
   ```php
   protected function initializeNewTypeDir()
   {
       $this->filesystem->ensureDirectoryExists($this->newTypeDir);
       $this->newTypeDir = realpath($this->newTypeDir);
   }
   ```

4. Add a branch in `getInstallPath()` (line ~156), following the existing `myadmin-template` pattern:
   ```php
   public function getInstallPath(PackageInterface $package)
   {
       if ($this->type == 'myadmin-template') {
           $this->initializeTemplateDir();
           $basePath = ($this->templateDir ? $this->templateDir.'/' : '') . $package->getPrettyName();
       } elseif ($this->type == 'myadmin-NEW_TYPE') {
           $this->initializeNewTypeDir();
           $basePath = ($this->newTypeDir ? $this->newTypeDir.'/' : '') . $package->getPrettyName();
       } else {
           $this->initializeVendorDir();
           $basePath = ($this->vendorDir ? $this->vendorDir.'/' : '') . $package->getPrettyName();
       }
       $targetDir = $package->getTargetDir();
       return $basePath . ($targetDir ? '/'.$targetDir : '');
   }
   ```

**Verify:** Read the updated `getInstallPath()` and confirm the logic branches are correct. Run `vendor/bin/phpunit tests/InstallerTest.php`.

### Step 3: (Optional) Add a standalone installer class if the type needs special validation

Only do this if the new type requires package-name prefix validation (like `TemplateInstaller` validates `myadmin/template-` prefix). Follow the `src/TemplateInstaller.php` pattern exactly:

```php
<?php
namespace MyAdmin\Plugins;

use Composer\Package\PackageInterface;
use Composer\Installer\LibraryInstaller;

class NewTypeInstaller extends LibraryInstaller
{
    public function getInstallPath(PackageInterface $package)
    {
        $prefix = mb_substr($package->getPrettyName(), 0, LENGTH);
        if ('myadmin/newtype-' !== $prefix) {
            throw new \InvalidArgumentException(
                'Unable to install new type, packages '
                .'should always start their package name with '
                .'"myadmin/newtype-"'
            );
        }
        return 'path/to/install/'.mb_substr($package->getPrettyName(), LENGTH);
    }

    public function supports($packageType)
    {
        return 'myadmin-NEW_TYPE' === $packageType;
    }
}
```

If you create a standalone installer, register it in `src/InstallerPlugin.php` by adding it in `activate()`:
```php
public function activate(Composer $composer, IOInterface $io)
{
    $installer = new Installer($io, $composer);
    $composer->getInstallationManager()->addInstaller($installer);
    // Add standalone installer for new type if needed:
    $newTypeInstaller = new NewTypeInstaller($io, $composer);
    $composer->getInstallationManager()->addInstaller($newTypeInstaller);
}
```

**Verify:** The new class file exists in `src/` and follows PSR-4 (`MyAdmin\Plugins\` namespace).

### Step 4: Add tests for the new type

Add a test to `tests/InstallerTest.php` following the existing pattern:

```php
/**
 * Test supports returns true for myadmin-NEW_TYPE type.
 */
public function testSupportsMyadminNewType(): void
{
    $installer = $this->createInstallerStub();
    $this->assertTrue($installer->supports('myadmin-NEW_TYPE'));
}
```

Also update `testAllSupportedTypes()` to include the new type in the `$expected` array:
```php
$expected = ['myadmin-template', 'myadmin-module', 'myadmin-plugin', 'myadmin-menu', 'myadmin-NEW_TYPE'];
```

If you created a standalone installer (Step 3), create a test file `tests/NewTypeInstallerTest.php` following `tests/TemplateInstallerTest.php` exactly:
- Test `supports()` returns true for the new type and false for others
- Test `getInstallPath()` returns the correct path for valid packages
- Test `getInstallPath()` throws `\InvalidArgumentException` for invalid prefixes
- Use `ReflectionClass::newInstanceWithoutConstructor()` for stubs
- Use anonymous class implementing `PackageInterface` for package stubs (copy from `TemplateInstallerTest.php`)

**Verify:** Run `vendor/bin/phpunit` — all tests must pass, including the new ones.

### Step 5: Update CLAUDE.md documentation

Add the new type to the "Package Types" table in `CLAUDE.md` with its install path.

**Verify:** The table has the correct type name and path.

## Examples

### Adding `myadmin-widget` type (standard vendor path)

User says: "Add support for myadmin-widget package type"

Actions:
1. Edit `src/Installer.php` line 71-76, add `'myadmin-widget'` to the `in_array()` list
2. No changes to `getInstallPath()` since widgets install to `vendor/`
3. Add `testSupportsMyadminWidget()` to `tests/InstallerTest.php`
4. Update `$expected` in `testAllSupportedTypes()`
5. Run `vendor/bin/phpunit` — all pass

Result: `src/Installer.php` supports the new type, packages of type `myadmin-widget` install to `vendor/{name}`.

### Adding `myadmin-theme` type with custom install path

User says: "Add myadmin-theme type that installs to include/themes/"

Actions:
1. Edit `src/Installer.php`:
   - Add `protected $themeDir;` property
   - Add `$this->themeDir = $this->vendorDir.'/../include/themes';` in constructor
   - Add `'myadmin-theme'` to `supports()` array
   - Add `elseif ($this->type == 'myadmin-theme')` branch in `getInstallPath()`
   - Add `initializeThemeDir()` protected method
2. Add tests in `tests/InstallerTest.php`
3. Run `vendor/bin/phpunit` — all pass

## Common Issues

### `supports()` returns false for the new type after adding it

The type string is case-sensitive. Ensure the string in `supports()` exactly matches what will appear in consuming packages' `composer.json` `type` field. Check for typos — the convention is `myadmin-` followed by a lowercase singular noun (e.g., `myadmin-widget`, not `myadmin-widgets` or `MyAdmin-Widget`).

### `testAllSupportedTypes` fails after adding new type

You added the type to `supports()` but forgot to update the `$expected` array in `testAllSupportedTypes()` at `tests/InstallerTest.php` line ~239. Add the new type string to that array.

### Standalone installer class not found

Ensure the file is in `src/` and the class namespace is `MyAdmin\Plugins\`. PSR-4 autoloading maps `MyAdmin\Plugins\` to `src/`. The filename must match the class name exactly (e.g., `ThemeInstaller.php` for `class ThemeInstaller`).

### `getInstallPath()` returns wrong directory

The `$this->type` check in `getInstallPath()` compares against the installer's own type (set in constructor), NOT the package type. If you're relying on `getInstallPath()` to route by package type, you need to check `$package->getType()` instead of `$this->type`. Look at how the existing template branch works — it checks `$this->type == 'myadmin-template'`.

### Tests fail with "Cannot instantiate abstract class" or constructor errors

Use `ReflectionClass::newInstanceWithoutConstructor()` to create installer stubs, not `new Installer()`. The constructor requires Composer dependencies that aren't available in unit tests. See `tests/InstallerTest.php` `createInstallerStub()` method at line ~252.