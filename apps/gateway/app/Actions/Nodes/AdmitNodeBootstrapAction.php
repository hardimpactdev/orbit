<?php

declare(strict_types=1);

namespace App\Actions\Nodes;

use App\Actions\Operations\StartOperationAction;
use App\Data\Nodes\ProvisionNodeData;
use App\Domain\Gateway\GatewayServingHost;
use App\Domain\Nodes\NodeBootstrapIntent;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Infrastructure\Swamp\SwampVaultExecutionCopy;
use App\Models\Node;
use App\Models\Operation;

/** Records one standalone bootstrap root. Enrollment composes its native lower directly. */
final readonly class AdmitNodeBootstrapAction
{
    public function __construct(private StartOperationAction $operations, private GatewayServingHost $gateway, private NodeBootstrapIntent $intent,
        private SshKeyProvider $keys, private SwampVaultExecutionCopy $copies) {}

    public function execute(Node $node, string $kind, ?ProvisionNodeData $candidate = null): Operation
    {
        $input = $this->intent->input($node, $kind, $candidate);
        $root = Node::query()->find($this->gateway->nodeId());
        if ($root === null || $root->status !== LifecycleStatus::Active || $root->platform !== 'linux') {
            throw new ResourceOperationException('node.bootstrap_gateway_unavailable', 'Bootstrap requires its active Gateway worker.', 409);
        }
        $participants = array_values(array_unique([$root->id, $node->id]));
        $operationInput = ['bootstrap' => $input, 'participant_node_ids' => $participants];
        if ($kind === 'node.bootstrap_probe') {
            $identity = @file_get_contents($this->keys->privateKeyPath());
            if (! is_string($identity) || $identity === '') {
                throw new ResourceOperationException('node.bootstrap_identity_unavailable', 'The bootstrap SSH identity is unavailable.', 503);
            }
            $operationInput['execution_secrets'] = ['bootstrap_identity' => $this->copies->synchronize(
                'orbit-control-inputs', 'bootstrap-identity-node-'.$root->id, $identity,
            )];
        } else {
            $operationInput['bootstrap_identity'] = ['$secret' => 'node.bootstrap_ssh_identity', 'id' => $node->id];
            $operationInput['private_bindings'] = ['bootstrap_identity' => ['owner_node_id' => $node->id, 'read_node_ids' => [$root->id], 'write_node_ids' => []]];
        }

        return $this->operations->execute($kind, 'node', $node->id, $root->id,
            $operationInput,
            'operator', request()->attributes->getString('orbit.request_id'));
    }
}
