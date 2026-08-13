<?php
/**
 * Plugins Management
 * @author Joe Huss <detain@interserver.net>
 * @copyright 2026
 * @package MyAdmin
 * @category Testing
 */

namespace MyAdmin\Plugins\Testing\Scaffold;

/**
 * Renders the agent-facing documentation a plugin package needs so the *next* session does
 * not undo the harness.
 *
 * ---------------------------------------------------------------------------------
 * WHY THIS IS PART OF THE SCAFFOLD AND NOT A ONE-OFF SWEEP
 * ---------------------------------------------------------------------------------
 * Every package in this fleet carries `.claude/skills/`, and 58 of them ship a skill whose
 * whole subject is how to write a test for that package. Those skills were written before
 * the contract harness existed and they teach what was true then: never instantiate the
 * plugin, never call `getSettings()`/`getActivate()`, check the signature with
 * `ReflectionMethod` and stop there. That advice is now exactly backwards — the harness
 * primes the constants those calls used to fatal on and executes the handlers for real —
 * and it is advice a model reads *first*, before it reads any code.
 *
 * So a conversion that ships only `tests/ContractTest.php` is half a conversion: the
 * package is on the harness and its own documentation still argues against it. Generating
 * the skill from the same command that generates the test is what keeps the two in step,
 * the same way {@see ContractTestGenerator} keeps 66 copies of one file in step.
 *
 * ---------------------------------------------------------------------------------
 * WHAT THIS DELIBERATELY DOES NOT DO
 * ---------------------------------------------------------------------------------
 * It does not rewrite or delete the skills a package already has. Those files carry real
 * per-package knowledge — which API wrapper takes an array, which class must not be
 * constructed because its constructor opens an IMAP connection — that is written down
 * nowhere else, and the fleet's standing rule is that nothing is removed without the
 * owner's say-so. {@see supersedeNotice()} is an *amendment* to be placed at the top of
 * such a file: it narrows what the file still governs and points the reader at the harness
 * for the rest, leaving every word underneath it intact.
 */
class SkillDoc
{
    /** Directory, relative to the package root, that the generated skill lives in. */
    const SKILL_PATH = '.claude/skills/plugin-contract-tests/SKILL.md';

    /**
     * Marker that makes the amendment idempotent.
     *
     * The sweep that applies it runs over 58 repositories and will be run again the next
     * time the guidance changes; it has to be able to tell "already amended" from "not yet"
     * without diffing prose.
     */
    const NOTICE_MARKER = '<!-- myadmin-contract-harness-notice -->';

    /**
     * Renders `.claude/skills/plugin-contract-tests/SKILL.md` for one package.
     *
     * INPUT:   $facts — measured, so the description names this package's real plugin class
     *          and the body claims service coverage only when the package has it.
     * RETURNS: string — complete file contents, LF-terminated.
     *
     * @param \MyAdmin\Plugins\Testing\Scaffold\PluginFacts $facts
     * @return string
     */
    public function render(PluginFacts $facts)
    {
        $file = $this->frontMatter($facts)
            .$this->critical($facts)
            .$this->instructions()
            .$this->orderingRules()
            .$this->serviceSection($facts)
            .$this->verifySection();

        // LF, whatever the host does — same reason as ContractTestGenerator: the heredocs
        // inherit this file's endings, and a Windows checkout with core.autocrlf would
        // commit CRLF into a repository whose every other file is LF.
        return str_replace("\r\n", "\n", $file);
    }

    /**
     * The amendment for a skill that predates the harness.
     *
     * It is a quotation block rather than a rewrite because the file underneath it is still
     * mostly right, and because a reader has to be able to see which part is the amendment
     * and which part is the package's own long-standing advice.
     *
     * @return string
     */
    public function supersedeNotice()
    {
        $notice = <<<'MD'
<!-- myadmin-contract-harness-notice -->
> ### ⚠️ Read this before the rest of the file
>
> This package is on the **shared plugin contract harness**. Parts of the guidance below
> predate it and are now wrong in one specific way:
>
> **Any instruction here that a plugin's `getHooks()` / `getSettings()` / `getActivate()` /
> `getDeactivate()` / `getQueue()` must not be *called* — that only its existence, visibility
> or parameter count may be checked through `ReflectionClass` — no longer applies.** That rule
> existed because those methods reference bare constants (`PRORATE_BILLING` and friends) that
> only a live MyAdmin request defines, so calling them from a test used to fatal. The harness
> defines them first. It then executes the handlers for real, in a process of its own.
>
> A reflection-only assertion passes whether or not the thing works: `getActivate()` can exist,
> be public, be static, take one argument, and still fatal the moment it runs. Three real
> production bugs in this fleet were sitting behind assertions of exactly that shape.
>
> **Use the `plugin-contract-tests` skill** for anything touching `tests/ContractTest.php`,
> the contract inspectors, or `composer myadmin:scaffold-tests`.
>
> **Everything else in this file is still accurate and still applies** — this package's own
> classes, its API wrappers, its fixtures, its bootstrap, and the reasons certain classes must
> not be constructed. Nothing below has been removed.

MD;

        return str_replace("\r\n", "\n", $notice)."\n";
    }

