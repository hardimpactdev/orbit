<?php

declare(strict_types=1);

namespace App\Actions\Nodes;

use App\Actions\Operations\StartOperationAction;
use App\Data\Nodes\RetargetNodeData;
use App\Domain\Gateway\GatewayServingHost;
use App\Domain\Nodes\NodeNetworkInput;
use App\Domain\Nodes\NodeProvisioningLock;
use App\Domain\Nodes\NodeProvisioningLockException;
use App\Domain\Nodes\NodeWireGuardCommandInput;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Infrastructure\Swamp\SwampCommandStep;
use App\Models\Node;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;

/** Captures the prior endpoint; only terminal native success commits its replacement. */
final readonly class RetargetNodeAction
{
    public function __construct(private StartOperationAction $operations, private GatewayServingHost $gateway,
        private NodeNetworkInput $network, private NodeWireGuardCommandInput $hub,
        private SshKeyProvider $keys, private SwampCommandStep $steps, private NodeProvisioningLock $lock) {}

    public function execute(RetargetNodeData $data): Operation
    {
        if ($data->publicSshHost === '' || (filter_var($data->publicSshHost, FILTER_VALIDATE_IP) === false
            && filter_var($data->publicSshHost, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false)
            || $data->publicSshPort < 1 || $data->publicSshPort > 65535) {
            throw new ResourceOperationException('node.public_ssh_host_invalid', 'The public SSH endpoint is invalid.', 422);
        }

        try {
            return $this->lock->run($data->name, fn (): Operation => $this->admit($data));
        } catch (NodeProvisioningLockException $exception) {
            throw $exception->toBusyException();
        }
    }

    private function admit(RetargetNodeData $data): Operation
    {
        return DB::transaction(function () use ($data): Operation {
            $node = Node::query()->where('name', $data->name)->lockForUpdate()->first();
            if (! $node instanceof Node) {
                throw new ResourceOperationException('node.not_active', 'The selected Node does not exist as an active Node.', 404);
            }
            if ($node->status !== LifecycleStatus::Active || ! is_string($node->wireguard_ip)
                || ! is_string($node->ssh_host_key_type) || $node->ssh_host_key_type === ''
                || ! is_string($node->ssh_host_key) || $node->ssh_host_key === ''
                || ! is_string($node->ssh_host_fingerprint) || $node->ssh_host_fingerprint === '') {
                throw new ResourceOperationException('node.retarget_requires_vpn', 'Retarget requires an active Node with its pinned WireGuard identity.', 409);
            }
            $gateway = $this->gateway->nodeId();
            if ($gateway === null || ! Node::query()->whereKey($gateway)->where('status', LifecycleStatus::Active)->exists()) {
                throw new ResourceOperationException('node.bootstrap_gateway_unavailable', 'Retarget requires the active Gateway SSH identity owner.', 409);
            }
            $roles = $node->roles()->exists();
            $identity = file_get_contents($this->keys->privateKeyPath());
            if (! is_string($identity) || $identity === '') {
                throw new ResourceOperationException('node.bootstrap_identity_unavailable', 'The managed SSH identity is unavailable.', 503);
            }
            $intent = ['node_id' => $node->id, 'has_roles' => $roles,
                'target' => ['host' => $roles ? $node->wireguard_ip : $data->publicSshHost,
                    'port' => $roles ? 22 : $data->publicSshPort, 'user' => $node->user,
                    'host_keys' => [$node->ssh_host_key_type.' '.$node->ssh_host_key]],
                'candidate' => ['public_ssh_host' => $data->publicSshHost, 'public_ssh_port' => $data->publicSshPort],
                'prior' => $node->only(['public_ssh_host', 'public_ssh_port', 'ssh_host_key_type', 'ssh_host_key', 'ssh_host_fingerprint'])];
            if (! $roles) {
                $intent += ['hub' => $this->hub->capture($node), 'network' => $this->network->capture($node, true)];
            }

            return $this->operations->execute('node.retarget', 'node', $node->id, 209,
                ['intent' => $intent, 'execution_secrets' => ['identity' => $this->steps->executionCopy(
                    'orbit-control-inputs', 'bootstrap-identity-node-'.$gateway, $identity)],
                    'participant_node_ids' => array_values(array_unique([209, $gateway, $node->id, ...($roles ? [] : [ValidatedData::integer(ValidatedData::object($intent['hub'])['hub_id'])])]))],
                'operator', request()->attributes->getString('orbit.request_id'));
        });
    }
}
