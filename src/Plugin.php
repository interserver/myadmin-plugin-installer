<?php
/**
 * Plugins Management
 * @author Joe Huss <detain@interserver.net>
 * @copyright 2019
 * @package MyAdmin
 * @category Plugins
 */

/**
 * MyAdmin Installer Plugin
 * @link https://github.com/composer/composer/blob/master/src/Composer/Plugin/PluginInterface.php
 */

namespace MyAdmin\Plugins;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;
use Composer\Plugin\Capable;
use Composer\Plugin\PluginEvents;
use Composer\Plugin\PreFileDownloadEvent;
use Composer\Script\Event;

/**
 * MyAdmin Installer Plugin
 */
class Plugin implements PluginInterface, EventSubscriberInterface, Capable
{
    protected $composer;
    protected $io;

    /**
     * Apply plugin modifications to Composer
     *
     * @param Composer	$composer
     * @param IOInterface $io
     */
    public function activate(Composer $composer, IOInterface $io)
    {
        $this->composer = $composer;
        $this->io = $io;
        //print 'Hello peoples...';
        $installer = new Installer($this->io, $this->composer);
        $this->composer->getInstallationManager()->addInstaller($installer);
    }

    public function deactivate(Composer $composer, IOInterface $io)
    {
    }

    public function uninstall(Composer $composer, IOInterface $io)
    {
    }

    /**
     * @return array
     */
    public function getCapabilities()
    {
        return [
            'Composer\Plugin\Capability\CommandProvider' => 'MyAdmin\Plugins\CommandProvider'
        ];
    }

    /**
     *
     *	Events
     *
     *		Command Events					Composer\Script\Event
     *
     * 			pre-install-cmd				occurs before the install command is executed with a lock file present.
     * 			post-install-cmd			occurs after the install command has been executed with a lock file present.
     * 			pre-update-cmd				occurs before the update command is executed, or before the install command is executed without a lock file present.
     * 			post-update-cmd				occurs after the update command has been executed, or after the install command has been executed without a lock file present.
     * 			post-status-cmd				occurs after the status command has been executed.
     * 			pre-archive-cmd				occurs before the archive command is executed.
     * 			post-archive-cmd			occurs after the archive command has been executed.
     * 			pre-autoload-dump			occurs before the autoloader is dumped, either during install/update, or via the dump-autoload command.
     * 			post-autoload-dump			occurs after the autoloader has been dumped, either during install/update, or via the dump-autoload command.
     * 			post-root-package-install	occurs after the root package has been installed, during the create-project command.
     *			post-create-project-cmd		occurs after the create-project command has been executed.
     *
     *		Installer Events				Composer\Installer\InstallerEvent
     *
     * 			pre-dependencies-solving	occurs before the dependencies are resolved.
     * 			post-dependencies-solving	occurs after the dependencies have been resolved.
     *
     *		Package Events					Composer\Installer\PackageEvent
     *
     * 			pre-package-install			occurs before a package is installed.
     * 			post-package-install		occurs after a package has been installed.
     * 			pre-package-update			occurs before a package is updated.
     * 			post-package-update			occurs after a package has been updated.
     * 			pre-package-uninstall		occurs before a package is uninstalled.
     * 			post-package-uninstall		occurs after a package has been uninstalled.
     *
     * 		Plugin Events					Composer\Plugin\PluginEvents
     *
     * 			init						occurs after a Composer instance is done being initialized.
     * 			command						occurs before any Composer Command is executed on the CLI. It provides you with access to the input and output objects of the program.
     * 			pre-file-download			occurs before files are downloaded and allows you to manipulate the RemoteFilesystem object prior to downloading files based on the URL to be downloaded.
     */
    public static function getSubscribedEvents()
    {
        return [
/*			PluginEvents::PRE_FILE_DOWNLOAD => [
                ['onPreFileDownload', 0]
            ]*/
        ];
    }

    /**
    * @param PreFileDownloadEvent $event
    */
    public function onPreFileDownload(PreFileDownloadEvent $event)
    {
        /*$protocol = parse_url($event->getProcessedUrl(), PHP_URL_SCHEME);
        if ($protocol === 's3') {
            $awsClient = new AwsClient($this->io, $this->composer->getConfig());
            $s3RemoteFilesystem = new S3RemoteFilesystem($this->io, $event->getRemoteFilesystem()->getOptions(), $awsClient);
            $event->setRemoteFilesystem($s3RemoteFilesystem);
        }*/
    }

    /**
     * An event that triggers setting writable permissions on any directories specified in the writable-dirs composer extra options
     *
     * @param Event $event
     * @return void
     */
    public static function setPermissions(Event $event)
    {
        if ('WIN' === strtoupper(substr(PHP_OS, 0, 3))) {
            $event->getIO()->write('<info>No permissions setup is required on Windows.</info>');
            return;
        }
        $event->getIO()->write('Setting up permissions.');
        /*		try {
                    self::setPermissionsSetfacl($event);
                    return;
                } catch (\Exception $setfaclException) {
                    $event->getIO()->write(sprintf('<error>%s</error>', $setfaclException->getMessage()));
                    $event->getIO()->write('<info>Trying chmod...</info>');
                }*/
        try {
            self::setPermissionsChmod($event);
            return;
        } catch (\Exception $chmodException) {
            $event->getIO()->write(sprintf('<error>%s</error>', $chmodException->getMessage()));
        }
    }

