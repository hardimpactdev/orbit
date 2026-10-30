<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Operations\SwampOperationExecutor;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Models\Node;
use App\Models\Operation;
use Illuminate\Support\Arr;
use LogicException;

/** Complete an admitted firewall intent through a schema-checked fake Node receipt. */
final class FirewallOperationProof
{
    public static function finish(string $kind, string $worker = 'test'): Operation
    {
        $executor = app(SwampOperationExecutor::class);
        $peerNodeId = Node::query()->firstOrFail()->id;
        $operation = $executor->claim($kind, $worker, $peerNodeId) ?? throw new LogicException('Expected a queued firewall Operation.');
        $step = 'ufw-remove';
        $result = ['backend' => 'absent'];
        app()->instance(AgentCommandRunner::class, new FakeAgentCommandRunner()->answer('firewall.rule', $result));
        $receipt = $executor->run($operation, ['worker' => $worker, 'key' => $step,
            'transport' => 'agent', 'name' => 'firewall.rule', 'input' => [
                ...Arr::only($operation->input, ['name', 'action', 'source', 'port', 'protocol']),
                'node_id' => $operation->node_id, 'operation' => 'remove',
            ]], $peerNodeId);
        $executor->step($operation, ['worker' => $worker, 'key' => $step.':finish',
            'name' => $step, 'status' => 'succeeded', 'result' => $receipt], $peerNodeId);

        return $executor->complete($operation, ['worker' => $worker, 'status' => 'succeeded', 'result' => $receipt], $peerNodeId);
    }
}
