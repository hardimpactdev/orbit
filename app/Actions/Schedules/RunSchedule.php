<?php

declare(strict_types=1);

namespace App\Actions\Schedules;

use App\Contracts\RemoteShell;
use App\Http\Gateway\GatewayApiException;
use App\Models\Node;
use App\Models\Schedule;
use App\Models\ScheduleRun;
use Carbon\CarbonImmutable;

final readonly class RunSchedule
{
    public function __construct(
        private RemoteShell $remoteShell,
    ) {}

    /**
     * @return array{data: array<string, mixed>, meta: array<string, mixed>}
     */
    public function handle(Schedule $schedule): array
    {
        $schedule->loadMissing(['app.node', 'node']);

        $targetNode = $schedule->scope === 'app' ? $schedule->app?->node : $schedule->node;

        if (! $targetNode instanceof Node) {
            throw new GatewayApiException("Schedule '{$schedule->name}' does not resolve to a target node.", 'validation_failed', [
                'field' => 'target',
                'schedule' => $schedule->name,
            ]);
        }

        $startedAt = CarbonImmutable::now();
        $result = $this->remoteShell->run(
            node: $targetNode,
            script: $schedule->execution_value,
            options: $this->executionOptions($schedule),
        );
        $finishedAt = CarbonImmutable::now();

        $run = ScheduleRun::query()->create([
            'node_id' => $targetNode->id,
            'schedule_key' => $schedule->schedule_key,
            'status' => $result->successful() ? 'completed' : 'failed',
            'exit_code' => $result->exitCode,
            'stdout' => $result->stdout,
            'stderr' => $result->stderr,
            'started_at' => $startedAt,
            'finished_at' => $finishedAt,
        ]);

        $data = [
            'run' => $this->serializeRun($schedule, $run, $targetNode),
            'output' => [
                'stdout' => $run->stdout ?? '',
                'stderr' => $run->stderr ?? '',
            ],
        ];
        $meta = ['duration_ms' => $result->durationMs];

        if (! $result->successful()) {
            throw new GatewayApiException(
                "Schedule '{$schedule->name}' exited with status {$result->exitCode}.",
                'schedule.run_failed',
                $meta,
                errorData: $data,
            );
        }

        return [
            'data' => $data,
            'meta' => $meta,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function executionOptions(Schedule $schedule): array
    {
        if ($schedule->scope !== 'app' || $schedule->app === null) {
            return [];
        }

        return ['cwd' => $schedule->app->path];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeRun(Schedule $schedule, ScheduleRun $run, Node $targetNode): array
    {
        return [
            'id' => $run->id,
            'schedule' => $schedule->name,
            'scope' => $schedule->scope,
            'target' => [
                'type' => $schedule->scope,
                'name' => $schedule->target_name,
                'node' => $targetNode->name,
            ],
            'status' => $run->status,
            'exit_code' => $run->exit_code,
            'started_at' => $run->started_at->toIso8601String(),
            'finished_at' => $run->finished_at?->toIso8601String(),
        ];
    }
}