    /**
     * returns a list of writeable directories specified in the writeable-dirs composer extra options
     *
     * @param Event $event
     * @return array an array of directory paths
     */
    public static function getWritableDirs(Event $event)
    {
        $configuration = $event->getComposer()->getPackage()->getExtra();
        if (!isset($configuration['writable-dirs'])) {
            throw new \Exception('The writable-dirs must be specified in composer arbitrary extra data.');
        }
        if (!is_array($configuration['writable-dirs'])) {
            throw new \Exception('The writable-dirs must be an array.');
        }
        return $configuration['writable-dirs'];
    }

    /**
     * returns a list of writeable files specified in the writeable-files composer extra options
     *
     * @param Event $event
     * @return array an array of file paths
     */
    public static function getWritableFiles(Event $event)
    {
        $configuration = $event->getComposer()->getPackage()->getExtra();
        if (!isset($configuration['writable-files'])) {
            throw new \Exception('The writable-files must be specified in composer arbitrary extra data.');
        }
        if (!is_array($configuration['writable-files'])) {
            throw new \Exception('The writable-files must be an array.');
        }
        return $configuration['writable-files'];
    }

    /**
     * Sets Writrable Directory permissions for any directories listed in the writeable-dirs option using setfacl
     *
     * @param Event $event
     */
    public static function setPermissionsSetfacl(Event $event)
    {
        $http_user = self::getHttpdUser($event);
        foreach (self::getWritableDirs($event) as $path) {
            self::SetfaclPermissionsSetter($event, $http_user, $path);
        }
        foreach (self::getWritableFiles($event) as $path) {
            self::ChmodPermissionsSetter($event, $http_user, $path, 'file');
        }
    }

    /**
     * Sets Writrable Directory permissions for any directories listed in the writeable-dirs option using chmod
     *
     * @param Event $event
     */
    public static function setPermissionsChmod(Event $event)
    {
        $http_user = self::getHttpdUser($event);
        foreach (self::getWritableDirs($event) as $path) {
            self::ChmodPermissionsSetter($event, $http_user, $path, 'dir');
        }
        foreach (self::getWritableFiles($event) as $path) {
            self::ChmodPermissionsSetter($event, $http_user, $path, 'file');
        }
    }

    /**
     * returns the user the webserver is running as
     *
     * @param Event $event
     * @return string the webserver username
     */
    public static function getHttpdUser(Event $event)
    {
        $ps = self::runProcess($event, 'ps aux');
        preg_match_all('/^.*([a]pache|[h]ttpd|[_]www|[w]ww-data|[n]ginx)$/m', $ps, $matches);
        foreach ($matches[0] as $match) {
            $user = substr($match, 0, strpos($match, ' '));
            if ($user != 'root') {
                return $user;
            }
        }
    }

    /**
     * sets the needed permissions for the $http_user and the running user on $path using setfacl
     *
     * @param Event $event
     * @param string $http_user the webserver username
     * @param string $path the directory to set permissions on
     */
    public static function SetfaclPermissionsSetter(Event $event, $http_user, $path)
    {
        self::EnsureDirExists($event, $path);
        self::runProcess($event, 'setfacl -m u:"'.$http_user.'":rwX -m u:'.$_SERVER['USER'].':rwX '.$path);
        self::runProcess($event, 'setfacl -d -m u:"'.$http_user.'":rwX -m u:'.$_SERVER['USER'].':rwX '.$path);
    }

    /**
     * sets the needed permissions for the $http_user and the running user on $path using chmod
     *
     * @param Event $event
     * @param string $http_user the webserver username
     * @param string $path the directory to set permissions on
     * @param string $type optional type of entry, defaults to dir, can be dir or file
     */
    public static function ChmodPermissionsSetter(Event $event, $http_user, $path, $type = 'dir')
    {
        if ($type == 'dir') {
            self::EnsureDirExists($event, $path);
        //			self::runProcess($event, 'chmod +a "'.$http_user.' allow delete,write,append,file_inherit,directory_inherit" '.$path);
//			self::runProcess($event, 'chmod +a "'.$_SERVER['USER'].' allow delete,write,append,file_inherit,directory_inherit" '.$path);
        } else {
            self::EnsureFileExists($event, $path);
        }
        self::runProcess($event, 'chmod 777 '.$path);
        self::runProcess($event, 'chown '.$_SERVER['USER'].':'.$http_user.' '.$path);
    }

    /**
     * checks if the given directory exists and if not tries to create it.
     *
     * @param Event $event
     * @param string $path the directory
     * @throws \Exception
     */
    public static function EnsureDirExists(Event $event, $path)
    {
        if (!is_dir($path)) {
            mkdir($path, 0777, true);
            if (!is_dir($path)) {
                throw new \Exception('Path Not Found: '.$path);
            }
            if ($event->getIO()->isVerbose() === true) {
                $event->getIO()->write(sprintf('Created Directory <info>%s</info>', $path));
            }
        }
    }

    /**
     * checks if the given file exists and if not tries to create it.
     *
     * @param Event $event
     * @param string $path the directory
     * @throws \Exception
     */
    public static function EnsureFileExists(Event $event, $path)
    {
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
            touch($path);
            if (!file_exists($path)) {
                throw new \Exception('File Not Found: '.$path);
            }
            if ($event->getIO()->isVerbose() === true) {
                $event->getIO()->write(sprintf('Created File <info>%s</info>', $path));
            }
        }
    }

    /**
     * runs a command process returning the output and checking return code
     *
     * @param Event $event
     * @param string $commandline the command line to run
     * @return string the output
     * @throws \Exception
     */
    public static function runProcess(Event $event, $commandline)
    {
        if ($event->getIO()->isVerbose() === true) {
            $event->getIO()->write(sprintf('Running <info>%s</info>', $commandline));
        }
        exec($commandline, $output, $return);
        if ($return != 0) {
            throw new \Exception('Returned Error Code '.$return);
        }
        return implode(PHP_EOL, $output);
    }
}
