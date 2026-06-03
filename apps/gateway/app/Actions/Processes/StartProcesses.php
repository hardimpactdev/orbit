<?php

declare(strict_types=1);

namespace App\Actions\Processes;

use App\Enums\ProcessEventType;
use App\Http\Gateway\GatewayApiException;
use App\Models\App;
use App\Models\Process;
use App\Models\Workspace;
use App\Services\Processes\ProcessRuntimeDriverRegistry;
use App\Services\Processes\ProcessRuntimeDrivers\ProcessRuntimeDriver;
use Illuminate\Database\Eloquent\Collection;

final readonly class StartProcesses
{
    public function __construct(
        private ProcessRuntimeDriverRegistry $runtimeDrivers,
        private RecordProcessEvent $recordProcessEvent,
    ) {}

    /**
     * @return array{data: array<string, mixed>, failed: bool, meta: array<string, mixed>, message: string}
     */
    public function handle(App $app, ?Workspace $workspace, ?string $name): array
    {
        $app->loadMissing('node');

        $processes = $this->processes($app, $name);

        if ($processes->isEmpty()) {
            if ($name !== null) {
                throw new GatewayApiException("Process '{$name}' not found for app '{$app->name}'.", 'process.not_found', [
                    'app' => $app->name,
                    'name' => $name,
                ]);
            }

            throw new GatewayApiException("App '{$app->name}' has no configured processes.", 'process.none_configured', [
                'app' => $app->name,
            ]);
        }

        $runtimes = [];
        $failed = false;
        $started = 0;

        foreach ($this->runtimeTargets($app, $workspace, $processes) as $target) {
            $process = $target['process'];
            $runtimeUnit = $target['runtime_unit'];
            $ok = $app->node !== null && $target['driver']->start($app->node, $runtimeUnit);
            $event = null;

            if ($ok && $app->node !== null) {
                $event = $this->recordProcessEvent->handle(ProcessEventType::Started, $app, $workspace, $process, $app->node, $runtimeUnit);
                $started++;
            }

            $failed = $failed || ! $ok;
            $runtimes[] = [
                'process' => $process->name,
                'app' => $app->name,
                'workspace' => $workspace?->name,
                'runtime_unit' => $runtimeUnit,
                'state' => $ok ? 'running' : 'failed',
                'event' => $event === null ? null : [
                    'id' => $event->id,
                    'type' => $event->event->value,
                ],
                ...($ok ? [] : ['message' => 'The runtime backend reported a start failure.']),
            ];
        }

        return [
            'data' => ['runtimes' => $runtimes],
            'failed' => $failed,
            'message' => 'The runtime unit could not be started.',
            'meta' => [
                'process' => $name,
                'partial_state' => $started === 0 ? 'none_started' : 'partially_started',
            ],
        ];
    }

    /**
     * @return Collection<int, Process>
     */
    private function processes(App $app, ?string $name): Collection
    {
        return $app->processes()
            ->when($name !== null, fn ($query) => $query->where('name', $name))
            ->orderBy('sort_order')
            ->get();
    }

    /**
     * @param  Collection<int, Process>  $processes
     * @return list<array{process: Process, driver: ProcessRuntimeDriver, runtime_unit: string}>
     */
    private function runtimeTargets(App $app, ?Workspace $workspace, Collection $processes): array
    {
        return $processes
            ->map(function (Process $process) use ($app, $workspace): array {
                $driver = $this->runtimeDrivers->forProcess($process);

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
