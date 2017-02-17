<?php
/**
 * MyAdmin Installer Plugin
 * Implements https://github.com/composer/composer/blob/master/src/Composer/Plugin/PluginInterface.php
 */

namespace MyAdmin\Plugins;

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
class MyPlugins implements PluginInterface, EventSubscriberInterface, Capable {
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
		print "Hello peoples...";

		$installer = new MyInstaller($this->io, $this->composer);
		$this->composer->getInstallationManager()->addInstaller($installer);
	}

    public function getCapabilities()
    {
        return array(
            'Composer\Plugin\Capability\CommandProvider' => 'MyAdmin\Plugins\CommandProvider',
        );
    }

	public static function getSubscribedEvents()
	{
		return array(
			PluginEvents::PRE_FILE_DOWNLOAD => array(
				array('onPreFileDownload', 0)
			),
		);
	}

	/**
	* @param PreFileDownloadEvent $event
	*/
	public function onPreFileDownload(PreFileDownloadEvent $event) {
		$protocol = parse_url($event->getProcessedUrl(), PHP_URL_SCHEME);
		/*if ($protocol === 's3') {
			$awsClient = new AwsClient($this->io, $this->composer->getConfig());
			$s3RemoteFilesystem = new S3RemoteFilesystem($this->io, $event->getRemoteFilesystem()->getOptions(), $awsClient);
			$event->setRemoteFilesystem($s3RemoteFilesystem);
		}*/
	}
}
