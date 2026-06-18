<?php

namespace Innobrain\ComposerFix;

use Composer\Plugin\Capability\CommandProvider as CommandProviderCapability;

class CommandProvider implements CommandProviderCapability
{
    /**
     * @return list<\Composer\Command\BaseCommand>
     */
    public function getCommands(): array
    {
        return [new FixCommand()];
    }
}
