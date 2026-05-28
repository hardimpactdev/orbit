<?php

declare(strict_types=1);

namespace App\Commands\Process;

final class ProcessStartCommand extends ProcessRuntimeActionCommand
{
    protected $signature = 'process:start
        {name? : Existing process name}
        {--app= : Parent app slug}
        {--workspace= : Workspace name}
        {--json : Output JSON}';

    protected $description = 'Start app process runtime units.';

    protected function action(): string
    {
        return 'start';
    }
}
