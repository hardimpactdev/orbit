<?php

declare(strict_types=1);

namespace App\Actions\Nodes;

use App\Actions\Operations\StartOperationAction;
use App\Domain\Nodes\NodeAgentRekeyContext;
use App\Domain\Operations\OperationIdempotency;
use App\Infrastructure\AgentCommands\AgentDelivery;
use App\Infrastructure\Nodes\NodeLocks;
use App\Infrastructure\Nodes\NodeWorkerRollout;
use App\Models\Operation;

/** Records one recovery intent; native Node work never runs in this request. */
final readonly class StartNodeAgentRekeyAction
{
    public function __construct(private StartOperationAction $operations, private NodeAgentRekeyContext $context,
        private OperationIdempotency $identity, private AgentDelivery $delivery) {}

    public function execute(int $nodeId, int $configureOperationId): Operation
    {
        $before = [$this->identity->kind, $this->identity->key, $this->identity->fingerprint];
        $previous = Operation::query()->where('kind', 'node.agent_rekey')->where('node_id', $nodeId)
            ->where('input->_agent_rekey->configure_operation_id', $configureOperationId)->latest('id')->first();
        // An explicit operator command may retry a terminal refusal or cancellation.
        // Unfinished and successful attempts keep their original admission identity.
        $key = $previous !== null && in_array($previous->status->value, ['failed', 'cancelled'], true)
            ? 'node-agent-rekey-'.$configureOperationId.'-after-'.$previous->id
            : ($previous->idempotency_key ?? 'node-agent-rekey-'.$configureOperationId);
        $this->identity->kind = 'node.agent_rekey';
        $this->identity->key = $key;
        $this->identity->fingerprint = hash('sha256', json_encode([$nodeId, $configureOperationId], JSON_THROW_ON_ERROR));
        try {
            if (($existing = $this->identity->replay('node.agent_rekey')) !== null) {
                return $existing;
            }
            $source = Operation::query()->find($configureOperationId) ?? throw NodeAgentRekeyContext::refused();
            $node = $this->context->node($nodeId, $source);
            if (! app(NodeWorkerRollout::class)->allows($node)) {
                throw NodeAgentRekeyContext::refused();
            }
            // The original nonresumable effect already started. A free legacy lock
            // proves its configure-and-store critical section ended; acquire nothing.
            if (app(NodeLocks::class)->lock('node-agent:id:'.$nodeId, 420)->isLocked()) {
                throw NodeAgentRekeyContext::refused();
            }
            $configuration = $this->context->configuration($source);
            $worker = "\n[swamp_worker]\nnode_id = {$nodeId}\nuser = ".json_encode($node->user, JSON_THROW_ON_ERROR)."\n";
            if (! str_ends_with($configuration['configuration'], $worker)) {
                throw NodeAgentRekeyContext::refused();
            }

            return $this->operations->execute('node.agent_rekey', 'node', $nodeId, $nodeId, ['_agent_rekey' => [
                'schema_version' => 1, 'node_id' => $nodeId, 'configure_operation_id' => $configureOperationId,
                'agent_sha256' => $this->delivery->pin($node), 'configuration' => $configuration,
            ]], 'operator-recovery', null, expectedWriter: 'direct');
        } finally {
            [$this->identity->kind, $this->identity->key, $this->identity->fingerprint] = $before;
        }
    }
}
