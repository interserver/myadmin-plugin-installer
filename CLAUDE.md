# MyAdmin Plugin Installer

Composer plugin providing custom installer logic for the MyAdmin hosting control panel ecosystem. Routes packages to correct directories by type and exposes Composer commands.

## Commands

```bash
composer install                          # install deps
vendor/bin/phpunit                        # run all tests
vendor/bin/phpunit tests/InstallerTest.php # run single test file
vendor/bin/phpunit --filter testSupports   # run specific test
```

## Architecture

**Namespace**: `MyAdmin\Plugins\` → `src/` · **Tests**: `Tests\MyAdmin\Plugins\` → `tests/`

**Entry point**: `src/Plugin.php` — implements `PluginInterface`, `EventSubscriberInterface`, `Capable`. Registers `src/Installer.php` via `activate()` and exposes `src/CommandProvider.php` via `getCapabilities()`.

**Installers**:
- `src/Installer.php` — extends `LibraryInstaller`, supports `myadmin-template`, `myadmin-module`, `myadmin-plugin`, `myadmin-menu`. Routes templates to `include/templates/`, others to `vendor/`
- `src/TemplateInstaller.php` — standalone template installer, routes `myadmin-template` packages to `data/templates/{name}`
- `src/InstallerPlugin.php` — minimal plugin that only registers `Installer`
- `src/TemplateInstallerPlugin.php` — subscribes to `PRE_FILE_DOWNLOAD` event

**Commands** (`src/Command/`) — all extend `Composer\Command\BaseCommand`:
- `Command.php` → `myadmin` base command
- `Parse.php` → `myadmin:parse` — parses PHP DocBlocks via `phpDocumentor\Reflection`
- `CreateUser.php` → `myadmin:create-user` — requires `username` argument
- `UpdatePlugins.php` → `myadmin:update-plugins` — discovers and caches plugins
- `SetPermissions.php` → `myadmin:set-permissions` — calls `Plugin::setPermissions()`
- `src/CommandProvider.php` — implements `CommandProviderCapability`, returns all 5 commands

**Helpers**:
- `src/Loader.php` — route registration system with `add_route_requirement()`, `add_page_requirement()`, `add_ajax_page_requirement()`, `add_api_page_requirement()`, `add_admin_page_requirement()`. Routes sorted by path length descending.
- `src/function_requirements.php` — delegates to `$GLOBALS['tf']->function_requirements()`
- `src/modules.php` — `register_module()`, `get_module_db()`, `get_module_settings()`, `get_module_name()`, `get_valid_module()`, `has_module_db()`

## Package Types

| Type | Install Path |
|---|---|
| `myadmin-template` | `data/templates/{name}` (via `TemplateInstaller`) or `include/templates/` (via `Installer`) |
| `myadmin-module` | `vendor/{name}` |
| `myadmin-plugin` | `vendor/{name}` |
| `myadmin-menu` | `vendor/{name}` |

## Testing Patterns

PHPUnit 9 with config at `phpunit.xml.dist`. Tests use `ReflectionClass` extensively to verify:
- Class hierarchy (`getParentClass()->getName()`)
- Interface implementation (`implementsInterface()`)
- Method visibility (`isPublic()`, `isProtected()`, `isStatic()`)
- Constructor parameter counts

Stub creation pattern — use `newInstanceWithoutConstructor()` to avoid Composer DI:
```php
private function createInstallerStub(): Installer
{
    $ref = new ReflectionClass(Installer::class);
    return $ref->newInstanceWithoutConstructor();
}
```

Test files mirror `src/` structure: `tests/InstallerTest.php`, `tests/LoaderTest.php`, `tests/Command/CommandTest.php`, etc.

## Conventions

- PHP >= 7.4, Composer plugin API ^2.0
- PSR-4 autoload with additional `files` autoload for `src/function_requirements.php` and `src/modules.php`
- Tabs for indentation (per `.scrutinizer.yml` coding style)
- `composer.json` `extra.class` points to `MyAdmin\Plugins\Plugin`
- Commit messages: lowercase, descriptive
- CI: `.travis.yml` (legacy), `.scrutinizer.yml`, `.codeclimate.yml`, `.bettercodehub.yml`
- `composer-plugins-installer.json` is an alternate/legacy composer config

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
  2. Run: `caliber refresh && git add CLAUDE.md .claude/ .cursor/ .cursorrules .github/copilot-instructions.md .github/instructions/ AGENTS.md CALIBER_LEARNINGS.md .agents/ .opencode/ 2>/dev/null`
  3. After it completes, briefly tell the user what Caliber updated. Then proceed with the commit.

If `caliber` is not found, tell the user: "This project uses Caliber for agent config sync. Run /setup-caliber to get set up."
<!-- /caliber:managed:pre-commit -->

<!-- caliber:managed:learnings -->
## Session Learnings

Read `CALIBER_LEARNINGS.md` for patterns and anti-patterns learned from previous sessions.
These are auto-extracted from real tool usage — treat them as project-specific rules.
<!-- /caliber:managed:learnings -->

<!-- caliber:managed:sync -->
## Context Sync

This project uses [Caliber](https://github.com/caliber-ai-org/ai-setup) to keep AI agent configs in sync across Claude Code, Cursor, Copilot, and Codex.
Configs update automatically before each commit via `caliber refresh`.
If the pre-commit hook is not set up, run `/setup-caliber` to configure everything automatically.
<!-- /caliber:managed:sync -->
