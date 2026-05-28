<?php

declare(strict_types=1);

namespace App\Commands\Process;

final class ProcessStopCommand extends ProcessRuntimeActionCommand
{
    protected $signature = 'process:stop
        {name? : Existing process name}
        {--app= : Parent app slug}
        {--workspace= : Workspace name}
        {--json : Output JSON}';

    protected $description = 'Stop app process runtime units.';

    protected function action(): string
    {
        return 'stop';
    }
}
