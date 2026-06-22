<?php

declare(strict_types=1);

namespace App\Tools;

final class CodexAppTool extends BaseTool
{
    public function slug(): string
    {
        return 'codex-app';
    }

    #[\Override]
    public function category(): string
    {
        return 'operator';
    }

    #[\Override]
    public function supportedOperatingSystems(): array
    {
        return ['macos'];
    }

    #[\Override]
    public function capabilities(): array
    {
        return [];
    }

    #[\Override]
    public function probeMetadata(): array
    {
        return [
            'binary' => 'open',
            'probe' => 'test -f ~/.codex/codex-app/config.json',
        ];
    }
}
