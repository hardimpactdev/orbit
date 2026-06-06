<?php

declare(strict_types=1);

namespace App\Tools;

final class RustfsTool extends BaseTool
{
    public function slug(): string
    {
        return 'rustfs';
    }

    #[\Override]
    public function requiredNodeRole(): string
    {
        return 's3';
    }

    #[\Override]
    public function category(): string
    {
        return 'storage';
    }

    #[\Override]
    public function capabilities(): array
    {
        return ['install', 'remove', 'update', 'credentials', 'safe-fix', 'safe-adopt'];
    }

    #[\Override]
    public function probeMetadata(): array
    {
        return [
            'binary' => 'docker',
            'version_command' => 'docker --version',
            'container' => 'orbit-rustfs',
            'image' => 'rustfs/rustfs',
            'repair_commands' => [
                'lifecycle_running' => 'docker start '.escapeshellarg('orbit-rustfs'),
                'lifecycle_stopped' => 'docker stop '.escapeshellarg('orbit-rustfs'),
                'lifecycle_restarted' => 'docker restart '.escapeshellarg('orbit-rustfs'),
            ],
        ];
    }
}
