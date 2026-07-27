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

**Option B — Custom directory:**

Branch on the **package's** type, never on `$this->type`:

```php
public function getInstallPath(PackageInterface $package)
{
    if ($package->getType() === 'myadmin-NEW_TYPE') {
        $path = 'path/to/new_type/'.$package->getPrettyName();
        $this->filesystem->ensureDirectoryExists(dirname($path));
        return $path;
    }
    return parent::getInstallPath($package);
}
```

⚠️ **`$this->type` is the installer's own constructor label, not the package's type.** It defaults to `'library'` and nothing ever passes anything else, so `if ($this->type == 'myadmin-template')` was dead code that could not fire in any configuration. That branch — and the `$templateDir` property and `initializeTemplateDir()` method it used — were deleted for exactly this reason. Do not resurrect the pattern.

**Also override `getPackageBasePath()`.** `install()` and `update()` use `getInstallPath()`, but `uninstall()` uses `getPackageBasePath()`. Override only the former and a package installs to your custom path but uninstalls from `vendor/`, silently leaving files behind:

```php
protected function getPackageBasePath(PackageInterface $package)
{
    return $this->getInstallPath($package);
}
```

**Before adding a custom directory at all, ask whether it is needed.** MyAdmin packages read their own templates and scripts in place via `__DIR__` and ship no web assets, so there is usually nothing to relocate. Composer's default `LibraryInstaller` accepts every package type and routes to `vendor/`, which is why all four MyAdmin types work today with no custom routing.

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
   - Add `'myadmin-theme'` to the `MYADMIN_PACKAGE_TYPES` constant
   - If it needs a non-`vendor/` location, add an `if ($package->getType() === 'myadmin-theme')` branch to `getInstallPath()` — branch on the **package's** type, never `$this->type`
   - Override `getPackageBasePath()` to match, or uninstall will look in the wrong place
2. Add tests in `tests/InstallerTest.php` — assert the resolved path, not just that the method exists
3. Run `vendor/bin/phpunit` — all pass

## Common Issues

### `supports()` returns false for the new type after adding it

The type string is case-sensitive. Ensure the string in `supports()` exactly matches what will appear in consuming packages' `composer.json` `type` field. Check for typos — the convention is `myadmin-` followed by a lowercase singular noun (e.g., `myadmin-widget`, not `myadmin-widgets` or `MyAdmin-Widget`).

### `testAllSupportedTypes` fails after adding new type

You added the type to `supports()` but forgot to update the `$expected` array in `testAllSupportedTypes()` at `tests/InstallerTest.php` (`testSupportsClaimsOnlyMyAdminTypes`). Add the new type string to that array.

### Standalone installer class not found

Ensure the file is in `src/` and the class namespace is `MyAdmin\Plugins\`. PSR-4 autoloading maps `MyAdmin\Plugins\` to `src/`. The filename must match the class name exactly (e.g., `ThemeInstaller.php` for `class ThemeInstaller`).

### `getInstallPath()` returns wrong directory

`$this->type` is the installer's own constructor label — always `'library'`, because nothing passes anything else. Routing on it can never work. Use `$package->getType()`.

This is not hypothetical: the original `myadmin-template` branch made exactly this mistake and was unreachable for its entire lifetime. It has been deleted; there is no "existing template branch" to copy.

### Tests fail with "Cannot instantiate abstract class" or constructor errors

Use `ReflectionClass::newInstanceWithoutConstructor()` to create installer stubs, not `new Installer()`. The constructor requires Composer dependencies that aren't available in unit tests. See `tests/InstallerTest.php` `createInstallerStub()` method at line ~252.