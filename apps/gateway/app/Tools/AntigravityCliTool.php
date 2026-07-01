<?php

declare(strict_types=1);

namespace App\Tools;

final class AntigravityCliTool extends UserScopedCliTool
{
    public function slug(): string
    {
        return 'antigravity-cli';
    }

    #[\Override]
    public function cliProfile(): UserScopedCliProfile
    {
        return new UserScopedCliProfile(
            binaryName: 'agy',
            installCommand: static fn (string $_version): string => 'curl -fsSL https://antigravity.google/cli/install.sh | bash',
        );
    }
}
