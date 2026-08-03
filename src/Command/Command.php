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
use MyAdmin\Plugins\VendorGuard;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `composer myadmin`
 *
 * Status overview of the MyAdmin installation as Composer sees it: how many plugin packages
 * are installed, whether the dispatch tables are in sync with them, and whether any
 * source-installed vendor package has uncommitted work.
 *
 * Read-only. Nothing here writes to disk.
 *
 * Previously this printed Symfony console demo boilerplate — "User Creator", `<info>foo</info>`,
 * and a string-truncation sample — under a description reading "Creates a new user."
 */
class Command extends BaseCommand
{
    /**
     * Declares the command name and description.
     *
     * The `: void` is load-bearing. symfony/console 8.0 added a native return type to
     * `Command::configure()`; an untyped override is a fatal signature mismatch there.
     * Declaring it here is compatible in both directions — a child may add a return type
     * the parent lacks — so this works against 7.x (untyped parent) and 8.x alike.
     *
     * @return void
     */
    protected function configure(): void
    {
        $this
            ->setName('myadmin')
            ->setDescription('Shows MyAdmin plugin and vendor status')
            ->setHelp(
                'Reports installed MyAdmin plugin packages, whether include/config/hooks.json and'
                .' plugins.json match what is on disk, and whether any source-installed vendor'
                .' package has uncommitted changes.'
                .PHP_EOL.PHP_EOL
                .'Read-only. Use myadmin:update-plugins to actually rebuild the dispatch tables.'
            );
    }

    /**
     * Prints the status report.
     *
     * INPUT:   $input  — no arguments or options.
     *          $output — unused; output goes through Composer's IO channel.
     * RETURNS: int — 0 always when Composer is available, 1 when it is not. Drift is
     *          reported, not treated as failure, so this is safe in a pipeline.
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
        $io->write('<info>MyAdmin status</info>');
        $io->write('  root: '.$root);

        if (is_dir($root.'/include/config')) {
            $result = PluginScanner::forProjectRoot($root)->rebuild(true);
            $io->write(sprintf(
                '  plugins: %d on disk, %d evaluated, %d retained',
                $result['present'],
                $result['scanned'],
                $result['retained']
            ));
            foreach (['hooks', 'plugins'] as $file) {
                $r = $result[$file];
                $drift = count($r['added']) + count($r['removed']);
                $io->write(sprintf(
                    '  %-12s %s',
                    $file.'.json:',
                    $drift === 0
                        ? '<info>in sync</info>'
                        : sprintf('<comment>%d entr%s out of sync (+%d / -%d)</comment>', $drift, $drift === 1 ? 'y' : 'ies', count($r['added']), count($r['removed']))
                ));
            }
            if ($result['skipped'] !== []) {
                $io->write(sprintf('  <comment>%d package(s) not evaluable outside a MyAdmin request</comment>', count($result['skipped'])));
            }
        } else {
            $io->write('  <comment>include/config not found; not a MyAdmin checkout</comment>');
        }

        $dirty = (new VendorGuard($root.'/vendor'))->findDirty();
        if ($dirty === []) {
            $io->write('  vendor:      <info>all working copies clean</info>');
        } else {
            $io->write(sprintf('  vendor:      <comment>%d package(s) with uncommitted changes</comment>', count($dirty)));
            foreach (VendorGuard::formatReport($dirty) as $line) {
                $io->write($line);
            }
        }
        return 0;
    }
}
