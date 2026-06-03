<?php

declare(strict_types=1);

namespace App\Actions\Processes;

use App\Enums\ProcessEventType;
use App\Http\Gateway\GatewayApiException;
use App\Models\App;
use App\Models\Process;
use App\Models\Workspace;
use App\Services\Processes\ProcessRuntimeDriverRegistry;
use Illuminate\Database\Eloquent\Collection;

final readonly class StopProcesses
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
        $stopped = 0;

        foreach ($processes as $process) {
            $driver = $this->runtimeDrivers->for($process->runtime);
            $runtimeUnit = $driver->runtimeUnitName($app, $process, $workspace);
            $ok = $app->node !== null && $driver->stop($app->node, $runtimeUnit);
            $event = null;

            if ($ok && $app->node !== null) {
                $event = $this->recordProcessEvent->handle(ProcessEventType::Stopped, $app, $workspace, $process, $app->node, $runtimeUnit);
                $stopped++;
            }

            $failed = $failed || ! $ok;
            $runtimes[] = [
                'process' => $process->name,
                'app' => $app->name,
                'workspace' => $workspace?->name,
                'runtime_unit' => $runtimeUnit,
                'state' => $ok ? 'stopped' : 'failed',
                'event' => $event === null ? null : [
                    'id' => $event->id,
                    'type' => $event->event->value,
                ],
                ...($ok ? [] : ['message' => 'The runtime backend reported a stop failure.']),
            ];
        }

        return [
            'data' => ['runtimes' => $runtimes],
            'failed' => $failed,
            'message' => 'The runtime unit could not be stopped.',
            'meta' => [
                'process' => $name,
                'partial_state' => $stopped === 0 ? 'none_stopped' : 'partially_stopped',
            ],
        ];
    }

    /**
     * @return Collection<int, Process>
     */
    private function processes(App $app, ?string $name): Collection
    {
        return Process::query()
            ->where('app_id', $app->id)
            ->when($name !== null, fn ($query) => $query->where('name', $name))
            ->orderBy('sort_order')
            ->get();
    }
}
