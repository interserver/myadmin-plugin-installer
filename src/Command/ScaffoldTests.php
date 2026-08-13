<?php
/**
 * Plugins Management
 * @author Joe Huss <detain@interserver.net>
 * @copyright 2026
 * @package MyAdmin
 * @category Plugins
 */

namespace MyAdmin\Plugins\Command;

use Composer\Command\BaseCommand;
use MyAdmin\Plugins\Testing\Scaffold\PluginFacts;
use MyAdmin\Plugins\Testing\Scaffold\RepoScaffold;
use MyAdmin\Plugins\Testing\Scaffold\SkillDoc;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `composer myadmin:scaffold-tests`
 *
 * Gives a plugin package everything it needs to run the shared contract harness: a
 * generated `tests/ContractTest.php` pinned to what the plugin actually registers, the
 * `plugin-contract-tests` skill that tells the next session how to read it, plus a PHPUnit
 * config and CI workflow if it has none.
 *
 * ---------------------------------------------------------------------------------
 * RUN THIS FROM INSIDE A PLUGIN REPO, NOT FROM MyAdmin CORE
 * ---------------------------------------------------------------------------------
 * MyAdmin core sets `config.allow-plugins: false`, so none of this package's `myadmin:*`
 * commands are registered there — Composer never activates the plugin that provides them.
 * From core, this command simply does not exist, and the first person to try it there will
 * conclude it is broken. In a plugin repo, where the installer is an allowed plugin, it is
 * available as soon as `composer install` has run.
 *
 * ---------------------------------------------------------------------------------
 * DRY RUN IS THE DEFAULT
 * ---------------------------------------------------------------------------------
 * This writes source files into someone's repository, so it prints the plan and stops
 * unless `--write` is given. `--dry-run` is accepted explicitly for symmetry with the other
 * myadmin:* commands, but it is what happens anyway.
 *
 * Even with `--write`, an existing file is never overwritten. The two wholly-generated files
 * — `tests/ContractTest.php` and the `plugin-contract-tests` skill — are the exception and
 * only under `--force`, because regeneration is how a package adopts a fix to the generator.
 */
class ScaffoldTests extends BaseCommand
{
    /**
     * Declares the command name, arguments, options and help.
     *
     * @return void
     */
    protected function configure(): void
    {
        $this
            ->setName('myadmin:scaffold-tests')
            ->setDescription('Generates the contract test harness scaffolding for a plugin package')
            ->setHelp(
                'Measures the plugin by executing it under the harness -- its $type, its $module and the'
                .' hook keys it really registers -- then generates tests/ContractTest.php pinned to those'
                .' facts, plus phpunit.xml.dist, a CI workflow, and the plugin-contract-tests skill if the'
                .' package has none.'
                .PHP_EOL.PHP_EOL
                .'Prints the plan and changes nothing unless --write is given. Existing files are never'
                .' overwritten; the wholly-generated files can be re-emitted with --force.'
                .PHP_EOL.PHP_EOL
                .'Run it from inside a plugin repository. MyAdmin core sets config.allow-plugins to false,'
                .' so the myadmin:* commands are not registered there at all.'
            )
            ->addArgument('path', InputArgument::OPTIONAL, 'Path to the plugin package (default: the current directory)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Print the plan without writing (the default)')
            ->addOption('write', null, InputOption::VALUE_NONE, 'Actually write the files the plan marks CREATE')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Also regenerate the wholly-generated files (tests/ContractTest.php, the skill) when they already exist');
    }

