<?php

declare(strict_types=1);

namespace App\Tools;

final class MailpitTool extends DockerComposeTool
{
    public function slug(): string
    {
        return 'mailpit';
    }
}
