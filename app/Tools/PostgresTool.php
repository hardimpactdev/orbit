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
    public function requiredNodeRole(): string
    {
        return 'database';
    }

    #[\Override]
    public function probeMetadata(): array
    {
        return [
            'binary' => 'psql',
            'version_command' => 'psql --version',
        ];
    }
}
