<?php

declare(strict_types=1);

namespace App\Actions\Nodes;

use App\Actions\Operations\StartOperationAction;
use App\Domain\Nodes\PrepareNodeNetworkCommand;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\OperationIdempotency;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentDelivery;
use App\Models\Node;
use App\Models\Operation;
use Illuminate\Support\Facades\DB;

/** Internal admission. The Node supplies the pinned public login before private transport exists. */
final readonly class StartNodeNetworkOperationAction
{
    public function __construct(private StartOperationAction $operations, private OperationIdempotency $idempotency) {}

    public function execute(Node $node, bool $rolelessOperator): Operation
    {
        if (($existing = $this->idempotency->replay('node.network_converge')) !== null) {
            return $existing;
        }

        $parent = $this->idempotency->parent;
        if ($parent === null || ! in_array($parent->kind, ['node.add', 'node.role_add'], true) || $parent->node_id !== $node->id
            || ($parent->kind === 'node.role_add' && ($rolelessOperator || $node->wireguard_public_key === null))) {
            throw new ResourceOperationException('operation.child_forbidden', 'Network convergence requires its owning Node lifecycle parent.', 422);
        }

        return DB::transaction(function () use ($node, $rolelessOperator): Operation {
            $node = Node::query()->lockForUpdate()->findOrFail($node->id);
            if ($node->platform !== 'linux' || ! in_array($node->status, [LifecycleStatus::Active, LifecycleStatus::Provisioning], true)
                || $node->ssh_host_fingerprint === null || $node->wireguard_ip === null
                || $node->roles()->where('role', RoleName::Vpn)->exists()) {
                throw new ResourceOperationException('node.network_unsupported', 'A managed Linux peer with pinned public SSH is required.', 422);
            }
            $operation = $this->operations->execute('node.network_converge', 'node', $node->id, $node->id, [
                'roleless_operator' => $rolelessOperator,
                'agent_pin' => app(AgentDelivery::class)->pin($node),
                'bootstrap_host' => $node->public_ssh_host, 'bootstrap_port' => $node->public_ssh_port,
                'bootstrap_user' => $node->user, 'bootstrap_fingerprint' => $node->ssh_host_fingerprint,
                'bootstrap_identity' => ['$secret' => 'node.bootstrap_ssh_identity', 'id' => $node->id],
            ], 'node-network', $this->idempotency->parent?->request_id);
            if ($operation->wasRecentlyCreated) {
                app(PrepareNodeNetworkCommand::class)->run($operation, ['roleless_operator' => $rolelessOperator]);
            }

            return $operation;
        });
    }
}
