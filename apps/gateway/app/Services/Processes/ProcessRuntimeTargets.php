<?php

declare(strict_types=1);

namespace App\Services\Processes;

use App\Models\Process;
use App\Services\Processes\ProcessRuntimeDrivers\ProcessRuntimeDriver;
use Orbit\Sdk\Laravel\GatewayApiException;

final readonly class ProcessRuntimeTargets
{
    public function __construct(
        private ProcessRuntimeDriverRegistry $runtimeDrivers,
    ) {}

    /**
     * @return array<int, array{process: Process, driver: ProcessRuntimeDriver, runtime_unit: string}>
     */
    public function for(ProcessOwnerContext $context, ?string $name): array
    {
        $processes = $context->lifecycleProcesses($name);

        if ($processes->isEmpty()) {
            if ($name !== null) {
                throw new GatewayApiException(
                    "Process '{$name}' not found for {$context->label()}.",
                    'process.not_found',
                    $context->errorMeta($name),
                );
            }

            throw new GatewayApiException(
                "{$context->label()} has no configured processes.",
                'process.none_configured',
                $context->errorMeta(),
            );
        }

        $app = $context->runtimeApp();

        return $processes
            ->map(function (Process $process) use ($context, $app): array {
                $driver = $this->runtimeDrivers->forProcess($process);
                $workspace = $context->runtimeWorkspaceFor($process);

                return [
                    'process' => $process,
                    'driver' => $driver,
                    'runtime_unit' => $driver->runtimeUnitName($app, $process, $workspace),
                ];
            })
            ->values()
            ->all();
    }
}
