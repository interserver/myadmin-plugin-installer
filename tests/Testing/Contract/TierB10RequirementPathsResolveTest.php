<?php
/**
 * @author Joe Huss <detain@interserver.net>
 * @copyright 2026
 * @package MyAdmin
 * @category Testing
 */

namespace Tests\MyAdmin\Plugins\Testing\Contract;

use MyAdmin\Plugins\Testing\Contract\Finding;
use MyAdmin\Plugins\Testing\Contract\PluginSubject;
use MyAdmin\Plugins\Testing\Contract\TierB10RequirementPathsResolve;
use PHPUnit\Framework\TestCase;

/**
 * Fixture handlers take an **untyped** `$event`, on purpose. The inspector passes a real
 * `Symfony\Component\EventDispatcher\GenericEvent` where the component is installed and a
 * `SubjectEvent` where it is not; an untyped parameter accepts both, so these tests
 * assert the same thing in both environments. Type-hinting the fixtures on `GenericEvent`
 * would make them pass here and skip in the core tree, or the reverse.
 *
 * All on-disk fixtures live under `sys_get_temp_dir()` and are removed in `tearDown()`, and
 * every path assertion is absolute: the whole point of B-10 is that the answer must not
 * change with the working directory.
 *
 * @covers \MyAdmin\Plugins\Testing\Contract\TierB10RequirementPathsResolve
 * @covers \MyAdmin\Plugins\Testing\Contract\SubjectEvent
 */
class TierB10RequirementPathsResolveTest extends TestCase
{
    /** @var TierB10RequirementPathsResolve */
    private $inspector;

    /** @var array<int,string> directories to remove, deepest first */
    private $scratchDirs = [];

