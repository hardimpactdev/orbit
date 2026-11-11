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
    /** A source names a relocation: its target publishes the role and the source withdraws it.
     * @return list<array<string,mixed>>
     */
    public function publications(Node $node, RoleName $role, bool $machineDeleted = false, ?Node $source = null): array
    {
        $duties = app(NodeRolePublicationInput::class)->capture($node, $role, $source);
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
            if ($machineDeleted && $id === $node->id) {
                continue;
            }
            $member = Node::query()->findOrFail($id);
            $snapshot = app(NativeRoutePublicationInput::class)->snapshot($member, $sites);
            // Removal publishes a complete Caddyfile, not a sparse Route section delta.
            $caddy = $snapshot['caddy'];
            if (is_array($caddy)) {
                unset($caddy['blocks']);
            }
            $result[] = ['node_id' => $id, ...app(NativeCaddyPublicationInput::class)->connectionForNode($member),
                'caddy' => in_array($id, $duties['caddy_node_ids'], true) ? $caddy : null,
                'php' => in_array($id, $duties['php_node_ids'], true) ? $snapshot['php'] : null,
                'dns' => in_array($id, $duties['dns_node_ids'], true) ? $snapshot['dns'] : null];
        }

        return $result;
    }

    /** The caller claims its existing role before capture. No Operation or host effect is admitted here.
     * @param  list<array<string,mixed>>  $before
     * @return array<string,mixed>
     */
    public function capture(Node $node, NodeRole $assignment, array $before, bool $machineDeleted = false, ?NodeRole $desiredAssignment = null, bool $purgeData = false): array
    {
        if ($assignment->node_id !== $node->id || $assignment->status !== LifecycleStatus::Removing
            || ! app(RoleRegistry::class)->definition($assignment->role)->mutable) {
            throw new ResourceOperationException('operation.execution_forbidden', 'Removal requires its claimed mutable role assignment.', 422);
        }
        if ($desiredAssignment !== null && (! $desiredAssignment->exists || $desiredAssignment->id !== $assignment->id
            || $desiredAssignment->node_id === $node->id || $desiredAssignment->role !== $assignment->role
            || ! NodeRole::query()->whereKey($desiredAssignment->id)->where('node_id', $desiredAssignment->node_id)
                ->where('role', $assignment->role)->whereIn('status', [LifecycleStatus::Active, LifecycleStatus::Provisioning])->exists())) {
            throw new ResourceOperationException('operation.execution_forbidden', 'Source cleanup requires the actual transferred role assignment.', 422);
        }
        $plan = ['firewall' => app(NodeRoleFirewallInput::class)->remove($node, $assignment->role),
            'runtime' => app(NodeRoleRuntimeInput::class)->removal($node, $assignment->role),
            'certificates' => app(NodeRoleCertificateInput::class)->capture($node, $assignment->role, removing: true)];
        $ssh = $machineDeleted ? null : app(SwampSshExecutionInput::class)->forNode($node);
        $firewall = [];
        $wanted = array_column(RouteExecutionProof::objects(ValidatedData::object($plan['firewall'])['remove']), 'comment');
        foreach (app(NodeFirewallRuleCatalog::class)->ownedByRole($node, $assignment->role) as $rule) {
            if (in_array($rule->shape->comment, $wanted, true)) {
                $firewall[] = ['ssh' => $ssh, 'input' => ['argv' => $rule->removalArguments()]];
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
                $containers = ['orbit-metrics-prometheus', 'orbit-metrics-grafana', 'orbit-metrics-prometheus-orbit-rollback', 'orbit-metrics-grafana-orbit-rollback'];
            } elseif ($assignment->role === RoleName::WebSocket) {
                $units[] = 'orbit-websocket.service';
            }
        }
        if ($assignment->role === RoleName::Gateway) {
            $units[] = 'orbit-gateway-dns.service';
            if (app(GatewayPrivateDnsInput::class)->requiresLocalListener($node)
                && ! $node->roles()->where('role', RoleName::Vpn)->exists()) {
                $units = [...$units, 'orbit-private-dns.socket', 'orbit-private-dns.service'];
            }
        }
        $files = [];
        $directories = [];
        $reloads = $units === [] ? [] : [['ssh' => $ssh, 'input' => []]];
        if ($assignment->role === RoleName::Metrics) {
            $catalog = app(NodeFirewallRuleCatalog::class);
            $gateway = app(MetricsGatewayResolver::class)->resolve();
            foreach ([$catalog->metricsGrafanaUpstream($node, ValidatedData::string($gateway->wireguard_ip)), $catalog->metricsGrafanaIsolation($node)] as $rule) {
                $firewall[] = ['ssh' => $ssh, 'input' => ['argv' => $rule->removalArguments()]];
            }
            foreach (app(MetricsExporterProjection::class)->for($node) as $member) {
                if (! $member->selection->selected) {
                    continue;
                }
                $target = $member->node;
                $connection = app(SwampSshExecutionInput::class)->forNode($target);
                if ($desiredAssignment === null) {
                    foreach (['prometheus-node-exporter.service', 'orbit-cadvisor.service', 'orbit-fpm-exporter.service'] as $unit) {
                        $unitsForFleet[] = ['ssh' => $connection, 'input' => ['unit' => $unit]];
                    }
                    $paths = NodeRemovalResources::footprints($connection);
                    $files = [...$files, ...$paths['files']];
                    $directories = [...$directories, ...$paths['directories']];
                    $reloads[] = ['ssh' => $connection, 'input' => []];
                }
                $catalog = app(NodeFirewallRuleCatalog::class);
                $rules = [$catalog->metricsExporter($target, $node), $catalog->metricsCadvisor($target, $node)];
                foreach (['caddy', 'fpm'] as $kind) {
                    foreach ($desiredAssignment === null ? [true, false] : [true] as $allow) {
                        $rules[] = $catalog->metricsService($target, $node, $kind, $allow);
                    }
                }
                foreach ($rules as $rule) {
                    $firewall[] = ['ssh' => $connection, 'input' => ['argv' => $rule->removalArguments()]];
                }
            }
        }
        $commands = [];
        if ($assignment->role === RoleName::Gateway) {
            $files[] = ['ssh' => $ssh, 'path' => '/etc/systemd/system/wg-quick@orbit.service.d/orbit-gateway-dns.conf'];
            $reloads[] = ['ssh' => $ssh];
            $commands[] = ['ssh' => $ssh, 'command' => 'timeout 60 resolvectl revert orbit',
                'only_if' => 'test ! -e /etc/wireguard/orbit.dns-link && ip link show dev orbit >/dev/null 2>&1'];
        }
        if ($assignment->role === RoleName::Metrics) {
            foreach (['/etc/orbit/metrics/prometheus.yml', '/etc/orbit/metrics/grafana/grafana.ini',
                '/etc/orbit/metrics/grafana/provisioning/datasources/prometheus.yml',
                '/etc/orbit/metrics/grafana/provisioning/dashboards/provider.yml',
                '/etc/orbit/metrics/grafana/dashboards/orbit-node-resources.json',
                '/etc/orbit/metrics/grafana/dashboards/orbit-caddy.json', '/etc/orbit/metrics/grafana/dashboards/orbit-fpm.json',
                '/etc/orbit/metrics/grafana/admin-password', '/etc/orbit/metrics/.orbit-owner'] as $path) {
                $files[] = ['ssh' => $ssh, 'path' => $path];
            }
            if ($purgeData) {
                foreach (['orbit-metrics-prometheus-data', 'orbit-metrics-grafana-data'] as $volume) {
                    $commands[] = ['ssh' => $ssh, 'command' => 'timeout 60 docker volume rm -- '.$volume,
                        'only_if' => 'docker volume inspect '.$volume.' >/dev/null 2>&1'];
                }
            }
        }
        if ($assignment->role === RoleName::WebSocket && $purgeData && is_array($runtime)) {
            $path = ValidatedData::string($runtime['install_path']);
            $files[] = ['ssh' => $ssh, 'path' => $path];
            $directories[] = ['ssh' => $ssh, 'path' => $path.'.releases'];
        }
        if ($assignment->role === RoleName::CiRunner && $purgeData) {
            foreach (['project' => 'orbit-ci', 'network' => 'orbitci0', 'network acl' => 'orbit-ci-egress'] as $type => $name) {
                $commands[] = ['ssh' => $ssh, 'command' => 'timeout 60 incus '.$type.' delete '.$name,
                    'only_if' => 'incus '.$type.' show '.$name.' >/dev/null 2>&1'];
            }
        }
        $certificates = [];
        foreach (RouteExecutionProof::objects(ValidatedData::object($plan['certificates'])['withdrawals']) as $entry) {
            if ($machineDeleted && $entry['node_id'] === $node->id) {
                continue;
            }
            $certificates[] = ['ssh' => app(SwampSshExecutionInput::class)->forNode(Node::query()->findOrFail(ValidatedData::integer($entry['node_id']))), 'path' => '/etc/caddy/orbit-'.str_replace('role-', '', ValidatedData::string($entry['scope'])).'-cert-current'];
        }
        $publications = [];
        foreach ($this->publications($node, $assignment->role, $machineDeleted) as $after) {
            $prior = array_find($before, static fn (array $member): bool => $member['node_id'] === $after['node_id']);
            if ($prior === null) {
                throw new \LogicException('Role removal needs its complete prior publication.');
            }
            $publications[] = ['node_id' => $after['node_id'], 'control_node_id' => SwampNode::id(),
                'host_keys' => $after['host_keys'], 'connection' => $after['connection'],
                'before' => array_intersect_key($prior, array_flip(['caddy', 'php', 'dns'])),
                'after' => array_intersect_key($after, array_flip(['caddy', 'php', 'dns']))];
        }

        $input = ['schema_version' => 1, 'node_id' => $node->id, 'assignment_id' => $assignment->id,
            'role' => $assignment->role->value, 'ssh' => $ssh, 'publications' => $publications,
            'public_ssh' => $node->roles()->whereKeyNot($assignment->id)->exists() ? [] : [['ssh' => $ssh,
                'input' => ['argv' => ['ufw', ...array_slice(app(NodeFirewallRuleCatalog::class)->publicSshRecovery($node)->arguments, 2)]]]],
            'stop_units' => [...$unitsForFleet, ...array_map(static fn (string $unit): array => ['ssh' => $ssh, 'input' => ['unit' => $unit]], $units)],
            'files' => [...$files, ...array_map(static fn (string $unit): array => ['ssh' => $ssh, 'path' => '/etc/systemd/system/'.$unit], $units)],
            'commands' => $commands, 'directories' => $directories, 'reload_units' => $reloads,
            'remove_units' => array_map(static fn (string $unit): array => ['ssh' => $ssh, 'input' => ['unit' => $unit]], $units),
            'stop_containers' => array_map(static fn (string $name): array => ['ssh' => $ssh, 'input' => ['name' => $name, 'action' => 'stop']], $containers),
            'remove_containers' => array_map(static fn (string $name): array => ['ssh' => $ssh, 'input' => ['name' => $name, 'action' => 'rm']], $containers),
            'firewall' => $firewall, 'certificates' => $certificates,
            'ci_host' => $assignment->role === RoleName::CiRunner ? [['ssh' => $ssh, 'input' => []]] : []];
        foreach (['public_ssh', 'stop_units', 'files', 'directories', 'commands', 'reload_units', 'remove_units', 'stop_containers', 'remove_containers', 'firewall', 'ci_host'] as $field) {
            $input[$field] = array_values(array_filter($input[$field], static fn (array $action): bool => $action['ssh'] !== null));
        }

        return $input;
    }
}
