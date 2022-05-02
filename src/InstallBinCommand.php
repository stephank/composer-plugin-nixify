<?php

declare(strict_types=1);

namespace Nixify;

use Composer\Command\BaseCommand;
use Composer\Installer\BinaryInstaller;
use Composer\Util\Filesystem;
use Composer\Util\ProcessExecutor;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\ExecutableFinder;

final class InstallBinCommand extends BaseCommand
{
    protected function configure()
    {
        $this
            ->setName('nixify-install-bin')
            ->setDescription('Internal Nixify plugin command to create executable wrappers')
            ->addArgument('bin-dir', InputArgument::REQUIRED, 'Directory to create wrappers in')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $binDir = $input->getArgument('bin-dir');
        $io = $this->getIO();
        $fs = new Filesystem();

        $scriptFiles = $this->requireComposer()->getPackage()->getBinaries();
        foreach ($scriptFiles as $scriptFile) {
            $scriptPath = realpath($scriptFile);
            if (!$scriptPath) {
                $io->writeError(sprintf(
                    '<warning>Skipped binary "%s" because the file does not exist</warning>',
                    $scriptFile
                ));
                continue;
            }

            $caller = BinaryInstaller::determineBinaryCaller($scriptPath);
            $scriptPath = ProcessExecutor::escape($scriptPath);

            // In Nix, the PHP executable is a small shell wrapper. Using this
            // as a shebang fails at least on macOS. Detect a PHP shebang, and
            // make sure PHP is properly invoked in our binary wrapper.
            if ($caller === 'php') {
                $exeFinder = new ExecutableFinder;
                $interpPath = $exeFinder->find($caller);
                if ($interpPath) {
                    $interpPath = ProcessExecutor::escape($interpPath);
                    $scriptPath = "$interpPath $scriptPath";
                }
            }

            $outputPath = sprintf('%s/%s', $binDir, basename($scriptFile));
            $fs->ensureDirectoryExists(dirname($outputPath));

            ob_start();
            require __DIR__ . '/../res/bin-wrapper.sh.php';
            file_put_contents($outputPath, ob_get_clean());

            chmod($outputPath, 0755);
        }

        return Command::SUCCESS;
    }
}