    /** @var string|null */
    private $root;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $this->inspector = new TierB10RequirementPathsResolve();
        $this->scratchDirs = [];
        $this->root = null;
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        foreach (array_reverse($this->scratchDirs) as $dir) {
            $this->removeTree($dir);
        }
        $this->scratchDirs = [];
        $this->root = null;
        TierB10Registry::$sources = [];
    }

    /**
     * @param string $dir
     * @return void
     */
    private function removeTree($dir)
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir.'/'.$entry;
            if (is_dir($path)) {
                $this->removeTree($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }

    /**
     * A throwaway `<core>` directory, cleaned up wholesale in tearDown.
     *
     * @return string
     */
    private function makeBase()
    {
        $base = sys_get_temp_dir().'/tierb10-'.getmypid().'-'.mt_rand();
        mkdir($base, 0777, true);
        $this->scratchDirs[] = $base;
        return $base;
    }

    /**
     * A throwaway requirement root, shaped like the real one — `<core>/include`, with room
     * for a sibling `<core>/vendor` so that `/../vendor/...` sources land *inside* the tree
     * that tearDown removes rather than scattering into the system temp directory.
     *
     * @param array<int,string> $files paths relative to the root, `..` allowed
     * @return string absolute root
     */
    private function makeRoot(array $files = [])
    {
        $root = $this->makeBase().'/include';
        mkdir($root, 0777, true);
        foreach ($files as $relative) {
            $full = $root.'/'.ltrim($relative, '/');
            $dir = dirname($full);
            if (!is_dir($dir)) {
                mkdir($dir, 0777, true);
            }
            file_put_contents($full, "<?php\n");
        }
        return $root;
    }

    /**
     * @param string|null $path
     * @return bool
     */
    private function isAbsolute($path)
    {
        return is_string($path) && $path !== '' && $path[0] === '/';
    }

    /**
     * @param string      $class
     * @param string|null $root null means "leave requirementRoot unset"
     * @return array<int,Finding>
     */
    private function inspect($class, $root)
    {
        $options = $root === null ? [] : ['requirementRoot' => $root];
        return $this->inspector->inspect(new PluginSubject($class, $options));
    }

    // -------------------------------------------------------------------
    // Identity
    // -------------------------------------------------------------------

    /**
     * @return void
     */
    public function testReportsItsCatalogueId()
    {
        $this->assertSame('B-10', $this->inspector->id());
        $this->assertNotSame('', $this->inspector->title());
    }

    // -------------------------------------------------------------------
    // Gate G2: "validated by fixing one path in a scratch copy and
    // confirming it goes green" — both directions, same plugin, same root.
    // -------------------------------------------------------------------

    /**
     * @return void
     */
    public function testBrokenPathFailsAndFixingItGoesGreen()
    {
        $root = $this->makeRoot(['/../vendor/detain/pkg/src/present.php']);

        // Broken: the file the plugin registers is not there.
        TierB10Registry::$sources = [
            'thing_one' => '/../vendor/detain/pkg/src/missing.php',
        ];
        $findings = $this->inspect(TierB10RegistryPlugin::class, $root);
        $this->assertCount(1, $findings);
        $this->assertTrue($findings[0]->isFailure());
        $this->assertStringContainsString('missing.php', $findings[0]->message());
        $this->assertSame('thing_one', $findings[0]->context()['function']);

        // Fixed: point the same registration at the file that does exist.
        TierB10Registry::$sources = [
            'thing_one' => '/../vendor/detain/pkg/src/present.php',
        ];
        $this->assertSame(
            [],
            $this->inspect(TierB10RegistryPlugin::class, $root),
            'fixing the one path must make the check green'
        );
    }

    /**
     * @return void
     */
    public function testEveryRegisteredSourceIsChecked()
    {
        $root = $this->makeRoot(['/src/there.php']);
        TierB10Registry::$sources = [
            'ok_one' => '/src/there.php',
            'bad_one' => '/src/gone_one.php',
            'bad_two' => '/src/gone_two.php',
        ];
        $findings = $this->inspect(TierB10RegistryPlugin::class, $root);
        $this->assertCount(2, $findings);
        $functions = [$findings[0]->context()['function'], $findings[1]->context()['function']];
        sort($functions);
        $this->assertSame(['bad_one', 'bad_two'], $functions);
    }

    /**
     * The failure has to be actionable without re-running anything: raw source, absolute
     * resolved path, and the root that produced it.
     *
     * @return void
     */
    public function testFailureContextCarriesSourceResolvedPathAndRoot()
    {
        $root = $this->makeRoot();
        TierB10Registry::$sources = ['thing' => '/../vendor/detain/pkg/src/nope.php'];
        $findings = $this->inspect(TierB10RegistryPlugin::class, $root);

        $context = $findings[0]->context();
        $this->assertSame('/../vendor/detain/pkg/src/nope.php', $context['source']);
        $this->assertSame($root, $context['root']);
        $this->assertSame($root.'/../vendor/detain/pkg/src/nope.php', $context['resolved']);
    }

    // -------------------------------------------------------------------
    // Resolution mirrors core, and does not depend on the cwd
    // -------------------------------------------------------------------

    /**
     * `/../vendor/...` climbing out of the include root is the fleet's dominant shape.
     *
     * @return void
     */
    public function testResolvesPathsThatClimbOutOfTheIncludeRoot()
    {
        $root = $this->makeRoot(['/../vendor/detain/pkg/src/real.php']);
        TierB10Registry::$sources = ['thing' => '/../vendor/detain/pkg/src/real.php'];
        $this->assertSame([], $this->inspect(TierB10RegistryPlugin::class, $root));
    }

    /**
     * The check is run from two different working directories and must give the same answer.
     * A `require_once` resolved against the cwd is a live bug in this fleet; a checker with
     * the same weakness would hide it.
     *
     * @return void
     */
    public function testAnswerDoesNotDependOnTheCurrentWorkingDirectory()
    {
        $root = $this->makeRoot(['/src/here.php']);
        TierB10Registry::$sources = [
            'good' => '/src/here.php',
            'bad' => '/src/nowhere.php',
        ];

        $original = getcwd();
        $elsewhere = $this->makeBase();
        try {
            chdir($elsewhere);
            $fromElsewhere = $this->inspect(TierB10RegistryPlugin::class, $root);
            chdir(sys_get_temp_dir());
            $fromTemp = $this->inspect(TierB10RegistryPlugin::class, $root);
        } finally {
            chdir($original);
        }

        $this->assertCount(1, $fromElsewhere);
        $this->assertCount(1, $fromTemp);
        $this->assertSame($fromElsewhere[0]->message(), $fromTemp[0]->message());
        $this->assertSame($fromElsewhere[0]->message(), $this->inspect(TierB10RegistryPlugin::class, $root)[0]->message());
    }

    /**
     * A trailing slash must change neither the verdict nor the root reported in the finding
     * context — a matrix that shows `/x/include` for one repo and `/x/include/` for the next
     * invites someone to "fix" a difference that is not one.
     *
     * @return void
     */
    public function testTrailingSlashOnTheRootIsNormalized()
    {
        $root = $this->makeRoot(['/src/here.php']);
        TierB10Registry::$sources = ['good' => '/src/here.php'];
        $this->assertSame([], $this->inspect(TierB10RegistryPlugin::class, $root.'/'));

        $subject = new PluginSubject(TierB10RegistryPlugin::class, ['requirementRoot' => $root.'/']);
        $this->assertSame($root, $this->inspector->requirementRootFor($subject));
    }

    /**
     * Core accepts a list of sources for one function and requires each in turn, so each
     * element is checked separately.
     *
     * @return void
     */
    public function testListOfSourcesForOneFunctionIsCheckedElementwise()
    {
        $root = $this->makeRoot(['/src/one.php']);
        TierB10Registry::$sources = ['thing' => ['/src/one.php', '/src/two.php']];
        $findings = $this->inspect(TierB10RegistryPlugin::class, $root);
        $this->assertCount(1, $findings);
        $this->assertStringContainsString('two.php', $findings[0]->message());
    }

    /**
     * Core's namespaced arm strips everything up to and including the last `\` plus one more
     * character. Reproduced verbatim so the check agrees with what would actually be required.
     *
     * @return void
     */
    public function testNamespacedSourceIsResolvedTheWayCoreResolvesIt()
    {
        $root = $this->makeRoot(['/tail.php']);
        TierB10Registry::$sources = ['thing' => 'MyAdmin\\Foo\\/tail.php'];
        $this->assertSame([], $this->inspect(TierB10RegistryPlugin::class, $root));
    }

    /**
     * Pins the `+ 2` specifically. In the conventional `Namespace\/path.php` shape the
     * character after the separator is the slash, so `+ 1` and `+ 2` name the same file and
     * the arithmetic is invisible; with any other character they diverge. Core drops both,
     * and a checker that dropped only the separator would resolve a path core never would.
     *
     * @return void
     */
    public function testNamespacedSourceDropsTheSeparatorAndOneFurtherCharacter()
    {
        $root = $this->makeRoot(['/tail.php']);
        TierB10Registry::$sources = ['thing' => 'MyAdmin\\Foo\\Xtail.php'];
        $this->assertSame([], $this->inspect(TierB10RegistryPlugin::class, $root));
    }

    /**
     * @return void
     */
    public function testNonStringSourceIsReported()
    {
        $root = $this->makeRoot();
        TierB10Registry::$sources = ['thing' => 42];
        $findings = $this->inspect(TierB10RegistryPlugin::class, $root);
        $this->assertCount(1, $findings);
        $this->assertTrue($findings[0]->isFailure());
        $this->assertStringContainsString('not a non-empty string', $findings[0]->message());
    }

    // -------------------------------------------------------------------
    // The real Loader, not a fake
    // -------------------------------------------------------------------

    /**
     * `add_page_requirement()` registers through `add_route_requirement()`, which stores the
     * source under the function name. Running against the real Loader is what makes that
     * plumbing part of the test rather than an assumption.
     *
     * @return void
     */
    public function testCollectsPathsRegisteredThroughTheRealLoaderApi()
    {
        $root = $this->makeRoot(['/src/page.php']);
        $findings = $this->inspect(TierB10PageRequirementPlugin::class, $root);
        $this->assertCount(1, $findings);
        $this->assertSame('missing_page', $findings[0]->context()['function']);
        $this->assertStringContainsString('gone.php', $findings[0]->message());
    }

    /**
     * `add_requirement()` drops empty sources, and `add_route_requirement()` registers a route
     * without one. Neither should surface as a requirement to check.
     *
     * @return void
     */
    public function testRoutesRegisteredWithoutASourceAreNotRequirements()
    {
        $root = $this->makeRoot();
        $this->assertSame([], $this->inspect(TierB10SourcelessRoutePlugin::class, $root));
    }

    /**
     * A handler that replaces the event's subject is what `tf::set_function_requirements()`
     * accommodates, so the inspector must read the loader back out of the event.
     *
     * @return void
     */
    public function testReadsTheLoaderBackOutOfTheEvent()
    {
        $root = $this->makeRoot();
        $findings = $this->inspect(TierB10SubjectSwappingPlugin::class, $root);
        $this->assertCount(1, $findings);
        $this->assertSame('swapped_in', $findings[0]->context()['function']);
    }

    /**
     * @return void
     */
    public function testHandlerOutputIsSwallowedRatherThanLeakedIntoTheTestRun()
    {
        $root = $this->makeRoot();
        $before = ob_get_level();
        $findings = $this->inspect(TierB10EchoingPlugin::class, $root);
        $this->assertSame($before, ob_get_level(), 'the inspector must leave the buffer stack as it found it');
        $this->assertCount(1, $findings);
    }

    /**
     * @return void
     */
    public function testHookedHandlerIsPreferredOverAMethodOfTheSameName()
    {
        $root = $this->makeRoot();
        $findings = $this->inspect(TierB10HookPointsElsewherePlugin::class, $root);
        $this->assertCount(1, $findings);
        $this->assertSame('from_the_hooked_handler', $findings[0]->context()['function']);
    }

    // -------------------------------------------------------------------
    // Root resolution
    // -------------------------------------------------------------------

    /**
     * @return void
     */
    public function testExplicitRequirementRootWins()
    {
        $root = $this->makeRoot();
        $subject = new PluginSubject(TierB10RegistryPlugin::class, ['requirementRoot' => $root]);
        $this->assertSame($root, $this->inspector->requirementRootFor($subject));
    }

    /**
     * With no explicit root, a package sitting at `<core>/vendor/<vendor>/<package>` resolves
     * to `<core>/include` — the same directory `INCLUDE_ROOT` names in
     * `include/config/config.inc.php`.
     *
     * @return void
     */
    public function testFallsBackToIncludeRootDerivedFromThePackageLocation()
    {
        if (defined('INCLUDE_ROOT')) {
            $this->assertTrue(true, 'INCLUDE_ROOT is defined here, so the constant arm wins by design');
            return;
        }

        $core = $this->makeBase();
        mkdir($core.'/include', 0777, true);
        $packageSrc = $core.'/vendor/acme/widget/src';
        mkdir($packageSrc, 0777, true);

        $file = $packageSrc.'/TierB10DerivedRootPlugin.php';
        file_put_contents(
            $file,
            "<?php\nnamespace Tests\\MyAdmin\\Plugins\\Testing\\Contract\\DerivedB10;\n"
                ."class TierB10DerivedRootPlugin\n{\n}\n"
        );
        require_once $file;

        $subject = new PluginSubject(
            'Tests\MyAdmin\Plugins\Testing\Contract\DerivedB10\TierB10DerivedRootPlugin'
        );
        $this->assertSame($core.'/include', $this->inspector->requirementRootFor($subject));
    }

    /**
     * The derived root is only used when it exists — guessing one would turn an environment
     * problem into a wall of fabricated failures.
     *
     * @return void
     */
    public function testDerivedRootIsIgnoredWhenThereIsNoIncludeDirectory()
    {
        $core = $this->makeBase();
        $packageSrc = $core.'/vendor/acme/gadget/src';
        mkdir($packageSrc, 0777, true);

        $file = $packageSrc.'/TierB10NoIncludePlugin.php';
        file_put_contents(
            $file,
            "<?php\nnamespace Tests\\MyAdmin\\Plugins\\Testing\\Contract\\NoIncludeB10;\n"
                ."class TierB10NoIncludePlugin\n{\n}\n"
        );
        require_once $file;

        $subject = new PluginSubject(
            'Tests\MyAdmin\Plugins\Testing\Contract\NoIncludeB10\TierB10NoIncludePlugin'
        );
        $resolved = $this->inspector->requirementRootFor($subject);
        $this->assertNotSame($core.'/include', $resolved);
    }

    /**
     * Whatever the fallback chain settles on, it is never relative — that is the entire
     * point. Null is allowed (a checkout with no core tree above it has no honest answer);
     * a relative string never is, because that is the cwd bug wearing a different hat.
     *
     * @return void
     */
    public function testFallbackRootIsNeverRelative()
    {
        $root = $this->inspector->requirementRootFor(new PluginSubject(TierB10RegistryPlugin::class));
        $this->assertTrue(
            $root === null || $this->isAbsolute($root),
            'fallback root must be absolute or absent, got: '.var_export($root, true)
        );
    }

    /**
     * A relative `requirementRoot` is the caller's business, but the derived ones never are.
     *
     * @return void
     */
    public function testDerivedRootIsAbsoluteWhenTheTreeShapeMatches()
    {
        $core = $this->makeBase();
        mkdir($core.'/include', 0777, true);
        $packageSrc = $core.'/vendor/acme/sprocket/src';
        mkdir($packageSrc, 0777, true);

        $file = $packageSrc.'/TierB10AbsoluteRootPlugin.php';
        file_put_contents(
            $file,
            "<?php\nnamespace Tests\\MyAdmin\\Plugins\\Testing\\Contract\\AbsoluteB10;\n"
                ."class TierB10AbsoluteRootPlugin\n{\n}\n"
        );
        require_once $file;

        $subject = new PluginSubject(
            'Tests\MyAdmin\Plugins\Testing\Contract\AbsoluteB10\TierB10AbsoluteRootPlugin'
        );
        $this->assertTrue($this->isAbsolute($this->inspector->requirementRootFor($subject)));
    }

    // -------------------------------------------------------------------
    // Skips — G2 requires every escape hatch used to be visible
    // -------------------------------------------------------------------

    /**
     * @return void
     */
    public function testExplicitOptOutIsReportedAsASkipNotAnEmptyPass()
    {
        $findings = $this->inspector->inspect(
            new PluginSubject(TierB10RegistryPlugin::class, ['requirementRoot' => null])
        );
        $this->assertCount(1, $findings);
        $this->assertTrue($findings[0]->isSkipped());
        $this->assertFalse($findings[0]->isFailure());
        $this->assertSame('requirementRoot', $findings[0]->context()['override']);
    }

    /**
     * Misuse direction one, reproduced in review: a `requirementRoot()` naming a directory
     * that does not exist made **every** registered source resolve to a file that is not
     * there, so the plugin was reported as riddled with dangling paths. Those failures are
     * indistinguishable from the fifteen fleet packages that really do have them, which is
     * what makes the mode expensive rather than merely wrong.
     *
     * The verdict has to be a skip naming the root: not a failure (nothing was learned about
     * the plugin), not a silent pass (the repo has a broken hatch and must hear about it).
     *
     * @return void
     */
    public function testExplicitRootThatIsNotADirectoryIsSkippedNamingTheRoot()
    {
        $absent = $this->makeBase().'/there-is-no-such-directory';
        $this->assertDirectoryDoesNotExist($absent, 'the fixture is only meaningful if the root is absent');

        TierB10Registry::$sources = [
            'one' => '/src/a.php',
            'two' => '/src/b.php',
            'three' => '/src/c.php',
        ];
        $findings = $this->inspect(TierB10RegistryPlugin::class, $absent);

        $this->assertCount(1, $findings, 'a broken root must not be multiplied by the number of paths');
        $this->assertTrue($findings[0]->isSkipped());
        $this->assertFalse($findings[0]->isFailure(), 'a bad root must never manufacture a plugin defect');
        $this->assertStringContainsString($absent, $findings[0]->message(), 'the bad root must be named');
        $this->assertStringContainsString('requirementRoot()', $findings[0]->message());
        $this->assertStringContainsString('not a directory', $findings[0]->message());
        $this->assertSame('requirementRoot', $findings[0]->context()['override']);
        $this->assertSame($absent, $findings[0]->context()['requirementRoot']);
    }

    /**
     * Misuse direction two: a root that *is* a directory and happens to contain the file
     * turns a real dangling path green. B-10 cannot tell that root from a correct one — that
     * is the point of {@see \MyAdmin\Plugins\Testing\PluginContractTestCase::overrideLedger()}
     * — but the `is_dir()` gate must not be mistaken for a defence against it, and the
     * check must keep failing honestly whenever the root is real. This is the guard on the
     * fifteen fleet failures: the gate must silence none of them.
     *
     * @return void
     */
    public function testAGateThatRejectsBadRootsStillFailsDanglingPathsUnderARealRoot()
    {
        $silencing = $this->makeRoot(['/src/dangling.php']);
        $honest = $this->makeRoot();

        TierB10Registry::$sources = ['thing' => '/src/dangling.php'];

        $this->assertSame(
            [],
            $this->inspect(TierB10RegistryPlugin::class, $silencing),
            'a root that contains the file is green — the is_dir() gate cannot detect this'
        );

        $findings = $this->inspect(TierB10RegistryPlugin::class, $honest);
        $this->assertCount(1, $findings);
        $this->assertTrue($findings[0]->isFailure(), 'a real root must still report a real dangling path');
        $this->assertStringContainsString('dangling.php', $findings[0]->message());
    }

    /**
     * `''` is a string and is not a directory. It reached the same wrong place as a
     * nonexistent path — `''.'/'.$source` resolves against the filesystem root — so it takes
     * the same exit.
     *
     * @return void
     */
    public function testEmptyExplicitRootIsSkippedRatherThanResolvedAgainstTheFilesystemRoot()
    {
        TierB10Registry::$sources = ['thing' => '/etc/hostname'];
        $findings = $this->inspect(TierB10RegistryPlugin::class, '');

        $this->assertCount(1, $findings);
        $this->assertTrue($findings[0]->isSkipped());
        $this->assertSame('', $findings[0]->context()['requirementRoot']);
    }

    /**
     * A repo with a broken hatch and a plugin that registers nothing used to pass vacuously:
     * the root was never consulted, so nothing said the hatch was wrong. The misconfiguration
     * outlives that silence — it is waiting for the first requirement the repo ever adds.
     *
     * @return void
     */
    public function testBadExplicitRootIsReportedEvenWhenThePluginRegistersNoRequirements()
    {
        $absent = $this->makeBase().'/not-a-directory-either';
        $findings = $this->inspect(TierB10NoRequirementsPlugin::class, $absent);

        $this->assertCount(1, $findings);
        $this->assertTrue($findings[0]->isSkipped());
        $this->assertStringContainsString($absent, $findings[0]->message());
    }

    /**
     * The explicit rung does not fall through. Substituting a derived root for the one the
     * repo named would produce a verdict about a directory nobody asked for — a third wrong
     * answer, and the hardest of the three to notice.
     *
     * @return void
     */
    public function testBadExplicitRootDoesNotFallThroughToADerivedRoot()
    {
        $core = $this->makeBase();
        mkdir($core.'/include', 0777, true);
        $packageSrc = $core.'/vendor/acme/hatched/src';
        mkdir($packageSrc, 0777, true);

        $file = $packageSrc.'/TierB10FallThroughPlugin.php';
        file_put_contents(
            $file,
            "<?php\nnamespace Tests\\MyAdmin\\Plugins\\Testing\\Contract\\FallThroughB10;\n"
                ."class TierB10FallThroughPlugin\n{\n}\n"
        );
        require_once $file;

        $class = 'Tests\MyAdmin\Plugins\Testing\Contract\FallThroughB10\TierB10FallThroughPlugin';
        $derived = $this->inspector->requirementRootFor(new PluginSubject($class));
        $this->assertSame($core.'/include', $derived, 'the fixture must have a derivable root to fall through to');

        $absent = $this->makeBase().'/still-not-a-directory';
        $this->assertNull(
            $this->inspector->requirementRootFor(new PluginSubject($class, ['requirementRoot' => $absent])),
            'a bad explicit root must resolve to nothing, not to the derived root'
        );
    }

    /**
     * An *unset* root is not an opt-out: it falls back, and the check still runs.
     *
     * @return void
     */
    public function testUnsetRootIsNotTreatedAsAnOptOut()
    {
        $subject = new PluginSubject(TierB10NoRequirementsPlugin::class);
        $this->assertFalse($subject->skipsRequirementCheck());

        $findings = $this->inspector->inspect($subject);
        $this->assertCount(1, $findings);
        $this->assertTrue(
            $findings[0]->isNotApplicable(),
            'the fixture registers nothing, so the check runs and finds nothing of its kind'
        );
        $this->assertFalse(
            $findings[0]->isSkipped(),
            'an unset root must not read as the opt-out, which would be a skip'
        );
    }

    /**
     * @return void
     */
    public function testUnloadablePluginIsSkipped()
    {
        $findings = $this->inspect('Tests\MyAdmin\Plugins\Testing\Contract\NoSuchB10Plugin', null);
        $this->assertCount(1, $findings);
        $this->assertTrue($findings[0]->isSkipped());
    }

    /**
     * The cell R-4 was written for. This used to return `[]` — a pass — on the reasoning that
     * all zero registered paths resolve. B-11 called the identical fact a skip, so the same 18
     * packages were green here and grey there.
     *
     * Pinned in both directions: not a pass, because a vacuous cell verifies nothing; not a
     * skip, because the check ran to completion and reached a verdict.
     *
     * @return void
     */
    public function testPluginRegisteringNothingIsNotApplicableRatherThanVacuouslyGreen()
    {
        $root = $this->makeRoot();
        $findings = $this->inspect(TierB10NoRequirementsPlugin::class, $root);

        $this->assertCount(1, $findings);
        $this->assertTrue($findings[0]->isNotApplicable());
        $this->assertFalse($findings[0]->isSkipped(), 'the check ran; it did not decline to look');
        $this->assertFalse($findings[0]->isFailure());
        $this->assertNotSame([], $findings, 'an empty list is the vacuous pass this replaced');
        $this->assertStringContainsString('registers no requirement paths', $findings[0]->describe());
    }

    /**
     * @return void
     */
    public function testThrowingHandlerIsSkippedRatherThanPropagated()
    {
        $root = $this->makeRoot();
        $findings = $this->inspect(TierB10ThrowingHandlerPlugin::class, $root);
        $this->assertCount(1, $findings);
        $this->assertTrue($findings[0]->isSkipped());
        $this->assertStringContainsString('detonated', $findings[0]->message());
    }

    /**
     * @return void
     */
    public function testDanglingHookTargetIsSkippedAndDefersToTierB9()
    {
        $root = $this->makeRoot();
        $findings = $this->inspect(TierB10DanglingHookPlugin::class, $root);
        $this->assertCount(1, $findings);
        $this->assertTrue($findings[0]->isSkipped());
        $this->assertStringContainsString('B-9', $findings[0]->message());
    }

    /**
     * @return void
     */
    public function testMalformedHookTargetIsSkippedAndDefersToTierA8()
    {
        $root = $this->makeRoot();
        $findings = $this->inspect(TierB10MalformedHookPlugin::class, $root);
        $this->assertCount(1, $findings);
        $this->assertTrue($findings[0]->isSkipped());
        $this->assertStringContainsString('A-8', $findings[0]->message());
    }

    /**
     * @return void
     */
    public function testThrowingGetHooksIsSkipped()
    {
        $root = $this->makeRoot();
        $findings = $this->inspect(TierB10ThrowingHooksPlugin::class, $root);
        $this->assertCount(1, $findings);
        $this->assertTrue($findings[0]->isSkipped());
    }

    // -------------------------------------------------------------------
    // R-10 — the package-relative ground, for a repo with no core checkout
    //
    // Every test below drives TierB10GroundlessInspector rather than the
    // plain one. That is not a convenience: the root ladder's fourth rung
    // derives <core>/include from *this installer package's* own location,
    // and this suite is itself run from inside a MyAdmin checkout, where
    // that rung succeeds. Asking the real inspector for a rootless verdict
    // here would therefore answer "root available" on a developer machine
    // and "no root" in the installer's own CI — the same environment-shaped
    // flapping B-10 exists to expose. The subclass pins the one input under
    // test (no root) and leaves everything else real: the same inspect(),
    // the same real Loader, the same on-disk fixtures.
    //
    // The end-to-end demonstration against the unmodified inspector, in a
    // process where rung 4 genuinely fails, is R-10's standalone proof and
    // is not reproducible as a unit test for exactly the reason above.
    // -------------------------------------------------------------------

    /**
     * The shape Phase 4 actually runs in: a checkout at
     * `/home/runner/work/<pkg>/<pkg>` with no `vendor/` above it, no `include/` anywhere,
     * and `INCLUDE_ROOT` undefined. The registered path is written relative to core's
     * `include/` — `/../vendor/<name>/src/...` — but it lands inside this very package, so
     * `packageDir()` is all the ground it needs.
     *
     * Both directions in one test, deliberately: a rule that only ever fails is not a check,
     * it is an outage.
     *
     * @return void
     */
    public function testSelfReferencingSourceIsJudgedAgainstThePackageWhenThereIsNoCoreRoot()
    {
        list($class, $packageDir) = $this->makeStandalonePackage(
            'Tests\MyAdmin\Plugins\Testing\Contract\StandaloneB10',
            'acme/myadmin-widget-plugin',
            ['/src/present.php'],
            [
                'present_thing' => '/../vendor/acme/myadmin-widget-plugin/src/present.php',
                'missing_thing' => '/../vendor/acme/myadmin-widget-plugin/src/missing.php',
            ]
        );

        $findings = $this->inspectGroundless($class);
        $this->assertCount(1, $findings, 'the path whose file exists must produce nothing at all');
        $this->assertTrue($findings[0]->isFailure());
        $this->assertSame('missing_thing', $findings[0]->context()['function']);
        $this->assertStringContainsString('missing.php', $findings[0]->message());
        $this->assertStringContainsString('will fatal', $findings[0]->message());
        $this->assertSame(
            $packageDir.'/src/missing.php',
            $findings[0]->context()['resolved'],
            'the path must be resolved against the package directory, not against a guessed root'
        );
        $this->assertSame(
            TierB10RequirementPathsResolve::GROUNDING_PACKAGE_RELATIVE,
            $findings[0]->context()['grounding']
        );
    }

    /**
     * The fix must not fabricate a verdict for a path it cannot see.
     * `myadmin-fantastico-licensing` registers `/vps/addons/vps_add_fantastico.php`, a
     * genuinely core-relative path to a file core does not have — a live fatal, and one of
     * B-10's fifteen fleet failures. Nothing in a standalone checkout can judge it, so it is
     * a skip, and the reason has to say which kind of skip it is: not "the plugin is
     * unloadable", not "the handler threw", not "the repo opted out" — this one is answerable
     * elsewhere.
     *
     * @return void
     */
    public function testCoreRelativeSourceWithNoCoreRootIsSkippedWithADistinguishableReason()
    {
        list($class) = $this->makeStandalonePackage(
            'Tests\MyAdmin\Plugins\Testing\Contract\StandaloneCoreRelB10',
            'acme/myadmin-fantastico-shaped',
            [],
            ['vps_add_thing' => '/vps/addons/vps_add_thing.php']
        );

        $findings = $this->inspectGroundless($class);
        $this->assertCount(1, $findings);
        $this->assertTrue($findings[0]->isSkipped());
        $this->assertFalse($findings[0]->isFailure(), 'an unjudgeable path must never be reported as a defect');
        $this->assertStringContainsString('/vps/addons/vps_add_thing.php', $findings[0]->message());
        $this->assertStringContainsString('points outside', $findings[0]->message());
        $this->assertStringContainsString('acme/myadmin-fantastico-shaped', $findings[0]->message());
        $this->assertSame(
            TierB10RequirementPathsResolve::GROUNDING_OUTSIDE_PACKAGE,
            $findings[0]->context()['grounding'],
            'the matrix has to be able to tell this skip from every other skip B-10 emits'
        );
    }

    /**
     * The fleet's other unjudgeable shape: `fantastico-licensing` registers two paths into
     * `detain/crud`, a package that is not itself. Pointing at *a* package is not the rule —
     * pointing at *this* package is.
     *
     * @return void
     */
    public function testCrossPackageSourceWithNoCoreRootIsSkipped()
    {
        list($class) = $this->makeStandalonePackage(
            'Tests\MyAdmin\Plugins\Testing\Contract\StandaloneCrossB10',
            'acme/myadmin-widget-plugin',
            ['/src/present.php'],
            ['crud_thing' => '/../vendor/detain/crud/src/crud/crud_thing.php']
        );

        $findings = $this->inspectGroundless($class);
        $this->assertCount(1, $findings);
        $this->assertTrue($findings[0]->isSkipped());
        $this->assertSame(
            TierB10RequirementPathsResolve::GROUNDING_OUTSIDE_PACKAGE,
            $findings[0]->context()['grounding']
        );
    }

    /**
     * The needle is `/vendor/<name>/` with both delimiters. Without the trailing one,
     * `acme/myadmin-widget` would swallow paths belonging to `acme/myadmin-widget-extra` and
     * resolve them to `<widget>/-extra/src/...`, inventing a dangling path out of a package
     * that is not even under inspection. The fleet has this collision for real:
     * `detain/myadmin-cpanel-licensing`, `detain/myadmin-cpanel-webhosting` and
     * `detain/myadmin-cpanel-vps-addon` all share a prefix.
     *
     * @return void
     */
    public function testPackageNeedleMatchesOnWholePathSegments()
    {
        list($class) = $this->makeStandalonePackage(
            'Tests\MyAdmin\Plugins\Testing\Contract\StandaloneSegmentB10',
            'acme/myadmin-widget',
            [],
            ['neighbour_thing' => '/../vendor/acme/myadmin-widget-extra/src/thing.php']
        );

        $findings = $this->inspectGroundless($class);
        $this->assertCount(1, $findings);
        $this->assertTrue(
            $findings[0]->isSkipped(),
            'a sibling package with a shared name prefix is outside this package, not inside it'
        );
    }

    /**
     * The other half of the needle. `vendor/` has to be a whole segment too, or
     * `/../notvendor/acme/myadmin-widget-plugin/src/present.php` — a path into some
     * lookalike directory that is not the Composer vendor tree at all — would be resolved
     * against this package and come back green.
     *
     * The fixture's file exists, so the wrong answer here is a **pass**, which is the
     * expensive direction: a check that silently stops checking.
     *
     * @return void
     */
    public function testPackageNeedleRequiresVendorItselfToBeAWholeSegment()
    {
        list($class) = $this->makeStandalonePackage(
            'Tests\MyAdmin\Plugins\Testing\Contract\StandaloneVendorSegmentB10',
            'acme/myadmin-widget-plugin',
            ['/src/present.php'],
            ['lookalike_thing' => '/../notvendor/acme/myadmin-widget-plugin/src/present.php']
        );

        $findings = $this->inspectGroundless($class);
        $this->assertCount(1, $findings, 'a lookalike directory is not this package');
        $this->assertTrue($findings[0]->isSkipped());
        $this->assertSame(
            TierB10RequirementPathsResolve::GROUNDING_OUTSIDE_PACKAGE,
            $findings[0]->context()['grounding']
        );
    }

    /**
     * The package name comes from the manifest, and it has to: in a standalone checkout the
     * directory is `<pkg>/<pkg>`, not `<vendor>/<pkg>`, so the directory names say
     * `myadmin-widget-plugin` twice and would never match the `acme/` the source names. This
     * fixture's directory is deliberately named nothing like its composer name.
     *
     * @return void
     */
    public function testPackageNameIsReadFromTheManifestNotTheDirectoryNames()
    {
        list($class, $packageDir) = $this->makeStandalonePackage(
            'Tests\MyAdmin\Plugins\Testing\Contract\StandaloneManifestB10',
            'acme/myadmin-widget-plugin',
            ['/src/present.php'],
            ['present_thing' => '/../vendor/acme/myadmin-widget-plugin/src/present.php'],
            'a-directory-named-nothing-like-the-package'
        );
        $this->assertSame('a-directory-named-nothing-like-the-package', basename($packageDir));

        $this->assertSame(
            [],
            $this->inspectGroundless($class),
            'the manifest name is the only thing that can match the registered path here'
        );
    }

    /**
     * A subject with no manifest — a scratch copy, a fixture — but laid out the way Composer
     * lays packages out is still identifiable. Second in the order, not first: a manifest
     * states the name, a directory only implies it.
     *
     * @return void
     */
    public function testVendorDirectoryLayoutNamesThePackageWhenThereIsNoManifest()
    {
        list($class, $packageDir) = $this->makeStandalonePackage(
            'Tests\MyAdmin\Plugins\Testing\Contract\StandaloneNoManifestB10',
            null,
            ['/src/present.php'],
            [
                'present_thing' => '/../vendor/acme/myadmin-widget-plugin/src/present.php',
                'missing_thing' => '/../vendor/acme/myadmin-widget-plugin/src/missing.php',
            ],
            'myadmin-widget-plugin',
            'vendor/acme'
        );
        $this->assertFileDoesNotExist($packageDir.'/composer.json');

        $findings = $this->inspectGroundless($class);
        $this->assertCount(1, $findings);
        $this->assertTrue($findings[0]->isFailure());
        $this->assertSame($packageDir.'/src/missing.php', $findings[0]->context()['resolved']);
    }

    /**
     * A tail that climbs back out of the package with `..` is answered against whatever
     * happens to sit beside the package on this machine — which is a verdict about a
     * directory nobody named, the exact failure mode the root ladder's `is_dir()` gates
     * refuse. Zero of the fleet's 305 self-referencing sources do this; one that did must be
     * skipped rather than resolved.
     *
     * The fixture points at a file that really does exist outside the package, so a checker
     * that resolved it would report a confident, wrong **pass**.
     *
     * @return void
     */
    public function testTailThatClimbsBackOutOfThePackageIsNotJudgedAgainstIt()
    {
        $base = $this->makeBase();
        file_put_contents($base.'/outside.php', "<?php\n");

        list($class) = $this->makeStandalonePackage(
            'Tests\MyAdmin\Plugins\Testing\Contract\StandaloneEscapeB10',
            'acme/myadmin-widget-plugin',
            [],
            ['escaping_thing' => '/../vendor/acme/myadmin-widget-plugin/../outside.php'],
            'myadmin-widget-plugin',
            '',
            $base
        );

        $findings = $this->inspectGroundless($class);
        $this->assertCount(1, $findings);
        $this->assertTrue(
            $findings[0]->isSkipped(),
            'a path that leaves the package again must not be answered from the package'
        );
    }

    /**
     * A tail with `.` and a `..` that cancels out stays inside and is still judged — the
     * guard is against *escaping*, not against every mention of a dot segment.
     *
     * @return void
     */
    public function testTailThatStaysInsideThePackageAfterDotSegmentsIsStillJudged()
    {
        list($class) = $this->makeStandalonePackage(
            'Tests\MyAdmin\Plugins\Testing\Contract\StandaloneDotsB10',
            'acme/myadmin-widget-plugin',
            ['/src/present.php', '/lib/keep.php'],
            ['dotted_thing' => '/../vendor/acme/myadmin-widget-plugin/./lib/../src/present.php']
        );

        $this->assertSame([], $this->inspectGroundless($class));
    }

    /**
     * Both grounds must see the same string core would. Core drops everything up to the last
     * `\` plus one further character, so a source whose *namespace prefix* happens to contain
     * a `/vendor/<name>/` run does not register that path at all — core would require the
     * tail. Matching the raw string instead would resolve the decoy and report a failure
     * about a file core never asks for.
     *
     * @return void
     */
    public function testNamespaceArmIsAppliedBeforeThePackageNeedleIsMatched()
    {
        list($class) = $this->makeStandalonePackage(
            'Tests\MyAdmin\Plugins\Testing\Contract\StandaloneNsB10',
            'acme/myadmin-widget-plugin',
            [],
            ['decoyed_thing' => '/../vendor/acme/myadmin-widget-plugin/src/decoy.php\\Ysrc/real.php']
        );

        $findings = $this->inspectGroundless($class);
        $this->assertCount(1, $findings);
        $this->assertTrue(
            $findings[0]->isSkipped(),
            'after the namespace arm the source is "src/real.php", which names no package at all'
        );
        $this->assertSame(
            TierB10RequirementPathsResolve::GROUNDING_OUTSIDE_PACKAGE,
            $findings[0]->context()['grounding']
        );
    }

    /**
     * @return void
     */
    public function testNonStringSourceIsReportedEvenWithNoCoreRoot()
    {
        list($class) = $this->makeStandalonePackage(
            'Tests\MyAdmin\Plugins\Testing\Contract\StandaloneNonStringB10',
            'acme/myadmin-widget-plugin',
            [],
            ['thing' => 42]
        );

        $findings = $this->inspectGroundless($class);
        $this->assertCount(1, $findings);
        $this->assertTrue($findings[0]->isFailure());
        $this->assertStringContainsString('not a non-empty string', $findings[0]->message());
    }

    /**
     * A finding must never name a root it did not use. `Finding::describe()` renders the
     * context verbatim into the fleet matrix, and `root=NULL` there would read as "resolved
     * against the filesystem root" to anyone triaging it.
     *
     * @return void
     */
    public function testPackageGroundedFindingsCarryNoRootKey()
    {
        list($class) = $this->makeStandalonePackage(
            'Tests\MyAdmin\Plugins\Testing\Contract\StandaloneNoRootKeyB10',
            'acme/myadmin-widget-plugin',
            [],
            [
                'missing_thing' => '/../vendor/acme/myadmin-widget-plugin/src/missing.php',
                'core_thing' => '/vps/addons/vps_add_thing.php',
            ]
        );

        $findings = $this->inspectGroundless($class);
        $this->assertCount(2, $findings);
        foreach ($findings as $finding) {
            $this->assertArrayNotHasKey('root', $finding->context());
            $this->assertStringNotContainsString('root=', $finding->describe());
        }
    }

    /**
     * A subject that can be neither rooted nor named has no ground at all, and says so
     * naming the directory it looked in. Silence here would be the worst of the three
     * outcomes: a plugin registering paths, none of them checked, reported as compliant.
     *
     * @return void
     */
    public function testSubjectWithNeitherARootNorANameIsSkippedNamingTheDirectory()
    {
        list($class, $packageDir) = $this->makeStandalonePackage(
            'Tests\MyAdmin\Plugins\Testing\Contract\StandaloneAnonymousB10',
            null,
            ['/src/present.php'],
            ['present_thing' => '/../vendor/acme/myadmin-widget-plugin/src/present.php'],
            'anonymous-package'
        );

        $findings = $this->inspectGroundless($class);
        $this->assertCount(1, $findings);
        $this->assertTrue($findings[0]->isSkipped());
        $this->assertStringContainsString($packageDir, $findings[0]->message());
        $this->assertStringContainsString('could not be identified', $findings[0]->message());
    }

    // -------------------------------------------------------------------
    // R-10 — and the ground that was already there keeps winning
    // -------------------------------------------------------------------

    /**
     * **The regression guard for the whole change.** The package-relative ground is an
     * addition, not a replacement: when a core root exists, it decides, and a file sitting in
     * the package cannot rescue a path that does not resolve under the root.
     *
     * Without this, the cheap version of R-10 — resolve everything against `packageDir()` —
     * looks correct on every fixture in this file and quietly costs the fleet its
     * `fantastico-licensing` finding, a live 500.
     *
     * @return void
     */
    public function testACoreRootStillDecidesEvenForASelfReferencingPathThePackageHolds()
    {
        list($class, $packageDir) = $this->makeStandalonePackage(
            'Tests\MyAdmin\Plugins\Testing\Contract\RootWinsB10',
            'acme/myadmin-widget-plugin',
            ['/src/present.php'],
            ['present_thing' => '/../vendor/acme/myadmin-widget-plugin/src/present.php']
        );
        $this->assertFileExists($packageDir.'/src/present.php', 'the package really does hold the file');

        // An empty root: nothing resolves under it, so the only way this can come back green
        // is if the package ground overrode the root.
        $root = $this->makeRoot();
        $findings = $this->inspector->inspect(new PluginSubject($class, ['requirementRoot' => $root]));

        $this->assertCount(1, $findings);
        $this->assertTrue($findings[0]->isFailure(), 'the root decides when there is one');
        $this->assertSame($root, $findings[0]->context()['root']);
        $this->assertArrayNotHasKey(
            'grounding',
            $findings[0]->context(),
            'a root-grounded finding must stay byte-identical to what the fleet matrix committed'
        );
    }

    /**
     * The `fantastico-licensing` shape under a real root: still a failure, not the new skip.
     * If the "points outside the package" skip ever leaked into root mode it would convert
     * that package's cell from fail to skip and drop B-10 from fifteen failing packages to
     * fourteen.
     *
     * @return void
     */
    public function testCoreRelativePathUnderACoreRootIsStillAFailureAndNeverTheNewSkip()
    {
        list($class) = $this->makeStandalonePackage(
            'Tests\MyAdmin\Plugins\Testing\Contract\RootCoreRelB10',
            'acme/myadmin-fantastico-shaped',
            [],
            ['vps_add_thing' => '/vps/addons/vps_add_thing.php']
        );

        $root = $this->makeRoot();
        $findings = $this->inspector->inspect(new PluginSubject($class, ['requirementRoot' => $root]));

        $this->assertCount(1, $findings);
        $this->assertTrue($findings[0]->isFailure());
        $this->assertSame($root.'/vps/addons/vps_add_thing.php', $findings[0]->context()['resolved']);
    }

    /**
     * The explicit opt-out is an opt-out from the assertion, not from one way of resolving
     * it. A repo that switched B-10 off does not get it switched back on by a ground it never
     * asked about.
     *
     * @return void
     */
    public function testExplicitOptOutIsNotResurrectedByThePackageGround()
    {
        list($class) = $this->makeStandalonePackage(
            'Tests\MyAdmin\Plugins\Testing\Contract\OptedOutStandaloneB10',
            'acme/myadmin-widget-plugin',
            [],
            ['missing_thing' => '/../vendor/acme/myadmin-widget-plugin/src/missing.php']
        );

        $findings = (new TierB10GroundlessInspector())->inspect(
            new PluginSubject($class, ['requirementRoot' => null])
        );
        $this->assertCount(1, $findings);
        $this->assertTrue($findings[0]->isSkipped());
        $this->assertSame('requirementRoot', $findings[0]->context()['override']);
    }

    /**
     * A `requirementRoot()` naming a non-directory still short-circuits. Substituting the
     * package ground for the root the repo named is the same mistake as substituting a
     * derived root for it — a verdict about a directory nobody asked for.
     *
     * The fixture's file exists, so a fall-through would come back green and the repo would
     * never learn its hatch is broken.
     *
     * @return void
     */
    public function testBadExplicitRootDoesNotFallThroughToThePackageGround()
    {
        list($class) = $this->makeStandalonePackage(
            'Tests\MyAdmin\Plugins\Testing\Contract\BadRootStandaloneB10',
            'acme/myadmin-widget-plugin',
            ['/src/present.php'],
            ['present_thing' => '/../vendor/acme/myadmin-widget-plugin/src/present.php']
        );

        $absent = $this->makeBase().'/no-such-directory-at-all';
        $findings = (new TierB10GroundlessInspector())->inspect(
            new PluginSubject($class, ['requirementRoot' => $absent])
        );
        $this->assertCount(1, $findings);
        $this->assertTrue($findings[0]->isSkipped());
        $this->assertStringContainsString('not a directory', $findings[0]->message());
    }

    // -------------------------------------------------------------------
    // Helpers for the R-10 fixtures
    // -------------------------------------------------------------------

    /**
     * @param string $class
     * @return array<int,Finding>
     */
    private function inspectGroundless($class)
    {
        return (new TierB10GroundlessInspector())->inspect(new PluginSubject($class));
    }

    /**
     * Writes a package on disk and returns its plugin class and directory.
     *
     * The plugin source is generated rather than declared inline because the whole point is
     * where the class *lives*: `PluginSubject::packageDir()` reads the reflected file name, so
     * a fixture declared at the bottom of this file would always report this repository's
     * `tests/` directory no matter what the test is about.
     *
     * @param string              $namespace    unique per fixture; the class is `<namespace>\Plugin`
     * @param string|null         $composerName written to composer.json, or null to ship no manifest
     * @param array<int,string>   $files        files to create inside the package
     * @param array<string,mixed> $sources      requirement table the plugin registers
     * @param string              $dirName      package directory name
     * @param string              $under        path between the scratch base and the package
     * @param string|null         $base         scratch base, or null for a fresh one
     * @return array{0:string,1:string} [plugin class, absolute package dir]
     */
    private function makeStandalonePackage(
        $namespace,
        $composerName,
        array $files,
        array $sources,
        $dirName = 'myadmin-widget-plugin',
        $under = '',
        $base = null
    ) {
        $base = $base === null ? $this->makeBase() : $base;
        $packageDir = $base.($under === '' ? '' : '/'.$under).'/'.$dirName;
        mkdir($packageDir.'/src', 0777, true);

        if ($composerName !== null) {
            file_put_contents(
                $packageDir.'/composer.json',
                (string)json_encode(['name' => $composerName, 'type' => 'myadmin-plugin'])
            );
        }
        foreach ($files as $relative) {
            $full = $packageDir.'/'.ltrim($relative, '/');
            if (!is_dir(dirname($full))) {
                mkdir(dirname($full), 0777, true);
            }
            file_put_contents($full, "<?php\n");
        }

        $source = "<?php\n\nnamespace ".$namespace.";\n\n"
            ."class Plugin\n{\n"
            ."    public static function getHooks()\n    {\n"
            ."        return ['function.requirements' => [__CLASS__, 'getRequirements']];\n"
            ."    }\n\n"
            ."    public static function getRequirements(\$event)\n    {\n"
            ."        \$loader = \$event->getSubject();\n"
            ."        foreach (".var_export($sources, true)." as \$function => \$source) {\n"
            ."            \$loader->add_requirement(\$function, \$source);\n"
            ."        }\n"
            ."    }\n}\n";
        file_put_contents($packageDir.'/src/Plugin.php', $source);
        require_once $packageDir.'/src/Plugin.php';

        return [$namespace.'\\Plugin', $packageDir];
    }
}

