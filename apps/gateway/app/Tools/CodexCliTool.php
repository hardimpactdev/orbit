<?php

declare(strict_types=1);

namespace App\Tools;

final class CodexCliTool extends UserScopedCliTool
{
    public function slug(): string
    {
        return 'codex-cli';
    }

    #[\Override]
    public function cliProfile(): UserScopedCliProfile
    {
        return new UserScopedCliProfile(
            binaryName: 'codex',
            installCommand: static fn (string $_version): string => 'curl -fsSL https://chatgpt.com/codex/install.sh | CODEX_NON_INTERACTIVE=1 sh',
        );
    }
}
