<?php

declare(strict_types=1);

namespace App\Actions\Doctor\Intent;

use App\Domain\DatabaseConnections\DatabaseConnectionEnvProjection;
use App\Domain\DatabaseConnections\DatabaseDriver;
use App\Domain\DatabaseServers\DatabaseServerProcess;
use App\Domain\DatabaseServers\PostgresTestDatabases;
use App\Domain\Firewall\RouterLanIngressPolicy;
use App\Domain\Instances\InstanceState;
use App\Domain\Nodes\ManagedUserAccount;
use App\Domain\Nodes\RoleName;
use App\Domain\Nodes\RoleRegistry;
use App\Domain\Processes\DesiredProcessState;
use App\Domain\WireGuard\VpnSettings;
use App\Infrastructure\AppDev\DevelopmentDnsConfigRenderer;
use App\Infrastructure\AppDev\DevelopmentPhpFpmConfigRenderer;
use App\Infrastructure\AppDev\DevelopmentSite;
use App\Infrastructure\AppDev\DevelopmentSiteRepository;
use App\Infrastructure\Caddy\Build\CaddySite;
use App\Infrastructure\Caddy\Build\NodeCaddyfileRenderer;
use App\Infrastructure\Firewall\NodeFirewallRuleCatalog;
use App\Infrastructure\Firewall\UfwManagedRule;
use App\Infrastructure\Gateway\GatewayPrivateDnsResolver;
use App\Infrastructure\Nodes\NodeBootstrapPackageCatalog;
use App\Infrastructure\Nodes\NodeRoleServiceCatalog;
use App\Infrastructure\Nodes\Roles\NodeRoleConvergeLock;
use App\Infrastructure\Processes\DockerProcessRenderer;
use App\Infrastructure\WireGuard\VpnConfigurationRepository;
use App\Infrastructure\WireGuard\WireGuardServerConfigRenderer;
use App\Models\DatabaseConnection;
use App\Models\DatabaseConnectionTarget;
use App\Models\DatabaseServer;
use App\Models\InstanceEnvironmentValue;
use App\Models\Node;
use App\Models\NodeRole;
use Throwable;

