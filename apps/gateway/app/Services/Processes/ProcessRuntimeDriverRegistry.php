<?php

declare(strict_types=1);

namespace App\Services\Processes;

use App\Enums\Processes\ProcessRuntime;
use App\Services\Processes\ProcessRuntimeDrivers\DockerProcessRuntimeDriver;
use App\Services\Processes\ProcessRuntimeDrivers\ProcessRuntimeDriver;
use App\Services\Processes\ProcessRuntimeDrivers\SupervisorProcessRuntimeDriver;

final readonly class ProcessRuntimeDriverRegistry
{
    public function __construct(
        private SupervisorProcessRuntimeDriver $supervisor,
        private DockerProcessRuntimeDriver $docker,
    ) {}

    public function for(ProcessRuntime $runtime): ProcessRuntimeDriver
    {
        return match ($runtime) {
            ProcessRuntime::Supervisor => $this->supervisor,
            ProcessRuntime::Docker => $this->docker,
        };
    }
}
