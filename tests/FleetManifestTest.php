<?php

namespace Tests\MyAdmin\Plugins;

use PHPUnit\Framework\TestCase;

/**
 * Guards the two data files the weekly fleet job runs on.
 *
 * ---------------------------------------------------------------------------------
 * WHY THESE NEED A TEST AND NOT JUST A REVIEW
 * ---------------------------------------------------------------------------------
 * Both files fail *quietly* when they are wrong, and both are read by a scheduled job that
 * nobody watches while it is green:
 *
 *   - A typo in `fleet-baseline.json` exempts nothing. The repo it was meant to name goes on
 *     failing and the job goes on reporting it — which is at least loud. But the reverse, a
 *     baseline entry naming a repo that is no longer in the fleet, is silent forever.
 *   - A repo missing from `fleet-repos.json` is simply never tested. Nothing reports it,
 *     because the job's own idea of "the fleet" is that file.
 *
 * @coversNothing
 */
class FleetManifestTest extends TestCase
{
    /**
     * @return array{repos: array<int, array{package: string, repo: string, branch: string}>}
     */
    private function manifest(): array
    {
        return json_decode((string)file_get_contents(dirname(__DIR__).'/tools/fleet-repos.json'), true);
    }

    /**
     * @return array{known_red: array<string, string>}
     */
    private function baseline(): array
    {
        return json_decode((string)file_get_contents(dirname(__DIR__).'/tools/fleet-baseline.json'), true);
    }

    public function testTheManifestParsesAndNamesTheWholeFleet(): void
    {
        $manifest = $this->manifest();

        $this->assertIsArray($manifest, 'tools/fleet-repos.json is not valid JSON');
        $this->assertArrayHasKey('repos', $manifest);
        $this->assertGreaterThanOrEqual(
            69,
            count($manifest['repos']),
            'the fleet does not shrink; a package dropped from this list is a package that stops being tested'
        );
    }

    /**
     * @depends testTheManifestParsesAndNamesTheWholeFleet
     */
    public function testEveryEntryCarriesWhatTheRunnerNeeds(): void
    {
        foreach ($this->manifest()['repos'] as $entry) {
            foreach (['package', 'repo', 'branch'] as $key) {
                $this->assertArrayHasKey($key, $entry);
                $this->assertNotSame('', $entry[$key], $key.' is empty in '.json_encode($entry));
            }
            $this->assertMatchesRegularExpression(
                '#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#',
                $entry['repo'],
                'repo must be owner/name, because the runner builds a clone URL from it'
            );
            $this->assertStringStartsWith('detain/', $entry['package']);
        }
    }

    /**
     * `powerdns` is on `adminlte`. A runner that assumed master everywhere would report it as
     * clone_failed every week, and the week it started genuinely failing would look the same.
     */
    public function testTheBranchIsRecordedPerRepoRatherThanAssumed(): void
    {
        $branches = [];
        foreach ($this->manifest()['repos'] as $entry) {
            $branches[$entry['repo']] = $entry['branch'];
        }

        $this->assertSame('adminlte', $branches['myadmin-plugins/powerdns'] ?? null);
    }

    public function testTheManifestHasNoDuplicates(): void
    {
        $repos = array_column($this->manifest()['repos'], 'repo');
        $packages = array_column($this->manifest()['repos'], 'package');

        $this->assertSame(array_unique($repos), $repos, 'a repo listed twice is cloned and tested twice');
        $this->assertSame(array_unique($packages), $packages);
    }

    /**
     * The one that rots. A baselined repo that leaves the fleet, or is misspelled, exempts
     * nothing and nobody finds out — the entry just sits there looking like it is doing work.
     */
    public function testEveryBaselinedRepoIsStillInTheFleet(): void
    {
        $known = [];
        foreach ($this->manifest()['repos'] as $entry) {
            $known[] = substr($entry['repo'], strrpos($entry['repo'], '/') + 1);
        }

        foreach (array_keys($this->baseline()['known_red']) as $repo) {
            $this->assertContains(
                $repo,
                $known,
                'tools/fleet-baseline.json exempts "'.$repo.'", which is not in the fleet — the entry does nothing'
            );
        }
    }

    /**
     * An exemption without a stated reason is indistinguishable from an exemption added to
     * make a build go green, which is the failure mode a baseline file invites.
     */
    public function testEveryExemptionSaysWhy(): void
    {
        foreach ($this->baseline()['known_red'] as $repo => $reason) {
            $this->assertGreaterThan(
                30,
                strlen((string)$reason),
                $repo.' is exempted without a usable reason'
            );
        }
    }

    /**
     * A baseline that has grown to most of the fleet is not a baseline, it is an opt-out. The
     * bound is deliberately generous and deliberately present.
     */
    public function testTheBaselineStaysASmallMinorityOfTheFleet(): void
    {
        $exempt = count($this->baseline()['known_red']);
        $total = count($this->manifest()['repos']);

        $this->assertLessThan(
            $total / 4,
            $exempt,
            'the fleet baseline now exempts '.$exempt.' of '.$total.' repos; at that point the job asserts very little'
        );
    }

    public function testTheRunnerIsExecutableAndReadsBothFiles(): void
    {
        $runner = dirname(__DIR__).'/tools/fleet-test.sh';

        $this->assertFileExists($runner);
        $this->assertTrue(is_executable($runner), 'the workflow invokes it directly');

        $source = (string)file_get_contents($runner);
        $this->assertStringContainsString('fleet-repos.json', $source);
        $this->assertStringContainsString('fleet-baseline.json', $source);
        $this->assertStringContainsString(
            '\\"symlink\\":false',
            $source,
            'without symlink:false every clone reads this same source tree and the run proves nothing'
        );
    }
}
