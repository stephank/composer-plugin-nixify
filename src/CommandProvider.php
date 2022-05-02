<?php

/**
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Nixify;

use Composer\Plugin\Capability\CommandProvider as CapabilityCommandProvider;

final class CommandProvider implements CapabilityCommandProvider
{
    public function getCommands(): array
    {
        return [
            new InstallBinCommand(),
            new NixifyCommand(),
        ];
    }
}
