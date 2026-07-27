# MyAdmin Plugin Installer

[![Tests](https://github.com/interserver/myadmin-plugin-installer/actions/workflows/tests.yml/badge.svg)](https://github.com/interserver/myadmin-plugin-installer/actions/workflows/tests.yml)
[![Latest Stable Version](https://poser.pugx.org/detain/myadmin-plugin-installer/version)](https://packagist.org/packages/detain/myadmin-plugin-installer)
[![Total Downloads](https://poser.pugx.org/detain/myadmin-plugin-installer/downloads)](https://packagist.org/packages/detain/myadmin-plugin-installer)
[![License](https://poser.pugx.org/detain/myadmin-plugin-installer/license)](https://packagist.org/packages/detain/myadmin-plugin-installer)

Composer integration for the MyAdmin hosting control panel: install-time automation, a small
set of `composer myadmin:*` commands, and the runtime helpers MyAdmin loads on every request.

## ⚠️ This package is load-bearing at runtime

It is easy to look at `config.allow-plugins` in a consuming project, see this package set to
`false`, and conclude it is unused. That conclusion is wrong, and removing the package will
fatal the application.

There are **three** independent load paths, and `allow-plugins` gates only one:

| Path | Gated by `allow-plugins`? | What it provides |
|---|---|---|
| Composer plugin — `Plugin::activate()`, `Installer`, `CommandProvider`, `Command\*` | **Yes** | Custom installer, `myadmin:*` commands, event handlers |
| `autoload.files` — `src/modules.php`, `src/function_requirements.php` | **No** | Global `get_module_db()`, `get_module_settings()`, `register_module()`, `get_module_name()`, `get_valid_module()`, `has_module_db()`, `get_module_stuff()`, `get_service_define()`, `function_requirements()` |
| Composer `scripts` callable — `Plugin::setPermissions` | **No** | Runs on every install/update; Composer resolves script callables through the *project* autoloader, bypassing the plugin allowlist |

`MyAdmin\Plugins\Loader` is also loaded by ordinary PSR-4 autoloading and drives every route
registration in MyAdmin's `include/config/router.php`.

## Supported package types

`myadmin-template` · `myadmin-module` · `myadmin-plugin` · `myadmin-menu`

All four currently install to `vendor/`, exactly like any other package. Composer's default
`LibraryInstaller` accepts every type, so a custom installer only earns its place when a type
genuinely needs to land somewhere else — which none currently do.

Earlier versions documented `myadmin-template` packages installing to `data/templates/` or
`include/templates/`. That never worked: the branch tested the installer's own constructor
type rather than the package's type, so it could not fire in any configuration. It has been
removed rather than repaired, because MyAdmin packages read their own templates in place via
`__DIR__` and ship no web assets — there is nothing to copy out of `vendor/`.

## Composer commands

Available only when the package is allowed by `config.allow-plugins`.

| Command | Description |
|---|---|
| `myadmin` | Status overview: plugin counts, dispatch-table drift, dirty vendor working copies. Read-only. |
| `myadmin:update-plugins` | Rebuilds `include/config/hooks.json` and `plugins.json` from installed packages. `--dry-run`, `--show-skipped`. |
| `myadmin:set-permissions` | Applies `extra.writable-dirs` / `writable-files`. `--dry-run`. |

`myadmin:parse` and `myadmin:create-user` were removed. The former required
`phpdocumentor/reflection`, which was never a declared dependency, and could not have run.
The latter was demo scaffolding; creating a user needs the full application bootstrap and a
database connection, neither of which exists in a Composer process.

## Install-time automation

| Hook | Behaviour |
|---|---|
| `pre-install-cmd` | Warns about uncommitted changes in source-installed vendor packages |
| `pre-update-cmd` | **Aborts** on uncommitted vendor changes — override with `MYADMIN_ALLOW_DIRTY_VENDOR=1` |
| `post-autoload-dump` | Rebuilds `hooks.json` / `plugins.json`; reports what changed |
| `post-install-cmd`, `post-update-cmd` | Clears the MCP tool cache; ensures the `public_html/lib` → `node_modules` symlink exists |

The update-time abort exists because MyAdmin sets `preferred-install: source` for its own
packages together with `discard-changes: stash`. Without the guard, `composer update`
silently stashes uncommitted work in any of roughly a hundred vendor working copies, with no
prompt and no summary.

Every other hook is written to be no-op-or-warn: they run in CI, where `composer install` is
invoked without `--no-scripts`.

### Configuration

```json
{
    "extra": {
        "writable-dirs":  ["logs", "logs/smarty_cache", "logs/mcp_cache"],
        "writable-files": ["include/config/hooks.json"]
    }
}
```

Both keys are optional; a project declaring neither gets a clean no-op.

## Plugin discovery

`PluginScanner` globs `vendor/*/*/src/Plugin.php`, evaluates each package's `getHooks()`, and
rebuilds MyAdmin's two dispatch tables.

**Pruning keys on disk presence, not scan success.** Several MyAdmin packages reference
constants defined in `include/config/config.inc.php` — `PRORATE_BILLING`, `NORMAL_BILLING` —
which do not exist inside a Composer process, so their `getHooks()` throws when called from
here. An entry is therefore removed only when its package is genuinely gone from disk; a
package that is installed but unscannable keeps whatever hooks it already had. Rebuilding
purely from scan results would silently drop live modules from the dispatch table.

Writes are validated and atomic (temp file + `rename`), and an empty payload is refused,
because MyAdmin's `include/tf.php` decodes `hooks.json` with no null check — a truncated
write there is an immediate site-wide fatal.

## Requirements

- PHP 7.4 or later
- Composer 2.x (plugin API `^2.0`)

## Running tests

```sh
composer install
vendor/bin/phpunit
```

## License

MIT. See [LICENSE](LICENSE).
