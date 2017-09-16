<?php
/**
 * Plugins Management
 * @author Joe Huss <detain@interserver.net>
 * @copyright 2017
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
class Plugin implements PluginInterface, EventSubscriberInterface, Capable {
	protected $composer;
	protected $io;

	/**
	 * Apply plugin modifications to Composer
	 *
	 * @param Composer	$composer
	 * @param IOInterface $io
	 */
	public function activate(Composer $composer, IOInterface $io) {
		$this->composer = $composer;
		$this->io = $io;
		print 'Hello peoples...';
		$installer = new Installer($this->io, $this->composer);
		$this->composer->getInstallationManager()->addInstaller($installer);
	}

	/**
	 * @return array
	 */
	public function getCapabilities() {
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
	public static function getSubscribedEvents() {
		return [
			PluginEvents::PRE_FILE_DOWNLOAD => [
				['onPreFileDownload', 0]
			]
		];
	}

	/**
	* @param PreFileDownloadEvent $event
	*/
	public function onPreFileDownload(PreFileDownloadEvent $event) {
		/*$protocol = parse_url($event->getProcessedUrl(), PHP_URL_SCHEME);
		if ($protocol === 's3') {
			$awsClient = new AwsClient($this->io, $this->composer->getConfig());
			$s3RemoteFilesystem = new S3RemoteFilesystem($this->io, $event->getRemoteFilesystem()->getOptions(), $awsClient);
			$event->setRemoteFilesystem($s3RemoteFilesystem);
		}*/
	}

	public static function setPermissions(Event $event) {
		if ('WIN' === strtoupper(substr(PHP_OS, 0, 3))) {
			$event->getIO()->write('<info>No permissions setup is required on Windows.</info>');
			return;
		}
		$event->getIO()->write('Setting up permissions.');
		try {
			self::setPermissionsSetfacl($event);
			return;
		} catch (\Exception $setfaclException) {
			$event->getIO()->write(sprintf('<error>%s</error>', $setfaclException->getMessage()));
			$event->getIO()->write('<info>Trying chmod...</info>');
		}
		try {
			self::setPermissionsChmod($event);
			return;
		} catch (\Exception $chmodException) {
			$event->getIO()->write(sprintf('<error>%s</error>', $chmodException->getMessage()));
		}
	}

	public static function getWritableDirs(Event $event) {
		$configuration = $event->getComposer()->getPackage()->getExtra();
		if (!isset($configuration['writable-dirs']))
			throw new \Exception('The writable-dirs must be specified in composer arbitrary extra data.');
		if (!is_array($configuration['writable-dirs']))
			throw new \Exception('The writable-dirs must be an array.');
		return $configuration['writable-dirs'];
	}

	public static function setPermissionsSetfacl(Event $event) {
		foreach (self::getWritableDirs($event) as $path)
			self::SetfaclPermissionsSetter($path);
	}

	public static function setPermissionsChmod(Event $event) {
		foreach (self::getWritableDirs($event) as $path)
			self::ChmodPermissionsSetter($path);
	}

	public static function SetfaclPermissionsSetter($path) {
		if (!is_dir($path))
			mkdir($path, 0777, true);
		if (!is_dir($path))
			throw new \Exception('Path Not Found: '.$path);
		self::runCommand('setfacl -m u:"%httpduser%":rwX -m u:$USER:rwX %path%', $path);
		self::runCommand('setfacl -d -m u:"%httpduser%":rwX -m u:$USER:rwX %path%', $path);
	}

	public static function ChmodPermissionsSetter($path) {
		if (!is_dir($path))
			mkdir($path, 0777, true);
		if (!is_dir($path))
			throw new \Exception('Path Not Found: '.$path);
		self::runCommand('chmod +a "%httpduser% allow delete,write,append,file_inherit,directory_inherit" %path%', $path);
		self::runCommand('chmod +a "$USER allow delete,write,append,file_inherit,directory_inherit" %path%', $path);
	}

	public static function runCommand($command, $path) {
		return self::runProcess(str_replace(['%httpduser%', '%path%'], [self::getHttpdUser(), $path], $command));
	}

	public static function getHttpdUser() {
		$ps = self::runProcess('ps aux');
		preg_match_all('/^.*([a]pache|[h]ttpd|[_]www|[w]ww-data|[n]ginx)$/m', $ps, $matches);
		foreach ($matches[0] as $match) {
			$user = substr($match, 0, strpos($match, ' '));
			if ($user != 'root')
				return $user;
		}
	}

	public static function runProcess($commandline) {
		exec($commandline, $output, $return);
		if ($return != 0)
			throw new \Exception('Returned Error Code '.$return);
		return implode(PHP_EOL, $output);
	}
}
