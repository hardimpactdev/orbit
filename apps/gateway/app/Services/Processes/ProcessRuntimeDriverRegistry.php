<?php

declare(strict_types=1);

namespace App\Services\Processes;

use App\Enums\Processes\ProcessRuntime;
use App\Http\Gateway\GatewayApiException;
use App\Models\Process;
use App\Services\Processes\ProcessRuntimeDrivers\DockerProcessRuntimeDriver;
use App\Services\Processes\ProcessRuntimeDrivers\ProcessRuntimeDriver;
use App\Services\Processes\ProcessRuntimeDrivers\SupervisorProcessRuntimeDriver;
use App\Services\Processes\ProcessRuntimeDrivers\SystemdProcessRuntimeDriver;

final readonly class ProcessRuntimeDriverRegistry
{
    public function __construct(
        private SupervisorProcessRuntimeDriver $supervisor,
        private DockerProcessRuntimeDriver $docker,
        private SystemdProcessRuntimeDriver $systemd,
    ) {}

    public function for(ProcessRuntime|string $runtime): ProcessRuntimeDriver
    {
        return $this->driverFor($this->resolveRuntime($runtime));
    }

    public function forProcess(Process $process): ProcessRuntimeDriver
    {
        return $this->driverFor($this->resolveRuntime(
            runtime: $process->getRawOriginal('runtime') ?? $process->getAttributes()['runtime'] ?? null,
            process: $process,
        ));
    }

    private function driverFor(ProcessRuntime $runtime): ProcessRuntimeDriver
    {
        return match ($runtime) {
            ProcessRuntime::Supervisor => $this->supervisor,
            ProcessRuntime::Docker => $this->docker,
            ProcessRuntime::Systemd => $this->systemd,
        };
    }

    private function resolveRuntime(ProcessRuntime|string|null $runtime, ?Process $process = null): ProcessRuntime
    {
        if ($runtime instanceof ProcessRuntime) {
            return $runtime;
        }

        if (is_string($runtime)) {
            $resolvedRuntime = ProcessRuntime::tryFrom($runtime);

            if ($resolvedRuntime instanceof ProcessRuntime) {
                return $resolvedRuntime;
            }
        }

        throw new GatewayApiException(
            $process instanceof Process
                ? "Process '{$process->name}' uses unsupported runtime '{$runtime}'."
                : "Unsupported process runtime '{$runtime}'.",
            'process.unsupported_runtime',
            [
                ...($process instanceof Process ? ['process' => $process->name] : []),
                'runtime' => $runtime,
                'allowed' => array_map(fn (ProcessRuntime $runtime): string => $runtime->value, ProcessRuntime::cases()),
            ],
        );
    }
}
