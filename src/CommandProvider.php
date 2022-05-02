<?php

namespace Nixify;

use Composer\Plugin\Capability\CommandProvider as CapabilityCommandProvider;

final class CommandProvider implements CapabilityCommandProvider
{
    public function getCommands(): array
    {
        return [
            new InstallBinCommand,
            new NixifyCommand,
        ];
    }
}
