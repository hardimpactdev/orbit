<?php

declare(strict_types=1);

namespace App\Commands\Process;

final class ProcessRestartCommand extends ProcessRuntimeActionCommand
{
    #[\Override]
    protected $signature = 'process:restart
        {name? : Existing process name}
        {--node= : Owning node name}
        {--app= : Parent app slug}
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
