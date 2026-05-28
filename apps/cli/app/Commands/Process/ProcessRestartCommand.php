<?php

declare(strict_types=1);

namespace App\Commands\Process;

final class ProcessRestartCommand extends ProcessRuntimeActionCommand
{
    protected $signature = 'process:restart
        {name? : Existing process name}
        {--app= : Parent app slug}
        {--workspace= : Workspace name}
        {--json : Output JSON}';

    protected $description = 'Restart app process runtime units.';

    protected function action(): string
    {
        return 'restart';
    }
}
