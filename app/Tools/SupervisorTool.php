<?php

declare(strict_types=1);

namespace App\Tools;

final class SupervisorTool extends BaseTool
{
    public function slug(): string
    {
        return 'supervisor';
    }

    #[\Override]
    public function category(): string
    {
        return 'always';
    }

    #[\Override]
    public function capabilities(): array
    {
        return ['start', 'stop', 'restart', 'reload', 'logs', 'safe-fix', 'safe-adopt'];
    }

    #[\Override]
    public function probeMetadata(): array
    {
        return [
            'binary' => 'supervisord',
            'service' => 'supervisor',
            'repair_commands' => $this->serviceRepairCommands(
                service: 'supervisor',
                reload: 'sudo supervisorctl reread',
            ),
        ];
    }
}
