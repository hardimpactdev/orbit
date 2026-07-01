<?php

declare(strict_types=1);

namespace App\Tools;

final class ClaudeCodeTool extends UserScopedCliTool
{
    public function slug(): string
    {
        return 'claude-code';
    }

    #[\Override]
    public function capabilities(): array
    {
        return ['install'];
    }

    #[\Override]
    public function supportsInstallVersion(): bool
    {
        return true;
    }

    #[\Override]
    public function cliProfile(): UserScopedCliProfile
    {
        return new UserScopedCliProfile(
            binaryName: 'claude',
            installCommand: static fn (string $version): array => [
                'command' => 'curl -fsSL https://claude.ai/install.sh | bash -s "$1"',
                'arguments' => ['bash', $version],
            ],
        );
    }
}
