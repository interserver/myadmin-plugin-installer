<?php
/**
 * @author Joe Huss <detain@interserver.net>
 * @copyright 2026
 * @package MyAdmin
 * @category Testing
 */

namespace MyAdmin\Plugins\Testing;

use MyAdmin\Plugins\Testing\Bootstrap;
use MyAdmin\Plugins\Testing\Contract\Finding;
use MyAdmin\Plugins\Testing\Contract\InspectorRegistry;
use MyAdmin\Plugins\Testing\Contract\PluginSubject;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * Base test case asserting that a plugin honours the MyAdmin plugin contract.
 *
 * A plugin repo subclasses this and names its class. Everything else is optional:
 *
 *     class PluginContractTest extends PluginContractTestCase
 *     {
 *         protected function pluginClass()
 *         {
 *             return \Detain\MyAdminCpanel\Plugin::class;
 *         }
 *     }
 *
 * That yields one test per catalogue assertion. One test per assertion, rather than one
 * test that checks everything, is deliberate: PHPUnit stops a test at its first failed
 * assertion, so a combined test would report the first broken thing and hide the rest.
 * The whole point of the catalogue is to see every violation at once.
 *
 * ---------------------------------------------------------------------------------
 * H-BUG vs P-BUG (decision D7)
 * ---------------------------------------------------------------------------------
 * The two failure streams must never be mixed, because they are fixed by different
 * people on different branches. This class encodes the distinction mechanically:
 *
 *  - An inspector that RETURNS a failure is reporting a **plugin** defect (P-bug).
 *  - An inspector that THROWS has violated its own contract — {@see Contract\PluginInspector}
 *    forbids throwing for a defect it is meant to detect — so that is a **harness**
 *    defect (H-bug), and the failure message says so in as many words.
 *
 * Without that split, a broken inspector reads as 69 broken plugins and the effort dies
 * in a triage swamp.
 *
 * ---------------------------------------------------------------------------------
 * ESCAPE HATCHES
 * ---------------------------------------------------------------------------------
 * The override hooks below exist so a repo with a legitimate deviation does not have to
 * weaken a shared assertion for everyone. Gate G2 requires that every hatch in use is
 * reviewable, so every failure message lists the overrides that were active
 * ({@see PluginSubject::overridesInUse()}). A hatch used to silence a real defect is
 * then visible in the failure output itself, not buried in a subclass nobody reads.
 *
 * The sentinel matters: returning `null` from {@see requirementRoot()} is a deliberate,
 * logged opt-out of the B-10 path check, which is NOT the same as never having
 * overridden it. {@see NOT_SET} keeps those two apart.
 *
 * ---------------------------------------------------------------------------------
 * PRIMING IS IRREVERSIBLE — WHAT THAT COSTS A REPO
 * ---------------------------------------------------------------------------------
 * {@see primeConstants()} defines real PHP constants, and PHP cannot undefine one. So the
 * first contract test in a repo's process permanently primes it. Two consequences a repo
 * author should know before this class surprises them:
 *
 *  - A test that wants to observe the plugin *unprimed* cannot run in the same process as
 *    these. Put it in a separate test file with `@runInSeparateProcess`, or a separate suite.
 *  - {@see constantOverrides()} is order-sensitive against anything else that primes first:
 *    whoever defines a constant first wins, and this class is usually first. If an override
 *    appears to be ignored, that is why.
 *
 * This is a property of constants, not a defect to be worked around — and it is exactly why
 * the fleet self-check runs one process per plugin rather than sharing one.
 */
abstract class PluginContractTestCase extends TestCase
{
    /**
     * Marker for "this repo did not override the hook", so that an explicit `null`
     * stays distinguishable from a default.
     */
    const NOT_SET = '__myadmin_plugin_contract_not_set__';

    /**
     * Fully-qualified plugin class under test.
     *
     * @return class-string
     */
    abstract protected function pluginClass();

    /**
     * Constrain `$type` to one exact value, or {@see NOT_SET} to accept any known type.
     *
     * @return string|mixed
     */
    protected function expectedType()
    {
        return self::NOT_SET;
    }

    /**
     * Filesystem root that `getRequirements()` sources must resolve under.
     *
     * Return `null` to opt this repo out of the B-10 path check — a logged escape hatch,
     * not a silent one.
     *
     * @return string|null|mixed
     */
    protected function requirementRoot()
    {
        return self::NOT_SET;
    }