    /**
     * Clause appended to a superseded skill's `description:` line.
     *
     * The description is the only part of a skill a model reads when it is *choosing* one,
     * so an amendment that lives only in the body arrives too late to affect the choice.
     *
     * @return string
     */
    public function descriptionSuffix()
    {
        return ' NOTE: for a plugin\'s contract/behavioral tests (tests/ContractTest.php, the shared'
            .' harness, composer myadmin:scaffold-tests) use the plugin-contract-tests skill instead —'
            .' this skill\'s reflection-only guidance predates that harness.';
    }

    /**
     * Whether a file has already been amended.
     *
     * @param string $contents
     * @return bool
     */
    public function isAmended($contents)
    {
        return strpos((string)$contents, self::NOTICE_MARKER) !== false;
    }

    /**
     * @param \MyAdmin\Plugins\Testing\Scaffold\PluginFacts $facts
     * @return string
     */
    private function frontMatter(PluginFacts $facts)
    {
        $class = $facts->pluginClass();
        $extra = $facts->isServicePlugin()
            ? ' This is a type=service package, so the service lifecycle assertions apply too.'
            : '';

        return <<<MD
---
name: plugin-contract-tests
description: Sets up, regenerates or debugs the shared MyAdmin plugin contract harness for this package — tests/ContractTest.php, the contract inspectors, and `composer myadmin:scaffold-tests`. Use when the user says 'add tests to this plugin', 'set up the harness', 'scaffold tests', 'why is ContractTest failing', or when deciding whether a contract failure is the plugin's fault or the harness's.{$extra} Do NOT use for this package's own non-plugin classes — its other testing skills cover those.
---
# Plugin contract tests

The class under contract here is `{$class}`.


MD;
    }

    /**
     * @param \MyAdmin\Plugins\Testing\Scaffold\PluginFacts $facts
     * @return string
     */
    private function critical(PluginFacts $facts)
    {
        $module = $facts->module() === null
            ? 'This package declares no `$module`, which is correct for a `type=plugin` package and is'
                .' asserted bidirectionally by A-7 — adding one without adding the module is a failure.'
            : 'This package declares `$module = \''.$facts->module().'\'`. Changing it detaches the plugin'
                .' from the events core dispatches to it, so the generated pin asserts it.';

        return <<<MD
## Critical

- **Never hand-write or hand-edit `tests/ContractTest.php`.** It is generated. Run
  `composer myadmin:scaffold-tests` from inside this repo; regenerate with `--force`. A hand
  edit is invisible to the next regeneration and to the next person.
- **Never write a reflection-only test for the plugin class.** Asserting that a handler exists,
  is static and takes one parameter passes whether or not the handler works. Execute it. The
  harness has already done the hard part — priming the constants that used to make that
  impossible.
- **Never delete an existing test to make room.** The harness is strictly additive: `ContractTest`
  runs *alongside* whatever this package already had. Duplicate coverage is the cheaper mistake.
  Removing anything is a question for the owner first — that is a standing rule on this fleet.
- **Run the whole suite, never just `--filter ContractTest`.** The contract class primes constants
  and calls `register_module()`, neither of which can be undone, so it can change how this
  package's *other* tests behave. A filtered run cannot show that.
- **`composer myadmin:scaffold-tests` does not exist in MyAdmin core.** Core sets
  `config.allow-plugins: false`, so Composer never activates the installer there and no
  `myadmin:*` command is registered. Run it from this repo.
- {$module}

MD;
    }

