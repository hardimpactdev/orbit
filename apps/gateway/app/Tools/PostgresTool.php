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
}
