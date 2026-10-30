<?php

declare(strict_types=1);

namespace App\Actions\Nodes;

use App\Domain\Nodes\ManagedNodeEligibility;
use App\Domain\Nodes\NodeAgentRuntime;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\Nodes\NodeWorkerRollout;
use App\Models\Node;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Throwable;

/** Bootstrap/rotation through existing pinned SSH. Credentials never enter a job or receipt. */
final readonly class ConvergeNodeWorkerAction
{
    public function __construct(private AgentCommandRunner $agents, private NodeAgentRuntime $runtime, private NodeWorkerRollout $rollout) {}

    public function execute(Node $node): void
    {
        if (! $this->rollout->allows($node)) {
            return;
        }
        try {
            $lock = Cache::lock('swamp:node-worker:'.$node->id, 1800);
            $lock->block(1);
            try {
                $this->runtime->converge($node);
                $state = $this->agents->run($node, 'swamp.converge-node-worker', []);
                if (count($state) !== 2 || ! is_bool($state['ready'] ?? null) || ! is_bool($state['drained'] ?? null)
                    || ($state['ready'] && $state['drained'])) {
                    throw new \RuntimeException;
                }
                if (! $state['drained']) {
                    return;
                }
                $issuer = Node::query()->find(Config::integer('swamp_workers.issuer_node_id'));
                if (! $issuer instanceof Node || ! app(ManagedNodeEligibility::class)->allows($issuer)) {
                    throw new \RuntimeException;
                }
                $pair = $this->agents->run($issuer, 'swamp.issue-node-worker', ['node_id' => $node->id]);
                if (count($pair) !== 3 || ! is_string($pair['enrollment_token'] ?? null)
                    || ! is_string($pair['server_token'] ?? null) || ! is_string($pair['expires_at'] ?? null)
                    || ! str_starts_with($pair['enrollment_token'], 'orbit-node-'.$node->id.'.')
                    || ! str_starts_with($pair['server_token'], 'orbit-node-'.$node->id.'-access.')) {
                    throw new \RuntimeException;
                }
                $started = $this->agents->run($node, 'swamp.converge-node-worker', ['credentials' => $pair]);
                if (($started['ready'] ?? null) !== true || ($started['drained'] ?? null) !== false) {
                    throw new \RuntimeException;
                }
            } finally {
                $lock->release();
            }
        } catch (Throwable) {
            // Discard native/transport diagnostics: they can contain credentials.
            throw new ResourceOperationException('swamp.worker_unavailable', 'The Node worker lifecycle is unavailable.', 503);
        }
    }
}
