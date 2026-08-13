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
 * Plans the files a plugin package needs in order to run the contract harness.
 *
 * ---------------------------------------------------------------------------------
 * THIS PLANS; IT DOES NOT WRITE
 * ---------------------------------------------------------------------------------
 * Every method here is a pure function of the package's composer.json, what is already on
 * disk, and the measured {@see PluginFacts}. Writing is the command's job, and the command
 * only writes files this planner marked CREATE.
 *
 * ---------------------------------------------------------------------------------
 * NOTHING EXISTING IS EVER OVERWRITTEN
 * ---------------------------------------------------------------------------------
 * A file that already exists is reported as KEEP, never rewritten, even when it differs
 * from the canonical template. Two reasons:
 *
 *   - Across the 66 converted packages there are 55 distinct phpunit.xml.dist files and 63
 *     distinct workflows. Most of that variation is legitimate — a package requiring
 *     ext-imap needs a different extension list — and a scaffolder that flattened it would
 *     be silently deleting per-package knowledge nobody wrote down anywhere else.
 *   - The one thing worth enforcing is the handful of settings the harness genuinely
 *     depends on, and those are reported as DRIFT for a human to act on rather than fixed
 *     in place.
 *
 * The exception the caller may allow is `tests/ContractTest.php` under --force, because
 * that file is wholly generated and regenerating it is how a package picks up a fix to the
 * generator. Even then the command writes it only when explicitly asked.
 */
class RepoScaffold
{
    /** File does not exist and would be created. */
    const CREATE = 'create';

    /** File exists and is left exactly as it is. */
    const KEEP = 'keep';

    /** File exists but is missing something the harness depends on. */
    const DRIFT = 'drift';

    /**
     * PHP versions this fleet's CI legs are drawn from, oldest first.
     *
     * @var string[]
     */
    const PHP_LADDER = ['7.4', '8.0', '8.1', '8.2', '8.3', '8.4'];

    /**
     * phpunit.xml.dist settings the harness depends on rather than merely prefers.
     *
     * Each is checked for presence in an existing config, and its absence is reported as
     * drift with the reason attached — see {@see auditPhpunitConfig()}.
     *
     * @var array<string, string>
     */
    const REQUIRED_PHPUNIT_SETTINGS = [
        'failOnWarning' => 'several contract findings surface first as a PHP warning; without this PHPUnit prints them and still exits 0',
        'failOnRisky' => 'a test that asserts nothing because its subject could not be loaded is risky, not passing',
        'beStrictAboutOutputDuringTests' => 'assertion B-15 (a plugin must not echo while its handlers run) is unenforceable without it',
    ];

    /** @var string absolute path to the package root, without a trailing slash */
    private $root;

    /** @var array the package's decoded composer.json */
    private $manifest;

    /**
     * @param string $root
     * @throws \RuntimeException when the path is not a composer package
     */
    public function __construct($root)
    {
        $this->root = rtrim((string)$root, '/');
        $manifestPath = $this->root.'/composer.json';
        if (!is_file($manifestPath)) {
            throw new \RuntimeException('no composer.json at '.$this->root);
        }
        $decoded = json_decode((string)file_get_contents($manifestPath), true);
        if (!is_array($decoded)) {
            throw new \RuntimeException($manifestPath.' is not valid JSON');
        }
        $this->manifest = $decoded;
    }

    /**
     * Builds the full plan.
     *
     * RETURNS: array<int, array{path: string, action: string, contents: ?string, notes: string[]}>
     *          — `contents` is null for anything that will not be written.
     *
     * @param \MyAdmin\Plugins\Testing\Scaffold\PluginFacts $facts
     * @return array
     */
    public function plan(PluginFacts $facts)
    {
        return [
            $this->planContractTest($facts),
            $this->planPhpunitConfig(),
            $this->planWorkflow(),
        ];
    }

    /**
     * @param \MyAdmin\Plugins\Testing\Scaffold\PluginFacts $facts
     * @return array
     */
    private function planContractTest(PluginFacts $facts)
    {
        $generator = new ContractTestGenerator();
        $contents = $generator->render($facts);
        $path = 'tests/ContractTest.php';
        $notes = [];

        if ($facts->hookError() !== null) {
            $notes[] = 'getHooks() threw while being measured, so the hook table is pinned as empty: '
                .$facts->hookError().' — assertion A-5 will report the root cause when the suite runs.';
        }
        if ($facts->isServicePlugin()) {
            $notes[] = 'type=service, so this extends ServicePluginTestCase and the activate/deactivate/'
                .'change-ip/queue assertions apply as well.';
        }

        if (is_file($this->root.'/'.$path)) {
            $current = (string)file_get_contents($this->root.'/'.$path);
            if ($current === $contents) {
                $notes[] = 'identical to what this generator produces.';
            } else {
                $notes[] = 'differs from what this generator produces — regenerate with --force to adopt'
                    .' the current template, or keep it if this package hand-edited the pin deliberately.';
            }
            return $this->entry($path, self::KEEP, $contents, $notes);
        }

        return $this->entry($path, self::CREATE, $contents, $notes);
    }

    /**
     * @return array
     */
    private function planPhpunitConfig()
    {
        $path = 'phpunit.xml.dist';
        $contents = $this->renderTemplate('phpunit.xml.dist', ['{{bootstrap}}' => $this->bootstrapPath()]);

        if (!is_file($this->root.'/'.$path)) {
            return $this->entry($path, self::CREATE, $contents, []);
        }

        $drift = $this->auditPhpunitConfig((string)file_get_contents($this->root.'/'.$path));

        return $drift === []
            ? $this->entry($path, self::KEEP, null, [])
            : $this->entry($path, self::DRIFT, null, $drift);
    }

