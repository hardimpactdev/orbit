<?php

declare(strict_types=1);

namespace App\Commands\Process;

final class ProcessRestartCommand extends ProcessRuntimeActionCommand
{
    #[\Override]
    protected $signature = 'process:restart
        {name? : Existing process name}
        {--app= : App-instance or workspace hostname (proxy_routes.domain)}
        {--node= : Owning node name}
        {--instance= : Instance selector}
        {--workspace= : Workspace name}
        {--json : Output JSON}';

    #[\Override]
    protected $description = 'Restart process runtime units.';

    protected function action(): string
    {
        return 'restart';
    }

    protected function treeTitle(): string
    {
        return 'Restarting Processes';
    }

    protected function pastTense(): string
    {
        return 'restarted';
    }
}
