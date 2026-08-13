---
name: plugin-contract-tests
description: Sets up or fixes the shared contract test harness for a MyAdmin plugin package — generating tests/ContractTest.php via `composer myadmin:scaffold-tests`, and interpreting what the 18 contract inspectors report. Use when the user says 'add tests to this plugin', 'set up the harness', 'scaffold tests', or when a plugin's ContractTest is failing and you need to decide whether the harness or the plugin is wrong. Do NOT use for testing the installer's own src/ classes — that is the `phpunit-test` skill.
---
# Plugin contract tests

## Critical

- **Never hand-write `tests/ContractTest.php`.** Run `composer myadmin:scaffold-tests` from inside the plugin repo. The file encodes three non-obvious ordering rules (below) that are easy to get wrong and whose breakage is silent.
- **Never write reflection-only tests for a plugin.** Asserting that `getActivate()` exists, is static, and takes one parameter passes whether or not activation works. Several packages' skills still teach that pattern from before this harness existed; it is what the harness replaced. Execute the handler.
- **Never delete a package's existing tests to make room.** The conversion is strictly additive: `ContractTest` runs *alongside* whatever was there. Duplicate coverage is the cheaper mistake. If something genuinely must go, ask the owner first — this is a standing rule on this fleet.
- **The command does not exist in MyAdmin core.** Core sets `config.allow-plugins: false`, so Composer never activates the installer there and no `myadmin:*` command is registered. Run it from the plugin repo.
- **`composer update --no-plugins`** if the package still vendors installer v2.0.2. That release predates Composer 2's `PluginInterface`, so activating it fatals *while updating the very package that would replace it*.

## Instructions

### Step 1: Get the package onto the harness

```bash
cd /path/to/myadmin-<package>
composer require detain/myadmin-plugin-installer:^2.1   # add --no-plugins if it deadlocks
composer myadmin:scaffold-tests                          # plan only — writes nothing
```

Read the plan. `CREATE` means a file is missing; `KEEP` means one exists and will not be touched; `DRIFT` means an existing `phpunit.xml.dist` lacks a setting the harness depends on.

```bash
composer myadmin:scaffold-tests --write
vendor/bin/phpunit
```

### Step 2: If a `DRIFT` was reported, fix it by hand

The three settings are not stylistic:

- `failOnWarning="true"` — several findings surface first as a PHP warning; without it PHPUnit prints the finding and exits 0.
- `failOnRisky="true"` — a test asserting nothing because its subject would not load is risky, not passing.
- `beStrictAboutOutputDuringTests="true"` — assertion B-15 is unenforceable without it.

### Step 3: Read the failures before changing anything

Every finding names a file and a line. Classify it first — this is the D7 rule and it decides which repo you touch:

| symptom | verdict | action |
|---|---|---|
| the plugin genuinely does the wrong thing (uses a variable before assigning it, constructs a class with the wrong arity) | **P-bug** | its own branch in the plugin repo, its own review. Do not bundle it into a test-scaffolding commit |
| the harness accuses a plugin of something it did not do | **H-bug** | fix in the installer, never in the plugin, and add the counter-test that proves the inspector can still fail |
| the failure is the environment (a `require` of a path that only exists in a MyAdmin checkout) | neither | the inspector should report a skip naming the blocker; if it reports a failure, that is an H-bug |

Two H-bugs have shipped already, both false accusations: v2.1.1 (a shadowed observer read as dead code) and v2.1.2 (a failed `require` read as the handler's own logic). Suspect the harness when a verdict changes depending on *how* the suite was launched.

### Step 4: If you must change the generated file, change the generator

`src/Testing/Scaffold/ContractTestGenerator.php` in the installer is the source of truth for all 66 copies. Regenerate with `--force`. A hand-edit is invisible to the next person and to the next regeneration.

## The three ordering rules the generated file encodes

Know these, because they look like style and are not:

1. **`primeConstants()` comes before the plugin class is mentioned at all.** A static property initializer can reference a bare constant, and initializers run on class *load* — so even reading `::$type` fatals on an unprimed class.
2. **The hook table is read via `TierA5HooksAreIdempotent::hookTable()`,** never a direct `getHooks()` call. A direct call is a second, independent answer to a question A-5 owns, and the two disagree for constant-referencing plugins.
3. **The table is evaluated exactly once.** Calling `getHooks()` twice asserts idempotence by accident and doubles any side effect.

Plus `@runTestsInSeparateProcesses` + `@preserveGlobalState disabled`, always: inspecting a plugin defines constants and calls `register_module()`, and neither can be undone, so without isolation the new file changes the outcome of tests the package already had.

## Namespaced stubs

If the package ships a `tests/stubs.php` declaring helpers **inside the plugin's own namespace**, know that PHP binds the plugin's unqualified calls to those instead of the harness's observers. Eight packages do this. The harness detects the shadow and skips rather than accusing, but the assertion is then vacuous. Prefer forwarding such a stub into the harness over making it a no-op, so the observation still lands.

## Verify

```bash
vendor/bin/phpunit                    # the whole suite, never just --filter ContractTest
```

The full suite matters: the isolation annotations exist because adding this file can change how the package's *other* tests behave, and a filtered run cannot show that.

## Reference

`docs/testing-harness.md` in the installer — §1.5 scaffolding, §3 traps, §7 H-bug vs P-bug, §11 the generated file.
