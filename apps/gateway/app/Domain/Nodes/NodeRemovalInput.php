<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Domain\Metrics\MetricsFleetCapture;
use App\Domain\Routes\NativeCaddyPublicationInput;
use App\Domain\Routes\NativeRoutePublicationInput;
use App\Domain\Routes\PrivateDnsPublicationNodes;
use App\Domain\Routes\RouteExecutionProof;
use App\Domain\Routes\RouteRemovalGuard;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Firewall\NodeFirewallRuleCatalog;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Infrastructure\Swamp\SwampSshExecutionInput;
use App\Infrastructure\WireGuard\PeerDnsPolicy;
use App\Models\Node;
use App\Support\ValidatedData;

/** Full immutable public intent. Nothing is read from the Gateway by an executing step. */
final readonly class NodeRemovalInput
{
    /** @param array<string,mixed> $snapshot
     * @return array<string,mixed>
     */
    public function capture(array $snapshot): array
    {
        $node = Node::query()->findOrFail(ValidatedData::integer($snapshot['node_id']));
        $ssh = app(SwampSshExecutionInput::class);
        $nativeRoles = app(NativeNodeRoleRemovalInput::class);
        $machineDeleted = ($snapshot['machine_deleted'] ?? false) === true;
        $roles = $node->roles()->orderBy('id')->lockForUpdate()->get();
        $before = [];
        foreach ($roles as $role) {
            if (! $machineDeleted && in_array($role->status, [LifecycleStatus::Provisioning, LifecycleStatus::Removing], true)) {
                throw new ResourceOperationException('node.role_busy', 'Wait for the active role operation before removing this Node.', 409);
            }
            app(RouteRemovalGuard::class)->assertRoleRemovable($node, $role->role);
            $before[$role->id] = $nativeRoles->publications($node, $role->role, $machineDeleted);
        }
        foreach ($roles as $role) {
            $role->claimRemoval();
        }
        $node->unsetRelation('roles');
        $roleIntents = [];
        foreach ($roles as $role) {
            $roleIntents[] = $nativeRoles->capture($node, $role->refresh(), $before[$role->id], $machineDeleted);
        }
        $target = null;
        if (! $machineDeleted && (! $snapshot['offline'] || $roles->isNotEmpty() || (is_string($node->ssh_host_key) && $node->ssh_host_key !== ''))) {
            $target = $ssh->forNode($node);
        }
        if (! is_string($node->wireguard_ip) || $node->wireguard_ip === '') {
            throw new ResourceOperationException('swamp.ssh_target_unavailable', 'Removal reachability requires its recorded WireGuard address.', 503);
        }
        $dns = [];
        foreach (PrivateDnsPublicationNodes::query()->get() as $member) {
            $dns[] = ['node_id' => $member->id, ...app(NativeCaddyPublicationInput::class)->connectionForNode($member),
                'before' => app(NativeRoutePublicationInput::class)->dns($member)];
        }
        $routerBefore = [];
        foreach (Node::query()->whereHas('roles', static fn ($q) => $q->where('role', RoleName::Router)->where('status', LifecycleStatus::Active))->get() as $router) {
            $routerBefore[$router->id] = app(NodeFirewallRuleCatalog::class)->routerLanIngress($router);
        }
        // Desired membership is recorded in this admission transaction; no runtime callback is needed.
        app(NodeRemovalRecords::class)->begin($snapshot);
        foreach ($dns as &$publication) {
            $publication['after'] = app(NativeRoutePublicationInput::class)->dns(Node::query()->findOrFail($publication['node_id']));
            $publication['alternatives'] = [];
        }
        unset($publication);
        $peers = [];
        $policy = app(PeerDnsPolicy::class);
        $beforeDomains = $policy->domains($node);
        $afterDomains = $policy->domains($node, true);
        if ($beforeDomains !== $afterDomains) {
            foreach (Node::query()->with('roles')->whereKeyNot($node->id)->where('status', LifecycleStatus::Active)->get() as $member) {
                if ($policy->manages($member)) {
                    $peers[] = ['ssh' => $ssh->forNode($member), 'dns_server' => $policy->server($member),
                        'public_key' => $member->wireguard_public_key, 'domains' => $afterDomains];
                }
            }
        }
        $hub = [];
        if ($node->wireguard_public_key !== null) {
            $owner = Node::query()->whereHas('roles', static fn ($q) => $q->where('role', RoleName::Vpn))->sole();
            $hub[] = ['ssh' => $ssh->forNode($owner), 'input' => ['public_key' => $node->wireguard_public_key]];
        }
        $routerRules = [];
        foreach ($routerBefore as $id => $prior) {
            $member = Node::query()->findOrFail($id);
            $after = app(NodeFirewallRuleCatalog::class)->routerLanIngress($member);
            foreach ($prior as $rule) {
                if (! array_any($after, static fn ($desired): bool => $desired->shape->matches($rule->shape))) {
                    $routerRules[] = ['ssh' => $ssh->forNode($member), 'input' => ['argv' => ['ufw', '--force', 'delete', ...array_slice($rule->arguments, 2)]]];
                }
            }
        }
        $publicSsh = app(NodeFirewallRuleCatalog::class)->publicSshRecovery($node);
        $retained = app(NodeSideResidue::class)->describe(array_values($roles->map(static fn ($role): RoleName => $role->role)->all()), nodeLeavesFleet: true);
        $participants = [209, $node->id, ...array_column($dns, 'node_id')];
        foreach ([...$peers, ...$hub, ...$routerRules] as $row) {
            $participants[] = Node::query()->where('wireguard_ip', $row['ssh']['target']['host'])->sole()->id;
        }

        $metrics = app(MetricsFleetCapture::class)->captureRetiring($node);
        $metricsFiles = [];
        $metricsReload = [];
        if ($metrics !== null && $metrics['metrics_node_id'] !== $node->id) {
            $owner = Node::query()->findOrFail(ValidatedData::integer($metrics['metrics_node_id']));
            foreach (ValidatedData::stringMap($metrics['runtime_files']) as $path => $content) {
                $metricsFiles[] = ['ssh' => $ssh->forNode($owner), 'input' => ['path' => $path, 'content' => $content,
                    'mode' => '0644', 'owner' => 'root:root', 'marker' => null]];
            }
            $metricsReload[] = ['ssh' => $ssh->forNode($owner)];
            $participants[] = $owner->id;
        }
        foreach ($roleIntents as $roleIntent) {
            foreach (RouteExecutionProof::objects($roleIntent['publications']) as $publication) {
                $participants[] = ValidatedData::integer($publication['node_id']);
            }
        }

        return ['snapshot' => $snapshot, 'participant_node_ids' => array_values(array_unique($participants)),
            'intent' => ['schema_version' => 1, 'node_id' => $node->id, 'name' => $node->name,
                'offline' => $snapshot['offline'], 'machine_deleted' => $machineDeleted, 'platform' => $node->platform,
                'probe' => ['host' => $node->wireguard_ip, 'port' => 22], 'ssh' => $target,
                'roles' => $roleIntents, 'dns' => $dns, 'peers' => $peers, 'hub' => $hub, 'router_rules' => $routerRules,
                'public_ssh' => ['argv' => ['ufw', ...array_slice($publicSsh->arguments, 2)]],
                'managed_keys' => array_values(array_unique([$ssh->identity()['public_key'], trim(app(SshKeyProvider::class)->publicKey())])),
                'preserved_on_node' => ['User account, installed packages, application data, role release data and volumes, and host-side WireGuard configuration'],
                'retained_on_node' => [...$retained, 'Swamp worker unit, credentials and cache', 'Orbit management SSH keys'],
                'metrics_files' => $metricsFiles, 'metrics_reload' => $metricsReload]];
    }
}
