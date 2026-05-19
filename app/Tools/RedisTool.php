<?php

declare(strict_types=1);

namespace App\Tools;

final class RedisTool extends DockerComposeTool
{
    public function slug(): string
    {
        return 'redis';
    }

    #[\Override]
    public function category(): string
    {
        return 'cache';
    }

    #[\Override]
    public function probeMetadata(): array
    {
        return [
            'binary' => 'redis-server',
            'version_command' => 'redis-server --version',
            'service' => 'redis-server',
            'repair_commands' => $this->serviceRepairCommands('redis-server', restart: true),
        ];
    }
}
