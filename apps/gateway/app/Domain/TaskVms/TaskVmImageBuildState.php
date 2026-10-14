<?php

declare(strict_types=1);

namespace App\Domain\TaskVms;

enum TaskVmImageBuildState: string
{
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
}
