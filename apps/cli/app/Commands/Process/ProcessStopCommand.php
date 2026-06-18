<?php

declare(strict_types=1);

namespace App\Commands\Process;

final class ProcessStopCommand extends ProcessRuntimeActionCommand
{
    #[\Override]
    protected $signature = 'process:stop
        {name? : Existing process name}
        {--node= : Owning node name}
        {--app= : Parent app slug}
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
