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
}