/**
 * The inspector with the root ladder pinned empty — the standalone environment, made
 * deterministic.
 *
 * Overriding a public method of the class under test is normally a smell. Here it is the only
 * honest option: rung 4 of the ladder derives `<core>/include` from the *installer package's*
 * own location, and this suite runs from inside a MyAdmin checkout, so the real inspector
 * finds a root on a developer machine and finds none in the installer's own CI. A test that
 * inherited that split would assert the new ground in one place and assert nothing in the
 * other, which is precisely the "green here, grey there" reading R-10 exists to end.
 *
 * Nothing else is stubbed. `inspect()`, the real `Loader`, the real handler dispatch, the real
 * on-disk lookups and every other rung's `is_dir()` gate are the production ones; the ladder's
 * *result* is the single pinned input.
 */
class TierB10GroundlessInspector extends TierB10RequirementPathsResolve
{
    /**
     * @param PluginSubject $subject
     * @return null
     */
    public function requirementRootFor(PluginSubject $subject)
    {
        return null;
    }
}

// -----------------------------------------------------------------------
// Fixtures — names unique to this file.
// -----------------------------------------------------------------------

/** Lets one fixture plugin register whatever the current test needs. */
class TierB10Registry
{
    /** @var array<string,mixed> */
    public static $sources = [];
}

