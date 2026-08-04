<?php

declare(strict_types=1);

namespace App\Commands\Process;

final class ProcessStartCommand extends ProcessRuntimeActionCommand
{
    #[\Override]
    protected $signature = 'process:start
        {name? : Existing process name}
        {--app= : App-instance or workspace hostname (proxy_routes.domain)}
        {--node= : Owning node name}
        {--instance= : Instance selector}
        {--workspace= : Workspace name}
        {--json : Output JSON}';

    #[\Override]
    protected $description = 'Start process runtime units.';

    protected function action(): string
    {
        return 'start';
    }

    protected function treeTitle(): string
    {
        return 'Starting Processes';
    }

    protected function pastTense(): string
    {
        return 'started';
    }
}
