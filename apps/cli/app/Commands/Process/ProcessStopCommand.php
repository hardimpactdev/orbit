<?php

declare(strict_types=1);

namespace App\Commands\Process;

final class ProcessStopCommand extends ProcessRuntimeActionCommand
{
    #[\Override]
    protected $signature = 'process:stop
        {name? : Existing process name}
        {--app= : App-instance or workspace hostname (proxy_routes.domain)}
        {--node= : Owning node name}
        {--instance= : Instance selector}
        {--workspace= : Workspace name}
        {--json : Output JSON}';

    #[\Override]
    protected $description = 'Stop process runtime units.';

    protected function action(): string
    {
        return 'stop';
    }

    protected function treeTitle(): string
    {
        return 'Stopping Processes';
    }

    protected function pastTense(): string
    {
        return 'stopped';
    }
}
