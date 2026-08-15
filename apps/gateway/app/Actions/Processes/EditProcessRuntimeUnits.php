<?php

declare(strict_types=1);

namespace App\Actions\Processes;

use App\Enums\Processes\ProcessRuntime;
use App\Enums\ProcessEventType;
use App\Models\App;
use App\Models\Process;
use App\Services\Processes\ProcessOwnerContext;
use App\Services\Processes\ProcessRuntimeDriverRegistry;
use Throwable;

final readonly class EditProcessRuntimeUnits
{
    public function __construct(
        private EditProcessRuntimeUnitCleaner $cleaner,
        private EditProcessRuntimeUnitResolver $resolver,
        private ProcessRuntimeDriverRegistry $runtimeDrivers,
        private RecordProcessEvent $recordProcessEvent,
    ) {}

    public function fixedRuntimeUnitName(Process $process): ?string
    {
        return $this->resolver->fixedRuntimeUnitName($process);
    }

    /**
     * @param array{context: ProcessOwnerContext, app: App, process: Process, runtime_units: list<array{name: string, context: string}>, previous_runtime: ProcessRuntime, previous_runtime_units: list<array{name: string, context: string}>} $request
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
     * Ordered durable lifecycle: restarting, then started or failed. On
     * exception, failed is recorded before rethrow so status is never left
     * transitional. process_name is the immutable snapshot from the Process
     * model at record time (includes renames applied earlier in process:update).
     *
     * @param  list<array{name: string, context: string}>  $runtimeUnits
     * @return list<array<string, mixed>>
     */
    public function restart(ProcessOwnerContext $context, Process $process, array $runtimeUnits): array
    {
        $warnings = [];
        $driver = $this->runtimeDrivers->forProcess($process);
        // App-level process:update may restart main plus each workspace unit.
        // Resolve workspace from each unit's context so events are not all
        // written with workspace_id=null (which would starve workspace streams
        // and contaminate main-scope status derivation).
        $app = $context->runtimeApp();
        $app?->loadMissing(['workspaces']);

        foreach ($runtimeUnits as $runtimeUnit) {
            $workspace = $this->resolver->runtimeWorkspaceForUnit(
                $context,
                $app,
                $process,
                $runtimeUnit,
            );

            $this->recordProcessEvent->handle(
                ProcessEventType::Restarting,
                $context->eventApp(),
                $workspace,
                $process,
                $context->node,
                $runtimeUnit['name'],
            );

            try {
                $ok = $driver->restart($context->node, $runtimeUnit['name']);
            } catch (Throwable $exception) {
                $this->recordProcessEvent->handle(
                    ProcessEventType::Failed,
                    $context->eventApp(),
                    $workspace,
                    $process,
                    $context->node,
                    $runtimeUnit['name'],
                );

                throw $exception;
            }

            $this->recordProcessEvent->handle(
                $ok ? ProcessEventType::Started : ProcessEventType::Failed,
                $context->eventApp(),
                $workspace,
                $process,
                $context->node,
                $runtimeUnit['name'],
            );

            if ($ok) {
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
