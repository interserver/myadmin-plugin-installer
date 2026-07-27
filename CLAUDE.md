# MyAdmin Plugin Installer

Composer integration for the MyAdmin hosting control panel: install-time automation, a few `composer myadmin:*` commands, and the runtime helpers MyAdmin loads on every request.

## ⚠️ Load-bearing at runtime

Consuming projects set `"detain/myadmin-plugin-installer": false` in `config.allow-plugins`. That does **not** make this package inert. Three load paths exist; `allow-plugins` gates only one:

1. **Composer plugin** (`Plugin::activate()`, `Installer`, `CommandProvider`, `Command/*`) — gated. Currently inactive in MyAdmin.
2. **`autoload.files`** (`src/modules.php`, `src/function_requirements.php`) — NOT gated. Loads every request. Sole definition site of the global `get_module_db()`, `get_module_settings()`, `register_module()`, `get_module_name()`, `get_valid_module()`, `has_module_db()`, `get_module_stuff()`, `get_service_define()`, `function_requirements()`.
3. **Composer `scripts` callable** (`Plugin::setPermissions`) — NOT gated. Composer resolves script callables through the *project* autoloader. Runs on every install/update; only `--no-scripts` stops it.

`MyAdmin\Plugins\Loader` is loaded by plain PSR-4 and drives all route registration in MyAdmin's `include/config/router.php`. **Removing this package fatals the application.**

## Commands

```bash
composer install                            # install deps (creates vendor/, gitignored)
vendor/bin/phpunit                          # run all tests
vendor/bin/phpunit tests/InstallerTest.php  # single file
vendor/bin/phpunit --filter testSupports    # single test
```

## Architecture