    /**
     * Measures the package, prints the plan, and writes it when asked.
     *
     * RETURNS: int — 0 planned or written · 1 the package could not be measured.
     *
     * @param \Symfony\Component\Console\Input\InputInterface   $input
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = $this->getIO();
        $root = rtrim((string)($input->getArgument('path') ?: getcwd()), '/');
        $write = (bool)$input->getOption('write');
        $force = (bool)$input->getOption('force');

        try {
            $scaffold = new RepoScaffold($root);
            $facts = PluginFacts::fromJson($this->measure($root, $io));
        } catch (\Exception $e) {
            $io->writeError('<error>'.$e->getMessage().'</error>');
            return 1;
        }

        $io->write(sprintf(
            '<info>%s</info> — type <comment>%s</comment>, module <comment>%s</comment>, %d hook%s',
            $facts->pluginClass(),
            (string)$facts->type(),
            $facts->module() === null ? '(none)' : (string)$facts->module(),
            count($facts->hookKeys()),
            count($facts->hookKeys()) === 1 ? '' : 's'
        ));

        $written = 0;
        foreach ($scaffold->plan($facts) as $entry) {
            $written += $this->report($entry, $root, $write, $force, $io) ? 1 : 0;
        }

        $advice = $scaffold->installerRequirementAdvice();
        if ($advice !== null) {
            $io->write('<comment>REQUIREMENT</comment> '.$advice);
        }

        if (!$write) {
            $io->write('');
            $io->write('Nothing was written. Re-run with <comment>--write</comment> to apply.');
        } elseif ($written === 0) {
            $io->write('');
            $io->write('Nothing to write — this package is already scaffolded.');
        }

        return 0;
    }

    /**
     * Prints one planned file, and writes it if it is a CREATE and writing is allowed.
     *
     * RETURNS: bool — whether anything was written.
     *
     * @param array                             $entry
     * @param string                            $root
     * @param bool                              $write
     * @param bool                              $force
     * @param \Composer\IO\IOInterface          $io
     * @return bool
     */
    private function report(array $entry, $root, $write, $force, $io)
    {
        // Both wholly-generated files may be re-emitted under --force. Everything else is a
        // package's own, and stays a package's own.
        $generated = ['tests/ContractTest.php', SkillDoc::SKILL_PATH];
        $regenerating = $force
            && $entry['action'] === RepoScaffold::KEEP
            && in_array($entry['path'], $generated, true)
            && $entry['contents'] !== null;

        $action = $regenerating ? 'REGENERATE' : strtoupper($entry['action']);
        $colour = ($entry['action'] === RepoScaffold::CREATE || $regenerating) ? 'info' : 'comment';
        $io->write(sprintf('  <%s>%-10s</%s> %s', $colour, $action, $colour, $entry['path']));

        foreach ($entry['notes'] as $note) {
            $io->write('             '.$note);
        }

        if (!$write || $entry['contents'] === null) {
            return false;
        }
        if ($entry['action'] !== RepoScaffold::CREATE && !$regenerating) {
            return false;
        }

        $target = $root.'/'.$entry['path'];
        $dir = dirname($target);
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            $io->writeError('<error>could not create '.$dir.'</error>');
            return false;
        }
        file_put_contents($target, $entry['contents']);
        $io->write('             written');

        return true;
    }

    /**
     * Runs the probe against the package in a process of its own.
     *
     * The probe must not share this process: priming defines constants and registers a
     * module, and neither can be undone. It also needs the *package's* autoloader, which
     * this process does not have.
     *
     * RETURNS: string — the probe's single line of JSON.
     * THROWS:  \RuntimeException carrying the probe's stderr, which is where the useful
     *          message lives (missing vendor/, no Plugin class, unparseable manifest).
     *
     * @param string                   $root
     * @param \Composer\IO\IOInterface $io
     * @return string
     */
    private function measure($root, $io)
    {
        $probe = dirname(__DIR__).'/Testing/Scaffold/probe.php';
        $pipes = [];
        // Array form, so there is no shell in the way and no quoting to get wrong.
        $process = proc_open(
            [PHP_BINARY, $probe, $root],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );

        if (!is_resource($process)) {
            throw new \RuntimeException('could not start the probe process');
        }
        $stdout = (string)stream_get_contents($pipes[1]);
        $stderr = (string)stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);

        if (trim($stderr) !== '') {
            // Deprecations from an old vendored installer land here and are worth seeing,
            // but they are not necessarily fatal — only the exit code decides that.
            foreach (explode("\n", trim($stderr)) as $line) {
                $io->writeError('<comment>probe:</comment> '.$line);
            }
        }
        if ($status !== 0) {
            throw new \RuntimeException('could not measure '.$root.' (probe exit '.$status.')');
        }

        return trim($stdout);
    }
}
