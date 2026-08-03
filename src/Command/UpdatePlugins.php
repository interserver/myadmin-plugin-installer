<?php
/**
 * Plugins Management
 * @author Joe Huss <detain@interserver.net>
 * @copyright 2025
 * @package MyAdmin
 * @category Plugins
 */

namespace MyAdmin\Plugins\Command;

use Composer\Command\BaseCommand;
use MyAdmin\Plugins\PluginScanner;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `composer myadmin:update-plugins`
 *
 * Rebuilds include/config/hooks.json and include/config/plugins.json from the plugin
 * packages actually installed in vendor/.
 *
 * This is the CLI counterpart to the automatic rebuild on post-autoload-dump. Before this
 * existed the ONLY way to refresh those dispatch tables was for an administrator to load the
 * admin Plugins page in a browser — there was no command-line path at all, and the previous
 * body of this command was Symfony demo boilerplate that printed "User Creator" and exited 0
 * without touching anything.
 *
 * @see \MyAdmin\Plugins\PluginScanner
 */
class UpdatePlugins extends BaseCommand
{
    /**
     * Declares the command name, description and options.
     *
     * @return void
     */
    protected function configure(): void
    {
        $this
            ->setName('myadmin:update-plugins')
            ->setDescription('Finds and Caches Plugins into MyAdmin')
            ->setHelp(
                'Scans vendor/ for packages shipping a src/Plugin.php with a getHooks() method and'
                .' rebuilds include/config/hooks.json and include/config/plugins.json from them.'
                .PHP_EOL.PHP_EOL
                .'Entries are pruned only when the package is genuinely gone from disk. A package that'
                .' is installed but cannot be evaluated here — several reference MyAdmin constants that'
                .' only exist once config.inc.php is loaded — keeps its existing entry rather than'
                .' being dropped.'
            )
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report what would change without writing')
            ->addOption('show-skipped', null, InputOption::VALUE_NONE, 'List packages that could not be evaluated, and why');
    }

    /**
     * Runs the rebuild.
     *
     * INPUT:   $input  — supports --dry-run and --show-skipped.
     *          $output — unused; output goes through Composer's IO channel.
     * RETURNS: int — 0 on success, 1 when Composer is unavailable, the project is not a
     *          MyAdmin checkout, no plugins were found, or a write was refused.
     *
     * @param \Symfony\Component\Console\Input\InputInterface   $input
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $composer = $this->tryComposer();
        $io = $this->getIO();
        if ($composer === null) {
            $io->writeError('<error>No composer.json found; run this from a project directory.</error>');
            return 1;
        }
        $root = dirname(rtrim((string)$composer->getConfig()->get('vendor-dir'), '/'));
        if (!is_dir($root.'/include/config')) {
            $io->writeError(sprintf('<error>%s does not look like a MyAdmin checkout (no include/config).</error>', $root));
            return 1;
        }
        $dryRun = (bool)$input->getOption('dry-run');
        $scanner = PluginScanner::forProjectRoot($root);
        $result = $scanner->rebuild($dryRun);

        $io->write(sprintf(
            '<info>%d plugin package(s) on disk; %d evaluated, %d retained from the existing config.</info>',
            $result['present'],
            $result['scanned'],
            $result['retained']
        ));
        if ($result['scanned'] === 0) {
            $io->writeError('<error>No plugins could be evaluated; refusing to write.</error>');
            return 1;
        }
        $exit = 0;
        foreach (['hooks', 'plugins'] as $file) {
            $r = $result[$file];
            $io->write(sprintf('<comment>%s.json</comment>: +%d / -%d', $file, count($r['added']), count($r['removed'])));
            foreach ($r['added'] as $name) {
                $io->write('  <info>+</info> '.$name);
            }
            foreach ($r['removed'] as $name) {
                $io->write('  <comment>-</comment> '.$name);
            }
            if (!$dryRun && $r['written'] !== true && ($r['added'] !== [] || $r['removed'] !== [])) {
                $io->writeError(sprintf('<error>Failed to write %s.json; it was left unchanged.</error>', $file));
                $exit = 1;
            }
        }
        if ($input->getOption('show-skipped')) {
            foreach ($result['skipped'] as $package => $reason) {
                $io->write(sprintf('  <comment>skipped</comment> %s: %s', $package, $reason));
            }
        } elseif ($result['skipped'] !== []) {
            $io->write(sprintf('<comment>%d package(s) could not be evaluated; re-run with --show-skipped for details.</comment>', count($result['skipped'])));
        }
        if ($dryRun) {
            $io->write('<comment>Dry run — nothing was written.</comment>');
        }
        return $exit;
    }
}
