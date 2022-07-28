<?php
/**
 * Plugins Management
 * @author Joe Huss <detain@interserver.net>
 * @copyright 2019
 * @package MyAdmin
 * @category Plugins
 */

namespace MyAdmin\Plugins;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;
use Composer\Plugin\PluginEvents;
use Composer\Plugin\PreFileDownloadEvent;

/**
 * Class TemplateInstallerPlugin
 *
 * @package MyAdmin\Plugins
 */
class TemplateInstallerPlugin implements PluginInterface, EventSubscriberInterface
{
    protected $composer;
    protected $io;

    /**
     * @param \Composer\Composer       $composer
     * @param \Composer\IO\IOInterface $io
     */
    public function activate(Composer $composer, IOInterface $io)
    {
        $this->composer = $composer;
        $this->io = $io;
        $installer = new TemplateInstaller($io, $composer);
        $composer->getInstallationManager()->addInstaller($installer);
    }

    public function deactivate(Composer $composer, IOInterface $io)
    {
    }

    public function uninstall(Composer $composer, IOInterface $io)
    {
    }

    public static function getSubscribedEvents()
    {
        return array(
            PluginEvents::PRE_FILE_DOWNLOAD => array(
                array('onPreFileDownload', 0)
            ),
        );
    }

    public function onPreFileDownload(PreFileDownloadEvent $event)
    {
        $protocol = parse_url($event->getProcessedUrl(), PHP_URL_SCHEME);
    }
}