**Namespace**: `MyAdmin\Plugins\` → `src/` · **Tests**: `Tests\MyAdmin\Plugins\` → `tests/`

**Entry point**: `src/Plugin.php` — the single `extra.class`. Implements `PluginInterface`, `EventSubscriberInterface`, `Capable`. Subscribes to all 25 Composer 2.10 events (6 `PluginEvents` + 12 `ScriptEvents` + 6 `PackageEvents` + 1 `InstallerEvents`); most handlers are documented no-ops.

**Core classes**:
- `src/Installer.php` — extends `LibraryInstaller`. Overrides all 10 `InstallerInterface` methods, each delegating to the parent and **returning its promise**. Claims `myadmin-template`, `myadmin-module`, `myadmin-plugin`, `myadmin-menu`; all resolve to `vendor/`.
- `src/PluginScanner.php` — discovers `vendor/*/*/src/Plugin.php`, rebuilds `include/config/hooks.json` and `plugins.json`.
- `src/VendorGuard.php` — detects uncommitted work in source-installed vendor packages.
- `src/Loader.php` — route registration used by MyAdmin's router.
- `src/CommandProvider.php` — the only capability Composer 2.10 defines.

**Commands** (`src/Command/`): `myadmin` (read-only status), `myadmin:update-plugins` (rebuild dispatch tables), `myadmin:set-permissions` (apply `extra.writable-*`).

**Removed**: `src/Command/Parse.php` (needed an undeclared `phpdocumentor/reflection`), `src/Command/CreateUser.php` (demo scaffolding needing app bootstrap + DB), `src/InstallerPlugin.php`, `src/TemplateInstaller.php`, `src/TemplateInstallerPlugin.php` (duplicate plugin entry points and an unreachable template installer), plus `composer-plugins-installer.json` and `.travis.yml`. CI is `.github/workflows/tests.yml`. Audit: `docs/audit-2026-07-27.md`.

## Non-obvious constraints

- **`prepare()`/`cleanup()` must leave `$type` unhinted.** `InstallerInterface` declares `string $type`; `LibraryInstaller` does not. Adding the hint to a `LibraryInstaller` subclass is a signature-compatibility fatal at class load.
- **Installer overrides must return the parent's promise.** Composer 2 batches operations across packages and awaits these; dropping one corrupts installs in ways that look random.
- **`addInstaller()` prepends.** Widening `supports()` takes packages away from Composer's catch-all `LibraryInstaller`, which accepts every type.
- **`PRE_DEPENDENCIES_SOLVING` / `POST_DEPENDENCIES_SOLVING` do not exist in Composer 2.** Use `PRE_POOL_CREATE`.
- **`PostFileDownloadEvent::getPackage()` is deprecated** and fires `E_USER_DEPRECATED` on every call. Use `getContext()`.
- **Plugin scanning prunes on disk presence, not scan success.** Packages referencing `PRORATE_BILLING` / `NORMAL_BILLING` throw when `getHooks()` is called outside a MyAdmin request. Pruning on scan failure would delete live modules from the dispatch table.
- **`hooks.json` writes must be validated and atomic.** MyAdmin's `include/tf.php` `json_decode()`s it with no null check; a truncated write is a site-wide fatal.
- **Script-event handlers run in CI.** MyAdmin's workflows run `composer install` without `--no-scripts` across four PHP legs. Everything must be no-op-or-warn. The one deliberate exception is the `pre-update-cmd` dirty-vendor abort, which cannot trigger on a fresh runner.

## Package Types

| Type | Install Path |
|---|---|
| `myadmin-template` | `vendor/{name}` |
| `myadmin-module` | `vendor/{name}` |
| `myadmin-plugin` | `vendor/{name}` |
| `myadmin-menu` | `vendor/{name}` |

Template routing to `data/templates/` or `include/templates/` was removed: it branched on the installer's own constructor type rather than the package's, so it was unreachable in every configuration. MyAdmin packages read templates in place via `__DIR__` and ship no web assets.

## Testing Patterns

PHPUnit 9, config `phpunit.xml.dist`, bootstrap `vendor/autoload.php`.

**Prefer behaviour over reflection.** Assert what a method *does*, not that it exists or is public. An earlier suite was ~48% pure reflection and concealed three real bugs, including a guaranteed `ArgumentCountError`.

- `PluginScannerTest` / `VendorGuardTest` build **real fixture trees** in `sys_get_temp_dir()` — temp vendor packages and actual `git init` repos. The behaviour under test is filesystem discovery and git output, which cannot be meaningfully mocked.
- `PluginPermissionsTest` builds a real `Composer\Script\Event` from a `RootPackage` plus `BufferIO`, so IO can be asserted.
- `tests/ModulesTest.php` covers the `autoload.files` globals from `src/modules.php`.
- Fixture plugin classes need a **unique namespace per test** — `include_once` in one process means colliding class names are fatal.
- `clearstatcache(true, $path)` before asserting on `fileperms()`; an earlier `is_dir()` populates the stat cache before the `chmod` lands.
- Source-text assertions must use the `SourceInspection` trait's `codeOf()` (`tests/Support/SourceInspection.php`), which strips comments. A raw `file_get_contents()` scan matches the docblocks describing what was removed.

## Conventions

- PHP >= 7.4, Composer plugin API ^2.0
- PSR-4 autoload plus `files` autoload for `src/function_requirements.php` and `src/modules.php`
- `composer.json` `extra.class` points to `MyAdmin\Plugins\Plugin`
- Commit messages: lowercase, descriptive
- CI: `.scrutinizer.yml`, `.codeclimate.yml`, `.bettercodehub.yml`, `.github/workflows/tests.yml`

<!-- caliber:managed:pre-commit -->
## Before Committing

**IMPORTANT:** Before every git commit, you MUST ensure Caliber syncs agent configs with the latest code changes.

First, check if the pre-commit hook is already installed:
```bash
grep -q "caliber" .git/hooks/pre-commit 2>/dev/null && echo "hook-active" || echo "no-hook"
```

- If **hook-active**: the hook handles sync automatically — just commit normally. Tell the user: "Caliber will sync your agent configs automatically via the pre-commit hook."
- If **no-hook**: run Caliber manually before committing:
  1. Tell the user: "Caliber: Syncing agent configs with your latest changes..."
  2. Run: `caliber refresh && git add CALIBER_LEARNINGS.md CLAUDE.md .claude/ .github/copilot-instructions.md .github/instructions/ 2>/dev/null`
  3. After it completes, briefly tell the user what Caliber updated. Then proceed with the commit.

**Valid `caliber refresh` options:** `--quiet` (suppress output) and `--dry-run` (preview without writing). Do not pass any other flags — options like `--auto-approve`, `--debug`, or `--force` do not exist and will cause errors.

**`caliber config`** takes no flags — it runs an interactive provider setup. Do not pass `--provider`, `--api-key`, or `--endpoint`.

If `caliber` is not found, tell the user: "This project uses Caliber for agent config sync. Run /setup-caliber to get set up."
<!-- /caliber:managed:pre-commit -->

<!-- caliber:managed:learnings -->
## Session Learnings

Read `CALIBER_LEARNINGS.md` for patterns and anti-patterns learned from previous sessions.
These are auto-extracted from real tool usage — treat them as project-specific rules.
<!-- /caliber:managed:learnings -->

<!-- caliber:managed:model-config -->
## Model Configuration

Recommended default: `claude-sonnet-4-6` with high effort (stronger reasoning; higher cost and latency than smaller models).
Smaller/faster models trade quality for speed and cost — pick what fits the task.
Pin your choice (`/model` in Claude Code, or `CALIBER_MODEL` when using Caliber with an API provider) so upstream default changes do not silently change behavior.

<!-- /caliber:managed:model-config -->

<!-- caliber:managed:sync -->
## Context Sync

This project uses [Caliber](https://github.com/caliber-ai-org/ai-setup) to keep AI agent configs in sync across Claude Code, Cursor, Copilot, and Codex.
Configs update automatically before each commit via `caliber refresh`.
If the pre-commit hook is not set up, run `/setup-caliber` to configure everything automatically.
<!-- /caliber:managed:sync -->
