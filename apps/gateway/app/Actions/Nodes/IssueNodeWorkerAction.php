<?php

declare(strict_types=1);

namespace App\Actions\Nodes;

use App\Domain\Nodes\ManagedNodeEligibility;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\Nodes\NodeWorkerRollout;
use App\Models\Node;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Throwable;

/** A lifecycle courier. The issuer's operator credential never leaves Node 209. */
final readonly class IssueNodeWorkerAction
{
    public function __construct(private AgentCommandRunner $agents, private NodeWorkerRollout $rollout) {}

    /** @return array{node_id: int, enrollment_token: string, server_token: string, expires_at: string} */
    public function execute(Node $node): array
    {
        if (! $this->rollout->allows($node)) {
            throw new ResourceOperationException('swamp.worker_not_enabled', 'The Node worker is not enabled for this Node.', 403);
        }
        $issuer = Node::query()->find(Config::integer('swamp_workers.issuer_node_id'));
        if (! $issuer instanceof Node || ! app(ManagedNodeEligibility::class)->allows($issuer)) {
            throw new ResourceOperationException('swamp.issuer_unavailable', 'The Node worker issuer is unavailable.', 503);
        }
        // No secret-bearing cache or Operation receipt. A lost reply is reminted
        // only while the requesting Node's root courier keeps its worker drained.
        try {
            $lock = Cache::lock('swamp:node-worker-issue:'.$node->id, 200);
            $lock->block(1);
            try {
                $pair = $this->agents->run($issuer, 'swamp.issue-node-worker', ['node_id' => $node->id]);
                if (($pair['node_id'] ?? null) !== $node->id || ! is_string($pair['enrollment_token'] ?? null)
                    || ! is_string($pair['server_token'] ?? null) || ! is_string($pair['expires_at'] ?? null)
                    || count($pair) !== 4) {
                    throw new \RuntimeException;
                }

                return ['node_id' => $node->id, 'enrollment_token' => $pair['enrollment_token'],
                    'server_token' => $pair['server_token'], 'expires_at' => $pair['expires_at']];
            } finally {
                $lock->release();
            }
        } catch (Throwable) {
            // Native diagnostics and credentials must never enter API errors.
            throw new ResourceOperationException('swamp.issuer_unavailable', 'The Node worker issuer is unavailable.', 503);
        }
    }
}