class TierB10RegistryPlugin
{
    /**
     * @return array<string,array<int,string>>
     */
    public static function getHooks()
    {
        return ['function.requirements' => [__CLASS__, 'getRequirements']];
    }

    /**
     * @param mixed $event
     * @return void
     */
    public static function getRequirements($event)
    {
        $loader = $event->getSubject();
        foreach (TierB10Registry::$sources as $function => $source) {
            $loader->add_requirement($function, $source);
        }
    }
}

class TierB10PageRequirementPlugin
{
    /**
     * @return array<string,array<int,string>>
     */
    public static function getHooks()
    {
        return ['function.requirements' => [__CLASS__, 'getRequirements']];
    }

    /**
     * @param mixed $event
     * @return void
     */
    public static function getRequirements($event)
    {
        $loader = $event->getSubject();
        $loader->add_page_requirement('present_page', '/src/page.php');
        $loader->add_page_requirement('missing_page', '/src/gone.php');
    }
}

class TierB10SourcelessRoutePlugin
{
    /**
     * @return array<string,array<int,string>>
     */
    public static function getHooks()
    {
        return ['function.requirements' => [__CLASS__, 'getRequirements']];
    }

    /**
     * @param mixed $event
     * @return void
     */
    public static function getRequirements($event)
    {
        $loader = $event->getSubject();
        $loader->add_route_requirement('client', 'routed_without_source');
        $loader->add_requirement('empty_source', '');
    }
}

