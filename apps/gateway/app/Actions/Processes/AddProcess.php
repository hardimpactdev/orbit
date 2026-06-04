<?php

declare(strict_types=1);

namespace App\Actions\Processes;

use App\Actions\Apps\EnsureAppProcessRuntimeUnits;
use App\Enums\ProcessCrashNotification;
use App\Enums\Processes\ProcessRuntime;
use App\Enums\ProcessRestartPolicy;
use App\Http\Gateway\GatewayApiException;
use App\Models\App;
use App\Models\Process;
use App\Services\Processes\ProcessOwnerContext;
use App\Services\Processes\ProcessRuntimeDriverRegistry;
use App\Services\Processes\ProcessRuntimeUnitPayload;
use Illuminate\Support\Facades\DB;
use LogicException;

final readonly class AddProcess
{
    public function __construct(
        private EnsureAppProcessRuntimeUnits $ensureRuntimeUnits,
        private ProcessRuntimeUnitPayload $runtimeUnitPayload,
        private ProcessRuntimeDriverRegistry $runtimeDrivers,
    ) {}

    /**
     * @return array{data: array<string, mixed>, warnings: list<array<string, mixed>>}
     */
    public function handle(ProcessOwnerContext $context, string $name, string $command, ProcessRestartPolicy $restartPolicy, ProcessCrashNotification $crashNotification, bool $start, ?ProcessRuntime $runtime = null, ?string $tool = null): array
    {
        $app = $context->runtimeApp();
        $app->loadMissing(['node', 'workspaces']);

        $resolvedRuntime = $runtime ?? $context->defaultRuntime();
        $context->assertRuntimeAllowed($resolvedRuntime);

        if ($context->ownerProcesses()->where('name', $name)->exists()) {
            throw new GatewayApiException("Process '{$name}' already exists for {$context->label()}.", 'process.name_collision', $context->errorMeta($name));
        }

        $process = DB::transaction(function () use ($context, $name, $command, $restartPolicy, $crashNotification, $resolvedRuntime, $tool): Process {
            $maxOrder = $context->ownerProcesses()
                ->lockForUpdate()
                ->max('sort_order') ?? 0;

            $process = $context->ownerProcesses()->create([
                'node_id' => $context->node->id,
                'name' => $name,
                'command' => $command,
                'restart_policy' => $restartPolicy,
                'crash_notification' => $crashNotification,
                'runtime' => $resolvedRuntime,
                'tool' => $tool,
                'sort_order' => $maxOrder + 1,
            ]);

            if (! $process instanceof Process) {
                throw new LogicException('Process owner relation created an unexpected model.');
            }

            return $process;
        });

        $app->unsetRelation('processes');
        $runtimeUnits = $this->runtimeUnitPayload->forProcess($app, $process, $context->runtimeWorkspaceFor($process));
        $warnings = $context->app instanceof App && $context->workspace === null
            ? $this->ensureRuntimeUnits->handle($app)
            : $this->applyRuntimeUnits($context, $app, $process, $runtimeUnits);

        if ($start) {
            $warnings = [
                ...$warnings,
                ...$this->startRuntimeUnits($context, $process, $runtimeUnits),
            ];
        }

        return [
            'data' => [
                'process' => [
                    ...$context->processPayload($process),
                ],
                'runtime_units' => $runtimeUnits,
            ],
            'warnings' => $warnings,
        ];
    }

    /**
     * @param  list<array{name: string, context: string}>  $runtimeUnits
     * @return list<array<string, mixed>>
     */
    private function applyRuntimeUnits(ProcessOwnerContext $context, App $app, Process $process, array $runtimeUnits): array
    {
        $warnings = [];
        $driver = $this->runtimeDrivers->forProcess($process);

        foreach ($runtimeUnits as $runtimeUnit) {
            $workspace = $context->runtimeWorkspaceFor($process);
            $applied = $driver->apply($context->node, $app, $process, $workspace);

            if (! $applied) {
                $warnings[] = [
                    'code' => 'process.runtime_unit_apply_failed',
                    'family' => 'process',
                    'message' => "Process runtime unit '{$runtimeUnit['name']}' could not be rendered or applied.",
                    'next_command' => 'doctor --family=process --restore',
                ];
            }
        }

        return $warnings;
    }

    /**
     * Start the rendered runtime units after a successful apply through the
     * process runtime driver selected by `$process->runtime`.
     *
     * @param  list<array{name: string, context: string}>  $runtimeUnits
     * @return list<array<string, mixed>>
     */
    private function startRuntimeUnits(ProcessOwnerContext $context, Process $process, array $runtimeUnits): array
    {
        $warnings = [];
        $driver = $this->runtimeDrivers->forProcess($process);

        foreach ($runtimeUnits as $runtimeUnit) {
            $name = $runtimeUnit['name'];
            $started = $driver->start($context->node, $name);

            if (! $started) {
                $warnings[] = [
                    'code' => 'process.runtime_unit_start_failed',
                    'family' => 'process',
                    'message' => "Process runtime unit '{$name}' was rendered but could not be started.",
                    'next_command' => 'doctor --family=process --restore',
                ];
            }
        }

        return $warnings;
    }
}
