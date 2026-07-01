<?php

declare(strict_types=1);

namespace App\Tools;

final class GrokCliTool extends UserScopedCliTool
{
    public function slug(): string
    {
        return 'grok-cli';
    }

    #[\Override]
    public function cliProfile(): UserScopedCliProfile
    {
        return new UserScopedCliProfile(
            binaryName: 'grok',
            installCommand: static fn (string $_version): string => 'curl -fsSL https://x.ai/cli/install.sh | bash',
            binaryPath: static fn (string $user): string => UserScopedCliUsers::homeDirectory($user).'/.grok/bin/grok',
            updateCommand: static fn (string $user): string => (
                UserScopedCliUsers::homeDirectory($user).'/.grok/bin/grok update'
            ),
        );
    }
}
