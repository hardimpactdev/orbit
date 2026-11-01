<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Domain\Metrics\MetricsExporterProjection;
use App\Domain\Metrics\MetricsGatewayResolver;
use App\Domain\Routes\NativeCaddyPublicationInput;
use App\Domain\Routes\NativeRoutePublicationInput;
use App\Domain\Routes\RouteExecutionProof;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AppDev\DevelopmentSiteRepository;
use App\Infrastructure\Caddy\Build\CaddySiteRoles;
use App\Infrastructure\Firewall\NodeFirewallRuleCatalog;
use App\Infrastructure\Nodes\NodeRoleFirewallInput;
use App\Infrastructure\Swamp\SwampSshExecutionInput;
use App\Models\Node;
use App\Models\NodeRole;
use App\Support\ValidatedData;

/** Reusable public contract for node-role-remove; role policy stays in the canonical capture. */
final readonly class NativeNodeRoleRemovalInput
{
    /** @return list<array<string,mixed>> */
    public function publications(Node $node, RoleName $role): array
    {
        $duties = app(NodeRolePublicationInput::class)->capture($node, $role);
        if ($role === RoleName::Metrics) {
            // The canonical fleet capture owns its membership. Disable both service
            // publication kinds as well as the Gateway site when Metrics leaves.
            foreach (app(MetricsExporterProjection::class)->for($node) as $member) {
                if (! $member->selection->selected) {
                    continue;
                }
                $target = $member->node;
                if (CaddySiteRoles::nodeExpectsCaddy($target->id)) {
                    $duties['caddy_node_ids'][] = $target->id;
                }
                if ($target->roles()->where('role', RoleName::AppProd)->exists()) {
                    $duties['php_node_ids'][] = $target->id;
                }
            }
        }
        $sites = app(DevelopmentSiteRepository::class)->all();
        $result = [];
        foreach (NodeRolePublicationInput::participants($duties) as $id) {
            $member = Node::query()->findOrFail($id);
            $snapshot = app(NativeRoutePublicationInput::class)->snapshot($member, $sites);
            $result[] = ['node_id' => $id, ...app(NativeCaddyPublicationInput::class)->connectionForNode($member),
                'caddy' => in_array($id, $duties['caddy_node_ids'], true) ? $snapshot['caddy'] : null,
                'php' => in_array($id, $duties['php_node_ids'], true) ? $snapshot['php'] : null,
                'dns' => in_array($id, $duties['dns_node_ids'], true) ? $snapshot['dns'] : null];
        }

        return $result;
    }

    /** The caller claims its existing role before capture. No Operation or host effect is admitted here.
     * @param  list<array<string,mixed>>  $before
     * @return array<string,mixed>
     */
    public function capture(Node $node, NodeRole $assignment, array $before): array
    {
        if ($assignment->node_id !== $node->id || $assignment->status !== LifecycleStatus::Removing
            || ! app(RoleRegistry::class)->definition($assignment->role)->mutable) {
            throw new ResourceOperationException('operation.execution_forbidden', 'Removal requires its claimed mutable role assignment.', 422);
        }
        $plan = ['firewall' => app(NodeRoleFirewallInput::class)->remove($node, $assignment->role),
            'runtime' => app(NodeRoleRuntimeInput::class)->removal($node, $assignment->role),
            'certificates' => app(NodeRoleCertificateInput::class)->capture($node, $assignment->role, removing: true)];
        $ssh = app(SwampSshExecutionInput::class)->forNode($node);
        $firewall = [];
        $wanted = array_column(RouteExecutionProof::objects(ValidatedData::object($plan['firewall'])['remove']), 'comment');
        foreach (app(NodeFirewallRuleCatalog::class)->ownedByRole($node, $assignment->role) as $rule) {
            if (in_array($rule->shape->comment, $wanted, true)) {
                $firewall[] = ['ssh' => $ssh, 'input' => ['argv' => ['ufw', '--force', 'delete', ...array_slice($rule->arguments, 2)]]];
            }
        }
        $unitsForFleet = [];
        $containers = [];
        $units = [];
        $runtime = $plan['runtime'];
        if (is_array($runtime)) {
            if ($assignment->role === RoleName::Analytics) {
                $containers[] = ValidatedData::string($runtime['name']);
            } elseif ($assignment->role === RoleName::Metrics) {
                $containers = ['orbit-metrics-prometheus', 'orbit-metrics-grafana'];
            } elseif ($assignment->role === RoleName::WebSocket) {
                $units[] = 'orbit-websocket.service';
            }
        }
        $footprints = [];
        $reloads = $units === [] ? [] : [['ssh' => $ssh, 'input' => []]];
        if ($assignment->role === RoleName::Metrics) {
            $catalog = app(NodeFirewallRuleCatalog::class);
            $gateway = app(MetricsGatewayResolver::class)->resolve();
            foreach ([$catalog->metricsGrafanaUpstream($node, ValidatedData::string($gateway->wireguard_ip)), $catalog->metricsGrafanaIsolation($node)] as $rule) {
                $firewall[] = ['ssh' => $ssh, 'input' => ['argv' => ['ufw', '--force', 'delete', ...array_slice($rule->arguments, 2)]]];
            }
            foreach (app(MetricsExporterProjection::class)->for($node) as $member) {
                if (! $member->selection->selected) {
                    continue;
                }
                $target = $member->node;
                $connection = app(SwampSshExecutionInput::class)->forNode($target);
                foreach (['prometheus-node-exporter.service', 'orbit-cadvisor.service', 'orbit-fpm-exporter.service'] as $unit) {
                    $unitsForFleet[] = ['ssh' => $connection, 'input' => ['unit' => $unit]];
                }
                $footprints[] = ['ssh' => $connection, 'input' => ['node_id' => $target->id, 'group' => 'exporter']];
                $reloads[] = ['ssh' => $connection, 'input' => []];
                $catalog = app(NodeFirewallRuleCatalog::class);
                $rules = [$catalog->metricsExporter($target, $node), $catalog->metricsCadvisor($target, $node)];
                foreach (['caddy', 'fpm'] as $kind) {
                    foreach ([true, false] as $allow) {
                        $rules[] = $catalog->metricsService($target, $node, $kind, $allow);
                    }
                }
                foreach ($rules as $rule) {
                    $firewall[] = ['ssh' => $connection, 'input' => ['argv' => ['ufw', '--force', 'delete', ...array_slice($rule->arguments, 2)]]];
                }
            }
        }
        $certificates = [];
        foreach (RouteExecutionProof::objects(ValidatedData::object($plan['certificates'])['withdrawals']) as $entry) {
            $certificates[] = ['ssh' => app(SwampSshExecutionInput::class)->forNode(Node::query()->findOrFail(ValidatedData::integer($entry['node_id']))), 'input' => ['scope' => $entry['scope']]];
        }
        $publications = [];
        foreach ($this->publications($node, $assignment->role) as $after) {
            $prior = array_find($before, static fn (array $member): bool => $member['node_id'] === $after['node_id']);
            if ($prior === null) {
                throw new \LogicException('Role removal needs its complete prior publication.');
            }
            $publications[] = ['node_id' => $after['node_id'], 'control_node_id' => 209,
                'host_keys' => $after['host_keys'], 'connection' => $after['connection'],
                'before' => array_intersect_key($prior, array_flip(['caddy', 'php', 'dns'])),
                'after' => array_intersect_key($after, array_flip(['caddy', 'php', 'dns']))];
        }

        return ['schema_version' => 1, 'node_id' => $node->id, 'assignment_id' => $assignment->id,
            'role' => $assignment->role->value, 'ssh' => $ssh, 'publications' => $publications,
            'stop_units' => [...$unitsForFleet, ...array_map(static fn (string $unit): array => ['ssh' => $ssh, 'input' => ['unit' => $unit]], $units)],
            'remove_footprints' => $footprints, 'reload_units' => $reloads,
            'remove_units' => array_map(static fn (string $unit): array => ['ssh' => $ssh, 'input' => ['unit' => $unit]], $units),
            'stop_containers' => array_map(static fn (string $name): array => ['ssh' => $ssh, 'input' => ['name' => $name, 'action' => 'stop']], $containers),
            'remove_containers' => array_map(static fn (string $name): array => ['ssh' => $ssh, 'input' => ['name' => $name, 'action' => 'rm']], $containers),
            'firewall' => $firewall, 'certificates' => $certificates,
            'ci_host' => $assignment->role === RoleName::CiRunner ? [['ssh' => $ssh, 'input' => []]] : []];
    }
}
