# MyAdmin plugin test harness

`MyAdmin\Plugins\Testing\` — the shared harness that lets a plugin's handlers
actually **execute** under test, instead of being described by reflection.

**Status: released and rolled out.** Merged to `master` and tagged `v2.1.0`,
then `v2.1.1` and `v2.1.2` for the two harness bugs recorded in §11. The fleet
requires `^2.1`; 66 packages carry a generated `tests/ContractTest.php`.

The section ordering is historical — §§1–10 were written during Phase 1, when
the question was whether the harness could work at all, and the measurements
in them are worth keeping. If you are here to make a package use the harness,
you want **§1.5 (scaffolding)** and **§11 (the generated file)**; if you are
here because a build went red, you want **§3 (traps)** and **§7 (H-bug vs
P-bug)**.

---

## 1. Using it

A plugin's entire `tests/bootstrap.php`:

```php
<?php
require __DIR__ . '/../vendor/autoload.php';
\MyAdmin\Plugins\Testing\Bootstrap::init(['module' => 'vps']);
```

Three lines, replacing the 341 in `myadmin-vps-module` and the 4,366 spread
across 30 repos in four competing conventions.

Options:

| key | type | effect |
|---|---|---|
| `module` | string | drives `register_module()` and `$GLOBALS['<module>_dbh']` |
| `settings` | array | `PREFIX`/`TABLE`/`TBLNAME`/… for `get_module_settings()`; sensible defaults derived from `module` |
| `constants` | array | explicit constant values, applied **before** anything else |
| `plugin` | class-string | scan this class's file for bare constants (D4) |
| `ima` | string | `App::ima()` — `admin` or `client` |
| `acl` | array\|true | `has_acl()` allowlist, or `true` to grant everything |
| `defines` | array | name => int for `get_service_define()` |
| `request` | array | seeds `App::variables()` |
| `rows` | array | rows the module `FakeDb` hands out via `next_record()` |

`init()` is idempotent — safe from `tests/bootstrap.php` *and* every `setUp()`.
It returns the `Harness` class name; reach the fakes through
`Harness::settings()`, `Harness::db()`, `Harness::history()`, and so on.

---

## 1.5 Scaffolding a package

```bash
cd /path/to/myadmin-something
composer require detain/myadmin-plugin-installer:^2.1
composer myadmin:scaffold-tests            # prints the plan, writes nothing
composer myadmin:scaffold-tests --write    # applies it
```

**Run it from inside the plugin repo, never from MyAdmin core.** Core sets
`config.allow-plugins: false`, so Composer never activates this package there
and none of the `myadmin:*` commands exist at all. From core the command is
not broken; it is absent.

What it does:

| file | when |
|---|---|
| `tests/ContractTest.php` | created if absent; regenerated only under `--force` |
| `phpunit.xml.dist` | created if absent; otherwise audited for the settings the harness depends on |
| `.github/workflows/tests.yml` | created if absent, with the PHP matrix and extension list derived from the package's own `composer.json` |

**Nothing existing is ever overwritten.** Across the 66 converted packages
there are 55 distinct `phpunit.xml.dist` files and 63 distinct workflows, and
most of that variation is a package knowing something about itself that is
written down nowhere else — an `ext-imap` requirement, an older PHP floor.
Flattening it would be a silent deletion. An existing config is instead
*audited*, and reported as `DRIFT` when it lacks one of:

| setting | why the harness depends on it |
|---|---|
| `failOnWarning="true"` | several contract findings surface first as a PHP warning — a `require()` whose path does not resolve, most often. Without this, PHPUnit prints the finding and still exits 0 |
| `failOnRisky="true"` | a test asserting nothing because its subject could not load is risky, not passing |
| `beStrictAboutOutputDuringTests="true"` | assertion B-15 (a plugin must not echo while its handlers run) is unenforceable without it |

### The facts are measured, not parsed

The generated pin records what the plugin *actually registers*, obtained by
executing it: `src/Testing/Scaffold/probe.php` boots the harness, primes the
plugin's bare constants, and calls `getHooks()`. This matters because in this
fleet the hook table is not a literal anywhere in the source — it is assembled
from `self::$module` and from constants the host defines at runtime, so a
tokenizer would report the expression and only execution reports the key.

The probe runs in a process of its own, always. Priming defines real constants
and calls `register_module()`; PHP cannot undefine a constant and
`register_module()` has no inverse, so a probe sharing the caller's process
would contaminate it permanently — and it needs the *package's* autoloader,
not the installer's.

Its stdout is a data channel carrying exactly one line of JSON. Deprecations
from a package still on an old vendored installer are routed to stderr,
because one landing on stdout corrupts the payload.

---

## 2. How it works, and why it is not what the plan first proposed

### The problem §629 identified

The installer is a `composer-plugin`, and its `autoload.files` already loads
`src/modules.php` and `src/function_requirements.php` into **every production
install**. Those two files define:

```
register_module   get_module_stuff   get_module_name    get_module_settings
get_service_define  has_module_db    get_module_db      get_valid_module
function_requirements
```

So a `stubs.php` that tries to redefine `get_module_db()` and friends is **dead
code**: the `function_exists()` guard is always false. This was confirmed
empirically — a repo's bootstrap stubs were silently doing nothing.

### The resolution, verified by spike rather than assumed

Three of the four contested functions are *pure delegations to `\MyAdmin\App`*:

```php
function function_requirements($f) { return \MyAdmin\App::functionRequirements($f); }
function get_service_define($s)    { return \MyAdmin\App::getServiceDefine($s); }
function get_module_db($m)         { /* … App::has() / App::db() … */ }
```

`Bootstrap::init()` therefore aliases `FakeApp` into `\MyAdmin\App`, and the
**real, unmodified installer functions then work against the fakes**. No
shadowing, no guard fight.

The fourth needs nothing at all: `get_module_settings()` reads
`$GLOBALS['modules']`, which `Bootstrap` populates through the installer's own
`register_module()`.

Spike results (`class_alias` — risk R9, "the single assumption most likely to
invalidate the phase"):

| # | check | result |
|---|---|---|
| B1 | `class_alias(FakeApp, 'MyAdmin\App')` | **true** |
| B4 | real `function_requirements()` → fake | **works** |
| B5 | real `get_service_define()` → fake | **works** |
| B6 | real `get_module_settings()` after `register_module()` | **works, no fake needed** |
| B7 | real `get_module_db()` → recording fake | **works** |
| B9 | namespace-local function beats installer global | **true** |

**R9 is retired.** The documented fallback still exists: if `\MyAdmin\App`
already exists (harness run inside a core bootstrap, or a repo's own legacy
doubles declared one), `installApp()` stands down and returns false rather than
fighting for the name. A second `class_alias()` to an existing name emits a
warning and returns false, and every plugin's `phpunit.xml.dist` sets
`failOnWarning="true"`, so that guard is load-bearing rather than tidy.

### Order of operations in `init()`

1. **Constant overrides**, then scanned stubs. Constants are immutable, so an
   explicit value must be defined before anything else claims the name.
2. **Install `FakeApp`** — before any plugin code and before step 4, because
   `get_module_db()` delegates to `App::has()` / `App::db()`.
3. **Load `stubs.php`** — before step 4, because `get_module_db()`'s fallback
   path calls `myadmin_log()`, which fatals if the stub is not yet defined.
   *(Found by spike, not by reading.)*
4. **`register_module()` + `$GLOBALS['<module>_dbh']`.**

---

## 3. Things that will bite you

### 3.1 Constants are immutable — plan for it up front

PHP constants are process-global and cannot be redefined. A test needing a
*different* value for an already-defined constant must either:

- pass it through `Bootstrap::init(['constants' => …])` **before** anything else
  defines it, or
- use `@runInSeparateProcess`, or
- **be redesigned not to depend on the value** — almost always the right answer.

`Harness::reset()` deliberately does **not** touch constants. A reset that tried
would either fatal or silently do nothing.

This also has a fleet-scale consequence: the fleet-wide smoke runs **one plugin
per process**, because two plugins defining the same constant with different
values cannot coexist.

### 3.2 Do not touch a plugin's statics before `init()`

PHP evaluates a static property's initializer lazily, **on first access to the
class**. Nine repos have a `$settings` initializer referencing `PRORATE_BILLING`,
so:

```php
$module = Plugin::$module;                  // <-- Error: Undefined constant
Bootstrap::init(['module' => $module]);
```

fatals, while the same code with the two lines swapped works. Read the module
out of the source text, or call `Bootstrap::init(['plugin' => Plugin::class])`
first and *then* read the property. The generated `tests/bootstrap.php` does the
former.

The reassuring corollary: `class_exists()` inside `ConstantStub::defineFrom()`
autoloads the class file **without** evaluating initializers, so scan-then-define
is safe. `ConstantOrderingTest` pins all of this.

### 3.3 `get_module_db()` returns a **clone**

This is the subtlest trap in the harness and the one most likely to produce a
silently-passing test. Core hands the caller `clone $GLOBALS['<module>_dbh']`.
A fake recording into a plain array property would have the handler write into
the clone while the test asserts against the empty original — a false pass.

Every fake therefore records into a shared `CallLog` **object**; shallow clone
copies the reference, so recordings survive. `Recorder::initRecorder()` must be
called from each fake's constructor: lazy creation would let a fake cloned
before its first call end up with two divergent logs.

`myadmin-servers-module` solved the same problem with `public static $queries`,
which works but makes two independent module handles impossible in one process.

### 3.4 `$event['success']` throws on a missing key

Symfony's `GenericEvent::offsetGet()` raises
`InvalidArgumentException: Argument "success" not found` — it does **not**
return null. Verified:

```
hasArgument(success): false
offsetGet THREW: InvalidArgumentException: Argument "success" not found.
```

Any assertion of the form *"a foreign type must not be marked successful"* must
use `$event->hasArgument('success')`. Fleet status as of 2026-08-03: **zero**
direct `$event['success']` accesses remain in `src/` or `tests/`;
`myadmin-whmsonic-licensing` and `myadmin-zonemta-mail` already use
`hasArgument()`. This is a forward-looking rule for Phase 3's catalogue, not a
backlog.

### 3.5 `powerdns` and `zonemta` are hardcoded in the installer

`get_module_db()` special-cases both and constructs a real `\MyDb\Mdb2\Db` /
`\MyDb\Mysqli\Db` against undefined connection constants — `Class "MyDb\Mdb2\Db"
not found`. Presetting `$GLOBALS['powerdns_dbh']` / `$GLOBALS['zonemta_dbh']`
skips that construction entirely, so neither module needs special casing.
`Bootstrap::installModuleDb()` does this for whatever module it is given.

---

## 4. D2 — test stubs must never reach production

`src/Testing/stubs.php` defines `myadmin_log()`, `has_acl()` and `dialog()`. If
it ever joined `autoload.files` it would shadow the real implementations in
every production install: logging would silently stop and `has_acl()` would
return a fixed answer for every permission check. Risk R2, severity Critical.

Enforcement is a **tripwire, not a comment**:

- `AutoloadTripwireTest` fails if any `autoload.files` entry contains `Testing`.
- It also pins `autoload.files` to exactly `['src/function_requirements.php',
  'src/modules.php']`, so *any* new entry is a deliberate decision.
- It asserts `stubs.php` declares no class, and that every function in it is
  `function_exists`-guarded.

**Verified to fail, not merely asserted to exist.** Adding
`src/Testing/stubs.php` to `autoload.files` turns the suite red via
`AutoloadTripwireTest` (mutation M1); removing the `function_exists` guard from
`myadmin_log()` turns it red via the same class (mutation M9).

`stubs.php` is loaded from exactly one place: `Bootstrap::loadStubs()`.

---

## 5. Mutation results

Every new assertion was verified by breaking the thing it guards and confirming
the suite goes red. An assertion never observed failing is not evidence.

| # | mutation | result |
|---|---|---|
| M1 | `stubs.php` added to `autoload.files` | KILLED |
| M2 | `FakeDb` records into a plain array | KILLED |
| M3 | `Recorder` creates its `CallLog` lazily | KILLED |
| M4 | `FakeSettings::add_dropdown_setting()` loses `$labels` | KILLED |
| M5 | `ConstantStub` stops skipping `Foo::BAR` | KILLED |
| M6 | `ConstantStub` denylist loses `COMMAND`/`DEBUG` | KILLED |
| M7 | `Bootstrap` stops presetting `$GLOBALS['<module>_dbh']` | KILLED |
| M8 | `FakeApp::has()` returns false | **SURVIVED — equivalent mutant** |
| M9 | `myadmin_log()` stub loses its guard | KILLED |
| M10 | `syntheticDefine()` returns a constant | KILLED |
| M11 | `TestContainerBuilder` alias not installed | KILLED |
| M12 | `FakeApp` ignores a bound `tf` in `getServiceDefine()` | KILLED |
| M13 | `ConstantStub` scan gains a side effect | KILLED |
| M14 | `FakeSettings::get_setting()` ignores defined constants | KILLED |
| M15 | `FakeOutput` echoes instead of buffering | KILLED |

**14 killed, 1 equivalent.** M8 cannot change observable behaviour: `Bootstrap`
seeds *both* sides of the `App::has()` branch in `get_module_db()` —
`App::db()` and `$GLOBALS['default_dbh']` are the same fake — so a plugin gets a
working recording handle whichever way `has()` answers. That is deliberate
belt-and-braces, and `testFallbackWorksWhicheverWayAppHasAnswers` pins it so a
future reader does not mistake it for a coverage gap.

Re-run with `scratchpad/mutate.sh`.

---

## 6. Signature fidelity (R5)

A fake whose signature has drifted from core lets a handler run and a test pass
while proving nothing — worse than no test. The installer package cannot see
the core tree, so `SignaturePinTest` **pins** each signature as data. A reviewer
diffs the table against core by hand once at gate G1; after that, drift in the
*fake* fails the build automatically.

Three entries differ from the signature list in an earlier revision of
`plugin_plan.md` §606/§643, and follow **core**:

| symbol | plan said | core says |
|---|---|---|
| `has_acl` | `$acl` | `$permission` |
| `get_service` | `$acl = null` | `$acl = false` |
| `generate_password` | `$len = 12` | `$length = 8, $available_sets = 'luds'` |
| `add_checkbox_setting` | "same as text" | takes `$values, $labels` like dropdown |
| `add_master_label` | — | no `$name`; ends in `$code` |

Also worth knowing: core's `Settings::get_setting()` is literally
`return constant($setting);`. `FakeSettings` mirrors that, which is why an
assertion on a `__STUB_*__` sentinel is an assertion about the real code path.

`FakeTable` is the one deliberate exception to verbatim mirroring — see its
class docblock. Core's `set_post_location($dir = POST_LOCATION)` takes a **bare
constant as a parameter default**, so copying it verbatim would make merely
*loading* the harness fatal unless `POST_LOCATION` happened to be defined first.

---

## 7. H-bug vs P-bug (D7)

Once handlers actually run, plugins fail for two very different reasons. Keep
the streams separate — mixing them is how this kind of effort dies.

- **H-bug** — the harness is wrong (missing fake, bad signature, over-strict
  assertion). Fix in the installer. **Never** fix by weakening a plugin.
- **P-bug** — the plugin is genuinely broken. Fix in the plugin, on its own
  branch, with its own review.

Worked examples from Phase 1:

| symptom | verdict | action |
|---|---|---|
| `Call to undefined method FakeApp::resetContainer()` (virtuozzo, 52 tests) | **H-bug** | added core's container methods to `FakeApp` |
| `Class "MyAdmin\App\Testing\TestContainerBuilder" not found` (virtuozzo, 3 tests) | **H-bug** | shipped a harness `TestContainerBuilder` and aliased it |
| `testGetHooksReturnType` asserts `getHooks()` declares `: array`; source declares none (mail-module) | **P-bug** | left alone — Policy K "replace count/shape assertion", Phase 6 |
| `ApiFunctionsTest` source-text drift (vps-module, 15 tests) | **P-bug** | left alone — pre-existing, recorded at G0 |

---

## 8. Namespace-scoped function stubs — **RECOMMENDATION NOW EVIDENCED**

`Bootstrap::stubNamespace()` exists and works, but **which mechanism becomes
the fleet standard is an owner call.** It is an amendment to D2/§629.

### Background

Plugin code calls core functions **unqualified** (`get_module_db(...)`, not
`\get_module_db(...)`). PHP resolves an unqualified call against the current
namespace first and only then falls back to global. So a function declared as
`Detain\MyAdminHyperv\get_module_db` beats the installer's global one with no
guard fight. Eight repos already use this, with a written rationale in
`myadmin-hyperv-vps/tests/stubs.php`.

### What Phase 1 measured

**The mechanism works — but it is not required for the four functions §629
flagged.** `FakeApp` + `register_module()` + `$GLOBALS['<module>_dbh']` covers
all four, including the hardcoded `powerdns`/`zonemta` branches, at zero
per-repo cost. Fleet evidence: **66 of 66 loadable plugins execute
`getSettings()` with no namespace stubs at all**, registering 337 settings,
zero throws.

Those 8 repos adopted namespace scoping because they had no `FakeApp` and were
fighting the installer's globals. Once `FakeApp` exists, that fight is over.

What namespace scoping is still genuinely for:

- a **plugin-specific** core helper the harness cannot know about —
  `vps_get_password()`, `ipcalc()`, `get_service_master()`;
- a test that needs one function to behave differently from the harness-wide
  default, for one namespace only.

### The three options

| | mechanism | pro | con |
|---|---|---|---|
| **(a)** | `Bootstrap::stubNamespace()` at runtime, via `eval()` | zero per-repo files; swappable per test | `eval()` in a shipped library; invisible to Phan and to grep |
| **(b)** | generated, committed per-repo forwarder file | explicit, greppable, statically analysable | one more file in ~71 repos |
| **(c)** | hybrid: nothing by default, (b) where genuinely needed | smallest footprint | two mechanisms to document |

### Recommendation — **(c)**

Default to **no namespace stubs at all**, because `FakeApp` already covers the
contested four. Where a repo genuinely needs a plugin-specific helper, generate
a committed forwarder whose bodies delegate back into the harness:

```php
<?php
namespace Detain\MyAdminHyperv;
use MyAdmin\Plugins\Testing\Bootstrap;
function vps_get_password($id, $custid) { return Bootstrap::callNamespaceStub(__FUNCTION__, func_get_args()); }
```

Behaviour stays in the installer; the file is five lines per function,
committed, greppable, Phan-analysable, and needs no `eval()`. The Phase 7
`composer myadmin:scaffold-tests` command should generate it.

`Bootstrap::stubNamespace()` (option a) is implemented and tested, and is the
right tool for a one-off inside a single test. It is **not** proposed as the
fleet standard. If the owner prefers (a) everywhere, drop the generator; if the
owner prefers (b) everywhere, `stubNamespace()` can stay as a test-local
convenience or be removed.

All three mechanisms were verified to work on PHP 8.3: `eval()` with a
namespace declaration, `eval()` with a braced namespace, and a temp-file
`require`.

### What Phase 6 then measured — the cost, in the field

Phase 1 argued (c) on footprint. Phase 6 found the real cost, and it is worse
than a spare file.

Those 8 repos declare **no-op** helpers in their namespace — including
`myadmin_log()`, which is one of the harness's observers. PHP binds the
plugin's unqualified call to the namespaced no-op, so the call never reaches
the recorder, and assertion A concluded the handler had done nothing and
reported it as dead code whose service "silently never gets provisioned".

The same plugin passed standalone and failed under its own bootstrap. Same
plugin, same harness, opposite verdicts, decided entirely by whose
`myadmin_log()` won name resolution. It blocked 5 packages; **none of them had
the defect alleged.**

`ServiceHandlerProbe::shadowedObservers()` now detects the shadow and reports
a skip naming the shadowing function instead of an accusation (v2.1.1, §11).
That makes the harness safe in the presence of these stubs — it does not make
the stubs a good idea.

**So the recommendation stands and is now evidenced: default to no namespace
stubs.** A namespaced stub that shadows a harness observer converts a real
assertion into a vacuous one, and the harness can only downgrade the verdict
to "could not tell", never recover the assertion. Where a plugin-specific
helper genuinely needs stubbing, forward it to the harness rather than
no-op'ing it, so the observation still lands.

**Still open for the owner:** assertion B — the inert direction — can pass
vacuously in these same 8 packages for the same reason, and unlike assertion A
its pass is not obviously wrong. Recorded as D-7 in
`docs/plugin-harness-findings.md` in MyAdmin core.

---

## 9. Phase 1 results

Fleet state before any harness change (independently re-run, not taken from the
plan): **66 pass / 3 fail of 69**. The three red are exactly the repos blocked
on this phase.

| repo | before | after | bootstrap |
|---|---|---|---|
| `myadmin-virtuozzo-vps` | 52 tests, **52 errors**, 119 assertions | **OK — 52 tests, 122 assertions** | 3 lines (had none) |
| `myadmin-mail-module` | 60 tests, **39 errors + 1 failure**, 43 assertions | **0 errors, 1 failure**, 119 assertions | 3 lines |
| `myadmin-vps-module` | 63 tests, 8 errors + 7 failures, 215 assertions | **identical**, 215 assertions | 3 lines, replacing **341** |
| `myadmin-kvm-vps` (control) | OK — 36 tests, 108 assertions | **OK — 36 tests, 108 assertions** | 3 lines |

- virtuozzo and mail-module went green **with no change to `src/`**, exactly as
  the phase predicted. mail-module's one surviving failure is a pre-existing
  P-bug (§7).
- vps-module's failing set is **byte-identical** before and after — the
  341-line bootstrap was replaced with three lines and nothing changed.
- The control repo is unaffected, so the harness causes no regression.
- Assertions actually executed went **up** in the two converted repos
  (119→122 and 43→119), which is the metric that matters.

Installer suite: **329 tests, 727 assertions, OK** (was 189 tests before this
phase), ~0.9 s — well inside R8's 2× budget.

Fleet-wide harness smoke, one process per plugin:

```
repos scanned            69
getHooks() executed      66
getSettings() executed   66  (337 settings registered in total)
getSettings() threw      0
getMenu() executed       40  (99 links in total)
getMenu() threw          0
could not load           3   (payum-payments, vps-module, whmsonic-licensing —
                              the three that do not require the installer)
