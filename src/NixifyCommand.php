<?php

namespace Nixify;

use Composer\Installer\BinaryInstaller;
use Composer\Util\Filesystem;
use Composer\Util\ProcessExecutor;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\ExecutableFinder;

class NixifyCommand extends \Composer\Command\BaseCommand
{
    protected function configure()
    {
        $this
            ->setName('nixify')
            ->setDescription('Manually generate the Nix expressions for this Composer project.')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $generator = new NixGenerator($this->getComposer(), $this->getIO());
        $generator->collect();
        $generator->generate();
        if ($generator->shouldPreload) {
            $generator->preload();
        }
    }
}
