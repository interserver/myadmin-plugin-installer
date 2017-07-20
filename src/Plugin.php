<?php
/**
 * Plugins Management
 * Last Changed: $LastChangedDate: 2017-04-27 04:45:04 -0400 (Thu, 27 Apr 2017) $
 * @author detain
 * @copyright 2017
 * @package MyAdmin
 * @category Plugins
 */

/**
 * MyAdmin Installer Plugin
 * @link https://github.com/composer/composer/blob/master/src/Composer/Plugin/PluginInterface.php
 */

namespace MyAdmin\PluginInstaller;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;
use Composer\Plugin\Capable;
use Composer\Plugin\PluginEvents;
use Composer\Plugin\PreFileDownloadEvent;

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
			'Composer\Plugin\Capability\CommandProvider' => 'MyAdmin\PluginInstaller\CommandProvider'
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
}