```

---

## 10. Notes for Phase 2

- **Test-method naming is not uniform.** `myadmin-hyperv-vps` has **54 tests and
  zero `test*`-prefixed methods** — all 54 rely on `@test`. Any
  `PluginContractTestCase`, CI check or coverage measurement assuming `test*`
  naming will under-count silently. `myadmin-maxmind-plugin` carries both on all
  79. Every other repo uses `test*` only.
- **`getMethods(IS_PUBLIC | IS_STATIC)` is a union, not an intersection.**
  Present in 3 files: `myadmin-docker-vps/tests/PluginTest.php:552`,
  `myadmin-kvm-vps/tests/PluginTest.php:375`,
  `myadmin-quickservers-module/tests/PluginTest.php:1117`.
- 30 repos ship a hand-rolled harness totalling **4,366 lines** across four
  conventions — `tests/bootstrap.php` (30 repos, 2,177 loc), `tests/stubs.php`
  (3, 339), `tests/support/doubles.php` (4, 1,409), `tests/stubs/framework.php`
  (3, 441). `FakeSettings`/`FakeDb`/`FakeMenu` were promoted from the richest of
  these rather than written from scratch.

---

## 11. The generated `ContractTest`, and what it encodes against

`ContractTestGenerator` is the single source of truth for the per-package test
file. Everything it emits, it emits for a reason that was paid for once
already.

### The three structural rules

Three throwaway generators wrote the 66 files now in the fleet, and the
differences between them were not cosmetic. `ContractTestGeneratorTest` pins
each as a property rather than a golden string, because a golden file pins
prose and misses reorderings.

1. **Prime before the plugin class is mentioned.** A static property
   initializer can itself reference a bare constant — `$settings` holding
   `REPEAT_BILLING_METHOD => PRORATE_BILLING`, which is mail-module's shape —
   and initializers run when the class *loads*. So even reading `::$type`
   fatals on an unprimed class, before the assertion that was supposed to
   catch anything. `primeConstants()` comes first.
2. **Read the hook table through `TierA5HooksAreIdempotent::hookTable()`.** A
   direct `getHooks()` call is a second, independent answer to a question A-5
   already owns, and for a plugin whose body touches a bare constant the two
   answers disagree: the inspector handles it, the direct caller throws.
3. **Evaluate it exactly once.** Calling `getHooks()` twice — once for the key
   list, once for the callable loop — asserts idempotence by accident and
   doubles whatever side effect the body has.

### Why the class is isolated

`@runTestsInSeparateProcesses` + `@preserveGlobalState disabled`, always.
Inspecting a plugin defines constants and calls `register_module()`, neither
reversible, so without isolation the new file changes the outcome of the tests
the package already had — which is exactly what an additive conversion must
not do. This was not theoretical: 3 of the first 25 packages went red on
their own pre-existing tests, and `executionOrder="depends,defects"` makes the
ordering unstable, so it can appear and disappear between runs.

### Escape hatches, and when they are legitimate

| hatch | legitimate when | not legitimate when |
|---|---|---|
| `--force` regeneration | the package is on an older generation of the template | the pin was hand-edited deliberately — read the diff first |
| `extra.myadmin-deferred-contract-defects` | a finding is real, agreed, and scheduled — it stays in the record as data | you want the build green and have not decided anything |
| `@runInSeparateProcess` on one test | that test needs a different constant value (D4 — constants are immutable) | as a substitute for understanding why state leaked |
| hand-editing `ContractTest.php` | never, in practice — change the generator | to make one package's failure go away |

### Harness bugs fixed since release

Both were the harness making a **false accusation against a plugin**, which is
the failure mode that costs the most trust and is hardest to notice, because
the output looks like a finding.

| ref | bug | fix |
|---|---|---|
| H-1 | assertion A called a handler dead code when its observer was shadowed by a namespaced no-op (§8) | `ServiceHandlerProbe::shadowedObservers()` — skip, naming the shadowing function — **v2.1.1** |
| H-2 | a failed `require` was reported as the handler's own logic failing. `isUnresolvableDependency()` gated on `$error instanceof \Error`, but a missing *file* raises a warning | `UNRESOLVABLE_FILE`, checked before the `\Error` gate — **v2.1.2** |

Both are pinned by tests, including the counter-test that an unshadowed plugin
which genuinely does nothing still fails. When adding an inspector, write that
counter-test: an inspector that cannot fail is worse than no inspector,
because it reads as coverage.
