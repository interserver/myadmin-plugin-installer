<?php

namespace Tests\MyAdmin\Plugins\Testing\Contract;

use MyAdmin\Plugins\Testing\Contract\Finding;
use MyAdmin\Plugins\Testing\Contract\PluginSubject;
use MyAdmin\Plugins\Testing\Contract\TierB14QueueActionScanner;
use MyAdmin\Plugins\Testing\Contract\TierB14TemplateCompleteness;
use PHPUnit\Framework\TestCase;

/**
 * Test suite for Tier-B-14.
 *
 * Fixtures are real package trees under sys_get_temp_dir(), with real `templates/*.sh.tpl`
 * files, because the behaviour under test is filesystem discovery anchored on
 * PluginSubject::packageDir(). PluginScannerTest and VendorGuardTest set the same precedent.
 *
 * @covers \MyAdmin\Plugins\Testing\Contract\TierB14TemplateCompleteness
 * @covers \MyAdmin\Plugins\Testing\Contract\TierB14QueueActionScanner
 */
class TierB14TemplateCompletenessTest extends TestCase
{
    /** @var string */
    private $root;

    /** @var TierB14TemplateCompleteness */
    private $inspector;

    /** @var int keeps every fixture class name unique; a collision in one process is fatal */
    private static $seq = 0;

    /** @var string */
    private $originalCwd;

    /**
     * The dynamic dispatch six of the eight real queue handlers use.
     *
     * @var string
     */
    private const DYNAMIC_BODY = <<<'PHP'
        $serviceInfo = $event->getSubject();
        if (!file_exists(__DIR__.'/../templates/'.$serviceInfo['action'].'.sh.tpl')) {
            return false;
        }
        $smarty = new \stdClass();
        return __DIR__.'/../templates/'.$serviceInfo['action'].'.sh.tpl';
PHP;

    protected function setUp(): void
    {
        $this->originalCwd = (string)getcwd();
        $this->root = sys_get_temp_dir().'/tierb14_'.getmypid().'_'.uniqid();
        mkdir($this->root, 0777, true);
        $this->inspector = new TierB14TemplateCompleteness();
    }

