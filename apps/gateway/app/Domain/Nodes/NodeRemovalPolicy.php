<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Domain\Routes\RouteRemovalGuard;
use App\Domain\Schedules\ScheduleTargetUseGuard;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\TaskVms\TaskVmException;
use App\Domain\TaskVms\TaskVmSettings;
use App\Models\DatabaseServer;
use App\Models\Node;
use App\Models\Route;
use App\Models\RouteCustomProxy;
use App\Models\TaskVm;
use App\Models\WebSocketRuntime;

/** Pure resource guards shared by admission and the final Node deletion proof. */
final readonly class NodeRemovalPolicy
{
    public function __construct(private ScheduleTargetUseGuard $schedules, private RouteRemovalGuard $routes) {}

    public function protected(Node $node, Node $caller): void
    {
        $this->schedules->assertNodeRemovable($node);
        if ($node->instances()->exists()) {
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

    public function resources(Node $node, bool $offline): void
    {
        try {
            $this->routes->assertNodeRemovable($node);
        } catch (ResourceOperationException $error) {
            // Active Route refusal remains mandatory. Offline residue needs its nonactive source relations.
            if (! $offline || $error->errorCode !== 'node.has_routes' || RouteCustomProxy::query()->where('node_id', $node->id)->exists()) {
                throw $error;
            }
        }
        if (! $offline && $node->roles()->exists()) {
            throw $this->conflict('node.has_roles', 'Remove the Node roles, or confirm an unreachable Node with --offline --force.');
        }
        if ($node->firewallRules()->exists()) {
            throw $this->conflict('node.has_firewall_rules', 'The Node still owns firewall rules.');
        }
        if (! $offline && $node->processes()->exists()) {
            throw $this->conflict('node.has_processes', 'The Node still owns Processes.');
        }
        if (! $offline && WebSocketRuntime::query()->where('node_id', $node->id)->where('required', true)->exists()) {
            throw $this->conflict('websocket.runtime_shutdown_required', 'Stop every Reverb process or confirm an unreachable Node with --offline --force.');
        }
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
