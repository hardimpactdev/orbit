<?php

declare(strict_types=1);

namespace App\Actions\Nodes;

use App\Actions\Operations\StartOperationAction;
use App\Domain\Nodes\PrepareNodeWireGuardCommand;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\OperationIdempotency;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use App\Models\Operation;

/** Hub effects always run as children on the VPN Node, never by borrowing the peer's run target. */
final readonly class StartNodeWireGuardHubOperationAction
{
    public function __construct(private StartOperationAction $operations, private OperationIdempotency $idempotency) {}

    /** @param array{key:string,kind:string,node_id:int|null,input:array<string,mixed>} $intent */
    public function execute(Operation $parent, array $intent): Operation
    {
        if (($existing = $this->idempotency->replay('node.wireguard_hub_converge')) !== null) {
            return $existing;
        }
        $input = $intent['input'];
        $context = $input['context_node_id'] ?? null;
        $excluded = $input['excluded_node_id'] ?? null;
        if ($this->idempotency->parent?->id !== $parent->id || $this->idempotency->childKey !== $intent['key']
            || ! in_array($parent->kind, ['node.network_converge', 'node.add', 'node.remove', 'node.removal_restore', 'node.enrollment_restore'], true)
            || ! is_int($intent['node_id']) || ! is_int($context) || $context !== $parent->node_id || ! array_key_exists('excluded_node_id', $input)
            || ($excluded !== null && $excluded !== $context)
            || ! Node::query()->whereKey($intent['node_id'])->whereHas('roles', static fn ($query) => $query->where('role', RoleName::Vpn))->exists()) {
            throw new ResourceOperationException('operation.child_forbidden', 'The hub child differs from its parent peer intent.', 422);
        }
        $operation = $this->operations->execute('node.wireguard_hub_converge', 'node', $intent['node_id'], $intent['node_id'],
            ['context_node_id' => $context, 'excluded_node_id' => $excluded], 'node-network', $parent->request_id);
        if ($operation->wasRecentlyCreated) {
            app(PrepareNodeWireGuardCommand::class)->run($operation, $operation->input);
        }

        return $operation;
    }
}