    /**
     * @return array
     */
    private function planWorkflow()
    {
        $path = '.github/workflows/tests.yml';
        if (is_file($this->root.'/'.$path)) {
            return $this->entry($path, self::KEEP, null, []);
        }
        $contents = $this->renderTemplate('tests.yml', [
            '{{php_versions}}' => $this->phpMatrix(),
            '{{extensions}}' => $this->extensionList(),
        ]);

        return $this->entry($path, self::CREATE, $contents, []);
    }

    /**
     * Checks an existing phpunit config for the settings the harness depends on.
     *
     * Deliberately a substring check on the raw XML rather than a parse: it must not care
     * about attribute order, quoting style or which of the several valid schema shapes the
     * package uses, and a package with an unparseable config has a louder problem than
     * drift.
     *
     * @param string $xml
     * @return string[] one message per missing or disabled setting
     */
    public function auditPhpunitConfig($xml)
    {
        $notes = [];
        foreach (self::REQUIRED_PHPUNIT_SETTINGS as $setting => $why) {
            if (strpos($xml, $setting.'="true"') === false) {
                $notes[] = $setting.'="true" is not set — '.$why;
            }
        }
        return $notes;
    }

    /**
     * The bootstrap an existing package already uses, or the default for a new one.
     *
     * 30 of the converted packages boot from `tests/bootstrap.php` and 36 from
     * `vendor/autoload.php`; both work, because the harness needs nothing beyond an
     * autoloader. A package that already has a bootstrap keeps it.
     *
     * @return string
     */
    public function bootstrapPath()
    {
        return is_file($this->root.'/tests/bootstrap.php') ? 'tests/bootstrap.php' : 'vendor/autoload.php';
    }

    /**
     * The CI matrix this package's own `php` constraint supports.
     *
     * Reads the lower bound and takes every ladder entry at or above it. A package
     * declaring `>=8.2` gets 8.2/8.3/8.4 — which is exactly what the kvm-vps pilot has by
     * hand, so the derivation is checked against reality rather than invented.
     *
     * @return string quoted, comma-separated, ready for the YAML sequence
     */
    public function phpMatrix()
    {
        $constraint = isset($this->manifest['require']['php']) ? (string)$this->manifest['require']['php'] : '';
        $floor = '8.2';
        if (preg_match('/(\d+\.\d+)/', $constraint, $found)) {
            $floor = $found[1];
        }
        $versions = [];
        foreach (self::PHP_LADDER as $version) {
            if (version_compare($version, $floor, '>=')) {
                $versions[] = "'".$version."'";
            }
        }
        if ($versions === []) {
            $versions = ["'8.2'", "'8.3'", "'8.4'"];
        }
        return implode(', ', $versions);
    }

    /**
     * The PHP extensions this package declares it needs.
     *
     * Derived from `ext-*` in require/require-dev. `soap` is the fleet default because the
     * harness's own fakes exercise a SOAP-shaped service class, and a leg without it fails
     * during autoload rather than in an assertion, which reads like a package defect.
     *
     * @return string comma-separated, in the order setup-php wants them
     */
    public function extensionList()
    {
        $extensions = [];
        foreach (['require', 'require-dev'] as $section) {
            foreach (array_keys(isset($this->manifest[$section]) ? (array)$this->manifest[$section] : []) as $package) {
                if (strpos((string)$package, 'ext-') === 0) {
                    $extensions[] = substr((string)$package, 4);
                }
            }
        }
        if ($extensions === []) {
            $extensions[] = 'soap';
        }
        return implode(', ', array_unique($extensions));
    }

    /**
     * Whether this package already requires the installer at a constraint that carries the
     * harness, and the exact command to fix it if not.
     *
     * The harness ships inside this package, so a plugin cannot extend PluginContractTestCase
     * without it. `^2.1` is the first constraint that has it; `dev-master` predates the
     * release and resolves differently for every developer.
     *
     * @return string|null null when the requirement is already correct
     */
    public function installerRequirementAdvice()
    {
        $self = 'detain/myadmin-plugin-installer';
        foreach (['require', 'require-dev'] as $section) {
            $constraint = isset($this->manifest[$section][$self]) ? (string)$this->manifest[$section][$self] : null;
            if ($constraint === null) {
                continue;
            }
            if (strpos($constraint, '2.1') !== false || strpos($constraint, '^2.') === 0) {
                return null;
            }
            return 'this package requires '.$self.':'.$constraint.', which predates the harness. Run:'
                ."\n    composer require ".$self.':^2.1';
        }

        return 'this package does not require '.$self.' at all, so the harness base classes will not'
            ." autoload. Run:\n    composer require ".$self.':^2.1';
    }

    /**
     * Reads a template and substitutes its placeholders.
     *
     * Substitution is by exact token, never by pattern, because the workflow template
     * legitimately contains `${{ matrix.php-version }}` and a regex over `{{...}}` would
     * eat it.
     *
     * @param string                $name
     * @param array<string, string> $replacements
     * @return string
     */
    private function renderTemplate($name, array $replacements)
    {
        $path = __DIR__.'/../templates/'.$name;
        if (!is_file($path)) {
            throw new \RuntimeException('missing scaffold template: '.$path);
        }
        return str_replace(array_keys($replacements), array_values($replacements), (string)file_get_contents($path));
    }

    /**
     * @param string      $path
     * @param string      $action
     * @param string|null $contents
     * @param string[]    $notes
     * @return array
     */
    private function entry($path, $action, $contents, array $notes)
    {
        return ['path' => $path, 'action' => $action, 'contents' => $contents, 'notes' => $notes];
    }
}
