<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Domain\Routes\RouteRemovalGuard;
use App\Domain\Schedules\ScheduleTargetUseGuard;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\TaskableType;
use App\Domain\TaskVms\TaskVmException;
use App\Domain\TaskVms\TaskVmSettings;
use App\Models\DatabaseServer;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Route;
use App\Models\TaskVm;
use App\Models\WebSocketRuntime;

/** Pure resource guards shared by admission and the final Node deletion proof. */
final readonly class NodeRemovalPolicy
{
    public function __construct(private ScheduleTargetUseGuard $schedules, private RouteRemovalGuard $routes) {}

    public function protected(Node $node, Node $caller, ?Instance $removingWorkspace = null): void
    {
        if ($node->id === 209) {
            throw $this->conflict('node.control_removal_forbidden', 'The Swamp control Node cannot remove its own runtime.');
        }
        $this->schedules->assertNodeRemovable($node);
        if ($removingWorkspace !== null) {
            $this->workspaceRouteIds($node, $removingWorkspace);
        }
        $instances = $node->instances();
        if ($removingWorkspace !== null) {
            $instances->whereKeyNot($removingWorkspace->id);
        }
        if ($instances->exists()) {
            throw $this->conflict('node.has_instances', 'The Node still owns Instances.');
        }
        if (DatabaseServer::query()->where('node_id', $node->id)->exists()) {
            throw $this->conflict('node.has_database_servers', 'Remove the Node Database servers first.');
        }
        if (TaskVm::query()->live()->where('host_node_id', $node->id)->exists()) {
            throw $this->conflict('node.has_task_vms', 'The Node still hosts live task VMs.');
        }
        try {
            $host = app(TaskVmSettings::class)->hasHost($node->id);
        } catch (TaskVmException) {
            $host = false;
        }
        if ($host) {
            throw $this->conflict('node.task_vm_host_configured', 'Drain task VMs and clear the Node task VM host setting first.');
        }
        if ($node->is($caller)) {
            throw $this->conflict('node.self_removal_forbidden', 'A Node cannot remove itself.');
        }
        foreach ([RoleName::Gateway, RoleName::Vpn] as $role) {
            if ($node->roles()->where('role', $role)->exists()) {
                throw $this->conflict('node.'.$role->value.'_removal_forbidden', 'The '.$role->value.' Node cannot be removed.');
            }
        }
    }

    public function resources(Node $node, bool $offline, ?Instance $removingWorkspace = null): void
    {
        $this->routes->assertNodeRemovable($node, $removingWorkspace === null ? [] : $this->workspaceRouteIds($node, $removingWorkspace));
        if ($node->firewallRules()->exists()) {
            throw $this->conflict('node.has_firewall_rules', 'The Node still owns firewall rules.');
        }
        $roleProcesses = [];
        foreach ($node->roles as $assignment) {
            $dependencies = app(NodeRoleDependencyInspector::class)->inspect($node, $assignment->role);
            $roleProcesses = [...$roleProcesses, ...$dependencies->processIds];
            $runtime = app(NodeRoleRuntimeInput::class)->removal($node, $assignment->role);
            if (is_array($runtime) && isset($runtime['process_id'])) {
                $roleProcesses[] = $runtime['process_id'];
            }
            if (! $offline && ($dependencies->instanceIds !== [] || $dependencies->workspaceIds !== [])) {
                throw $this->conflict('node.has_role_dependents', 'Remove role-owned Instances and workspaces first.');
            }
        }
        if (! $offline && $node->processes()->whereNotIn('id', $roleProcesses)->exists()) {
            throw $this->conflict('node.has_processes', 'The Node still owns Processes.');
        }
        if (! $offline && ! $node->roles()->where('role', RoleName::WebSocket)->exists()
            && WebSocketRuntime::query()->where('node_id', $node->id)->where('required', true)->exists()) {
            throw $this->conflict('websocket.runtime_shutdown_required', 'Stop every Reverb process or confirm an unreachable Node with --offline --force.');
        }
    }

    /** Only the exact group-owned workspace and its exclusive Project Routes may leave with this VM.
     * @return list<int>
     */
    public function workspaceRouteIds(Node $node, Instance $workspace): array
    {
        $vm = TaskVm::forNode($node->id);
        $group = $vm?->group;
        if ($group === null || ! $group->isTopLevel() || $workspace->node_id !== $node->id
            || $workspace->task_workspace_owner_id !== $group->id || $workspace->project_id !== $group->project_id
            || ($group->taskable_id !== null && ($group->taskable_type !== TaskableType::Instance || $group->taskable_id !== $workspace->id))) {
            throw $this->conflict('task_vm.workspace_mismatch', 'Cleanup requires the exact workspace owned by this VM group and Project.');
        }
        $routes = $workspace->routes()->with('targets')->get();
        if ($routes->count() > 1) {
            throw $this->conflict('task_vm.workspace_mismatch', 'Cleanup requires at most one exclusive workspace Route.');
        }
        foreach ($routes as $route) {
            if (! $workspace->task_workspace_routed || $route->project_id !== $workspace->project_id
                || $route->targets->count() !== 1 || $route->targets->sole()->instance_id !== $workspace->id
                || $route->customProxy()->exists() || $route->hasPlacementTransition()
                || $route->replaces_route_id !== null || $route->replaced_by_route_id !== null) {
                throw $this->conflict('task_vm.workspace_mismatch', 'Cleanup refuses shared, foreign or transitioning workspace Routes.');
            }
        }

        return array_values($routes->map(static fn (Route $route): int => $route->id)->all());
    }

    /** @return list<array<string,mixed>> */
    public function routeRows(Node $node): array
    {
        $rows = Route::query()->where(static fn ($query) => $query->where('node_id', $node->id)->orWhere('generation_basis_node_id', $node->id)
            ->orWhere('transition_node_id', $node->id)->orWhereHas('targets.instance', static fn ($query) => $query->where('node_id', $node->id)))
            ->orderBy('id')->lockForUpdate()->get()->map(static fn (Route $route): array => $route->attributesToArray())->all();

        return array_values($rows);
    }

    private function conflict(string $code, string $message): ResourceOperationException
    {
        return new ResourceOperationException($code, $message, 409);
    }
}
