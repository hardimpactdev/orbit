<?php

declare(strict_types=1);

namespace App\Actions\Processes;

use App\Enums\Processes\ProcessRuntime;
use App\Models\App;
use App\Models\Process;
use App\Services\Processes\ProcessOwnerContext;
use App\Services\Processes\ProcessRuntimeDriverRegistry;

final readonly class EditProcessRuntimeUnits
{
    public function __construct(
        private EditProcessRuntimeUnitCleaner $cleaner,
        private EditProcessRuntimeUnitResolver $resolver,
        private ProcessRuntimeDriverRegistry $runtimeDrivers,
    ) {}

    public function fixedRuntimeUnitName(Process $process): ?string
    {
        return $this->resolver->fixedRuntimeUnitName($process);
    }

    /**
     * @param  array{
     *     context: ProcessOwnerContext,
     *     app: App,
     *     process: Process,
     *     runtime_units: list<array{name: string, context: string}>,
     *     previous_runtime: ProcessRuntime,
     *     previous_runtime_units: list<array{name: string, context: string}>
     * }  $request
     * @return array{
     *     warnings: list<array<string, mixed>>,
     *     applied_runtime_units: list<array{name: string, context: string}>
     * }
     */
    public function apply(array $request): array
    {
        $warnings = [];
        $appliedRuntimeUnits = [];
        $process = $request['process'];
        $driver = $this->runtimeDrivers->forProcess($process);

        foreach ($request['runtime_units'] as $index => $runtimeUnit) {
            $previousName = $this->previousRuntimeUnitName($runtimeUnit, $request['previous_runtime_units'], $index);
            $workspace = $this->resolver->runtimeWorkspaceForUnit(
                $request['context'],
                $request['app'],
                $process,
                $runtimeUnit,
            );

            if (! $driver->apply($request['context']->node, $request['app'], $process, $workspace)) {
                $warnings[] = $this->warning(
                    'process.runtime_unit_apply_failed',
                    "Process runtime unit '{$runtimeUnit['name']}' could not be rendered or applied.",
                );

                continue;
            }

            $cleanupWarning = $this->cleaner->cleanupPreviousName(
                $request['context'],
                $request['previous_runtime'],
                $previousName,
                $runtimeUnit['name'],
                $process,
            );

            if ($cleanupWarning !== null) {
                $warnings[] = $cleanupWarning;
            }

            $appliedRuntimeUnits[] = $runtimeUnit;
        }

        return [
            'warnings' => $warnings,
            'applied_runtime_units' => $appliedRuntimeUnits,
        ];
    }

    /**
     * @param  list<array{name: string, context: string}>  $previousRuntimeUnits
     * @param  list<array{name: string, context: string}>  $runtimeUnits
     * @return list<array<string, mixed>>
     */
    public function cleanupPrevious(
        ProcessOwnerContext $context,
        ProcessRuntime $previousRuntime,
        array $previousRuntimeUnits,
        array $runtimeUnits,
    ): array {
        return $this->cleaner->cleanupPrevious($context, $previousRuntime, $previousRuntimeUnits, $runtimeUnits);
    }

    /**
     * Restart the rendered runtime units after a successful apply through the
     * process runtime driver selected by `$process->runtime`.
     *
     * @param  list<array{name: string, context: string}>  $runtimeUnits
     * @return list<array<string, mixed>>
     */
    public function restart(ProcessOwnerContext $context, Process $process, array $runtimeUnits): array
    {
        $warnings = [];
        $driver = $this->runtimeDrivers->forProcess($process);

        foreach ($runtimeUnits as $runtimeUnit) {
            if ($driver->restart($context->node, $runtimeUnit['name'])) {
                continue;
            }

            $warnings[] = $this->warning(
                'process.runtime_unit_restart_failed',
                "Process runtime unit '{$runtimeUnit['name']}' was rendered but could not be restarted.",
            );
        }

        return $warnings;
    }

    /**
     * @param  array{name: string, context: string}  $runtimeUnit
     * @param  list<array{name: string, context: string}>  $previousRuntimeUnits
     */
    private function previousRuntimeUnitName(array $runtimeUnit, array $previousRuntimeUnits, int $index): string
    {
        return is_string($previousRuntimeUnits[$index]['name'] ?? null)
            ? $previousRuntimeUnits[$index]['name']
            : $runtimeUnit['name'];
    }

    /**
     * @return array<string, mixed>
     */
    private function warning(string $code, string $message): array
    {
        return [
            'code' => $code,
            'family' => 'process',
            'message' => $message,
            'next_command' => 'doctor --family=process --restore',
        ];
    }
}