    /**
     * Service-id constants to seed before plugin code runs.
     *
     * @return array<string,int>|mixed
     */
    protected function serviceDefines()
    {
        return self::NOT_SET;
    }

    /**
     * Constants to force before the plugin class is touched, e.g. `PRORATE_BILLING`.
     *
     * @return array<string,mixed>|mixed
     */
    protected function constantOverrides()
    {
        return self::NOT_SET;
    }

    /**
     * One case per catalogue assertion.
     *
     * Yields class names, not inspector instances: providers run before the test body,
     * and constructing 17 inspectors during collection would turn a broken inspector
     * into a collection-time error with no useful attribution.
     *
     * @return array<string,array{0:class-string}>
     */
    public static function contractAssertions()
    {
        $ordered = [];
        foreach (InspectorRegistry::classes() as $class) {
            $inspector = new $class();
            $ordered[] = [$inspector->id(), $inspector->title(), $class];
        }
        // Sorted by catalogue id, not by class name. `classes()` is sorted by class name,
        // which prints "B-10" before "B-9" — the unreadable ordering InspectorRegistry
        // ::compareIds() exists to prevent, in the one place a plugin maintainer reads it.
        usort($ordered, function ($a, $b) {
            return InspectorRegistry::compareIds($a[0], $b[0]);
        });
        $cases = [];
        foreach ($ordered as $row) {
            $cases[$row[0].' '.$row[1]] = [$row[2]];
        }
        return $cases;
    }

    /**
     * Defines the constants the plugin's own code references, before that code is run.
     *
     * Load-bearing, and easy to leave out — the fleet self-check was written without it and
     * produced 20 false failures plus 70 false skips. Inspectors that only READ static
     * properties survive unprimed, because {@see PluginSubject} recovers literals from source;
     * inspectors that EXECUTE plugin code (A-1 constructs it, A-5 calls `getHooks()`) do not,
     * and throw `Error: Undefined constant` on every plugin whose class body references one.
     *
     * Best-effort by design. A failure here is NOT raised directly: if the class cannot be
     * loaded or primed, the inspectors are the right reporters of that — A-1 says
     * "constructing threw" and attributes it correctly. Aborting here would replace an
     * accurate per-assertion verdict with one opaque setup error. The message is returned so
     * it can still be surfaced alongside a failure rather than silently dropped.
     *
     * @return string|null the failure message, or null when priming succeeded
     */
    protected function primeConstants()
    {
        try {
            $options = ['plugin' => $this->pluginClass()];
            $overrides = $this->constantOverrides();
            if ($overrides !== self::NOT_SET && is_array($overrides)) {
                $options['constants'] = $overrides;
            }
            Bootstrap::init($options);
            return null;
        } catch (Throwable $e) {
            return get_class($e).': '.$e->getMessage();
        }
    }