    /**
     * @return string
     */
    private function instructions()
    {
        return <<<'MD'

## Instructions

### Step 1 — regenerate, do not edit

```bash
composer myadmin:scaffold-tests            # plan only; writes nothing
composer myadmin:scaffold-tests --write    # create what is missing
composer myadmin:scaffold-tests --force --write   # also re-emit tests/ContractTest.php
```

`CREATE` means a file is missing. `KEEP` means one exists and will not be touched. `DRIFT`
means an existing `phpunit.xml.dist` is missing a setting the harness depends on.

If Composer deadlocks, this package still vendors installer `v2.0.2`, which predates Composer
2's `PluginInterface` and fatals while activating — break it once with `composer update
--no-plugins`.

### Step 2 — fix a reported DRIFT by hand

The three settings are load-bearing, not stylistic:

- `failOnWarning="true"` — several findings surface first as a PHP warning; without it PHPUnit
  prints the finding and exits 0.
- `failOnRisky="true"` — a test asserting nothing because its subject would not load is risky,
  not passing.
- `beStrictAboutOutputDuringTests="true"` — assertion B-15 (a plugin must not echo while its
  handlers run) is unenforceable without it.

### Step 3 — classify a failure before changing anything

This decides *which repository you touch*, so do it first:

| symptom | verdict | action |
|---|---|---|
| the plugin genuinely does the wrong thing — uses a variable before assigning it, constructs a class with the wrong arity, registers a requirement path that does not exist | **P-bug** | fix in this repo, on its own branch, with its own review. Do not bundle it into a test-scaffolding commit |
| the harness accuses the plugin of something it did not do | **H-bug** | fix in `detain/myadmin-plugin-installer`, never here, and add the counter-test proving the inspector can still fail |
| the blocker is the environment — a `require` of a path that only exists inside a MyAdmin checkout | neither | the inspector should *skip*, naming the blocker. If it fails instead, that is an H-bug |

Three H-bugs have shipped, and all three were the harness falsely accusing a plugin: a shadowed
observer read as dead code (v2.1.1), a failed `require` read as the handler's own logic (v2.1.2),
and a Windows path treated as relative so every package looked like it shipped no templates
(v2.2.1). **Suspect the harness first** when a verdict changes depending on how the suite was
launched, or when a finding fires on every package at once.

### Step 4 — if the generated file is wrong, change the generator

`src/Testing/Scaffold/ContractTestGenerator.php` in the installer is the single source of truth
for all 66 generated copies. Fix it there, tag, then regenerate here.

MD;
    }

    /**
     * @return string
     */
    private function orderingRules()
    {
        return <<<'MD'

## Three ordering rules the generated file encodes

They look like style. They are not.

1. **`primeConstants()` runs before the plugin class is mentioned at all.** A static property
   initializer can reference a bare constant — `$settings` holding
   `REPEAT_BILLING_METHOD => PRORATE_BILLING` is the common shape — and initializers run on class
   *load*, so even reading `::$type` fatals on an unprimed class.
2. **The hook table is read through `TierA5HooksAreIdempotent::hookTable()`,** never a direct
   `getHooks()` call. A direct call is a second, independent answer to a question A-5 owns, and
   the two disagree for any plugin whose body touches a bare constant.
3. **The table is evaluated exactly once.** Calling `getHooks()` twice asserts idempotence by
   accident and doubles whatever side effect the body has.

Plus `@runTestsInSeparateProcesses` + `@preserveGlobalState disabled`, always.

### Namespaced stubs

If this package ships a `tests/stubs.php` declaring helpers **inside the plugin's own
namespace**, PHP binds the plugin's unqualified calls to those rather than to the harness's
observers. Eight packages in the fleet do this. The harness detects the shadow and skips instead
of accusing, but the assertion is then vacuous. Prefer forwarding such a stub into the harness
over making it a no-op, so the observation still lands.

MD;
    }

    /**
     * The extra section a `type=service` package gets.
     *
     * @param \MyAdmin\Plugins\Testing\Scaffold\PluginFacts $facts
     * @return string
     */
    private function serviceSection(PluginFacts $facts)
    {
        if (!$facts->isServicePlugin()) {
            return '';
        }

        return <<<'MD'

## Service lifecycle (this package is `type=service`)

`ContractTest` extends `ServicePluginTestCase`, which adds the assertions the eighteen shared
inspectors cannot make: it drives `getActivate()`, `getDeactivate()`, `getChangeIp()` and
`getQueue()` **twice** — once for a service type this plugin owns and once for a type it does
not — and asserts it acts on the first and stays inert for the second.

That second half is the one that finds things. A handler that ignores its service-type guard
looks perfectly healthy until something else in the fleet dispatches the same event.

If one of these fatals, read it as a P-bug until proven otherwise: it means the handler has
never run under test before, which is precisely the gap the harness was built to close.

MD;
    }

    /**
     * @return string
     */
    private function verifySection()
    {
        return <<<'MD'

## Verify

```bash
vendor/bin/phpunit
```

Whole suite, green, before committing.

## Reference

- `docs/testing-harness.md` in `detain/myadmin-plugin-installer` — §1.5 scaffolding, §3 traps,
  §7 the P-bug/H-bug split, §11 the generated file.
- `.claude/rules/plugin-tests.md` in MyAdmin core.
MD;
    }
}
