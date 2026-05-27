<?php

declare(strict_types=1);

namespace App\Tools;

final class ComposerTool extends BaseTool
{
    public function slug(): string
    {
        return 'composer';
    }

    #[\Override]
    public function category(): string
    {
        return 'always';
    }

    #[\Override]
    public function capabilities(): array
    {
        return ['update', 'safe-adopt'];
    }

    public function updateScript(array $config = []): string
    {
        return 'sudo composer self-update 2>/dev/null';
    }

    #[\Override]
    public function probeMetadata(): array
    {
        return [
            'binary' => 'composer',
            'version_command' => 'composer --version',
            'update_command' => $this->updateScript(),
        ];
    }
}