    protected function tearDown(): void
    {
        chdir($this->originalCwd);
        $this->rrmdir($this->root);
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
     * Writes and loads a fixture service package, returning its class name.
     *
     * @param string     $queueBody body of getQueue(); omit via options['withQueue'] = false
     * @param array      $options   type, templates, templatesDir, extra, withQueue
     */
    private function makePlugin(string $queueBody, array $options = []): string
    {
        $namespace = 'TierB14Fixture'.getmypid().'x'.(++self::$seq);
        $package = $this->root.'/pkg'.self::$seq;
        mkdir($package.'/src', 0777, true);
        $this->makeTemplates($package.'/'.($options['templatesDir'] ?? 'templates'), $options['templates'] ?? null);
        $type = $options['type'] ?? 'service';
        $extra = $options['extra'] ?? '';
        $queue = ($options['withQueue'] ?? true)
            ? "    public static function getQueue(\$event)\n    {\n".$queueBody."\n    }\n"
            : '';
        $source = "<?php\nnamespace {$namespace};\nclass Plugin\n{\n"
            ."    public static \$name = 'Fixture';\n"
            ."    public static \$module = 'vps';\n"
            ."    public static \$type = '{$type}';\n"
            .'    '.$extra."\n"
            .$queue
            ."}\n";
        $file = $package.'/src/Plugin.php';
        file_put_contents($file, $source);
        require $file;
        return $namespace.'\\Plugin';
    }

    /**
     * @param string[]|null $templates null to create no directory at all
     */
    private function makeTemplates(string $dir, ?array $templates): void
    {
        if ($templates === null) {
            return;
        }
        mkdir($dir, 0777, true);
        foreach ($templates as $name) {
            file_put_contents($dir.'/'.$name.'.sh.tpl', "#!/bin/sh\necho {$name}\n");
        }
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

    public function testCatalogueIdentityIsStable(): void
    {
        $this->assertSame('B-14', $this->inspector->id());
        $this->assertSame('Queue templates and queue handler agree', $this->inspector->title());
    }

    public function testNonServicePluginIsNotApplicableRatherThanPassedOrSkipped(): void
    {
        $class = $this->makePlugin(self::DYNAMIC_BODY, ['type' => 'plugin', 'templates' => ['create']]);

        $findings = $this->inspect($class);

        $this->assertCount(1, $findings);
        $this->assertTrue($findings[0]->isNotApplicable(), 'a non-service plugin must not read as a pass');
        $this->assertFalse($findings[0]->isSkipped(), 'nor as a check that could not run');
        $this->assertStringContainsString('type is "plugin", not "service"', $findings[0]->message());
        $this->assertSame('B-14', $findings[0]->assertion());
    }

    public function testServicePluginWithoutAQueueHandlerIsNotApplicable(): void
    {
        $class = $this->makePlugin('', ['withQueue' => false, 'templates' => ['create']]);

        $findings = $this->inspect($class);

        $this->assertCount(1, $findings);
        $this->assertTrue($findings[0]->isNotApplicable());
        $this->assertFalse($findings[0]->isSkipped());
        $this->assertStringContainsString('no getQueue() handler', $findings[0]->message());
    }

    public function testUnloadableClassIsSkipped(): void
    {
        $findings = $this->inspect('TierB14\\Definitely\\Missing');

        $this->assertCount(1, $findings);
        $this->assertTrue($findings[0]->isSkipped());
        $this->assertStringContainsString('does not load', $findings[0]->message());
    }

    /**
     * The myadmin-hyperv-vps shape: a queue handler that dispatches to SOAP calls through a
     * map and renders nothing. Template completeness simply does not apply.
     */
    public function testQueueHandlerThatRendersNoTemplatesIsNotApplicable(): void
    {
        $body = <<<'PHP'
        $serviceInfo = $event->getSubject();
        $calls = ['restart' => ['Reboot'], 'stop' => ['TurnOff']];
        return $calls[$serviceInfo['action']] ?? [];
PHP;
        $class = $this->makePlugin($body, ['templates' => null]);

        $findings = $this->inspect($class);

        $this->assertCount(1, $findings);
        $this->assertTrue($findings[0]->isNotApplicable());
        $this->assertFalse($findings[0]->isSkipped(), 'the scan read the handler cleanly and found no templates');
        $this->assertStringContainsString('does not render *.sh.tpl', $findings[0]->message());
    }

    public function testMissingTemplateDirectoryIsAFailure(): void
    {
        $class = $this->makePlugin(self::DYNAMIC_BODY, ['templates' => null]);

        $failures = $this->failures($this->inspect($class));

        $this->assertCount(1, $failures, $this->messages($failures));
        $this->assertStringContainsString('which does not exist', $failures[0]->message());
        $this->assertStringEndsWith('/templates', $failures[0]->context()['directory']);
    }

    /**
     * The one branch of this inspector that R-4 leaves as a skip, and the reason it does not
     * go to zero. Everything of B-14's kind is present — a service, a queue handler, a
     * directory holding three templates — and the action set cannot be recovered, so none of
     * the three is checked. That is a blind spot, and `o` would file it among the packages
     * that simply have no queue.
     */
    public function testDynamicDispatchWithNoLiteralActionsStaysASkipRatherThanBecomingNotApplicable(): void
    {
        $class = $this->makePlugin(self::DYNAMIC_BODY, ['templates' => ['create', 'delete', 'restart']]);

        $findings = $this->inspect($class);

        $this->assertCount(1, $findings);
        $this->assertTrue($findings[0]->isSkipped(), 'nothing was cross-checked, so this is not a pass');
        $this->assertFalse(
            $findings[0]->isNotApplicable(),
            'three real templates went unchecked; that is a coverage hole, not an absent feature'
        );
        $this->assertStringContainsString('no action set to cross-check', $findings[0]->message());
        $this->assertSame(3, $findings[0]->context()['templates']);
    }

    public function testActionReferencedWithoutATemplateIsAFailure(): void
    {
        $body = "        \$serviceInfo = \$event->getSubject();\n"
            ."        if (in_array(\$serviceInfo['action'], ['create', 'resize'])) {\n"
            ."            return __DIR__.'/../templates/'.\$serviceInfo['action'].'.sh.tpl';\n"
            ."        }\n"
            ."        return false;";
        $class = $this->makePlugin($body, ['templates' => ['create']]);

        $findings = $this->inspect($class);
        $failures = $this->failures($findings);

        $this->assertCount(1, $failures, $this->messages($findings));
        $this->assertStringContainsString('queue action "resize" has no template', $failures[0]->message());
        $this->assertSame('resize', $failures[0]->context()['action']);
    }

    public function testActionsThatAllHaveTemplatesPass(): void
    {
        $body = "        \$serviceInfo = \$event->getSubject();\n"
            ."        if (in_array(\$serviceInfo['action'], ['create', 'delete'])) {\n"
            ."            return __DIR__.'/../templates/'.\$serviceInfo['action'].'.sh.tpl';\n"
            ."        }\n"
            ."        return false;";
        $class = $this->makePlugin($body, ['templates' => ['create', 'delete']]);

        $findings = $this->inspect($class);

        $this->assertSame([], $findings, $this->messages($findings));
    }

    /**
     * Under a dynamic dispatch every file in the directory is selectable from queue data, so
     * "unreachable" is not a thing that can be said about any of them.
     */
    public function testUnreachableNoticesAreSuppressedUnderDynamicDispatch(): void
    {
        $body = "        \$serviceInfo = \$event->getSubject();\n"
            ."        if (in_array(\$serviceInfo['action'], ['create'])) {\n"
            ."            return __DIR__.'/../templates/'.\$serviceInfo['action'].'.sh.tpl';\n"
            ."        }\n"
            ."        return false;";
        $class = $this->makePlugin($body, ['templates' => ['create', 'destroy', 'backup']]);

        $findings = $this->inspect($class);

        $this->assertSame([], $findings, 'destroy and backup are reachable from queue data: '.$this->messages($findings));
    }

    /**
     * The other direction, and the one Finding::notice() was introduced for: it is reported,
     * and it does not fail.
     */
    public function testPresentButUnreachableTemplateIsANoticeThatDoesNotFail(): void
    {
        $body = "        \$serviceInfo = \$event->getSubject();\n"
            ."        if (\$serviceInfo['action'] === 'create') {\n"
            ."            return __DIR__.'/../templates/create.sh.tpl';\n"
            ."        }\n"
            ."        return false;";
        $class = $this->makePlugin($body, ['templates' => ['create', 'destroy']]);

        $findings = $this->inspect($class);

        $this->assertSame([], $this->failures($findings), 'an unreachable template must never fail a build');
        $notices = $this->notices($findings);
        $this->assertCount(1, $notices, $this->messages($findings));
        $this->assertSame(Finding::NOTICE, $notices[0]->severity());
        $this->assertStringContainsString('"destroy.sh.tpl" is present but no action', $notices[0]->message());
        $this->assertFalse($notices[0]->isFailure());
        $this->assertFalse($notices[0]->isSkipped());
    }

    public function testBothDirectionsAreReportedTogether(): void
    {
        $body = "        \$serviceInfo = \$event->getSubject();\n"
            ."        if (in_array(\$serviceInfo['action'], ['create', 'resize'])) {\n"
            ."            return __DIR__.'/../templates/create.sh.tpl';\n"
            ."        }\n"
            ."        return false;";
        $class = $this->makePlugin($body, ['templates' => ['create', 'destroy']]);

        $findings = $this->inspect($class);

        $failures = $this->failures($findings);
        $notices = $this->notices($findings);
        $this->assertCount(1, $failures, $this->messages($findings));
        $this->assertStringContainsString('"resize"', $failures[0]->message());
        $this->assertCount(1, $notices, $this->messages($findings));
        $this->assertStringContainsString('"destroy.sh.tpl"', $notices[0]->message());
    }

    public function testSwitchCaseActionsAreExtracted(): void
    {
        $body = "        \$serviceInfo = \$event->getSubject();\n"
            ."        switch (\$serviceInfo['action']) {\n"
            ."            case 'start':\n"
            ."            case 'stop':\n"
            ."                return __DIR__.'/../templates/'.\$serviceInfo['action'].'.sh.tpl';\n"
            ."        }\n"
            ."        return false;";
        $class = $this->makePlugin($body, ['templates' => ['start']]);

        $failures = $this->failures($this->inspect($class));

        $this->assertCount(1, $failures, $this->messages($failures));
        $this->assertSame('stop', $failures[0]->context()['action']);
    }

    public function testComparisonActionsAreExtractedInEitherOrder(): void
    {
        $body = "        \$serviceInfo = \$event->getSubject();\n"
            ."        if ('reboot' == \$serviceInfo['action']) {\n"
            ."            return __DIR__.'/../templates/'.\$serviceInfo['action'].'.sh.tpl';\n"
            ."        }\n"
            ."        return false;";
        $class = $this->makePlugin($body, ['templates' => ['create']]);

        $failures = $this->failures($this->inspect($class));

        $this->assertCount(1, $failures, $this->messages($failures));
        $this->assertSame('reboot', $failures[0]->context()['action']);
    }

    /**
     * The false positive that shaped the extraction rules. A looser "string literals near an
     * ['action'] subscript" rule pulled 'error' out of the log call below and would have
     * reported a missing error.sh.tpl on six real packages.
     */
    public function testLogMessageLiteralsAreNotMistakenForActions(): void
    {
        $body = "        \$serviceInfo = \$event->getSubject();\n"
            ."        if (!file_exists(__DIR__.'/../templates/'.\$serviceInfo['action'].'.sh.tpl')) {\n"
            ."            \$msg = 'error' . 'Call '.\$serviceInfo['action'].' for VPS '.\$serviceInfo['id'];\n"
            ."            return \$msg;\n"
            ."        }\n"
            ."        return __DIR__.'/../templates/'.\$serviceInfo['action'].'.sh.tpl';";
        $class = $this->makePlugin($body, ['templates' => ['create']]);

        $findings = $this->inspect($class);

        $this->assertSame([], $this->failures($findings), $this->messages($findings));
        $this->assertCount(1, $findings);
        $this->assertTrue($findings[0]->isSkipped(), 'no action literal was found, so nothing was cross-checked');
        $this->assertFalse($findings[0]->isNotApplicable(), 'a template directory this check cannot read is a hole');
    }

    /**
     * A harvested literal is only an action if it could name a file. Without this filter a
     * path-like or sentence-like operand becomes a demand for a template that could never
     * have existed, which is a false failure.
     */
    public function testImplausibleActionLiteralsAreIgnored(): void
    {
        $body = "        \$serviceInfo = \$event->getSubject();\n"
            ."        if (in_array(\$serviceInfo['action'], ['create', 'not a filename', '../escape'])) {\n"
            ."            return __DIR__.'/../templates/'.\$serviceInfo['action'].'.sh.tpl';\n"
            ."        }\n"
            ."        return false;";
        $class = $this->makePlugin($body, ['templates' => ['create']]);

        $findings = $this->inspect($class);

        $this->assertSame([], $findings, 'only "create" is a plausible action name: '.$this->messages($findings));
    }

    public function testTypeSubscriptIsNotMistakenForActionSubscript(): void
    {
        $source = "<?php\nin_array(\$event['type'], ['DOCKER', 'DOCKER_STORAGE']);\n";

        $this->assertSame([], TierB14QueueActionScanner::actionLiterals($source));
    }

    /**
     * myadmin-quickservers-module renders from __DIR__.'/../../myadmin-kvm-vps/templates/',
     * a sibling package it does not declare a dependency on. The directory therefore has to
     * come from the source, not from an assumption that it is <package>/templates.
     */
    public function testCrossPackageTemplateDirectoryIsResolved(): void
    {
        $body = "        \$serviceInfo = \$event->getSubject();\n"
            ."        if (in_array(\$serviceInfo['action'], ['create'])) {\n"
            ."            return __DIR__.'/../../sibling/templates/'.\$serviceInfo['action'].'.sh.tpl';\n"
            ."        }\n"
            ."        return false;";
        mkdir($this->root.'/sibling/templates', 0777, true);
        file_put_contents($this->root.'/sibling/templates/create.sh.tpl', "echo create\n");

        $class = $this->makePlugin($body, ['templates' => null]);

        $findings = $this->inspect($class);

        $this->assertSame([], $findings, 'the sibling package supplies the template: '.$this->messages($findings));
    }

    public function testCrossPackageTemplateDirectoryThatIsAbsentIsAFailure(): void
    {
        $body = "        \$serviceInfo = \$event->getSubject();\n"
            ."        return __DIR__.'/../../absent/templates/'.\$serviceInfo['action'].'.sh.tpl';";

        $class = $this->makePlugin($body, ['templates' => null]);

        $failures = $this->failures($this->inspect($class));

        $this->assertCount(1, $failures, $this->messages($failures));
        $this->assertSame($this->root.'/absent/templates', $failures[0]->context()['directory']);
    }

    /**
     * myadmin-webuzo-vps has a require_once resolved against the current working directory,
     * so its suite only passes when run from inside the core tree. An inspector with the same
     * flaw would report different results depending on where it was invoked from.
     */
    public function testResultIsIndependentOfTheCurrentWorkingDirectory(): void
    {
        $body = "        \$serviceInfo = \$event->getSubject();\n"
            ."        if (in_array(\$serviceInfo['action'], ['create', 'resize'])) {\n"
            ."            return __DIR__.'/../templates/'.\$serviceInfo['action'].'.sh.tpl';\n"
            ."        }\n"
            ."        return false;";
        $class = $this->makePlugin($body, ['templates' => ['create']]);

        $fromHere = $this->messages($this->inspect($class));
        chdir(sys_get_temp_dir());
        $fromElsewhere = $this->messages($this->inspect($class));
        chdir('/');
        $fromRoot = $this->messages($this->inspect($class));

        $this->assertStringContainsString('"resize" has no template', $fromHere);
        $this->assertSame($fromHere, $fromElsewhere);
        $this->assertSame($fromHere, $fromRoot);
    }

    /**
     * Reading any static property makes PHP evaluate every constant expression the class
     * declares, so a plugin with a PRORATE_BILLING-style initialiser cannot have its $type
     * read by reflection at all. Ten of the 69 are in that state; if the gate treated the
     * throw as "no type", a real service plugin would be silently dropped from the check.
     */
    public function testTypeIsRecoveredFromSourceWhenStaticInitialisersThrow(): void
    {
        $class = $this->makePlugin('', [
            'withQueue' => false,
            'templates' => ['create'],
            'extra' => 'public static $settings = [TIER_B14_UNDEFINED_CONSTANT];',
        ]);

        $findings = $this->inspect($class);

        $this->assertCount(1, $findings, $this->messages($findings));
        $this->assertTrue($findings[0]->isNotApplicable());
        $this->assertStringContainsString(
            'no getQueue() handler',
            $findings[0]->message(),
            'the gate must recognise this as a service plugin, not report its type as undeclared'
        );
        $this->assertStringNotContainsString('undeclared', $findings[0]->message());
    }

    public function testScannerDescribesDynamicAndClosedDispatchDifferently(): void
    {
        $dynamic = TierB14QueueActionScanner::templateDispatches(
            "<?php\n\$s->fetch(__DIR__.'/../templates/'.\$info['action'].'.sh.tpl');\n"
        );
        $closed = TierB14QueueActionScanner::templateDispatches(
            "<?php\n\$s->fetch(__DIR__.'/../templates/create.sh.tpl');\n"
        );

        $this->assertCount(1, $dynamic);
        $this->assertTrue($dynamic[0]['dynamic']);
        $this->assertSame('dir', $dynamic[0]['anchor']);
        $this->assertSame('/../templates/', $dynamic[0]['directory']);
        $this->assertNull($dynamic[0]['template']);

        $this->assertCount(1, $closed);
        $this->assertFalse($closed[0]['dynamic']);
        $this->assertSame('create', $closed[0]['template']);
        $this->assertSame('/../templates/', $closed[0]['directory']);
    }

    public function testScannerFindsNoDispatchWhenNothingRendersATemplate(): void
    {
        $this->assertSame(
            [],
            TierB14QueueActionScanner::templateDispatches("<?php\n\$calls = ['restart' => ['Reboot']];\n")
        );
    }
}
