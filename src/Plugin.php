<?php

declare(strict_types=1);

namespace Nixify;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Composer\Plugin\Capable;
use Composer\Plugin\PluginInterface;
use Composer\Script\Event;

final class Plugin implements PluginInterface, Capable, EventSubscriberInterface
{
    public function postInstall(Event $event)
    {
        $generator = new NixGenerator($event->getComposer(), $event->getIO());
        if ($generator->shouldPreload) {
            $generator->collect();
            $generator->preload();
        }
    }

    public function postUpdate(Event $event)
    {
        $generator = new NixGenerator($event->getComposer(), $event->getIO());
        $generator->collect();
        $generator->generate();
        if ($generator->shouldPreload) {
            $generator->preload();
        }
    }

    // PluginInterface

    public function activate(Composer $composer, IOInterface $io)
    {
    }

    public function deactivate(Composer $composer, IOInterface $io)
    {
    }

    public function uninstall(Composer $composer, IOInterface $io)
    {
        if (file_exists('composer-project.nix') || file_exists('default.nix')) {
            $io->writeError(
                '<info>You may also want to delete the generated "*.nix" files.</info>'
            );
        }
    }

    // Capable

    public function getCapabilities()
    {
        return [
            'Composer\Plugin\Capability\CommandProvider' => 'Nixify\CommandProvider',
        ];
    }

    // EventSubscriberInterface

    public static function getSubscribedEvents()
    {
        return [
            'post-install-cmd' => 'postInstall',
            'post-update-cmd' => 'postUpdate',
        ];
    }
}
