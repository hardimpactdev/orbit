<?php

declare(strict_types=1);

namespace App\Actions\Processes;

use App\Contracts\RemoteShell;
use App\Enums\Processes\ProcessRuntime;
use App\Enums\ProcessEventType;
use App\Http\Gateway\GatewayApiException;
use App\Models\App;
use App\Models\Process;
use App\Models\Workspace;
use App\Services\Processes\ProcessDockerContainerRenderer;
use App\Services\Processes\ProcessDockerRuntimeManager;
use App\Services\Processes\SupervisorProgramRenderer;
use Illuminate\Database\Eloquent\Collection;

final readonly class StopProcesses
{
    public function __construct(
        private RemoteShell $remoteShell,
        private SupervisorProgramRenderer $renderer,
        private ProcessDockerContainerRenderer $dockerRenderer,
        private ProcessDockerRuntimeManager $dockerManager,
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
            $runtimeUnit = $this->resolveRuntimeUnit($app, $process, $workspace);
            $ok = $app->node !== null && $this->stopRuntimeUnit($app, $process, $workspace, $runtimeUnit);
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

    private function resolveRuntimeUnit(App $app, Process $process, ?Workspace $workspace): string
    {
        if ($process->runtime === ProcessRuntime::Docker) {
            return $this->dockerRenderer->containerName($app, $process, $workspace);
        }

        return $this->renderer->programName($app, $process, $workspace);
    }

    private function stopRuntimeUnit(App $app, Process $process, ?Workspace $workspace, string $runtimeUnit): bool
    {
        if ($process->runtime === ProcessRuntime::Docker) {
            return $this->dockerManager->stop($app->node, $runtimeUnit);
        }

        return $this->remoteShell->run($app->node, 'sudo supervisorctl stop '.escapeshellarg($runtimeUnit))->successful();
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