    /**
     * @dataProvider contractAssertions
     * @param class-string $inspectorClass
     * @return void
     */
    public function testPluginSatisfiesContractAssertion($inspectorClass)
    {
        $inspector = new $inspectorClass();
        $subject = $this->contractSubject();
        $primingError = $this->primeConstants();

        try {
            $findings = $inspector->inspect($subject);
        } catch (Throwable $e) {
            $this->fail(
                'HARNESS BUG (H-bug), not a plugin defect: inspector '.$inspector->id().' threw '
                .get_class($e).' — "'.$e->getMessage().'". '
                .'PluginInspector forbids throwing to report a defect; a detected problem must be '
                .'returned as a Finding. Fix the inspector, not '.$subject->pluginClass().'.'
                ."\n".$this->describeOverrides($subject)
            );
        }

        $failures = [];
        $skips = [];
        foreach ($findings as $finding) {
            if ($finding->isFailure()) {
                $failures[] = $finding->describe();
            } elseif ($finding->isSkipped()) {
                $skips[] = $finding->describe();
            }
        }

        if ($failures !== []) {
            $this->fail(
                $inspector->id().' — '.$inspector->title()."\n"
                .$subject->pluginClass()." violates the plugin contract:\n  - "
                .implode("\n  - ", $failures)."\n"
                .$this->describeOverrides($subject)
                .($primingError === null
                    ? ''
                    : "\nNote: constant priming failed (".$primingError.'), so this failure may be a '
                      .'setup problem rather than a plugin defect.')
            );
        }

        // A check that could not run is not a check that passed. Reporting it as a skip
        // keeps the triage matrix honest about its own coverage.
        //
        // DELIBERATE: the test is "EVERY finding is a skip", not "every finding is a skip or
        // a notice". A skip alongside a NOTICE therefore does NOT skip the test. That looks
        // like it buries the skip, and it was changed once on that reasoning and changed back:
        //   - A notice is an OBSERVATION. Emitting one proves the inspector ran and had
        //     something to report, so "could not run" would be a false statement about it.
        //     Understating coverage is still misreporting it.
        //   - Nothing is actually lost. The fleet triage matrix derives each cell straight
        //     from the findings with fail > skip > pass, so a skip is rendered as a skip in
        //     the G2 artefact whatever this method decides. Only the PHPUnit bucket differs.
        // Pinned by PluginContractTestCaseTest::testASkipAlongsideANoticeDoesNotSkipTheTest.
        if ($skips !== [] && count($skips) === count($findings)) {
            $this->markTestSkipped(
                $inspector->id().' could not run against '.$subject->pluginClass().': '
                .implode('; ', $skips)
            );
            return;
        }

        $this->assertSame([], $failures, $inspector->id().' reported no contract violations');
    }

    /**
     * Builds the subject, passing through only the hooks this repo actually overrode.
     *
     * @return \MyAdmin\Plugins\Testing\Contract\PluginSubject
     */
    protected function contractSubject()
    {
        $options = [];
        foreach ([
            'expectedType' => $this->expectedType(),
            'requirementRoot' => $this->requirementRoot(),
            'serviceDefines' => $this->serviceDefines(),
            'constantOverrides' => $this->constantOverrides(),
        ] as $name => $value) {
            if ($value !== self::NOT_SET) {
                $options[$name] = $value;
            }
        }
        return new PluginSubject($this->pluginClass(), $options);
    }

    /**
     * Names the escape hatches in play, so a failure message shows whether the result was
     * reached under a relaxed contract.
     *
     * @param \MyAdmin\Plugins\Testing\Contract\PluginSubject $subject
     * @return string
     */
    protected function describeOverrides(PluginSubject $subject)
    {
        $used = $subject->overridesInUse();
        if ($used === []) {
            return 'No per-repo overrides are in effect.';
        }
        return 'Per-repo overrides in effect (review under gate G2): '.implode(', ', $used).'.';
    }

    /**
     * Every finding for this plugin, keyed by assertion id — the row this plugin
     * contributes to the fleet triage matrix.
     *
     * Exposed so the Phase 2 self-check and the PHPUnit path share one code path. If the
     * matrix were built by re-implementing the loop above, the two could disagree, and the
     * matrix is the artefact gate G2 is reviewed against.
     *
     * @param \MyAdmin\Plugins\Testing\Contract\PluginSubject $subject
     * @return array<string,array<int,\MyAdmin\Plugins\Testing\Contract\Finding>>
     */
    public static function inspectAll(PluginSubject $subject)
    {
        $rows = [];
        foreach (InspectorRegistry::all() as $inspector) {
            try {
                // Primed inside the per-inspector try, not once before the loop: Bootstrap::init()
                // throwing outside this catch would abort the whole 69-plugin matrix and pin the
                // blame on whichever plugin happened to be in flight — the exact failure this
                // catch exists to prevent. Defining a constant twice is a no-op, so repeating it
                // per inspector costs nothing.
                Bootstrap::init(['plugin' => $subject->pluginClass()] + (
                    $subject->constantOverrides() === []
                        ? []
                        : ['constants' => $subject->constantOverrides()]
                ));
                $rows[$inspector->id()] = $inspector->inspect($subject);
            } catch (Throwable $e) {
                $rows[$inspector->id()] = [
                    Finding::failure(
                        $inspector->id(),
                        'HARNESS BUG (H-bug): inspector threw '.get_class($e).' — '.$e->getMessage(),
                        ['harnessBug' => true, 'exception' => get_class($e)]
                    ),
                ];
            }
        }
        return $rows;
    }
}
