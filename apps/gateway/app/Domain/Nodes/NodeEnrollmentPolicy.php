<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Actions\Nodes\AssignRoleAction;
use App\Data\Nodes\ProvisionNodeData;
use App\Domain\Clusters\ActiveTldScopeGuard;
use App\Domain\Clusters\ClusterState;
use App\Domain\Nodes\Storage\ConfiguredStoragePathValidator;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\WireGuard\WireGuardAddressAllocator;
use App\Domain\WireGuard\WireGuardEndpoint;
use App\Models\Cluster;
use App\Models\Node;

/** Validates enrollment before reserving a Node or performing a remote effect. */
final readonly class NodeEnrollmentPolicy
{
    public function __construct(private WireGuardAddressAllocator $addresses, private ActiveTldScopeGuard $tlds,
        private ConfiguredStoragePathValidator $storage,
        private AssignRoleAction $assign, private RoleRegistry $roles) {}

    /** @return array{mode:string,candidate:array<string,mixed>,bootstrap_user:string,managed_user:string,fingerprint:string,requested_architecture:?string,roleless_operator:bool} */
    public function prepare(Node $node, ProvisionNodeData $data): array
    {
        $mac = $data->platform === 'macos' || ($node->exists && $node->platform === 'macos');
        $platform = $mac ? 'macos' : ($node->exists ? $node->platform : $data->platform);
        if (! in_array($platform, ['linux', 'macos'], true) || ($mac && $node->exists && $node->platform !== 'macos' && $node->ssh_host_fingerprint !== null)
            || ($mac && $node->exists && $data->platformProvided && $data->platform !== 'macos')) {
            throw $this->refuse('node.platform_unsupported', 'The Node platform cannot be changed by enrollment.');
        }
        if ($platform === 'linux' && $data->architecture !== null && MachineArchitecture::normalize($data->architecture) !== MachineArchitecture::Linux) {
            throw $this->refuse('node.architecture_unsupported', 'Linux enrollment requires the supported x86_64 architecture.');
        }
        if ($data->sshJumpNodeId !== null || $node->ssh_jump_node_id !== null) {
            throw $this->refuse('node.jump_host_unsupported', 'Enrollment connects directly from Node209.');
        }
        $managed = $data->orbitUser ?? ($node->exists ? $node->user : 'orbit');
        $bootstrap = $data->user ?? ($node->exists ? $node->user : 'root');
        foreach ([$managed, $bootstrap] as $user) {
            if (! LinuxUserName::isValid($user)) {
                throw $this->refuse('node.invalid_linux_user', 'The Node account name is invalid.');
            }
        }
        if ($managed === 'root') {
            throw $this->refuse('node.invalid_linux_user', 'The managed Node account must be non-root.');
        }
        if ($node->exists && $managed !== $node->user && ($node->roles()->exists() || ($mac && $node->ssh_host_fingerprint !== null))) {
            throw $this->refuse('node.user_change_unsupported', 'The Node cannot change its managed account while it owns roles or an enrolled Mac identity.', 409);
        }
        if ($data->wireguardEndpointOverride !== null && ! WireGuardEndpoint::isValid($data->wireguardEndpointOverride)) {
            throw $this->refuse('vpn.endpoint_override_invalid', 'The WireGuard endpoint override is invalid.');
        }
        if ($data->settingsProvided) {
            $this->storage->validateGrammar($data->settings);
        }
        $clusterId = $data->clusterId ?? ($node->exists ? $node->cluster_id : null);
        if ($node->exists && $node->cluster_id !== null && $data->clusterId !== null && $node->cluster_id !== $data->clusterId) {
            throw $this->refuse('cluster.membership_conflict', 'The Node already belongs to another Cluster.', 409);
        }
        $cluster = $clusterId === null ? null : Cluster::query()->find($clusterId);
        if ($clusterId !== null && $cluster === null) {
            throw $this->refuse('cluster.not_found', 'The selected Cluster does not exist.', 404);
        }
        $lan = $data->lanIpProvided ? $data->lanIp : ($node->exists ? $node->lan_ip : null);
        if ($clusterId !== null && $lan !== null && Node::query()->where('cluster_id', $clusterId)->where('lan_ip', $lan)
            ->when($node->exists, static fn ($q) => $q->whereKeyNot($node->id))->exists()) {
            throw $this->refuse('cluster.lan_ip_conflict', 'The LAN address is already assigned in the Cluster.', 409);
        }
        $tld = $data->tldProvided || $data->tld !== null ? $data->tld : ($node->exists ? $node->tld : null);
        $appDev = in_array(RoleName::AppDev, $data->roles, true) || ($node->exists && $node->roles()->where('role', RoleName::AppDev)->exists());
        if ($tld === null && $appDev && ! ($cluster?->state === ClusterState::Active && $cluster->tld !== null)) {
            throw $this->refuse('node.tld_required', 'An app-dev Node requires a Node TLD or an active Cluster TLD.');
        }
        if ($tld !== null) {
            $tld = NodeTld::normalize($tld);
            if (! NodeTld::isValid($tld)) {
                throw $this->refuse('node.tld_invalid', 'The Node TLD is invalid.');
            }
            if (Node::query()->where('tld', $tld)->when($node->exists, static fn ($q) => $q->whereKeyNot($node->id))->exists()) {
                throw $this->refuse('node.tld_taken', 'The Node TLD is already assigned.', 409);
            }
        }
        $this->tlds->assertNodeTldAvailable($node, $tld, $clusterId);
        $hasInstances = $node->exists && $node->instances()->exists();
        $tldOnly = $hasInstances && $this->tldOnly($node, $data, $tld, $clusterId);
        if ($hasInstances && (! $tldOnly || ! $node->roles()->where('role', RoleName::AppDev)->where('status', LifecycleStatus::Active)->exists())) {
            throw $this->refuse('node.has_instances', 'A Node with Instances permits only an active app-dev TLD change.', 409);
        }
        $address = $data->wireguardIp ?? ($node->exists ? $node->wireguard_ip : null);
        if ($mac) {
            if ($data->user === null || $data->orbitUser === null) {
                throw $this->refuse('node.macos_account_required', 'Mac enrollment requires the existing account in user and orbit_user.');
            }
            if ($data->user !== $data->orbitUser) {
                throw $this->refuse('node.macos_account_mismatch', 'Mac enrollment requires the same existing account.');
            }
            if ($data->roles !== [] || ($node->exists && $node->roles()->exists())) {
                throw $this->refuse('node.platform_unsupported', 'Mac Nodes cannot own service roles.');
            }
            if ($data->settingsProvided || ($data->tldProvided && $tld !== ($node->exists ? $node->tld : null))
                || ($data->clusterProvided && $data->clusterId !== ($node->exists ? $node->cluster_id : null))
                || ($data->lanIpProvided && $lan !== ($node->exists ? $node->lan_ip : null))
                || ($data->wireguardEndpointOverride !== null && $data->wireguardEndpointOverride !== ($node->exists ? $node->wireguard_endpoint_override : null))
                || ($data->dnsServerOverride !== null && $data->dnsServerOverride !== ($node->exists ? $node->dns_server_override : null))) {
                throw $this->refuse('node.platform_unsupported', 'Mac enrollment preserves Cluster, DNS, tunnel and storage settings.');
            }
            if ($address === null || $address === '' || ($node->exists && $node->wireguard_ip !== null && $address !== $node->wireguard_ip)) {
                throw $this->refuse('node.wireguard_required', 'Mac enrollment requires its existing WireGuard address.', 409);
            }
        }
        $address = $this->addresses->forProvisioning($address, $node->exists ? $node : null);
        $host = $data->publicSshHost !== '' ? $data->publicSshHost : ($node->exists ? $node->public_ssh_host : '');
        if ($host === '' && ! $mac) {
            $host = $address;
        }
        if (! $mac && $node->exists && $node->wireguard_ip !== null && $address !== $node->wireguard_ip
            && in_array($host, [$node->wireguard_ip, $address], true)) {
            throw $this->refuse('node.recovery_endpoint_required', 'A WireGuard address change requires a separate bootstrap SSH endpoint.', 409);
        }
        $fingerprint = $node->ssh_host_fingerprint ?? $data->expectedSshHostFingerprint;
        if (! $tldOnly && ($host === '' || $fingerprint === null)) {
            throw $this->refuse('node.ssh_host_fingerprint_required', 'Enrollment requires a pinned public SSH host.');
        }
        if (! $tldOnly && preg_match('/\ASHA256:[A-Za-z0-9+\/]{43}\z/D', $fingerprint) !== 1) {
            throw $this->refuse('node.ssh_host_fingerprint_required', 'Enrollment requires one complete SHA256 host fingerprint.');
        }
        if ($data->expectedSshHostFingerprint !== null && $node->ssh_host_fingerprint !== null
            && ! hash_equals($node->ssh_host_fingerprint, $data->expectedSshHostFingerprint)) {
            throw $this->refuse('node.ssh_host_key_mismatch', 'The requested fingerprint differs from the recorded Node identity.', 409);
        }
        foreach ($data->roles as $role) {
            if (! $this->roles->definition($role)->assignableDuringProvisioning) {
                throw new RoleAssignmentException('The requested role cannot be assigned during enrollment.');
            }
            $this->assign->preflight($node, $role, $data->roles);
        }

        $unobservedMac = $mac && $node->ssh_host_fingerprint === null;
        $architecture = $unobservedMac ? null : MachineArchitecture::normalize($node->architecture);

        return ['mode' => $tldOnly ? 'tld_only' : $platform, 'bootstrap_user' => $bootstrap, 'managed_user' => $managed,
            'fingerprint' => $fingerprint ?? '', 'requested_architecture' => MachineArchitecture::normalize($mac ? ($data->architecture ?? $architecture) : ($architecture ?? $data->architecture)),
            'roleless_operator' => $data->roles === [] && (! $node->exists || ! $node->roles()->exists()),
            'candidate' => ['cluster_id' => $clusterId, 'platform' => $platform, 'architecture' => $architecture, 'tld' => $tld, 'user' => $managed,
                'public_ssh_host' => $host, 'public_ssh_port' => $node->exists ? $node->public_ssh_port : $data->publicSshPort,
                'wireguard_ip' => $address, 'lan_ip' => $lan,
                'wireguard_endpoint_override' => $data->wireguardEndpointOverride ?? $node->wireguard_endpoint_override,
                'dns_server_override' => $data->dnsServerOverride ?? $node->dns_server_override,
                'ssh_jump_node_id' => null]];
    }

    private function tldOnly(Node $node, ProvisionNodeData $data, ?string $tld, ?int $clusterId): bool
    {
        return $node->exists && $tld !== $node->tld && $clusterId === $node->cluster_id && $data->roles === []
            && ($data->user === null || $data->user === $node->user) && ($data->orbitUser === null || $data->orbitUser === $node->user)
            && ($data->publicSshHost === '' || $data->publicSshHost === $node->public_ssh_host)
            && ($data->wireguardIp === null || $data->wireguardIp === $node->wireguard_ip)
            && (! $data->lanIpProvided || $data->lanIp === $node->lan_ip) && ! $data->settingsProvided && ! $data->clusterProvided
            && $data->wireguardEndpointOverride === null && $data->dnsServerOverride === null
            && ($data->architecture === null || $data->architecture === $node->architecture);
    }

    private function refuse(string $code, string $message, int $status = 422): ResourceOperationException
    {
        return new ResourceOperationException($code, $message, $status);
    }
}
