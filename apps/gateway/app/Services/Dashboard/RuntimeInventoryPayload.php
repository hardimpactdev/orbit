<?php

declare(strict_types=1);

namespace App\Services\Dashboard;

use App\Enums\Nodes\NodeStatus;
use App\Models\Node;
use App\Models\NodeTool;
use App\Models\Process;
use App\Models\Project;
use App\Models\Workspace;
use App\Services\Apps\AppInstancePayloads;
use App\Services\Apps\AppResponsePayload;
use App\Services\Apps\AppShowVisibility;
use App\Services\Nodes\Access\NodeAccessAuthorizer;
use App\Services\Nodes\Roles\NodeRoleAssignmentPayload;
use App\Services\Nodes\Roles\NodeRoleAssignments;
use App\Services\Tools\ToolPayloadMapper;
use Illuminate\Support\Collection;

final readonly class RuntimeInventoryPayload
{
    public function __construct(
        private NodeRoleAssignments $nodeRoleAssignments,
        private NodeAccessAuthorizer $authorizer,
        private AppResponsePayload $appPayload,
        private AppShowVisibility $appVisibility,
        private ToolPayloadMapper $toolPayload,
    ) {}

    /**
     * @return array{
     *     nodes: list<array<string, mixed>>,
     *     projects: list<array<string, mixed>>,
     *     processes: list<array<string, mixed>>,
     *     tools: list<array<string, mixed>>,
     * }
     */
    public function forCaller(Node $caller): array
    {
        return [
            'nodes' => $this->nodePayloads($this->fetchNodes()),
            'projects' => $this->projectPayloads($caller),
            'processes' => $this->processPayloads($this->fetchProcesses($caller)),
            'tools' => array_values(
                $this
                    ->fetchTools($caller)
                    ->map(fn (NodeTool $tool): array => $this->toolPayload->toArray($tool))
                    ->all(),
            ),
        ];
    }

    /**
     * @return Collection<int, Node>
     */
    private function fetchNodes(): Collection
    {
        $nodes = Node::query()
            ->with('roleAssignments')
            ->get()
            ->sortBy(fn (Node $node): array => [
                $this->nodeRoleAssignments->assignmentRoleLabel($node),
                mb_strtolower($node->name),
            ])
            ->values();

        /** @var Collection<int, Node> $nodes */
        return $nodes;
    }

    /**
     * @return Collection<int, Project>
     */
    private function fetchApps(Node $caller): Collection
    {
        /** @var Collection<int, Project> $apps */
        $apps = Project::query()
            ->with(['instances', 'dependencyAuditSummaries'])
            ->get();

        /** @mago-expect lint:inline-variable-return */
        $visibleApps = $apps
            ->filter(fn (Project $app): bool => $this->appVisibility->visibleInstances($app, $caller) !== [])
            ->sortBy(static fn (Project $app): string => mb_strtolower($app->name))
            ->values();

        /** @var Collection<int, Project> $visibleApps */
        return $visibleApps;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function projectPayloads(Node $caller): array
    {
        $instancePayloads = app(AppInstancePayloads::class);

        return array_values(
            $this->fetchApps($caller)->map(function (Project $app) use ($caller, $instancePayloads): array {
                $instances = array_map(
                    $instancePayloads->placement(...),
                    $this->appVisibility->visibleInstances($app, $caller),
                );

                return [
                    ...$this->appPayload->forApp($app),
                    'instances' => $instances,
                ];
            })->all(),
        );
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

        $query = Process::query()->with(['node', 'owner']);

        if ($visibleNodeIds !== null) {
            $query->whereIn('node_id', $visibleNodeIds);
        }

        $processes = $query
            ->get()
            ->sortBy(static fn (Process $process): array => [
                mb_strtolower((string) $process->node?->name),
                mb_strtolower((string) $process->app?->name),
                $process->sort_order,
                mb_strtolower($process->name),
            ])
            ->values();

        /** @var Collection<int, Process> $processes */
        return $processes;
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

        $tools = NodeTool::query()
            ->with('node')
            ->whereIn('node_id', $visibleNodeIds)
            ->get();

        /** @var Collection<int, NodeTool> $tools */
        $sortedTools = $tools
            ->sortBy(static fn (NodeTool $tool): array => [
                mb_strtolower((string) $tool->node?->name),
                mb_strtolower($tool->name),
            ])
            ->values();

        /** @var Collection<int, NodeTool> $sortedTools */
        return $sortedTools;
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

        $nodes = Node::query()
            ->where('status', NodeStatus::Active->value)
            ->whereIn('id', $candidateNodeIds)
            ->get();

        /** @var Collection<int, Node> $nodes */
        $nodeIds = $nodes
            ->filter(fn (Node $node): bool => $this->authorizer->allows($caller, $node, $permission))
            ->map(static fn (Node $node): int => $node->id)
            ->values()
            ->all();

        return array_values($nodeIds);
    }

    /**
     * @param  Collection<int, Node>  $nodes
     * @return list<array<string, mixed>>
     */
    private function nodePayloads(Collection $nodes): array
    {
        return array_values(
            $nodes->map(static fn (Node $node): array => [
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
            ])->all(),
        );
    }

    /**
     * @param  Collection<int, Process>  $processes
     * @return list<array<string, mixed>>
     */
    private function processPayloads(Collection $processes): array
    {
        return array_values(
            $processes->map(static function (Process $process): array {
                $app = $process->app;
                $workspace = $process->owner instanceof Workspace ? $process->owner : null;

                return [
                    'node' => $process->node?->name,
                    'project' => $app?->name,
                    'workspace' => $workspace?->name,
                    'name' => $process->name,
                    'command' => $process->command,
                    'restart_policy' => $process->restart_policy->value,
                    'crash_notification' => $process->crash_notification->value,
                    'runtime' => $process->runtime->value,
                    'tool' => $process->tool,
                    'status' => 'managed',
                ];
            })->all(),
        );
    }
}
