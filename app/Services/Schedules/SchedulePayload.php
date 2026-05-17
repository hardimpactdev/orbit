<?php

declare(strict_types=1);

namespace App\Services\Schedules;

use App\Http\Gateway\GatewayApiException;
use App\Models\Node;
use App\Models\Schedule;
use App\Services\Nodes\Roles\NodeRoleAssignments;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class SchedulePayload
{
    /**
     * @return array{schedules: list<array<string, mixed>>, meta: array{app: string|null, node: string|null, count: int}}
     */
    public function list(?string $app, ?string $node, ?Node $caller = null): array
    {
        $this->ensureExclusiveFilters($app, $node);

        $visibleNodeIds = $this->visibleNodeIds($caller);

        if ($caller instanceof Node && ! app(NodeRoleAssignments::class)->nodeIsGateway($caller) && $visibleNodeIds === []) {
            throw new GatewayApiException('This node is not authorized to read schedule intent.', 'authorization_failed', [
                'caller_role' => $caller->role,
            ]);
        }

        $query = $this->visibleSchedules($caller, $visibleNodeIds)
            ->when($app !== null, fn (Builder $query): Builder => $query->where('scope', 'app')->whereHas('app', fn (Builder $query): Builder => $query->where('name', $app)))
            ->when($node !== null, fn (Builder $query): Builder => $query->where('scope', 'node')->whereHas('node', fn (Builder $query): Builder => $query->where('name', $node)))
            ->orderBy('scope')
            ->orderBy('target_name')
            ->orderBy('name');

        $schedules = $query->get()
            ->map(fn (Schedule $schedule): array => $this->serialize($schedule))
            ->values()
            ->all();

        return [
            'schedules' => $schedules,
            'meta' => [
                'app' => $app,
                'node' => $node,
                'count' => count($schedules),
            ],
        ];
    }

    /**
     * @return array{schedule: array<string, mixed>, meta: array{app: string|null, node: string|null}}
     */
    public function show(string $name, ?string $app, ?string $node, ?Node $caller = null): array
    {
        $schedule = $this->find($name, $app, $node, $caller);

        return [
            'schedule' => $this->serialize($schedule),
            'meta' => [
                'app' => $app,
                'node' => $node,
            ],
        ];
    }

    public function find(string $name, ?string $app, ?string $node, ?Node $caller = null): Schedule
    {
        $this->ensureExclusiveFilters($app, $node);

        $visibleNodeIds = $this->visibleNodeIds($caller);

        if ($caller instanceof Node && ! app(NodeRoleAssignments::class)->nodeIsGateway($caller) && $visibleNodeIds === []) {
            throw new GatewayApiException('This node is not authorized to read schedule intent.', 'authorization_failed', [
                'caller_role' => $caller->role,
            ]);
        }

        $schedule = $this->visibleSchedules($caller, $visibleNodeIds)
            ->where('name', $name)
            ->when($app !== null, fn (Builder $query): Builder => $query->where('scope', 'app')->whereHas('app', fn (Builder $query): Builder => $query->where('name', $app)))
            ->when($node !== null, fn (Builder $query): Builder => $query->where('scope', 'node')->whereHas('node', fn (Builder $query): Builder => $query->where('name', $node)))
            ->orderBy('scope')
            ->orderBy('target_name')
            ->first();

        if (! $schedule instanceof Schedule) {
            throw new GatewayApiException("Schedule '{$name}' was not found.", 'schedule.not_found', [
                'name' => $name,
                'app' => $app,
                'node' => $node,
            ]);
        }

        return $schedule;
    }

    private function ensureExclusiveFilters(?string $app, ?string $node): void
    {
        if ($app === null || $node === null) {
            return;
        }

        throw new GatewayApiException('The schedule filters are mutually exclusive.', 'validation_failed', [
            'fields' => ['app', 'node'],
        ]);
    }

    /**
     * @param  list<int>|null  $visibleNodeIds
     * @return Builder<Schedule>
     */
    private function visibleSchedules(?Node $caller, ?array $visibleNodeIds): Builder
    {
        return Schedule::query()
            ->with(['app.node.schedulerState', 'node.schedulerState', 'latestRun'])
            ->when($caller instanceof Node && ! app(NodeRoleAssignments::class)->nodeIsGateway($caller), fn (Builder $query): Builder => $query->where(function (Builder $query) use ($visibleNodeIds): void {
                $query
                    ->whereIn('node_id', $visibleNodeIds ?? [])
                    ->orWhereHas('app', fn (Builder $query): Builder => $query->whereIn('node_id', $visibleNodeIds ?? []));
            }));
    }

    /**
     * @return list<int>|null
     */
    private function visibleNodeIds(?Node $caller): ?array
    {
        if (! $caller instanceof Node || app(NodeRoleAssignments::class)->nodeIsGateway($caller)) {
            return null;
        }

        return DB::table('node_access')
            ->join('nodes', 'nodes.id', '=', 'node_access.serving_node_id')
            ->where('node_access.consumer_node_id', $caller->id)
            ->where(function ($query): void {
                $query
                    ->where('nodes.role', 'gateway')
                    ->orWhereIn('nodes.id', app(NodeRoleAssignments::class)->activeGatewayOrAppHostNodeIds());
            })
            ->where('nodes.status', 'active')
            ->pluck('nodes.id')
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function forSchedule(Schedule $schedule): array
    {
        $schedule->loadMissing(['app.node.schedulerState', 'node.schedulerState', 'latestRun']);

        return $this->serialize($schedule);
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(Schedule $schedule): array
    {
        $targetNode = $schedule->scope === 'app'
            ? $schedule->app?->node
            : $schedule->node;

        return [
            'name' => $schedule->name,
            'scope' => $schedule->scope,
            'target' => [
                'type' => $schedule->scope,
                'name' => $schedule->target_name,
                'node' => $targetNode?->name,
            ],
            'interval' => $schedule->interval,
            'timezone' => $schedule->timezone,
            'execution' => [
                'type' => $schedule->execution_type,
                'value' => $schedule->execution_value,
            ],
            'enabled' => $schedule->enabled,
            'status' => $schedule->status,
            'scheduler' => [
                'node' => $targetNode?->name,
                'heartbeat_at' => $targetNode?->schedulerState?->heartbeat_at?->toIso8601String(),
                'registry_synced_at' => $targetNode?->schedulerState?->registry_synced_at?->toIso8601String(),
            ],
            'last_run' => $schedule->latestRun === null ? null : [
                'id' => $schedule->latestRun->id,
                'status' => $schedule->latestRun->status,
                'exit_code' => $schedule->latestRun->exit_code,
                'started_at' => $schedule->latestRun->started_at->toIso8601String(),
                'finished_at' => $schedule->latestRun->finished_at?->toIso8601String(),
            ],
        ];
    }
}
