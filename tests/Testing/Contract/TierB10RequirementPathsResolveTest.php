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
     * An *unset* root is not an opt-out: it falls back, and the check still runs.
     *
     * @return void
     */
    public function testUnsetRootIsNotTreatedAsAnOptOut()
    {
        $subject = new PluginSubject(TierB10NoRequirementsPlugin::class);
        $this->assertFalse($subject->skipsRequirementCheck());
        $this->assertSame([], $this->inspector->inspect($subject));
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
     * @return void
     */
    public function testPluginRegisteringNothingPassesVacuously()
    {
        $root = $this->makeRoot();
        $this->assertSame([], $this->inspect(TierB10NoRequirementsPlugin::class, $root));
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
