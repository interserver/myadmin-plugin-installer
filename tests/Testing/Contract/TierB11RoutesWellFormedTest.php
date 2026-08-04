<?php

namespace Tests\MyAdmin\Plugins\Testing\Contract;

use MyAdmin\Plugins\Loader;
use MyAdmin\Plugins\Testing\Contract\Finding;
use MyAdmin\Plugins\Testing\Contract\PluginSubject;
use MyAdmin\Plugins\Testing\Contract\TierB11RecordingLoader;
use MyAdmin\Plugins\Testing\Contract\TierB11RouteCallScanner;
use MyAdmin\Plugins\Testing\Contract\TierB11RoutesWellFormed;
use MyAdmin\Plugins\Testing\FleetMatrix;
use PHPUnit\Framework\TestCase;

/**
 * Test suite for Tier-B-11.
 *
 * Fixture plugins are written to real files under sys_get_temp_dir() rather than declared
 * inline, for two reasons. The inspector's source-scan mode reads
 * `ReflectionClass::getFileName()`, so inline fixtures would all share this test file and
 * every scan would see every other fixture's route calls. And a plugin package is what the
 * inspector is modelled on, so a fixture that is one is a truer test than one that is not.
 *
 * @covers \MyAdmin\Plugins\Testing\Contract\TierB11RoutesWellFormed
 * @covers \MyAdmin\Plugins\Testing\Contract\TierB11RecordingLoader
 * @covers \MyAdmin\Plugins\Testing\Contract\TierB11RouteCallScanner
 * @covers \MyAdmin\Plugins\Testing\Contract\SubjectEvent
 */
class TierB11RoutesWellFormedTest extends TestCase
{
    /** @var string */
    private $root;

    /** @var TierB11RoutesWellFormed */
    private $inspector;

