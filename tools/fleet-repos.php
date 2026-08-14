<?php
/**
 * Regenerates tools/fleet-repos.json from a MyAdmin core checkout.
 *
 * @author Joe Huss <detain@interserver.net>
 * @copyright 2026
 * @package MyAdmin
 * @category Testing
 *
 * ---------------------------------------------------------------------------------
 * WHY THE LIST IS COMMITTED RATHER THAN DISCOVERED
 * ---------------------------------------------------------------------------------
 * The fleet job runs on a bare GitHub runner that has no core checkout and therefore no
 * `vendor/detain/` to enumerate. It could ask the GitHub API for the org's repositories
 * instead, and then the set of packages under test would change whenever somebody created a
 * repo — silently, in a scheduled job nobody is watching. A committed manifest makes that
 * change a reviewable diff.
 *
 * Each entry records the branch as well, because it is not `master` everywhere: `powerdns`
 * lives on `adminlte`, and a job that assumed otherwise would report it as `clone_failed`
 * every week.
 *
 * ---------------------------------------------------------------------------------
 * USAGE
 * ---------------------------------------------------------------------------------
 *   php tools/fleet-repos.php            print the regenerated manifest
 *   php tools/fleet-repos.php --write    write it to tools/fleet-repos.json
 *   php tools/fleet-repos.php --check    exit 1 if the committed file is stale
 *
 * Run it from inside a core checkout; it reads the sibling packages in `vendor/detain/`.
 */

$root = dirname(__DIR__);
$vendor = dirname($root);
$target = $root.'/tools/fleet-repos.json';
$write = in_array('--write', $argv, true);
$check = in_array('--check', $argv, true);

/** This package is the subject, and contracts ships no plugin. */
$excluded = ['myadmin-plugin-installer', 'myadmin-contracts'];

$repos = [];
foreach (glob($vendor.'/myadmin-*', GLOB_ONLYDIR) as $dir) {
    $name = basename($dir);
    if (in_array($name, $excluded, true) || !is_dir($dir.'/.git')) {
        continue;
    }

    $url = trim((string)shell_exec('git -C '.escapeshellarg($dir).' remote get-url composer 2>/dev/null'));
    if ($url === '') {
        $url = trim((string)shell_exec('git -C '.escapeshellarg($dir).' remote get-url origin 2>/dev/null'));
    }
    if ($url === '') {
        fwrite(STDERR, $name.": no remote, skipped\n");
        continue;
    }

    $slug = preg_replace('#(^.*[:/])|(\.git$)#', '', $url);
    $slug = implode('/', array_slice(explode('/', rtrim(preg_replace('#\.git$#', '', $url), '/')), -2));
    $slug = str_replace('git@github.com:', '', $slug);

    $manifest = json_decode((string)file_get_contents($dir.'/composer.json'), true);
    $repos[] = [
        'package' => isset($manifest['name']) ? $manifest['name'] : $name,
        'repo' => $slug,
        'branch' => defaultBranch($dir),
    ];
}

usort($repos, function ($a, $b) {
    return strcmp($a['package'], $b['package']);
});

$json = json_encode([
    '_comment' => [
        'The plugin fleet this harness is published to. Consumed by tools/fleet-test.sh and by the weekly fleet workflow.',
        'Regenerate from a MyAdmin core checkout with: php tools/fleet-repos.php --write',
        'myadmin-plugin-installer and myadmin-contracts are excluded: the first is this package, the second ships no plugin.',
    ],
    'repos' => $repos,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";

if ($check) {
    $current = is_file($target) ? (string)file_get_contents($target) : '';
    if ($current === $json) {
        echo "fleet-repos.json is current (".count($repos)." repos)\n";
        exit(0);
    }
    fwrite(STDERR, "fleet-repos.json is stale; run: php tools/fleet-repos.php --write\n");
    exit(1);
}

if ($write) {
    file_put_contents($target, $json);
    echo 'wrote '.$target.' ('.count($repos)." repos)\n";
    exit(0);
}

echo $json;

/**
 * The branch the fleet job should clone, which is the repo's DEFAULT branch.
 *
 * Deliberately not `rev-parse --abbrev-ref HEAD`. These are working checkouts: one sitting on
 * a fix branch during a review would silently rewrite the manifest to test that branch
 * forever, and the weekly job would report on work-in-progress as though it were master. The
 * remote's own HEAD is the only answer that does not depend on what someone was doing here.
 *
 * @param string $dir
 * @return string
 */
function defaultBranch($dir)
{
    foreach (['composer', 'origin'] as $remote) {
        $ref = trim((string)shell_exec(
            'git -C '.escapeshellarg($dir).' symbolic-ref --short refs/remotes/'.$remote.'/HEAD 2>/dev/null'
        ));
        if ($ref !== '') {
            return substr($ref, strlen($remote) + 1);
        }
        // No local HEAD ref for that remote yet -- ask it.
        $shown = (string)shell_exec(
            'git -C '.escapeshellarg($dir).' remote show '.escapeshellarg($remote).' 2>/dev/null'
        );
        if (preg_match('/HEAD branch:\s*(\S+)/', $shown, $found) && $found[1] !== '(unknown)') {
            return $found[1];
        }
    }

    return trim((string)shell_exec('git -C '.escapeshellarg($dir).' rev-parse --abbrev-ref HEAD'));
}
