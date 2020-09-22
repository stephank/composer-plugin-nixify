<?php

namespace Nixify;

class CommandProvider implements \Composer\Plugin\Capability\CommandProvider
{
    public function getCommands()
    {
        return [
            new InstallBinCommand,
        ];
    }
}
