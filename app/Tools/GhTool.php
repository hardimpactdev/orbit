<?php

declare(strict_types=1);

namespace App\Tools;

final class GhTool extends BaseTool
{
    public function slug(): string
    {
        return 'gh';
    }

    #[\Override]
    public function capabilities(): array
    {
        return ['update', 'safe-adopt'];
    }

    public function updateScript(array $config = []): string
    {
        return 'export DEBIAN_FRONTEND=noninteractive && sudo apt-get install --only-upgrade -y gh 2>/dev/null';
    }

    #[\Override]
    public function probeMetadata(): array
    {
        return [
            'binary' => 'gh',
            'version_command' => 'gh --version',
            'update_command' => $this->updateScript(),
        ];
    }
}
