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
    public function defaultRuntime(): string
    {
        return 'docker';
    }

    #[\Override]
    public function supportedRuntimes(): array
    {
        return [
            'docker' => [
                'platforms' => ['linux', 'ubuntu'],
            ],
            'docker-swarm' => [
                'platforms' => ['linux', 'ubuntu'],
            ],
        ];
    }

    #[\Override]
    public function supportedVersionFamilies(): array
    {
        return [
            '7' => [
                'default' => '7.2',
                'versions' => ['7.2'],
            ],
        ];
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
