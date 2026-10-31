<?php

declare(strict_types=1);

namespace App\Infrastructure\Nodes;

use App\Domain\Nodes\NodeWireGuardInput;
use App\Domain\Operations\DirectOperationPrivateBindings;
use App\Domain\Operations\OperationSecretSource;
use App\Domain\Operations\OperationSecretValue;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\WireGuard\Ipv4Subnet;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Infrastructure\WireGuard\VpnConfigurationRepository;
use App\Models\Node;
use App\Models\Operation;

/** Existing VPN private type, bound to one admitted hub participant in a native parent run. */
final readonly class NodeVpnConfigurationSource implements OperationSecretSource
{
    public function __construct(private VpnConfigurationRepository $configuration) {}

    public function name(): string
    {
        return 'node.vpn_configuration';
    }

    public function targetTypes(): array
    {
        return ['node', 'task_vm', 'task_group'];
    }

    public function ownerId(Operation $operation): ?int
    {
        $snapshot = NodeWireGuardInput::bound($operation);

        return $snapshot !== null && app(DirectOperationPrivateBindings::class)->ownerNode($operation, 'configuration') === $snapshot['hub_id']
            ? $snapshot['hub_id'] : null;
    }

    public function resolve(Operation $operation, int $id, string $key, string $parameter): OperationSecretValue
    {
        $snapshot = NodeWireGuardInput::bound($operation);
        if ($snapshot === null || $this->ownerId($operation) !== $id || $parameter !== 'configuration'
            || ($operation->input['configuration'] ?? null) !== ['$secret' => $this->name(), 'id' => $id]) {
            throw new ResourceOperationException('operation.execution_forbidden', 'The VPN material requires its exact admitted hub participant.', 422);
        }
        $prospectiveAddress = null;
        if ($operation->kind === 'node.add' && ($operation->state['node_wireguard_restoring'] ?? false) === false) {
            $enrollment = $operation->input['_enrollment'] ?? null;
            $candidate = is_array($enrollment) ? ($enrollment['candidate'] ?? null) : null;
            $address = is_array($candidate) ? ($candidate['wireguard_ip'] ?? null) : null;
            if (! is_array($enrollment) || ($enrollment['schema_version'] ?? null) !== 1
                || ($enrollment['node_id'] ?? null) !== $snapshot['context_node_id']
                || ($enrollment['executor_node_id'] ?? null) !== $operation->node_id
                || $operation->node_id !== $snapshot['executor_node_id']
                || ! is_string($address) || filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
                throw new ResourceOperationException('operation.execution_forbidden', 'The prospective VPN address requires its exact enrollment guest.', 422);
            }
            $prospectiveAddress = $address;
        }
        $vpn = $this->configuration->forPeer(Node::query()->findOrFail($snapshot['hub_id']));
        if ($vpn->server->id !== $id) {
            throw new ResourceOperationException('operation.execution_forbidden', 'The VPN owner changed after admission.', 422);
        }
        if ($prospectiveAddress !== null && ! Ipv4Subnet::from($vpn->subnet)->containsUsableAddress($prospectiveAddress)) {
            throw new ResourceOperationException('operation.execution_forbidden', 'The prospective VPN address is outside the managed subnet.', 422);
        }
        $peers = Node::query()->whereNotNull('wireguard_public_key')
            ->where(fn ($query) => $query->whereNotNull('wireguard_ip')
                ->when($prospectiveAddress !== null, fn ($query) => $query->orWhereKey($snapshot['context_node_id'])))
            ->where('status', '!=', LifecycleStatus::Removing)->orderBy('id')->get()
            ->map(static fn (Node $node): array => ['node_id' => $node->id, 'name' => $node->name,
                'public_key' => $node->wireguard_public_key,
                'address' => $node->id === $snapshot['context_node_id'] && $prospectiveAddress !== null ? $prospectiveAddress : $node->wireguard_ip])->all();

        $material = ['snapshot' => $snapshot, 'address' => $vpn->serverAddress, 'port' => $vpn->port,
            'private_key' => $vpn->serverPrivateKey, 'peers' => $peers];
        if ($snapshot['executor_node_id'] !== $snapshot['hub_id']) {
            $hub = $vpn->server;
            if (! is_string($hub->ssh_host_fingerprint) || preg_match('/\ASHA256:[A-Za-z0-9+\/]{43}\z/D', $hub->ssh_host_fingerprint) !== 1) {
                throw new ResourceOperationException('operation.execution_forbidden', 'Hub SSH requires its pinned host identity.', 422);
            }
            $material['connection'] = ['target' => ['node_id' => $hub->id, 'host' => $hub->public_ssh_host,
                'port' => $hub->public_ssh_port, 'user' => $hub->user, 'fingerprint' => $hub->ssh_host_fingerprint],
                'identity' => app(SshKeyProvider::class)->privateKeyPath()];
        }

        return new OperationSecretValue($material, [$vpn->serverPrivateKey]);
    }
}
