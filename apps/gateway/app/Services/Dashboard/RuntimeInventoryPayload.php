<?php

declare(strict_types=1);

namespace App\Services\Dashboard;

use App\Enums\Nodes\NodeStatus;
use App\Models\App;
use App\Models\Node;
use App\Models\NodeTool;
use App\Models\Process;
use App\Models\Workspace;
use App\Services\Apps\AppResponsePayload;
use App\Services\Nodes\Access\NodeAccessAuthorizer;
use App\Services\Nodes\Roles\NodeRoleAssignmentPayload;
use App\Services\Nodes\Roles\NodeRoleAssignments;
use App\Services\Tools\ToolPayloadMapper;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

final readonly class RuntimeInventoryPayload
{
    public function __construct(
        private NodeRoleAssignments $nodeRoleAssignments,
        private NodeAccessAuthorizer $authorizer,
        private AppResponsePayload $appPayload,
        private ToolPayloadMapper $toolPayload,
    ) {}

    /**
     * @return array{
     *     nodes: list<array<string, mixed>>,
     *     apps: list<array<string, mixed>>,
     *     processes: list<array<string, mixed>>,
     *     tools: list<array<string, mixed>>,
     * }
     */
    public function forCaller(Node $caller): array
    {
        return [
            'nodes' => $this->nodePayloads($this->fetchNodes()),
            'apps' => $this
                ->fetchApps($caller)
                ->map(fn (App $app): array => $this->appPayload->forApp($app))
                ->all(),
            'processes' => $this->processPayloads($this->fetchProcesses($caller)),
            'tools' => $this
                ->fetchTools($caller)
                ->map(fn (NodeTool $tool): array => $this->toolPayload->toArray($tool))
                ->all(),
        ];
    }

    /**
     * @return Collection<int, Node>
     */
    private function fetchNodes(): Collection
    {
        return Node::query()
            ->with('roleAssignments')
            ->get()
            ->sortBy(fn (Node $node): array => [
                $this->nodeRoleAssignments->assignmentRoleLabel($node),
                mb_strtolower($node->name),
            ])
            ->values();
    }

    /**
     * @return Collection<int, App>
     */
    private function fetchApps(Node $caller): Collection
    {
        $visibleNodeIds = $this->visibleNodeIds(
            $caller,
            'app:read',
            $this->nodeRoleAssignments->activeAppHostNodeIds(),
        );

        return App::query()
            ->with(['node', 'dependencyAuditSummaries'])
            ->when($visibleNodeIds !== null, static fn (Builder $query): Builder => $query->whereIn(
                'node_id',
                $visibleNodeIds,
            ))
            ->get()
            ->sortBy(static fn (App $app): array => [
                mb_strtolower((string) $app->node?->name),
                mb_strtolower($app->name),
            ])
            ->values();
    }

    /**
     * @return Collection<int, Process>
     */
    private function fetchProcesses(Node $caller): Collection
    {
        $visibleNodeIds = $this->visibleNodeIds(
            $caller,
            'process:read',
            $this->nodeRoleAssignments->activeRoleBearingNodeIds(),
        );

        return Process::query()
            ->with(['node', 'owner'])
            ->when($visibleNodeIds !== null, static fn (Builder $query): Builder => $query->whereIn(
                'node_id',
                $visibleNodeIds,
            ))
            ->get()
            ->sortBy(static fn (Process $process): array => [
                mb_strtolower((string) $process->node?->name),
                mb_strtolower((string) $process->app?->name),
                $process->sort_order,
                mb_strtolower($process->name),
            ])
            ->values();
    }

    /**
     * @return Collection<int, NodeTool>
     */
    private function fetchTools(Node $caller): Collection
    {
        $candidateNodeIds = array_values(array_unique([
            ...$this->nodeRoleAssignments->activeToolHostNodeIds(),
            ...$this->nodeRoleAssignments->activeMetricsExporterNodeIds(),
        ]));
        $visibleNodeIds = $this->visibleNodeIds($caller, 'tool:read', $candidateNodeIds) ?? $candidateNodeIds;

        return NodeTool::query()
            ->with('node')
            ->whereIn('node_id', $visibleNodeIds)
            ->get()
            ->sortBy(static fn (NodeTool $tool): array => [
                mb_strtolower((string) $tool->node?->name),
                mb_strtolower($tool->name),
            ])
            ->values();
    }

    /**
     * @param  list<int>  $candidateNodeIds
     * @return list<int>|null
     */
    private function visibleNodeIds(Node $caller, string $permission, array $candidateNodeIds): ?array
    {
        if ($this->nodeRoleAssignments->nodeIsGateway($caller)) {
            return null;
        }

        return Node::query()
            ->where('status', NodeStatus::Active->value)
            ->whereIn('id', $candidateNodeIds)
            ->get()
            ->filter(fn (Node $node): bool => $this->authorizer->allows($caller, $node, $permission))
            ->map(static fn (Node $node): int => $node->id)
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, Node>  $nodes
     * @return list<array<string, mixed>>
     */
    private function nodePayloads(Collection $nodes): array
    {
        return $nodes->map(static fn (Node $node): array => [
            'name' => $node->name,
            'host' => $node->host,
            'addresses' => [
                'wireguard' => $node->wireguard_address,
            ],
            'platform' => $node->platform ?? 'unknown',
            'status' => $node->status->value,
            'roles' => $node
                ->roleAssignments
                ->map(NodeRoleAssignmentPayload::fromModel(...))
                ->all(),
        ])->all();
    }

    /**
     * @param  Collection<int, Process>  $processes
     * @return list<array<string, mixed>>
     */
    private function processPayloads(Collection $processes): array
    {
        return $processes->map(static function (Process $process): array {
            $app = $process->app;
            $workspace = $process->owner instanceof Workspace ? $process->owner : null;

            return [
                'node' => $process->node?->name,
                'app' => $app?->name,
                'workspace' => $workspace?->name,
                'name' => $process->name,
                'command' => $process->command,
                'restart_policy' => $process->restart_policy->value,
                'crash_notification' => $process->crash_notification->value,
                'runtime' => $process->runtime->value,
                'tool' => $process->tool,
                'status' => 'managed',
            ];
        })->all();
    }
}
