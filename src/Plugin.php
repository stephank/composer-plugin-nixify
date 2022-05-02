<?php

/**
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 */

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
    public function activate(Composer $composer, IOInterface $io)
    {
    }

    public function deactivate(Composer $composer, IOInterface $io)
    {
    }

    public function getCapabilities()
    {
        return [
            'Composer\Plugin\Capability\CommandProvider' => 'Nixify\CommandProvider',
        ];
    }

    public static function getSubscribedEvents()
    {
        return [
            'post-install-cmd' => 'postInstall',
            'post-update-cmd' => 'postUpdate',
        ];
    }

    public function postInstall(Event $event)
    {
        $generator = new NixGenerator($event->getComposer(), $event->getIO());

        if ($generator->shouldPreload) {
            $generator->preload();
        }
    }

    public function postUpdate(Event $event)
    {
        $generator = new NixGenerator($event->getComposer(), $event->getIO());
        $generator->generate();

        if ($generator->shouldPreload) {
            $generator->preload();
        }
    }

    public function uninstall(Composer $composer, IOInterface $io)
    {
    }
}