class TierB10SubjectSwappingPlugin
{
    /**
     * @return array<string,array<int,string>>
     */
    public static function getHooks()
    {
        return ['function.requirements' => [__CLASS__, 'getRequirements']];
    }

    /**
     * Symfony's real `GenericEvent` has no `setSubject()` — only the fallback event does — so
     * the swap is exercised in an environment without the component and the equivalent
     * registration is made directly in one that has it. Either way the inspector must end up
     * reporting exactly one requirement named `swapped_in`.
     *
     * @param mixed $event
     * @return void
     */
    public static function getRequirements($event)
    {
        if (method_exists($event, 'setSubject')) {
            $replacement = new \MyAdmin\Plugins\Loader();
            $replacement->add_requirement('swapped_in', '/src/not-there.php');
            $event->setSubject($replacement);
            return;
        }
        $event->getSubject()->add_requirement('swapped_in', '/src/not-there.php');
    }
}

class TierB10EchoingPlugin
{
    /**
     * @return array<string,array<int,string>>
     */
    public static function getHooks()
    {
        return ['function.requirements' => [__CLASS__, 'getRequirements']];
    }

    /**
     * @param mixed $event
     * @return void
     */
    public static function getRequirements($event)
    {
        echo 'a plugin that talks during a test run';
        $event->getSubject()->add_requirement('noisy', '/src/absent.php');
    }
}