/** Safe stored intent; native Swamp owns every live Doctor observation. */
final readonly class InfrastructureDoctorIntent
{
    /** @return array<string, list<array<string, mixed>>> */
    public function execute(Node $node): array
    {
        $roles = NodeRole::query()->where('node_id', $node->id)->orderBy('id')->get();
        $registry = new RoleRegistry;
        $fleet = NodeRole::query()->orderBy('id')->get();
        $roleRows = [];
        foreach ($roles as $role) {
            $definition = $registry->definition($role->role);
            $row = [
                'id' => $role->id, 'name' => $role->role->value, 'role' => $role->role->value,
                'status' => $role->status->value, 'stale_claim' => $role->isStaleClaim(),
                'claim_held' => app(NodeRoleConvergeLock::class)->isHeld($node),
                'singleton' => $definition->singleton,
                'fleet_ids' => $fleet->where('role', $role->role)->modelKeys(),
                'conflicts' => array_map(static fn (RoleName $name): string => $name->value,
                    array_values(array_filter($registry->names(), static fn (RoleName $name): bool => $registry->conflicts($role->role, $name)))),
                'cluster_id' => $role->cluster_id, 'node_cluster_id' => $node->cluster_id,
                'cluster_ingress_ids' => $fleet->filter(static fn (NodeRole $item): bool => $item->role === RoleName::Ingress && $item->status->value === 'active' && $item->cluster_id === $role->cluster_id)->modelKeys(),
            ];
            try {
                $row['packages'] = app(NodeBootstrapPackageCatalog::class)->forRole($node, $role->role);
                $row['services'] = app(NodeRoleServiceCatalog::class)->forRole($role->role);
                $row['firewall'] = [
                    'expected' => array_map(static fn (UfwManagedRule $rule): array => $rule->shape->toAgentInput(), app(NodeFirewallRuleCatalog::class)->forRole($node, $role->role)),
                    'exclusive_families' => $role->role === RoleName::Router ? array_keys(RouterLanIngressPolicy::Families) : [],
                    'stored' => false,
                ];
                if ($role->role === RoleName::Gateway) {
                    $settings = app(VpnSettings::class);
                    $resolver = new GatewayPrivateDnsResolver;
                    $address = $resolver->vpnDnsAddress($settings);
                    if ($address !== null) {
                        $row['private_dns'] = ['address' => $address, 'domain' => $settings->domain(), 'drop_in' => $resolver->dropIn($address, $settings->domain())];
                    }
                }
                if ($role->role === RoleName::Vpn) {
                    $configuration = app(VpnConfigurationRepository::class)->forPeer($node);
                    $row['vpn'] = [
                        'config_sha256' => hash('sha256', app(WireGuardServerConfigRenderer::class)->render($configuration, Node::query()->whereNotNull('wireguard_public_key')->get())),
                        'dns_records_sha256' => hash('sha256', app(DevelopmentDnsConfigRenderer::class)->render()),
                    ];
                }
            } catch (Throwable) {
                $row['intent_failed'] = true;
            }
            $roleRows[] = $row;
        }
        $shared = [];
        if ($roles->contains(static fn (NodeRole $role): bool => $role->status->value === 'active')) {
            try {
                $build = app(NodeCaddyfileRenderer::class)->render($node);
                $shared['caddy'] = [
                    'needed' => $build->sites !== [] || $roles->contains(static fn (NodeRole $role): bool => in_array($role->role->value, ['gateway', 'router', 'ingress', 'app-dev', 'app-prod', 'websocket', 'analytics'], true)),
                    'sha256' => hash('sha256', $build->content), 'version' => $build->version, 'buildable' => $build->buildable(),
                    'sources' => array_values(array_unique(array_map(static fn (CaddySite $site): string => $site->source, $build->sites))),
                ];
            } catch (Throwable) {
                $shared['caddy'] = ['failed' => true];
            }
            if ($roles->contains(static fn (NodeRole $role): bool => $role->status->value === 'active' && in_array($role->role, [RoleName::AppDev, RoleName::AppProd], true))) {
                try {
                    $sites = app(DevelopmentSiteRepository::class)->forNode($node)->filter(static fn (DevelopmentSite $site): bool => $site->phpVersion !== null && ! $site->isProxy() && ! $site->usesDedicatedPhpRuntime());
                    $configurations = [];
                    if ($sites->isNotEmpty()) {
                        $account = new ManagedUserAccount($node->user, 'orbit-doctor-group', '/__orbit_doctor_home__');
                        foreach ($sites->groupBy('phpVersion') as $version => $versionSites) {
                            $configurations[$version] = app(DevelopmentPhpFpmConfigRenderer::class)->render($versionSites->values(), $account);
                        }
                    }
                    $shared['php'] = ['configurations' => (object) $configurations];
                } catch (Throwable) {
                    $shared['php'] = ['failed' => true];
                }
            }
        }
        $attachments = DatabaseConnectionTarget::query()->with(['databaseConnection', 'instance'])->whereHas('instance', static fn ($q) => $q->where('node_id', $node->id)->where('status', '!=', InstanceState::Removing))->orderBy('id')->get();
        $connections = [];
        foreach ($attachments as $attachment) {
            $connections[] = $this->connection($attachment->databaseConnection, $attachment);
        }
        foreach (DatabaseConnection::query()->where('node_id', $node->id)->whereNotIn('id', $attachments->pluck('database_connection_id'))->orderBy('id')->get() as $connection) {
            $connections[] = $this->connection($connection);
        }
        $servers = DatabaseServer::query()->where('node_id', $node->id)->whereHas('process', static fn ($q) => $q->where('desired_state', DesiredProcessState::Running))
            ->where(static fn ($q) => $q->where('driver', DatabaseDriver::Redis)->orWhere('private_endpoints_managed', true)->orWhere(static fn ($q) => $q->where('driver', DatabaseDriver::Pgsql)->where(static fn ($q) => $q->whereHas('databaseConnections.users')->orWhereHas('databaseConnections', static fn ($q) => $q->whereNotNull('test_database')))))
            ->with(['node', 'process', 'databaseConnections.users'])->orderBy('id')->get();
        $serverRows = $servers->map(static fn (DatabaseServer $server): array => [
            'id' => $server->id, 'name' => $server->slug, 'driver' => $server->driver->value,
            'host' => $server->node->wireguard_ip, 'port' => $server->port, 'default_open' => $server->default_open,
            'container' => $server->process !== null ? new DockerProcessRenderer()->containerName($server->process) : null,
            'private_endpoints_managed' => $server->private_endpoints_managed,
            'published_ports' => $server->process?->runtime_config['ports'] ?? [],
            'expected_ports' => DatabaseServerProcess::publishedPorts($server->node, $server->port, $server->driver->defaultPort() ?? 3306),
            'initialization_secret_present' => $server->process !== null && DatabaseServerProcess::holdsRootPassword($server->process),
            'connections' => $server->databaseConnections->map(static fn (DatabaseConnection $connection): array => [
                'name' => $connection->slug, 'test_databases' => new PostgresTestDatabases()->recordedNames($connection),
                'users' => $connection->users->map(static fn ($user): array => ['username' => $user->username, 'replication' => $user->replication])->all(),
            ])->all(),
        ])->all();

        return ['role_checks' => $roleRows, 'role_shared' => $shared === [] ? [] : [$shared], 'database_connections' => $connections, 'database_servers' => array_values($serverRows)];
    }

    /** @return array<string, mixed> */
    private function connection(?DatabaseConnection $connection, ?DatabaseConnectionTarget $attachment = null): array
    {
        $row = ['id' => $attachment !== null ? 'attachment:'.$attachment->id : 'connection:'.$connection?->id,
            'name' => ($connection === null ? 'missing' : $connection->slug).($attachment !== null ? ':'.$attachment->prefix : ''), 'missing' => $connection === null];
        if ($connection === null) {
            return $row;
        }
        $row += ['driver' => $connection->driver->value, 'node_exists' => $connection->node_id === null || Node::query()->whereKey($connection->node_id)->exists(),
            'host' => $connection->host, 'port' => $connection->port, 'database' => $connection->database, 'path' => $connection->path, 'username' => $connection->username];
        try {
            $row['password_present'] = is_string($connection->password);
            if ($attachment !== null) {
                $projection = app(DatabaseConnectionEnvProjection::class)->project($connection, $attachment->prefix, $attachment->network, $attachment->instance->node);
                $stored = InstanceEnvironmentValue::query()->where('instance_id', $attachment->instance_id)->orderBy('env_key')->get()->mapWithKeys(static fn (InstanceEnvironmentValue $value): array => [$value->env_key => $value->env_value])->all();
                $row['env_expected_keys'] = array_keys($projection['values']);
                $row['env_forget_keys'] = $projection['forget'];
                $row['env_present_keys'] = array_keys($stored);
                $row['env_equal_keys'] = array_keys(array_filter($projection['values'], static fn (string $value, string $key): bool => isset($stored[$key]) && $stored[$key] === $value, ARRAY_FILTER_USE_BOTH));
            }
        } catch (Throwable) {
            $row['intent_failed'] = true;
        }

        return $row;
    }
}
