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
use Composer\Script\Event;
use MyAdmin\Plugins\Plugin;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `composer myadmin:set-permissions`
 *
 * Creates and permissions every path listed in the root package's `extra.writable-dirs` and
 * `extra.writable-files`.
 *
 * The same work runs automatically on post-install-cmd/post-update-cmd through MyAdmin's
 * `scripts` block. This command exists so it can be re-run on demand without a full install.
 *
 * PREVIOUSLY BROKEN: execute() called Plugin::setPermissions() with no arguments against a
 * signature requiring a Composer\Script\Event, so it was a guaranteed ArgumentCountError.
 * It went unnoticed because the whole command surface is unreachable while the package is
 * blocked by config.allow-plugins. Fixed by constructing the Event the API expects.
 */
class SetPermissions extends BaseCommand
{
    /**
     * Declares the command name, description and options.
     *
     * @return void
     */
    protected function configure(): void
    {
        $this
            ->setName('myadmin:set-permissions')
            ->setDescription('Creates and Sets Writable Permissions on Required Dirs')
            ->setHelp(
                'Creates and sets writable permissions on the directories and files listed in the'
                .' writable-dirs and writable-files entries of the root composer.json "extra" section.'
                .PHP_EOL.PHP_EOL
                .'Runs automatically on composer install/update; use this to re-run it on its own.'
            )
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'List the paths that would be changed without changing them');
    }

    /**
     * Runs the permission pass.
     *
     * INPUT:   $input  — supports --dry-run.
     *          $output — unused; progress goes through Composer's IO channel so it honours
     *                    -q/-v/--no-ansi.
     * RETURNS: int — 0 on success, 1 when Composer is unavailable.
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
        $event = new Event('myadmin:set-permissions', $composer, $io);
        if ($input->getOption('dry-run')) {
            foreach (Plugin::getWritableDirs($event) as $path) {
                $io->write(sprintf('dir  <info>%s</info>%s', $path, is_dir($path) ? '' : ' (would be created)'));
            }
            foreach (Plugin::getWritableFiles($event) as $path) {
                $io->write(sprintf('file <info>%s</info>%s', $path, file_exists($path) ? '' : ' (would be created)'));
            }
            return 0;
        }
        Plugin::setPermissions($event);
        return 0;
    }
}
