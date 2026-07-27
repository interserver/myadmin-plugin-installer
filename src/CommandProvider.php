<?php
/**
 * Plugins Management
 * @author Joe Huss <detain@interserver.net>
 * @copyright 2025
 * @package MyAdmin
 * @category Plugins
 */

namespace MyAdmin\Plugins;

use Composer\Plugin\Capability\CommandProvider as CommandProviderCapability;
use MyAdmin\Plugins\Command\Command;
use MyAdmin\Plugins\Command\UpdatePlugins;
use MyAdmin\Plugins\Command\SetPermissions;

/**
 * Supplies this package's `composer myadmin:*` commands.
 *
 * Wired up by Plugin::getCapabilities(). CommandProvider is the ONLY capability interface
 * Composer 2.10 defines — the `Validator` capability shown in Composer's own Capable
 * docblock does not exist.
 *
 * ---------------------------------------------------------------------------------
 * CONSTRUCTION CONTRACT
 * ---------------------------------------------------------------------------------
 * Composer instantiates a capability class with exactly ONE argument: an array. For
 * CommandProvider that array is:
 *
 *     [
 *         'composer' => \Composer\Composer,
 *         'io'       => \Composer\IO\IOInterface,
 *         'plugin'   => \Composer\Plugin\PluginInterface,
 *     ]
 *
 * A constructor that omits it still works — PHP ignores extra arguments to userland
 * functions — but then the command classes have no route to Composer except
 * BaseCommand::requireComposer(). Accepting and retaining it is the documented pattern.
 *
 * ---------------------------------------------------------------------------------
 * WHEN THESE COMMANDS APPEAR
 * ---------------------------------------------------------------------------------
 * Only when the package is allowed by config.allow-plugins. MyAdmin currently sets it to
 * false, so `composer myadmin:*` does not exist there. Composer collects commands lazily via
 * Application::getPluginCommands(), iterating only activated plugins.
 *
 * Composer validates the return value: a non-array throws UnexpectedValueException, and so
 * does any element that is not a Composer\Command\BaseCommand.
 *
 * @see \Composer\Plugin\Capability\CommandProvider
 * @see \Composer\Plugin\Capability\Capability
 */
class CommandProvider implements CommandProviderCapability
{
    /**
     * The Composer instance Composer handed us at construction, if any.
     *
     * @var \Composer\Composer|null
     */
    protected $composer;

    /**
     * The IO channel Composer handed us at construction, if any.
     *
     * @var \Composer\IO\IOInterface|null
     */
    protected $io;

    /**
     * The plugin instance that declared this capability.
     *
     * @var \Composer\Plugin\PluginInterface|null
     */
    protected $plugin;

    /**
     * @param array $args capability constructor payload; keys 'composer', 'io' and 'plugin'.
     *                    Defaulted so the class stays directly constructible in tests.
     */
    public function __construct(array $args = [])
    {
        $this->composer = isset($args['composer']) ? $args['composer'] : null;
        $this->io = isset($args['io']) ? $args['io'] : null;
        $this->plugin = isset($args['plugin']) ? $args['plugin'] : null;
    }

    /**
     * The commands this package contributes to the `composer` CLI.
     *
     * RETURNS: \Composer\Command\BaseCommand[] — every element must be a BaseCommand or
     * Composer throws UnexpectedValueException.
     *
     *   myadmin                  status overview: plugin counts, dispatch-table drift, dirty
     *                            vendor working copies. Read-only.
     *   myadmin:update-plugins   rebuilds include/config/hooks.json and plugins.json from
     *                            vendor/. Supports --dry-run and --show-skipped.
     *   myadmin:set-permissions  applies extra.writable-dirs / writable-files. Supports
     *                            --dry-run.
     *
     * Two commands were removed rather than repaired:
     *   myadmin:parse       required phpdocumentor/reflection, which was never a declared
     *                       dependency, and additionally declared a function inside a method
     *                       body and passed directories to a file reader. It could not ever
     *                       have run.
     *   myadmin:create-user was Symfony demo scaffolding. Creating a MyAdmin user needs the
     *                       full application bootstrap and a database connection, neither of
     *                       which exists in a Composer process.
     *
     * @return \Composer\Command\BaseCommand[]
     */
    public function getCommands()
    {
        return [
            new Command(),
            new UpdatePlugins(),
            new SetPermissions()
        ];
    }
}
