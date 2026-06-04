<?php

declare(strict_types=1);

namespace App\Tools;

final class MysqlTool extends DockerComposeTool
{
    public function slug(): string
    {
        return 'mysql';
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
            '8' => [
                'default' => '8.4',
                'versions' => ['8.4'],
            ],
            '9' => [
                'default' => '9',
                'versions' => ['9'],
            ],
        ];
    }
}
