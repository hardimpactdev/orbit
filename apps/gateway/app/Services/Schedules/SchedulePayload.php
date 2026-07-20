<?php

declare(strict_types=1);

namespace App\Services\Schedules;

use App\Enums\Nodes\NodeStatus;
use App\Models\Node;
use App\Models\Schedule;
use App\Services\Nodes\Access\NodeAccessAuthorizer;
use App\Services\Nodes\Roles\NodeRoleAssignments;
use Illuminate\Database\Eloquent\Builder;
use Orbit\Sdk\Laravel\GatewayApiException;

class SchedulePayload
{
    /**
     * @return array{schedules: list<array<string, mixed>>, meta: array{instance: string|null, node: string|null, count: int}}
     */
    public function list(?string $instance, ?string $node, ?Node $caller = null): array
    {
        $this->ensureExclusiveFilters($instance, $node);

        $appInstances = app(ScheduleAppInstanceResolver::class);
        $visibleNodeIds = $this->visibleNodeIds($caller, 'schedule:read');
        $visibleInstanceIds = $caller instanceof Node
            ? $appInstances->visibleInstanceIds($caller, 'schedule:read')
            : null;
        $appSelection =
            $instance !== null && $caller instanceof Node
                ? $appInstances->resolve($instance, $caller, 'schedule:read')
                : null;
        $appInstanceId = $appSelection?->instance?->id;

        if (
            $caller instanceof Node
            && ! app(NodeRoleAssignments::class)->nodeIsGateway($caller)
            && $visibleNodeIds === []
        ) {
            throw new GatewayApiException(
                'This node is not authorized to read schedule intent.',
                'authorization_failed',
                [
                    'reason' => 'missing_permission',
                    'missing_permission' => 'schedule:read',
                ],
            );
        }

        $query = $this
            ->visibleSchedules($caller, $visibleNodeIds, $visibleInstanceIds)
            ->when(is_int($appInstanceId), fn (Builder $query): Builder => $query
                ->where('scope', 'app')
                ->where('app_instance_id', $appInstanceId))
            ->when($node !== null, fn (Builder $query): Builder => $query->where(
                'scope',
                'node',
            )->whereHas('node', fn (Builder $query): Builder => $query->where('name', $node)))
            ->orderBy('scope')
            ->orderBy('target_name')
            ->orderBy('name');

        $schedules = array_values(
            $query
                ->get()
                ->map(fn (Schedule $schedule): array => $this->serialize($schedule))
                ->values()
                ->all(),
        );

        return [
            'schedules' => $schedules,
            'meta' => [
                'instance' => $instance,
                'node' => $node,
                'count' => count($schedules),
            ],
        ];
    }

    /**
     * @return array{schedule: array<string, mixed>, meta: array{instance: string|null, node: string|null}}
     */
    public function show(string $name, ?string $instance, ?string $node, ?Node $caller = null): array
    {
        $schedule = $this->find($name, $instance, $node, $caller);

        return [
            'schedule' => $this->serialize($schedule),
            'meta' => [
                'instance' => $instance,
                'node' => $node,
            ],
        ];
    }

    public function find(
        string $name,
        ?string $instance,
        ?string $node,
        ?Node $caller = null,
        string $permission = 'schedule:read',
    ): Schedule {
        $this->ensureExclusiveFilters($instance, $node);

        $appInstances = app(ScheduleAppInstanceResolver::class);
        $visibleNodeIds = $this->visibleNodeIds($caller, $permission);
        $visibleInstanceIds = $caller instanceof Node
            ? $appInstances->visibleInstanceIds($caller, $permission)
            : null;
        $appSelection =
            $instance !== null && $caller instanceof Node
                ? $appInstances->resolve($instance, $caller, $permission)
                : null;
        $appInstanceId = $appSelection?->instance?->id;

        if (
            $caller instanceof Node
            && ! app(NodeRoleAssignments::class)->nodeIsGateway($caller)
            && $visibleNodeIds === []
        ) {
            throw new GatewayApiException(
                'This node is not authorized to read schedule intent.',
                'authorization_failed',
                [
                    'reason' => 'missing_permission',
                    'missing_permission' => $permission,
                ],
            );
        }

        $schedules = $this
            ->visibleSchedules($caller, $visibleNodeIds, $visibleInstanceIds)
            ->where('name', $name)
            ->when(is_int($appInstanceId), fn (Builder $query): Builder => $query
                ->where('scope', 'app')
                ->where('app_instance_id', $appInstanceId))
            ->when($node !== null, fn (Builder $query): Builder => $query->where(
                'scope',
                'node',
            )->whereHas('node', fn (Builder $query): Builder => $query->where('name', $node)))
            ->orderBy('scope')
            ->orderBy('target_name')
            ->limit(2)
            ->get();

        $schedule = $schedules->first();

        if (! $schedule instanceof Schedule) {
            throw new GatewayApiException("Schedule '{$name}' was not found.", 'schedule.not_found', [
                'name' => $name,
                'instance' => $instance,
                'node' => $node,
            ]);
        }

        if ($schedules->count() > 1) {
            throw new GatewayApiException(
                "Schedule '{$name}' matches more than one visible target.",
                'validation_failed',
                [
                    'field' => 'name',
                    'reason' => 'schedule_selector_ambiguous',
                    'name' => $name,
                    'targets' => $schedules->pluck('target_name')->values()->all(),
                ],
            );
        }

        return $schedule;
    }