    /** @var int keeps every fixture class name unique; a collision in one process is fatal */
    private static $seq = 0;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/tierb11_'.getmypid().'_'.uniqid();
        mkdir($this->root, 0777, true);
        $this->inspector = new TierB11RoutesWellFormed();
        TierB11RouteCallScanner::reset();
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->root);
        TierB11RouteCallScanner::reset();
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (array_diff(scandir($dir), ['.', '..']) as $entry) {
            $path = $dir.'/'.$entry;
            is_dir($path) && !is_link($path) ? $this->rrmdir($path) : @unlink($path);
        }
        @rmdir($dir);
    }

    /**
     * Writes and loads a fixture plugin package, returning its class name.
     *
     * @param string $body    statements for the requirements handler; `$loader` is in scope
     * @param array  $options paramType, handlerName, hooks, extra
     */
    private function makePlugin(string $body, array $options = []): string
    {
        $namespace = 'TierB11Fixture'.getmypid().'x'.(++self::$seq);
        $dir = $this->root.'/pkg'.self::$seq.'/src';
        mkdir($dir, 0777, true);
        $paramType = $options['paramType'] ?? '';
        $handler = $options['handlerName'] ?? 'getRequirements';
        $hooks = isset($options['hooks'])
            ? "public static function getHooks() { return {$options['hooks']}; }"
            : '';
        $extra = $options['extra'] ?? '';
        $source = "<?php\nnamespace {$namespace};\nclass Plugin\n{\n"
            ."    public static \$type = 'plugin';\n"
            .'    '.$extra."\n"
            .'    '.$hooks."\n"
            ."    public static function {$handler}({$paramType}\$event)\n"
            ."    {\n"
            ."        \$loader = \$event->getSubject();\n"
            .$body."\n"
            ."    }\n"
            ."}\n";
        $file = $dir.'/Plugin.php';
        file_put_contents($file, $source);
        require $file;
        return $namespace.'\\Plugin';
    }

    /**
     * A plugin with no handler at all: nothing to require, nothing to load.
     */
    private function makeHandlerlessPlugin(): string
    {
        $namespace = 'TierB11Fixture'.getmypid().'x'.(++self::$seq);
        $dir = $this->root.'/pkg'.self::$seq.'/src';
        mkdir($dir, 0777, true);
        $file = $dir.'/Plugin.php';
        file_put_contents($file, "<?php\nnamespace {$namespace};\nclass Plugin { public static \$type = 'plugin'; }\n");
        require $file;
        return $namespace.'\\Plugin';
    }

    /**
     * @return Finding[]
     */
    private function inspect(string $class): array
    {
        return $this->inspector->inspect(new PluginSubject($class));
    }

    /**
     * @param Finding[] $findings
     * @return Finding[]
     */
    private function failures(array $findings): array
    {
        return array_values(array_filter($findings, function (Finding $finding) {
            return $finding->isFailure();
        }));
    }

    /**
     * @param Finding[] $findings
     * @return Finding[]
     */
    private function notices(array $findings): array
    {
        return array_values(array_filter($findings, function (Finding $finding) {
            return $finding->severity() === Finding::NOTICE;
        }));
    }

    /**
     * @param Finding[] $findings
     */
    private function messages(array $findings): string
    {
        return implode(' | ', array_map(function (Finding $finding) {
            return $finding->describe();
        }, $findings));
    }

    public function testWellFormedRoutesProduceNoFindings(): void
    {
        $class = $this->makePlugin("        \$loader->add_page_requirement('abuse', '/src/abuse.php');");

        $findings = $this->inspect($class);

        $this->assertSame([], $findings, 'a plugin registering one ordinary page must pass cleanly');
    }

    public function testCatalogueIdentityIsStable(): void
    {
        $this->assertSame('B-11', $this->inspector->id());
        $this->assertSame('Route registrations are well-formed', $this->inspector->title());
    }

    public function testDuplicatePathWithinOnePluginIsAFailure(): void
    {
        $class = $this->makePlugin(
            "        \$loader->add_page_requirement('abuse', '/src/abuse.php');\n"
            ."        \$loader->add_page_requirement('abuse', '/src/other.php');"
        );

        $failures = $this->failures($this->inspect($class));

        // add_page_requirement registers both /abuse and /admin/abuse, so a repeated call
        // collides twice.
        $this->assertCount(2, $failures, $this->messages($failures));
        $this->assertStringContainsString('registered more than once', $failures[0]->message());
        $this->assertSame('B-11', $failures[0]->assertion());
        $paths = [$failures[0]->context()['path'], $failures[1]->context()['path']];
        sort($paths);
        $this->assertSame(['/abuse', '/admin/abuse'], $paths);
    }

    public function testPathNotStartingWithASlashIsAFailure(): void
    {
        $class = $this->makePlugin(
            "        \$loader->add_route_requirement('client', 'thing', '', 'thing', ['GET']);"
        );

        $failures = $this->failures($this->inspect($class));

        $this->assertCount(1, $failures, $this->messages($failures));
        $this->assertStringContainsString('does not start with "/"', $failures[0]->message());
    }

    public function testUnknownHttpVerbIsAFailure(): void
    {
        $class = $this->makePlugin(
            "        \$loader->add_route_requirement('client', 'thing', '', '/thing', ['GET', 'FETCH']);"
        );

        $failures = $this->failures($this->inspect($class));

        $this->assertCount(1, $failures, $this->messages($failures));
        $this->assertStringContainsString('"FETCH"', $failures[0]->message());
    }

    public function testLowercaseVerbIsAFailure(): void
    {
        $class = $this->makePlugin(
            "        \$loader->add_route_requirement('client', 'thing', '', '/thing', ['get']);"
        );

        $failures = $this->failures($this->inspect($class));

        $this->assertCount(1, $failures, $this->messages($failures));
        $this->assertStringContainsString('"get"', $failures[0]->message());
    }

    public function testEmptyMethodListIsAFailure(): void
    {
        $class = $this->makePlugin(
            "        \$loader->add_route_requirement('client', 'thing', '', '/thing', []);"
        );

        $failures = $this->failures($this->inspect($class));

        $this->assertCount(1, $failures, $this->messages($failures));
        $this->assertStringContainsString('empty method list', $failures[0]->message());
    }

    public function testBareStringMethodIsANoticeAndNeverAFailure(): void
    {
        $class = $this->makePlugin(
            "        \$loader->add_route_requirement('client_api', 'thing', '', '/apiv2/thing', 'GET');"
        );

        $findings = $this->inspect($class);

        $this->assertSame([], $this->failures($findings), $this->messages($findings));
        $notices = $this->notices($findings);
        $this->assertCount(1, $notices);
        $this->assertStringContainsString('bare string "GET"', $notices[0]->message());
        $this->assertFalse($notices[0]->isFailure(), 'the bare-string form must never fail a build');
    }

    public function testUnknownRouteTypeIsAFailure(): void
    {
        $class = $this->makePlugin(
            "        \$loader->add_route_requirement('clietn', 'thing', '', '/thing', ['GET']);"
        );

        $failures = $this->failures($this->inspect($class));

        $this->assertCount(1, $failures, $this->messages($failures));
        $this->assertStringContainsString('permission buckets', $failures[0]->message());
    }

    public function testEmptyHandlerIsAFailure(): void
    {
        $class = $this->makePlugin(
            "        \$loader->add_route_requirement('client', '', '', '/thing', ['GET']);"
        );

        $failures = $this->failures($this->inspect($class));

        $this->assertCount(1, $failures, $this->messages($failures));
        $this->assertStringContainsString('empty string', $failures[0]->message());
    }

    /**
     * The documented multi-verb convention: an array handler whose method is the literal
     * 'METHOD', which route.php rewrites to the lowercased request verb. It must be accepted,
     * not mistaken for a malformed callable or for an eighth HTTP verb.
     */
    public function testMultiVerbMethodConventionIsAccepted(): void
    {
        $class = $this->makePlugin(
            "        \$loader->add_route_requirement('admin_api', ['\\\\MyAdmin\\\\Api\\\\Thing', 'METHOD'], "
            ."'', '/apiv2/admin/thing', ['GET', 'POST', 'DELETE']);"
        );

        $findings = $this->inspect($class);

        $this->assertSame([], $findings, $this->messages($findings));
    }

    public function testMalformedHandlerArrayIsAFailure(): void
    {
        $class = $this->makePlugin(
            "        \$loader->add_route_requirement('client', ['\\\\Only\\\\One'], '', '/thing', ['GET']);"
        );

        $failures = $this->failures($this->inspect($class));

        $this->assertCount(1, $failures, $this->messages($failures));
        $this->assertStringContainsString('[class, method] pair', $failures[0]->message());
    }

    /**
     * Neither a pass nor a skip (R-4). No handler means no route registration means nothing of
     * B-11's kind in this package, and the check established that rather than failing to.
     */
    public function testPluginWithNoRequirementsHandlerIsNotApplicableRatherThanPassedOrSkipped(): void
    {
        $findings = $this->inspect($this->makeHandlerlessPlugin());

        $this->assertCount(1, $findings);
        $this->assertTrue($findings[0]->isNotApplicable(), 'a plugin with no handler must not read as a pass');
        $this->assertFalse($findings[0]->isSkipped(), 'nor as a check that could not run');
        $this->assertStringContainsString('no function.requirements handler', $findings[0]->message());
    }

    /**
     * The handler ran, every `$loader->` call was watched, and none of them registered a
     * route. That is an observation, so the cell is `o` and not `-`.
     */
    public function testPluginThatRegistersOnlyRequirementsIsNotApplicableRatherThanPassedOrSkipped(): void
    {
        $class = $this->makePlugin(
            "        \$loader->add_requirement('class.Thing', '/src/Thing.php');"
        );

        $findings = $this->inspect($class);

        $this->assertCount(1, $findings);
        $this->assertTrue($findings[0]->isNotApplicable());
        $this->assertFalse($findings[0]->isSkipped());
        $this->assertSame('plugin registers no routes', $findings[0]->message());
    }

    public function testUnloadableClassIsSkipped(): void
    {
        $findings = $this->inspect('TierB11\\Definitely\\Missing');

        $this->assertCount(1, $findings);
        $this->assertTrue($findings[0]->isSkipped());
        $this->assertStringContainsString('does not load', $findings[0]->message());
    }

    public function testHandlerBoundUnderANonConventionalNameIsStillFollowed(): void
    {
        $class = $this->makePlugin(
            "        \$loader->add_route_requirement('client', 'thing', '', 'thing', ['GET']);",
            [
                'handlerName' => 'wireRoutes',
                'hooks' => "['function.requirements' => [__CLASS__, 'wireRoutes']]",
            ]
        );

        $failures = $this->failures($this->inspect($class));

        $this->assertCount(1, $failures, 'the handler named by getHooks() must be the one executed');
        $this->assertStringContainsString('does not start with "/"', $failures[0]->message());
    }

    /**
     * A handler that cannot be invoked — here because its parameter type cannot be satisfied,
     * exactly as `GenericEvent` cannot be in this package — must fall back to the source scan
     * rather than silently reporting nothing.
     */
    public function testUninvokableHandlerFallsBackToSourceScan(): void
    {
        $class = $this->makePlugin(
            "        \$loader->add_page_requirement('abuse', '/src/abuse.php');\n"
            ."        \$loader->add_page_requirement('abuse', '/src/other.php');",
            ['paramType' => '\\DateTimeImmutable ']
        );

        $failures = $this->failures($this->inspect($class));

        $this->assertCount(2, $failures, $this->messages($failures));
        $this->assertSame('source-scan', $failures[0]->context()['mode']);
        $this->assertArrayHasKey('line', $failures[0]->context(), 'source-scan findings carry a source line');
    }

    public function testExecutionModeIsUsedWhenTheHandlerCanBeInvoked(): void
    {
        $class = $this->makePlugin(
            "        \$loader->add_route_requirement('client', 'thing', '', 'thing', ['GET']);"
        );

        $failures = $this->failures($this->inspect($class));

        $this->assertCount(1, $failures);
        $this->assertSame('execute', $failures[0]->context()['mode']);
    }

    /**
     * The two modes are only interchangeable if they observe the same thing. Verified on the
     * fleet across 52 packages and 443 registrations; pinned here so a change to either path
     * cannot quietly diverge.
     */
    public function testBothModesObserveTheSameRegistrations(): void
    {
        $body = "        \$loader->add_page_requirement('one', '/src/one.php');\n"
            ."        \$loader->add_admin_page_requirement('two', '/src/two.php');\n"
            ."        \$loader->add_requirement('class.Three', '/src/Three.php');\n"
            ."        \$loader->add_route_requirement('client_api', 'four', '', '/apiv2/four', ['GET', 'POST']);";

        $executed = new TierB11RecordingLoader();
        $executable = $this->makePlugin($body);
        $executable::getRequirements(new \MyAdmin\Plugins\Testing\Contract\SubjectEvent($executed));

        $scanned = new TierB11RecordingLoader();
        $scannable = $this->makePlugin($body, ['paramType' => '\\DateTimeImmutable ']);
        $file = (new \ReflectionClass($scannable))->getFileName();
        foreach (TierB11RouteCallScanner::scanFile($file) as $call) {
            $this->assertTrue($call['resolved'], 'literal call sites must all resolve');
            call_user_func_array([$scanned, $call['helper']], $call['args']);
        }

        $strip = function (array $rows): array {
            return array_map(function (array $row): array {
                unset($row['line']);
                return $row;
            }, $rows);
        };
        $this->assertSame($strip($executed->registrations()), $strip($scanned->registrations()));
        $this->assertSame(
            ['/one', '/admin/one', '/admin/two', '/apiv2/four'],
            array_column($executed->registrations(), 'path'),
            'add_page_requirement registers twice, add_admin_page_requirement once, add_requirement never'
        );
    }

    /**
     * Stays a skip after R-4, and the contrast with the test below is the whole point of the
     * fourth state. Here the scan found a route registration and could not read it: a route
     * may well exist and this inspector cannot say, which is a coverage hole. There, the scan
     * read the source cleanly and there was no registration to find.
     */
    public function testNonLiteralArgumentsAreSkippedRatherThanPassedOrCalledInapplicable(): void
    {
        $class = $this->makePlugin(
            "        \$name = 'thing';\n"
            ."        \$loader->add_page_requirement(\$name, '/src/thing.php');",
            ['paramType' => '\\DateTimeImmutable ']
        );

        $findings = $this->inspect($class);

        $this->assertCount(1, $findings);
        $this->assertTrue($findings[0]->isSkipped(), 'an unevaluable registration must not read as a pass');
        $this->assertFalse(
            $findings[0]->isNotApplicable(),
            'a registration this inspector cannot read is a blind spot, not an absent feature'
        );
        $this->assertStringContainsString('non-literal arguments', $findings[0]->message());
    }

    /**
     * Source-scan mode is a first-class observation mode, not a degraded one (see the
     * inspector's class docblock), so a clean scan that finds no call sites has reached a
     * verdict: this plugin registers no routes.
     */
    public function testSourceScanThatFindsNoRouteCallsIsNotApplicableRatherThanSkipped(): void
    {
        $class = $this->makePlugin(
            "        \$loader->add_requirement('class.Thing', '/src/Thing.php');",
            ['paramType' => '\\DateTimeImmutable ']
        );

        $findings = $this->inspect($class);

        $this->assertCount(1, $findings, $this->messages($findings));
        $this->assertTrue($findings[0]->isNotApplicable());
        $this->assertFalse($findings[0]->isSkipped());
        $this->assertSame('plugin registers no routes', $findings[0]->message());
        $this->assertSame('source-scan', $findings[0]->context()['mode'], 'the fallback mode really was used');
    }

    public function testCommentedOutRegistrationsAreNotScanned(): void
    {
        $class = $this->makePlugin(
            "        //\$loader->add_route_requirement('client', 'thing', '', 'thing', ['GET']);\n"
            ."        \$loader->add_page_requirement('real', '/src/real.php');",
            ['paramType' => '\\DateTimeImmutable ']
        );

        $findings = $this->inspect($class);

        $this->assertSame([], $findings, 'a commented-out call is not a registration: '.$this->messages($findings));
    }

    /**
     * The other half of using a tokeniser: a comment *inside* a call must not hide it. If
     * comment tokens survived into the scan they would break argument evaluation, and the
     * registration would silently degrade from "checked" to "could not be evaluated".
     */
    public function testInlineCommentsInsideARegistrationDoNotHideIt(): void
    {
        $class = $this->makePlugin(
            "        \$loader->add_route_requirement('client', /* handler */ 'thing', '', 'thing', ['GET']);",
            ['paramType' => '\\DateTimeImmutable ']
        );

        $failures = $this->failures($this->inspect($class));

        $this->assertCount(1, $failures, 'an inline comment must not cost us the registration');
        $this->assertStringContainsString('does not start with "/"', $failures[0]->message());
    }

    /**
     * Nine repos initialise a static from a bare constant that does not exist outside a
     * MyAdmin request, and reading any one static evaluates them all — so getHooks() throws.
     * The inspector must recover to the conventional handler name, not rethrow.
     */
    public function testGetHooksThrowingIsSurvivedRatherThanRethrown(): void
    {
        $class = $this->makePlugin(
            "        \$loader->add_route_requirement('client', 'thing', '', 'thing', ['GET']);",
            [
                'extra' => 'public static $settings = [TIER_B11_UNDEFINED_CONSTANT];',
                'hooks' => "['function.requirements' => [__CLASS__, 'getRequirements'], "
                    ."'poison' => self::\$settings]",
            ]
        );

        $failures = $this->failures($this->inspect($class));

        $this->assertCount(1, $failures, 'a throwing getHooks() must not cost us the check');
        $this->assertStringContainsString('does not start with "/"', $failures[0]->message());
    }

    /**
     * When the handler itself throws, execution is impossible and the source scan must still
     * report the defect rather than the plugin reading as a pass.
     */
    public function testHandlerThatThrowsFallsBackToSourceScan(): void
    {
        $class = $this->makePlugin(
            "        \$boom = self::\$settings;\n"
            ."        \$loader->add_route_requirement('client', 'thing', '', 'thing', ['GET']);",
            ['extra' => 'public static $settings = [TIER_B11_UNDEFINED_CONSTANT];']
        );

        $failures = $this->failures($this->inspect($class));

        $this->assertCount(1, $failures);
        $this->assertSame('source-scan', $failures[0]->context()['mode']);
    }

    // -----------------------------------------------------------------------
    // Buffer discipline (R-8)
    // -----------------------------------------------------------------------

    /**
     * Execute mode runs plugin code, so a `function.requirements` handler with a stray echo
     * used to print straight into the PHPUnit process. `beStrictAboutOutputDuringTests` +
     * `failOnRisky` then failed *this* test with `R  This test printed output: …`, naming
     * neither the plugin nor the handler — the report B-15 exists to replace.
     */
    public function testRequirementsHandlerOutputIsCapturedRatherThanEscaping(): void
    {
        $class = $this->makePlugin(
            "        echo 'b11 requirements leak';\n"
            ."        \$loader->add_page_requirement('abuse', '/src/abuse.php');"
        );

        $level = ob_get_level();
        ob_start();
        $findings = $this->inspect($class);
        $escaped = ob_get_clean();

        $this->assertSame('', $escaped, 'the inspector must swallow the handler output, not re-emit it');
        $this->assertSame($level, ob_get_level());
        $this->assertNotSame([], $findings);
    }

    /**
     * Captured is not the same as reported. B-15 executes `getSettings()` and `getMenu()` and
     * never this handler, so B-11 is the only inspector in the catalogue that will ever see
     * these bytes — dropping them would be the swallowed evidence the harness exists to catch.
     */
    public function testEchoingRequirementsHandlerIsReportedAsAFailure(): void
    {
        $class = $this->makePlugin(
            "        echo 'b11 requirements leak';\n"
            ."        \$loader->add_page_requirement('abuse', '/src/abuse.php');"
        );

        $failures = $this->failures($this->inspect($class));

        $this->assertCount(1, $failures, $this->messages($failures));
        $this->assertStringContainsString('b11 requirements leak', $failures[0]->message());
        $this->assertStringContainsString('add_output()', $failures[0]->message());
        $this->assertSame(21, $failures[0]->context()['bytes']);
        $this->assertSame('execute', $failures[0]->context()['mode']);
    }

    /**
     * "Registers no routes" is not-applicable, and it must not absorb the print: the two are
     * separate observations and both are true. The failure still decides the cell — the matrix
     * requires *every* finding to be not-applicable before it will call a cell vacuous — so a
     * package that prints from a route-less handler stays red rather than going quiet.
     */
    public function testOutputIsReportedEvenWhenTheHandlerRegistersNoRoutes(): void
    {
        $class = $this->makePlugin("        echo 'b11 silent but noisy';");

        $findings = $this->inspect($class);

        $this->assertCount(2, $findings, $this->messages($findings));
        $this->assertTrue($findings[0]->isFailure(), 'the printed bytes are a defect and must be reported');
        $this->assertStringContainsString('b11 silent but noisy', $findings[0]->message());
        $this->assertTrue($findings[1]->isNotApplicable(), 'and no route was observed, which is not a skip');
        $this->assertSame(
            FleetMatrix::FAIL,
            FleetMatrix::verdictFor(array_map(static function (Finding $finding) {
                return $finding->severity();
            }, $findings)),
            'the not-applicable finding must not dilute the failure'
        );
    }

    /**
     * A handler that prints and then throws really printed. Execution mode fails and the
     * route observation falls back to the source scan, but the bytes were written and must
     * survive that fallback rather than being dropped with the failed invocation.
     */
    public function testOutputThatPrecededAThrowSurvivesTheSourceScanFallback(): void
    {
        $class = $this->makePlugin(
            "        echo 'b11 printed then died';\n"
            ."        \$loader->add_route_requirement('client', 'thing', '', 'thing', ['GET']);\n"
            ."        throw new \\RuntimeException('b11 handler exploded');"
        );

        $failures = $this->failures($this->inspect($class));

        $this->assertCount(2, $failures, $this->messages($failures));
        $this->assertStringContainsString('b11 printed then died', $failures[0]->message());
        $this->assertSame('source-scan', $failures[1]->context()['mode'], 'the route came from the scan');
    }

    /**
     * `getHooks()` is buffered too — otherwise B-11 would be blamed for a print that belongs
     * to A-5 — but the bytes are dropped here, because A-5 makes the identical call under the
     * identical preconditions and reports them in its own column.
     */
    public function testGetHooksOutputIsSwallowedWithoutBeingReportedHere(): void
    {
        $class = $this->makePlugin(
            "        \$loader->add_page_requirement('abuse', '/src/abuse.php');",
            [
                'extra' => "public static function getHooks() { echo 'b11 hooks leak'; "
                    ."return ['function.requirements' => [__CLASS__, 'getRequirements']]; }",
            ]
        );

        $level = ob_get_level();
        ob_start();
        $findings = $this->inspect($class);
        $escaped = ob_get_clean();

        $this->assertSame('', $escaped, 'B-11 must not let A-5\'s defect escape into the test process');
        $this->assertSame($level, ob_get_level());
        $this->assertSame([], $findings, 'a getHooks() print is A-5\'s to report: '.$this->messages($findings));
    }

    /**
     * The rationale for TierB11RecordingLoader existing at all: get_routes() keys by path, so
     * a collision leaves no trace there. If this ever stops being true the recorder can be
     * deleted — and until then, duplicate detection cannot be built on get_routes().
     */
    public function testRecordingLoaderKeepsDuplicatesThatGetRoutesDiscards(): void
    {
        $loader = new TierB11RecordingLoader();
        $loader->add_route_requirement('client', 'first', '', '/same', ['GET']);
        $loader->add_route_requirement('admin', 'second', '', '/same', ['POST']);

        $this->assertCount(1, $loader->get_routes(), 'get_routes() cannot show a collision');
        $this->assertCount(2, $loader->registrations(), 'the recorder must keep both');
        $this->assertSame('first', $loader->registrations()[0]['function']);
        $this->assertSame('second', $loader->registrations()[1]['function']);
    }

    /**
     * The recorder must not model the Loader's defaulting rules, it must inherit them.
     */
    public function testRecordingLoaderInheritsProductionDefaults(): void
    {
        $loader = new TierB11RecordingLoader();
        $loader->add_page_requirement('thing', '/src/thing.php');

        $registrations = $loader->registrations();
        $this->assertCount(2, $registrations);
        $this->assertSame(['/thing', '/admin/thing'], array_column($registrations, 'path'));
        $this->assertSame(['GET', 'POST'], $registrations[0]['methods'], 'the $methods=false default');

        $reference = new Loader();
        $reference->add_page_requirement('thing', '/src/thing.php');
        $this->assertSame(
            array_keys($reference->get_routes()),
            array_column(array_reverse($registrations), 'path'),
            'recorded paths must match a plain Loader (get_routes sorts longest-first)'
        );
    }

    public function testScannerRecoversArityEvenWhenArgumentsAreNotLiterals(): void
    {
        $calls = TierB11RouteCallScanner::scanSource(
            "<?php\n\$loader->add_page_requirement(\$name, '/src/x.php', ['GET']);\n"
        );

        $this->assertCount(1, $calls);
        $this->assertSame('add_page_requirement', $calls[0]['helper']);
        $this->assertSame(3, $calls[0]['argCount']);
        $this->assertFalse($calls[0]['resolved']);
    }

    public function testScannerIgnoresNonLoaderMethodsAndPlainFunctions(): void
    {
        $calls = TierB11RouteCallScanner::scanSource(
            "<?php\nadd_page_requirement('x', '/y.php');\n\$other->add_thing('x', '/y.php');\n"
        );

        $this->assertSame([], $calls, 'only method calls named after a Loader route helper count');
    }

    public function testRouteHelperListIsDerivedFromTheLoaderAndExcludesAddRequirement(): void
    {
        $helpers = TierB11RouteCallScanner::routeHelpers();

        $this->assertArrayHasKey('add_page_requirement', $helpers);
        $this->assertArrayHasKey('add_route_requirement', $helpers);
        $this->assertArrayHasKey('add_admin_api_page_requirement', $helpers);
        $this->assertArrayNotHasKey('add_requirement', $helpers, 'add_requirement registers no route');
        $this->assertSame(2, $helpers['add_page_requirement']['required']);
        $this->assertSame(5, $helpers['add_route_requirement']['total']);
    }
}
