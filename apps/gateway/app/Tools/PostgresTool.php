<?php

declare(strict_types=1);

namespace App\Tools;

final class PostgresTool extends DockerComposeTool
{
    public function slug(): string
    {
        return 'postgres';
    }

    #[\Override]
    public function category(): string
    {
        return 'database';
    }

    #[\Override]
    public function requiredNodeRole(): string
    {
        return 'database';
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
            '16' => [
                'default' => '16',
                'versions' => ['16'],
            ],
        ];
    }
}
