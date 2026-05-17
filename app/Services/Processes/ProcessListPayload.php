<?php

declare(strict_types=1);

namespace App\Services\Processes;

use App\Http\Gateway\GatewayApiException;
use App\Models\App;
use App\Models\Node;
use App\Models\Process;
use App\Models\ProcessEvent;
use App\Models\Workspace;
use App\Services\Nodes\Roles\NodeRoleAssignments;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class ProcessListPayload
{
    public function __construct(
        private readonly NodeRoleAssignments $nodeRoleAssignments,
    ) {}

    /**
     * @return array{context: array{app: string|null, workspace: string|null}, processes: list<array<string, mixed>>}
     */
    public function forContext(?string $appName, ?string $workspaceName, ?Node $caller = null): array
    {
        $visibleNodeIds = $this->visibleAppNodeIds($caller);

        if ($caller instanceof Node && $caller->role !== 'gateway' && $visibleNodeIds === []) {
            throw new GatewayApiException('This node is not authorized to read process intent.', 'authorization_failed', [
                'caller_role' => $caller->role,
            ]);
        }

        $context = $this->resolveContext($appName, $workspaceName, $caller, $visibleNodeIds);
        $app = $context['app'];
        $workspace = $context['workspace'];

        $app->loadMissing('processes');

        return [
            'context' => [
                'app' => $app->name,
                'workspace' => $workspace?->name,
            ],
            'processes' => $app->processes
                ->map(fn (Process $process): array => [
                    'name' => $process->name,
                    'command' => $process->command,
                    'restart_policy' => $process->restart_policy->value,
                    'crash_notification' => $process->crash_notification->value,
                    'runtime_unit' => $this->runtimeUnit($app, $process, $workspace),
                    'last_event' => $this->lastEvent($process, $workspace),
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * @param  list<int>|null  $visibleNodeIds
     * @return array{app: App, workspace: Workspace|null}
     */
    private function resolveContext(?string $appName, ?string $workspaceName, ?Node $caller, ?array $visibleNodeIds): array
    {
        if ($workspaceName !== null) {
            $workspace = $this->resolveWorkspace($workspaceName, $appName, $caller, $visibleNodeIds);
            $workspace->loadMissing('app');

            $app = $workspace->app;

            if (! $app instanceof App) {
                throw new GatewayApiException("Workspace '{$workspaceName}' is not attached to an app.", 'validation_failed', [
                    'field' => 'workspace',
                    'value' => $workspaceName,
                ]);
            }

            return [
                'app' => $app,
                'workspace' => $workspace,
            ];
        }

        if ($appName !== null) {
            return [
                'app' => $this->resolveApp($appName, $caller, $visibleNodeIds),
                'workspace' => null,
            ];
        }

        $apps = $this->visibleApps($caller, $visibleNodeIds)->get();

        if ($apps->count() === 1) {
            return [
                'app' => $apps->firstOrFail(),
                'workspace' => null,
            ];
        }

        throw new GatewayApiException('An app context is required.', 'validation_failed', [
            'field' => 'app',
        ]);
    }

    /**
     * @param  list<int>|null  $visibleNodeIds
     */
    private function resolveApp(string $appName, ?Node $caller, ?array $visibleNodeIds): App
    {
        $app = $this->visibleApps($caller, $visibleNodeIds)
            ->where('name', $appName)
            ->first();

        if (! $app instanceof App) {
            throw new GatewayApiException("App '{$appName}' not found or not visible.", 'validation_failed', [
                'field' => 'app',
                'value' => $appName,
            ]);
        }

        return $app;
    }

    /**
     * @param  list<int>|null  $visibleNodeIds
     */
    private function resolveWorkspace(string $workspaceName, ?string $appName, ?Node $caller, ?array $visibleNodeIds): Workspace
    {
        $matches = Workspace::query()
            ->with('app')
            ->where('name', $workspaceName)
            ->when($appName !== null, fn (Builder $query): Builder => $query->whereHas('app', fn (Builder $query): Builder => $query->where('name', $appName)))
            ->when($caller instanceof Node && $caller->role !== 'gateway', fn (Builder $query): Builder => $query->whereHas('app', fn (Builder $query): Builder => $query->whereIn('node_id', $visibleNodeIds ?? [])))
            ->get();

        if ($matches->isEmpty()) {
            throw new GatewayApiException("Workspace '{$workspaceName}' not found or not visible.", 'validation_failed', [
                'field' => 'workspace',
                'value' => $workspaceName,
            ]);
        }

        if ($appName === null && $matches->count() > 1) {
            throw new GatewayApiException("Workspace name '{$workspaceName}' is ambiguous.", 'validation_failed', [
                'field' => 'workspace',
                'value' => $workspaceName,
                'apps' => $matches->map(fn (Workspace $workspace): ?string => $workspace->app?->name)->filter()->values()->all(),
            ]);
        }

        return $matches->firstOrFail();
    }

    /**
     * @param  list<int>|null  $visibleNodeIds
     * @return Builder<App>
     */
    private function visibleApps(?Node $caller, ?array $visibleNodeIds): Builder
    {
        return App::query()
            ->with('processes')
            ->when($caller instanceof Node && $caller->role !== 'gateway', fn (Builder $query): Builder => $query->whereIn('node_id', $visibleNodeIds ?? []));
    }

    /**
     * @return list<int>|null
     */
    private function visibleAppNodeIds(?Node $caller): ?array
    {
        if (! $caller instanceof Node || $caller->role === 'gateway') {
            return null;
        }

        $visibleNodeIds = array_values(array_unique([
            ...$this->nodeRoleAssignments->activeNodeIdsForRole('app-development'),
            ...$this->nodeRoleAssignments->activeNodeIdsForRole('app-production'),
        ]));

        return DB::table('node_access')
            ->where('node_access.consumer_node_id', $caller->id)
            ->whereIn('node_access.serving_node_id', $visibleNodeIds)
            ->pluck('node_access.serving_node_id')
            ->map(fn (mixed $nodeId): int => (int) $nodeId)
            ->all();
    }

    private function runtimeUnit(App $app, Process $process, ?Workspace $workspace): string
    {
        $scope = $workspace instanceof Workspace ? $workspace->name : 'main';

        return "orbit_{$app->name}_{$scope}_{$process->name}";
    }

    /**
     * @return array{id: int, type: string}|null
     */
    private function lastEvent(Process $process, ?Workspace $workspace): ?array
    {
        $event = ProcessEvent::query()
            ->where('process_id', $process->id)
            ->when(
                $workspace instanceof Workspace,
                fn (Builder $query): Builder => $query->where('workspace_id', $workspace->id),
                fn (Builder $query): Builder => $query->whereNull('workspace_id'),
            )
            ->latest('recorded_at')
            ->latest('id')
            ->first();

        if (! $event instanceof ProcessEvent) {
            return null;
        }

        return [
            'id' => $event->id,
            'type' => $event->event->value,
        ];
    }
}