class TierB10HookPointsElsewherePlugin
{
    /**
     * @return array<string,array<int,string>>
     */
    public static function getHooks()
    {
        return ['function.requirements' => [__CLASS__, 'registerPaths']];
    }

    /**
     * @param mixed $event
     * @return void
     */
    public static function registerPaths($event)
    {
        $event->getSubject()->add_requirement('from_the_hooked_handler', '/src/absent.php');
    }

    /**
     * Never dispatched; the hook points at registerPaths(). If the inspector called this one
     * it would be checking paths production never registers.
     *
     * @param mixed $event
     * @return void
     */
    public static function getRequirements($event)
    {
        $event->getSubject()->add_requirement('from_the_unhooked_method', '/src/absent.php');
    }
}

class TierB10NoRequirementsPlugin
{
    /**
     * @return array<string,array<int,string>>
     */
    public static function getHooks()
    {
        return ['system.settings' => [__CLASS__, 'getSettings']];
    }
}

class TierB10ThrowingHandlerPlugin
{
    /**
     * @return array<string,array<int,string>>
     */
    public static function getHooks()
    {
        return ['function.requirements' => [__CLASS__, 'getRequirements']];
    }

    /**
     * @param mixed $event
     * @return void
     */
    public static function getRequirements($event)
    {
        throw new \RuntimeException('detonated');
    }
}

class TierB10DanglingHookPlugin
{
    /**
     * @return array<string,array<int,string>>
     */
    public static function getHooks()
    {
        return ['function.requirements' => [__CLASS__, 'handlerThatWasRenamed']];
    }
}

class TierB10MalformedHookPlugin
{
    /**
     * @return array<string,mixed>
     */
    public static function getHooks()
    {
        return ['function.requirements' => 'not_a_pair'];
    }
}

class TierB10ThrowingHooksPlugin
{
    /**
     * @return array<string,mixed>
     */
    public static function getHooks()
    {
        throw new \RuntimeException('hooks exploded');
    }
}