    private function ensureExclusiveFilters(?string $instance, ?string $node): void
    {
        if ($instance === null || $node === null) {
            return;
        }

        throw new GatewayApiException('The schedule filters are mutually exclusive.', 'validation_failed', [
            'fields' => ['instance', 'node'],
        ]);
    }

    /**
     * @param  list<int>|null  $visibleNodeIds
     * @param  list<int>|null  $visibleInstanceIds
     * @return Builder<Schedule>
     */
    private function visibleSchedules(
        ?Node $caller,
        ?array $visibleNodeIds,
        ?array $visibleInstanceIds,
    ): Builder {
        $canSeeOrbitSchedules =
            $visibleNodeIds !== null && array_intersect($visibleNodeIds, $this->gatewayNodeIds()) !== [];

        return Schedule::query()
            ->with(['app', 'appInstance', 'node', 'latestRun'])
            ->when(
                $caller instanceof Node && ! app(NodeRoleAssignments::class)->nodeIsGateway($caller),
                fn (Builder $query): Builder => $query->where(function (Builder $query) use (
                    $visibleNodeIds,
                    $visibleInstanceIds,
                    $canSeeOrbitSchedules,
                ): void {
                    $query
                        ->whereIn('node_id', $visibleNodeIds ?? [])
                        ->orWhereIn('app_instance_id', $visibleInstanceIds ?? []);

                    if ($canSeeOrbitSchedules) {
                        $query->orWhere('scope', 'orbit');
                    }
                }),
            );
    }

    /**
     * @return list<int>|null
     */
    private function visibleNodeIds(?Node $caller, string $permission): ?array
    {
        if (! $caller instanceof Node || app(NodeRoleAssignments::class)->nodeIsGateway($caller)) {
            return null;
        }

        $authorizer = app(NodeAccessAuthorizer::class);
        $nodes = Node::query()
            ->where('status', NodeStatus::Active->value)
            ->where(function (Builder $query): void {
                $query
                    ->whereIn('id', app(NodeRoleAssignments::class)->activeGatewayOrAppHostNodeIds());
            })
            ->get();

        $visibleNodeIds = [];

        foreach ($nodes as $node) {
            if ($authorizer->allows($caller, $node, $permission)) {
                $visibleNodeIds[] = $node->id;
            }
        }

        return $visibleNodeIds;
    }

    /**
     * @return list<int>
     */
    private function gatewayNodeIds(): array
    {
        return app(NodeRoleAssignments::class)
            ->activeGatewayNodeQuery()
            ->pluck('id')
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function forSchedule(Schedule $schedule): array
    {
        $schedule->loadMissing(['app', 'appInstance', 'node', 'latestRun']);

        return $this->serialize($schedule);
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(Schedule $schedule): array
    {
        $gatewayNode = $this->gatewayNode();
        $targetNode = match ($schedule->scope) {
            'app' => app(ScheduleAppInstanceResolver::class)->targetNode($schedule),
            'node' => $schedule->node,
            'orbit' => $gatewayNode,
            default => null,
        };

        return [
            'name' => $schedule->name,
            'scope' => $schedule->scope === 'app' ? 'instance' : $schedule->scope,
            'target' => [
                'type' => $schedule->scope === 'app' ? 'instance' : $schedule->scope,
                'name' => $schedule->target_name,
                'node' => $targetNode?->name,
            ],
            'interval' => $schedule->interval,
            'timezone' => $schedule->timezone,
            'execution' => [
                'type' => $schedule->execution_type,
                'value' => $schedule->execution_value,
                'timeout_seconds' => $schedule->timeout_seconds,
            ],
            'enabled' => $schedule->enabled,
            'status' => $schedule->status,
            'scheduler' => [
                'node' => $gatewayNode?->name,
                'heartbeat_at' => $gatewayNode?->schedulerState?->heartbeat_at?->toIso8601String(),
                'registry_synced_at' => $gatewayNode?->schedulerState?->registry_synced_at?->toIso8601String(),
            ],
            'last_run' => $schedule->latestRun === null
                ? null
                : [
                    'id' => $schedule->latestRun->id,
                    'status' => $schedule->latestRun->status,
                    'exit_code' => $schedule->latestRun->exit_code,
                    'started_at' => $schedule->latestRun->started_at->toIso8601String(),
                    'finished_at' => $schedule->latestRun->finished_at?->toIso8601String(),
                ],
        ];
    }

    private function gatewayNode(): ?Node
    {
        return app(NodeRoleAssignments::class)
            ->activeGatewayNodeQuery()
            ->with('schedulerState')
            ->first();
    }
}
