<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Actions\Nodes\AssignRoleAction;
use App\Data\Nodes\ProvisionNodeData;
use App\Domain\Fleet\CliConvergePlan;
use App\Domain\Nodes\Storage\ConfiguredStoragePathValidator;
use App\Domain\Nodes\Storage\NodeSettingsNormalizer;
use App\Domain\Routes\RouteExecutionProof;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\TaskVms\TaskVmAptCache;
use App\Domain\WireGuard\Ipv4Subnet;
use App\Domain\WireGuard\VpnSettings;
use App\Infrastructure\AgentCommands\AgentDelivery;
use App\Infrastructure\AppDev\VpnDnsmasqBackendListen;
use App\Infrastructure\Firewall\NodeFirewallRuleCatalog;
use App\Infrastructure\Nodes\NodeAgentFootprint;
use App\Infrastructure\Nodes\NodeAgentSshExecutor;
use App\Infrastructure\Nodes\NodeBootstrapCommandFactory;
use App\Infrastructure\Nodes\NodePrivateDnsListenerFootprint;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Infrastructure\Swamp\SwampCommandStep;
use App\Infrastructure\Swamp\SwampSshExecutionInput;
use App\Models\Node;
use App\Models\NodeRole;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\Config;

/** Freeze enrollment resources. Native observations bind the public host key before any role work. */
final readonly class NativeNodeEnrollmentInput
{
    public const string AlgorithmMark = '@ORBIT_ENROLLMENT_HOST_ALGORITHM@';

    public const string KeyMark = '@ORBIT_ENROLLMENT_HOST_KEY@';

    /** @param array<string,mixed> $spec
     * @return array{intent:array<string,mixed>,execution_secrets:array<string,mixed>,role_snapshots:list<array<string,mixed>>,agent_secret_hash:?string,participant_node_ids:list<int>}
     */
    public function capture(Node $node, Operation $operation, array $spec, ProvisionNodeData $request): array
    {
        $identity = app(SwampSshExecutionInput::class)->identity();
        $key = file_get_contents(app(SshKeyProvider::class)->privateKeyPath());
        if (! is_string($key) || $key === '') {
            throw new ResourceOperationException('node.bootstrap_identity_unavailable', 'The Gateway bootstrap identity is unavailable.', 503);
        }
        $copies = ['bootstrap_identity' => app(SwampCommandStep::class)->executionCopy(
            'orbit-control-inputs', 'bootstrap-identity-node-'.$node->id, $key)];
        $candidate = ValidatedData::object($spec['candidate']);
        $bootstrapUser = ValidatedData::string($spec['bootstrap_user']);
        $intent = ['platform' => $candidate['platform'], 'expected_architecture' => $spec['requested_architecture'],
            'managed_user' => $spec['managed_user'], 'address' => $candidate['wireguard_ip'],
            'bootstrap' => ['host' => $candidate['public_ssh_host'], 'port' => $candidate['public_ssh_port'],
                'user' => $bootstrapUser, 'fingerprint' => $spec['fingerprint']],
            'identity' => $identity,
            'packages' => [], 'apt_proxy' => null, 'kernel' => ['files' => [], 'sysctl' => []], 'sudo' => ['path' => '', 'content' => ''],
            'agent' => ['url' => '', 'sha256' => '', 'unit' => '', 'environment_group' => NodeAgentSshExecutor::EnvGroup, 'files' => []],
            'network' => ['is_hub' => false, 'sync_path' => '', 'private_key_file' => '', 'sync_body' => '', 'port' => null, 'address' => '', 'retired_address' => null, 'other_public_keys' => [], 'required_public_key' => null,
                'files' => [], 'hubs' => [], 'hub_files' => [], 'trust_argv' => []],
            'public_ssh_argv' => [], 'roles' => [], 'dns' => [], 'private_dns' => [], 'cli' => null,
            'authorized_keys' => [$identity['public_key']], 'known_hosts_path' => '/home/orbit/.ssh/known_hosts'];
        if ($candidate['platform'] === 'macos') {
            return ['intent' => $intent, 'execution_secrets' => $copies, 'role_snapshots' => [],
                'agent_secret_hash' => null, 'participant_node_ids' => [SwampNode::id(), $node->id]];
        }

        // The transaction supplies a prospective rendering view. It never commits an observed identity or status.
        $node->refresh();
        $original = $node->getAttributes();
        $roleSnapshots = [];
        $originalRoles = $node->roles()->get();
        $existingRoles = $originalRoles->keyBy(fn (NodeRole $role): string => $role->role->value);
        $requested = array_values(array_unique([...$existingRoles->keys()->all(),
            ...array_map(static fn (RoleName $role): string => $role->value, $request->roles)]));
        if (in_array('vpn', $requested, true)) {
            $requested = ['vpn', ...array_values(array_diff($requested, ['vpn']))];
        }
        $participants = [SwampNode::id(), $node->id];
        $beforeDns = app(NodeConvergeInput::class)->dns();
        $agentSecret = bin2hex(random_bytes(32));
        $copies['agent_secret'] = app(SwampCommandStep::class)->executionCopy(
            'orbit-control-inputs', 'agent-secret-node-'.$node->id, $agentSecret);
        try {
            $node->forceFill([...$candidate, 'status' => LifecycleStatus::Active,
                'architecture' => $candidate['architecture'] ?? 'x86_64',
                'ssh_host_key_type' => self::AlgorithmMark, 'ssh_host_key' => self::KeyMark, 'ssh_host_fingerprint' => $spec['fingerprint'],
                'wireguard_public_key' => $node->wireguard_public_key ?? '@ORBIT_ENROLLMENT_WIREGUARD_PUBLIC_KEY@',
                ...($request->settingsProvided ? ['settings' => app(NodeSettingsNormalizer::class)->storedOver($node->settings, $request->settings)] : [])])->saveQuietly();
            $base = app(NodeBootstrapCommandFactory::class)->input($node, $node->user, $spec['roleless_operator'] === true);
            $intent['packages'] = $base['packages'];
            $intent['kernel'] = NodeKernelPolicy::intent();
            // The package cache of the task VM for a Node of its nested fleet; null for every other Node, which reads the archive directly.
            $intent['apt_proxy'] = TaskVmAptCache::for($node);
            $intent['sudo'] = ['path' => '/etc/sudoers.d/orbit-'.$node->user,
                'content' => $node->user." ALL=(ALL) NOPASSWD: ALL\n"];
            $home = $node->user === 'root' ? '/root' : '/home/'.$node->user;
            $root = app(ConfiguredStoragePathValidator::class)->validateEffective(
                app(NodeSettingsNormalizer::class)->fromStored($node->settings), $node,
                new ManagedUserAccount($node->user, $node->user, $home));
            $agent = app(NodeAgentSshExecutor::class)->configurationForRoot($node, $root->value);
            $delivery = app(AgentDelivery::class)->artifacts()['linux-x86_64'];
            $url = $delivery['url'] ?? null;
            if (! is_string($url) || ! str_starts_with($url, 'https://')) {
                throw new ResourceOperationException('node.agent_delivery_unavailable', 'Native enrollment requires the pinned agent HTTPS artifact.', 503);
            }
            $intent['agent'] = ['url' => $url, 'sha256' => $delivery['sha256'], 'unit' => $agent['unit'], 'environment_group' => NodeAgentSshExecutor::EnvGroup,
                'files' => [['path' => NodeAgentFootprint::ConfigurationPath, 'content' => $agent['configuration']],
                    ['path' => NodeAgentFootprint::CertificatePath, 'content' => $agent['certificate']]]];
            $recovery = app(NodeFirewallRuleCatalog::class)->publicSshRecovery($node);
            $intent['public_ssh_argv'] = ['timeout', '60', 'ufw', ...array_slice($recovery->arguments, 2)];
            foreach ($requested as $roleName) {
                $role = RoleName::from($roleName);
                $before = app(NativeNodeRoleRemovalInput::class)->publications($node, $role);
                $assignment = $existingRoles->get($roleName) ?? app(AssignRoleAction::class)->execute($node, $role);
                $created = ! $existingRoles->has($roleName);
                if ($created || $role !== RoleName::Analytics) {
                    app(NodeRoleRuntimeInput::class)->prepare($node, $assignment);
                }
                $snapshot = app(NodeRoleDirectInput::class)->capture($node->refresh(), $assignment, $created);
                $native = app(NativeNodeRoleAddInput::class)->capture($node, $assignment, $snapshot, $before, $operation, ! $created);
                $intent['roles'][] = ['kind' => $created ? 'node.role_add' : 'node.role_converge',
                    'intent' => $native['intent'], 'execution_secrets' => $native['execution_secrets']];
                $roleSnapshots[] = $snapshot;
                $participants = [...$participants, ...$native['participant_node_ids'],
                    ...NodeRolePublicationInput::participants(ValidatedData::object($snapshot['publication'])),
                    ...NodeRoleCertificateInput::participants(ValidatedData::object($snapshot['certificates']))];
            }
            $intent['cli'] = ['ssh' => app(SwampSshExecutionInput::class)->forNode($node),
                'cli' => app(CliConvergePlan::class)->input($node)];
            $intent['network'] = $this->network($node, $spec['roleless_operator'] === true);
            $publicPath = rtrim(Config::string('orbit.home'), '/').'/wireguard/public.key';
            $hubKey = is_file($publicPath) ? trim((string) file_get_contents($publicPath)) : '';
            $otherKeys = ValidatedData::stringList(Node::query()->whereKeyNot($node->id)
                ->whereNotNull('wireguard_public_key')->pluck('wireguard_public_key')->all());
            $intent['network']['other_public_keys'] = $intent['network']['is_hub'] === true ? $otherKeys : [...$otherKeys, $hubKey];
            $intent['network']['required_public_key'] = $intent['network']['is_hub'] === true && $otherKeys !== [] ? $hubKey : null;
            $intent['network']['address'] = $node->wireguard_ip.'/'.Ipv4Subnet::from(app(VpnSettings::class)->subnet())->prefixLength();
            $intent['network']['retired_address'] = is_string($original['wireguard_ip'] ?? null) && $original['wireguard_ip'] !== $node->wireguard_ip
                ? $original['wireguard_ip'].'/'.Ipv4Subnet::from(app(VpnSettings::class)->subnet())->prefixLength() : null;
            $participants = [...$participants, ...ValidatedData::integerList($intent['network']['participant_node_ids'])];
            if ($intent['network']['is_hub'] === true) {
                $dns = app(NodeConvergeInput::class);
                $intent['private_dns'][] = ['node_id' => $node->id, 'ssh' => app(SwampSshExecutionInput::class)->forNode($node),
                    'backend' => VpnDnsmasqBackendListen::apply("# Managed by Orbit: private DNS backend.\n"),
                    'dns' => $dns->privateDns($node, ['listener' => app(NodePrivateDnsListenerFootprint::class)->input($node), 'publication' => $dns->dns()])];
            }
            foreach (app(NativeNodeRoleRemovalInput::class)->publications($node, RoleName::Gateway) as $publication) {
                if ($publication['node_id'] === $node->id && $intent['network']['is_hub'] === true) {
                    continue;
                }
                if ($publication['dns'] !== null) {
                    $intent['dns'][] = ['node_id' => $publication['node_id'], 'connection' => $publication['connection'],
                        'host_keys' => $publication['host_keys'], 'before' => $beforeDns, 'after' => $publication['dns'], 'alternatives' => []];
                }
            }
        } finally {
            $node->setRawAttributes($original);
            $node->saveQuietly();
        }

        return ['intent' => $intent, 'execution_secrets' => $copies, 'role_snapshots' => $roleSnapshots,
            'agent_secret_hash' => hash('sha256', $agentSecret), 'participant_node_ids' => array_values(array_unique($participants))];
    }

    /** @return array<string,mixed> */
    private function network(Node $node, bool $roleless): array
    {
        if ($node->roles()->where('role', RoleName::Vpn)->exists()) {
            $hub = NodeWireGuardHubInput::intent($node);
            $peerFiles = array_map(static fn (array $peer): array => ['path' => ValidatedData::string($peer['file']), 'content' => ValidatedData::string($peer['content'])],
                RouteExecutionProof::objects($hub['peers']));
            $trust = app(NodeFirewallRuleCatalog::class)->forNode($node)[1];

            // The hub's file is the one the hub converge writes: no peer in it, and wg-quick loads every peer file at start, so a peer
            // that only has its file (the operator of a task VM's nested fleet) survives a restart. Every recorded peer gets its file,
            // and the hub loads the files it has when it applies, so a Node that joined after admission keeps its live peer.
            return ['is_hub' => true, 'files' => [['path' => '/etc/wireguard/orbit.conf', 'content' => ValidatedData::string($hub['config'])],
                ['path' => '/etc/wireguard/orbit.peers', 'content' => ''], ...$peerFiles],
                'sync_path' => '/etc/wireguard/orbit.peers', 'private_key_file' => '/etc/wireguard/orbit.key',
                'sync_body' => '', 'port' => $hub['port'],
                'trust_argv' => ['timeout', '60', 'ufw', ...array_slice($trust->arguments, 2)],
                'hubs' => [], 'hub_files' => [], 'participant_node_ids' => []];
        }
        $network = app(NodeNetworkConvergeInput::class)->capture($node, $roleless);
        $hub = Node::query()->findOrFail(ValidatedData::integer($network['hub_id']));
        $joining = ValidatedData::object($network['hub']);
        $hubPlan = NodeWireGuardHubInput::intent($hub);
        $peerFiles = [];
        foreach (RouteExecutionProof::objects($hubPlan['peers']) as $peer) {
            $peer = ValidatedData::object($peer);
            if ($peer['node_id'] !== $node->id) {
                $peerFiles[] = ['path' => ValidatedData::string($peer['file']), 'content' => ValidatedData::string($peer['content'])];
            }
        }
        $ssh = ValidatedData::object($joining['ssh']);
        // Every peer the hub knows also gets its own file, so none of them depends on a list that the hub file no longer holds.
        $peerFiles = array_map(static fn (array $file): array => ['ssh' => $ssh, ...$file], $peerFiles);
        $files = [['path' => '/etc/wireguard/orbit.conf', 'content' => $network['config']],
            ['path' => '/etc/wireguard/orbit.peers', 'content' => '']];

        return ['is_hub' => false, 'files' => $files, 'sync_path' => '/etc/wireguard/orbit.peers',
            'private_key_file' => '/etc/wireguard/orbit.key', 'sync_body' => ValidatedData::string($network['peers']), 'port' => null, 'trust_argv' => ['timeout', '60', ...ValidatedData::stringList($network['trust_argv'])],
            'hubs' => [['ssh' => $ssh, 'sync_path' => '/etc/wireguard/orbit.enrollment-sync',
                'private_key_file' => '/etc/wireguard/orbit.key', 'public_key' => $hub->wireguard_public_key, 'port' => $hubPlan['port']]],
            'hub_files' => [...$peerFiles, ['ssh' => $ssh, 'path' => $joining['file'], 'content' => $joining['section']],
                ['ssh' => $ssh, 'path' => '/etc/wireguard/orbit.conf', 'content' => ValidatedData::string($hubPlan['config'])],
                ['ssh' => $ssh, 'path' => '/etc/wireguard/orbit.enrollment-sync', 'content' => '']],
            'participant_node_ids' => [$hub->id]];
    }
}
