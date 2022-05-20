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
use Nixify\Service\NixGenerator;

final class Plugin implements PluginInterface, Capable, EventSubscriberInterface
{
    public function activate(Composer $composer, IOInterface $io): void
    {
    }

    public function deactivate(Composer $composer, IOInterface $io): void
    {
    }

    public function getCapabilities(): array
    {
        return [
            'Composer\Plugin\Capability\CommandProvider' => 'Nixify\CommandProvider',
        ];
    }

    public static function getSubscribedEvents(): array
    {
        return [
            'post-install-cmd' => 'postInstall',
            'post-update-cmd' => 'postUpdate',
        ];
    }

    public function postInstall(Event $event): void
    {
        $generator = new NixGenerator($event->getComposer(), $event->getIO());

        if ($generator->shouldPreload()) {
            $generator->preload(iterator_to_array($generator->collect()));
        }
    }

    public function postUpdate(Event $event): void
    {
        $generator = new NixGenerator($event->getComposer(), $event->getIO());
        $collected = iterator_to_array($generator->collect());

        $generator->generate($collected);

        if ($generator->shouldPreload()) {
            $generator->preload($collected);
        }
    }

    public function uninstall(Composer $composer, IOInterface $io)
    {
    }
}
