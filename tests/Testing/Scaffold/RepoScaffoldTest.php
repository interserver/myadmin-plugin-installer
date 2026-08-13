<?php

namespace Tests\MyAdmin\Plugins\Testing\Scaffold;

use MyAdmin\Plugins\Testing\Scaffold\PluginFacts;
use MyAdmin\Plugins\Testing\Scaffold\RepoScaffold;
use MyAdmin\Plugins\Testing\Scaffold\SkillDoc;
use PHPUnit\Framework\TestCase;

/**
 * @covers \MyAdmin\Plugins\Testing\Scaffold\RepoScaffold
 */
class RepoScaffoldTest extends TestCase
{
    /** @var string */
    private $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/scaffold-'.uniqid('', true);
        mkdir($this->root.'/tests', 0755, true);
    }

    protected function tearDown(): void
    {
        $this->deleteTree($this->root);
    }

    /**
     * @param string $path
     * @return void
     */
    private function deleteTree($path)
    {
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $full = $path.'/'.$entry;
            is_dir($full) ? $this->deleteTree($full) : unlink($full);
        }
        rmdir($path);
    }

    /**
     * @param array $manifest
     * @return \MyAdmin\Plugins\Testing\Scaffold\RepoScaffold
     */
    private function scaffold(array $manifest = [])
    {
        file_put_contents($this->root.'/composer.json', json_encode($manifest + [
            'name' => 'detain/myadmin-thing',
            'require' => ['php' => '>=8.2', 'ext-soap' => '*', 'detain/myadmin-plugin-installer' => '^2.1'],
        ]));
        return new RepoScaffold($this->root);
    }

    /**
     * @return \MyAdmin\Plugins\Testing\Scaffold\PluginFacts
     */
    private function facts()
    {
        return new PluginFacts('Detain\\MyAdminThing\\Plugin', 'Detain\\MyAdminThing\\Tests', 'Thing', 'service', 'licenses', ['licenses.activate']);
    }

    /**
     * @param array  $plan
     * @param string $path
     * @return array
     */
    private function entryFor(array $plan, $path)
    {
        foreach ($plan as $entry) {
            if ($entry['path'] === $path) {
                return $entry;
            }
        }
        $this->fail('no plan entry for '.$path);
    }

    public function testAnEmptyPackageGetsAllFourFiles(): void
    {
        $plan = $this->scaffold()->plan($this->facts());

        $expected = [
            'tests/ContractTest.php',
            'phpunit.xml.dist',
            '.github/workflows/tests.yml',
            '.claude/skills/plugin-contract-tests/SKILL.md',
        ];
        foreach ($expected as $path) {
            $this->assertSame(RepoScaffold::CREATE, $this->entryFor($plan, $path)['action'], $path);
            $this->assertNotEmpty($this->entryFor($plan, $path)['contents'], $path);
        }
    }

    /**
     * The skill is generated for the same reason the test is: a package that lands on the
     * harness while its own `.claude/skills/` still argues for reflection-only assertions has
     * been converted in the code and un-converted in the documentation the next session reads
     * first.
     */
    public function testTheGeneratedSkillNamesThisPackagesOwnPluginClass(): void
    {
        $entry = $this->entryFor(
            $this->scaffold()->plan($this->facts()),
            '.claude/skills/plugin-contract-tests/SKILL.md'
        );

        $this->assertStringContainsString('Detain\\MyAdminThing\\Plugin', $entry['contents']);
        $this->assertStringContainsString('name: plugin-contract-tests', $entry['contents']);
    }

    /**
     * Reporting is the whole intervention here. A planner that rewrote these files would be
     * deleting per-package knowledge — which class must not be constructed, and why — that is
     * written down nowhere else.
     */
    public function testASkillStillTeachingTheOldPatternIsReportedAndNotTouched(): void
    {
        $scaffold = $this->scaffold();
        mkdir($this->root.'/.claude/skills/phpunit-reflection-test', 0755, true);
        $stale = $this->root.'/.claude/skills/phpunit-reflection-test/SKILL.md';
        file_put_contents($stale, "---\nname: phpunit-reflection-test\n---\nUse ReflectionClass only.\n");

        $entry = $this->entryFor(
            $scaffold->plan($this->facts()),
            '.claude/skills/plugin-contract-tests/SKILL.md'
        );

        $this->assertSame(['phpunit-reflection-test'], $scaffold->supersededSkills());
        $this->assertStringContainsString('phpunit-reflection-test', implode(' ', $entry['notes']));
        $this->assertStringContainsString('do not rewrite or delete it', implode(' ', $entry['notes']));
        $this->assertSame(
            "---\nname: phpunit-reflection-test\n---\nUse ReflectionClass only.\n",
            file_get_contents($stale),
            'planning must not touch the file it is reporting on'
        );
    }

    public function testAnAmendedSkillIsNoLongerReportedAsStale(): void
    {
        $scaffold = $this->scaffold();
        mkdir($this->root.'/.claude/skills/phpunit-reflection-test', 0755, true);
        file_put_contents(
            $this->root.'/.claude/skills/phpunit-reflection-test/SKILL.md',
            "---\nname: phpunit-reflection-test\n---\n".(new SkillDoc())->supersedeNotice()."Use ReflectionClass only.\n"
        );

        $this->assertSame([], $scaffold->supersededSkills());
    }

    /**
     * The generated skill is not itself a finding. Without this exclusion the command would
     * report the file it just wrote as stale, because it necessarily discusses reflection in
     * order to tell you not to rely on it.
     */
    public function testTheGeneratedSkillIsNotReportedAsStaleAgainstItself(): void
    {
        $scaffold = $this->scaffold();
        mkdir($this->root.'/.claude/skills/plugin-contract-tests', 0755, true);
        file_put_contents(
            $this->root.'/.claude/skills/plugin-contract-tests/SKILL.md',
            (new SkillDoc())->render($this->facts())
        );

        $this->assertSame([], $scaffold->supersededSkills());
    }

    /**
     * The fleet has 55 distinct phpunit configs and 63 distinct workflows across 66
     * packages. Most of that variation is a package knowing something about itself that is
     * written down nowhere else, so flattening it would be a silent deletion.
     */
    public function testAnExistingFileIsKeptRatherThanRewritten(): void
    {
        $scaffold = $this->scaffold();
        file_put_contents($this->root.'/phpunit.xml.dist', '<phpunit failOnWarning="true" failOnRisky="true" beStrictAboutOutputDuringTests="true"/>');
        file_put_contents($this->root.'/tests/ContractTest.php', '<?php // hand written');
        mkdir($this->root.'/.github/workflows', 0755, true);
        file_put_contents($this->root.'/.github/workflows/tests.yml', 'name: Tests');
        mkdir($this->root.'/.claude/skills/plugin-contract-tests', 0755, true);
        file_put_contents($this->root.'/.claude/skills/plugin-contract-tests/SKILL.md', '# hand written');

        foreach ($scaffold->plan($this->facts()) as $entry) {
            $this->assertSame(RepoScaffold::KEEP, $entry['action'], $entry['path']);
        }
    }

    /**
     * KEEP still carries the generated contents, because that is what --force regenerates
     * from; the guarantee is that the command does not write it, not that the planner
     * forgets it.
     */
    public function testAKeptContractTestStillCarriesWhatRegenerationWouldProduce(): void
    {
        $scaffold = $this->scaffold();
        file_put_contents($this->root.'/tests/ContractTest.php', '<?php // older generation');

        $entry = $this->entryFor($scaffold->plan($this->facts()), 'tests/ContractTest.php');

        $this->assertSame(RepoScaffold::KEEP, $entry['action']);
        $this->assertStringContainsString('class ContractTest extends ServicePluginTestCase', $entry['contents']);
        $this->assertNotEmpty($entry['notes'], 'a package on an older generation should be told so');
    }

    public function testAContractTestAlreadyMatchingTheTemplateIsReportedAsIdentical(): void
    {
        $scaffold = $this->scaffold();
        $planned = $this->entryFor($scaffold->plan($this->facts()), 'tests/ContractTest.php')['contents'];
        file_put_contents($this->root.'/tests/ContractTest.php', $planned);

        $entry = $this->entryFor($scaffold->plan($this->facts()), 'tests/ContractTest.php');

        $this->assertContains('identical to what this generator produces.', $entry['notes']);
    }

    /**
     * A config missing failOnWarning is the case where a contract finding is printed and
     * the build still exits 0 — the exact failure mode the harness cannot tolerate.
     */
    public function testAConfigMissingASettingTheHarnessNeedsIsReportedAsDrift(): void
    {
        $scaffold = $this->scaffold();
        file_put_contents($this->root.'/phpunit.xml.dist', '<phpunit failOnRisky="true" beStrictAboutOutputDuringTests="true"/>');

        $entry = $this->entryFor($scaffold->plan($this->facts()), 'phpunit.xml.dist');

        $this->assertSame(RepoScaffold::DRIFT, $entry['action']);
        $this->assertNull($entry['contents'], 'drift is reported, never repaired in place');
        $this->assertStringContainsString('failOnWarning', implode(' ', $entry['notes']));
    }

    public function testDriftIsNotReportedForAConfigThatHasEverythingTheHarnessNeeds(): void
    {
        $scaffold = $this->scaffold();

        $this->assertSame([], $scaffold->auditPhpunitConfig(
            '<phpunit failOnWarning="true" failOnRisky="true" beStrictAboutOutputDuringTests="true"/>'
        ));
    }

    /**
     * The derivation is checked against the kvm-vps pilot, whose matrix and extension list
     * were written by hand long before this template existed.
     */
    public function testTheCiMatrixIsDerivedFromThePackagesOwnPhpConstraint(): void
    {
        $this->assertSame("'8.2', '8.3', '8.4'", $this->scaffold()->phpMatrix());
    }

    public function testAnOlderFloorProducesMoreLegs(): void
    {
        $scaffold = $this->scaffold(['require' => ['php' => '>=8.0']]);

        $this->assertSame("'8.0', '8.1', '8.2', '8.3', '8.4'", $scaffold->phpMatrix());
    }

    public function testExtensionsComeFromTheDeclaredExtRequirements(): void
    {
        $this->assertSame('soap', $this->scaffold()->extensionList());
        $this->assertSame(
            'soap, curl',
            $this->scaffold(['require' => ['php' => '>=8.2', 'ext-soap' => '*', 'ext-curl' => '*']])->extensionList()
        );
    }

    public function testAPackageDeclaringNoExtensionsStillGetsSoap(): void
    {
        $this->assertSame('soap', $this->scaffold(['require' => ['php' => '>=8.2']])->extensionList());
    }

    public function testTheGeneratedWorkflowKeepsGithubsOwnExpressions(): void
    {
        $entry = $this->entryFor($this->scaffold()->plan($this->facts()), '.github/workflows/tests.yml');

        $this->assertStringContainsString('php-version: [\'8.2\', \'8.3\', \'8.4\']', $entry['contents']);
        $this->assertStringContainsString('extensions: soap', $entry['contents']);
        $this->assertStringContainsString('${{ matrix.php-version }}', $entry['contents']);
    }

    /**
     * A package that already boots from tests/bootstrap.php keeps doing so — 30 of the 66
     * converted packages do, and rerouting them would silently drop whatever that file
     * sets up.
     */
    public function testAnExistingTestBootstrapIsHonoured(): void
    {
        $scaffold = $this->scaffold();
        $this->assertSame('vendor/autoload.php', $scaffold->bootstrapPath());

        file_put_contents($this->root.'/tests/bootstrap.php', '<?php');
        $this->assertSame('tests/bootstrap.php', (new RepoScaffold($this->root))->bootstrapPath());
    }

    public function testACorrectInstallerRequirementNeedsNoAdvice(): void
    {
        $this->assertNull($this->scaffold()->installerRequirementAdvice());
    }

    /**
     * dev-master predates the harness release and resolves differently for every developer,
     * which is the whole reason the fleet moved to ^2.1.
     */
    public function testDevMasterIsCalledOut(): void
    {
        $scaffold = $this->scaffold([
            'require' => ['php' => '>=8.2', 'detain/myadmin-plugin-installer' => 'dev-master'],
        ]);

        $this->assertStringContainsString('predates the harness', (string)$scaffold->installerRequirementAdvice());
        $this->assertStringContainsString('composer require', (string)$scaffold->installerRequirementAdvice());
    }

    public function testAMissingInstallerRequirementIsCalledOut(): void
    {
        $scaffold = $this->scaffold(['require' => ['php' => '>=8.2']]);

        $this->assertStringContainsString('does not require', (string)$scaffold->installerRequirementAdvice());
    }

    /**
     * The 3 packages that carry the harness in require-dev rather than require are just as
     * correct: the base classes are only needed under test.
     */
    public function testRequireDevSatisfiesTheRequirementToo(): void
    {
        $scaffold = $this->scaffold([
            'require' => ['php' => '>=8.2'],
            'require-dev' => ['detain/myadmin-plugin-installer' => '^2.1'],
        ]);

        $this->assertNull($scaffold->installerRequirementAdvice());
    }

    public function testRefusesAPathThatIsNotAComposerPackage(): void
    {
        $this->expectException(\RuntimeException::class);

        new RepoScaffold($this->root.'/nope');
    }
}
