<?php

declare(strict_types=1);

namespace App\Commands\Process;

final class ProcessStartCommand extends ProcessRuntimeActionCommand
{
    #[\Override]
    protected $signature = 'process:start
        {name? : Existing process name}
        {--node= : Owning node name}
        {--app= : Parent app slug}
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
