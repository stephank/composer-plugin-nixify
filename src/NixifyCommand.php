<?php

/**
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Nixify;

use Composer\Command\BaseCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class NixifyCommand extends BaseCommand
{
    protected function configure()
    {
        $this
            ->setName('nixify')
            ->setDescription('Manually generate the Nix expressions for this Composer project.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $generator = new NixGenerator($this->requireComposer(), $this->getIO());
        $generator->collect();
        $generator->generate();

        if ($generator->shouldPreload) {
            $generator->preload();
        }

        return Command::SUCCESS;
    }
}
