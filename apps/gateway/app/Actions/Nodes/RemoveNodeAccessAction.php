<?php

declare(strict_types=1);

namespace App\Actions\Nodes;

use App\Data\Nodes\NodeAccessNodeData;
use App\Data\Nodes\RemovedNodeAccessData;
use App\Domain\Metrics\MetricsAccessRevoker;
use App\Domain\Nodes\NodeAccessAuthorizer;
use App\Domain\Nodes\NodeAccessRemovalHook;
use App\Domain\Nodes\NodeAccessRemovalTransactionHook;
use App\Domain\Shared\LifecycleStatus;
use App\Models\Node;
use App\Models\NodeAccess;
use Illuminate\Container\Attributes\Tag;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

final readonly class RemoveNodeAccessAction
{
    /**
     * @param  iterable<NodeAccessRemovalHook>  $hooks
     * @param  iterable<NodeAccessRemovalTransactionHook>  $transactionHooks
     */
    public function __construct(
        private NodeAccessAuthorizer $access,
        private MetricsAccessRevoker $metricsAccess,
        #[Tag(NodeAccessRemovalHook::class)] private iterable $hooks = [],
        #[Tag(NodeAccessRemovalTransactionHook::class)] private iterable $transactionHooks = [],
    ) {}

    public function execute(Node $consumer, Node $serving, Node $caller): RemovedNodeAccessData
    {
        $this->requireActive($consumer);
        $this->requireActive($serving);

        // The deletion and its hooks commit together before any fallible side effect, so no committed deletion misses a hook.
        // A failure there or in a hook's after-commit work still tries the Metrics revoke, so an open Grafana session is checked
        // again, but the original failure is what the caller gets: a revoke error must not report a rolled-back removal as done.
        try {
            // The transaction begins IMMEDIATE, so the edge read, its delete and every hook's writes hold one write lock.
            $wasDeleted = DB::transaction(function () use ($consumer, $serving): bool {
                $edge = NodeAccess::query()
                    ->where('consumer_node_id', $consumer->id)
                    ->where('serving_node_id', $serving->id)
                    ->first();
                if (! $edge instanceof NodeAccess) {
                    return false;
                }
                $edge->delete();
                foreach ($this->transactionHooks as $hook) {
                    $hook->edgeDeleted($edge);
                }
                foreach ($this->hooks as $hook) {
                    $hook->removed($consumer, $serving);
                }

                return true;
            });
        } catch (Throwable $failure) {
            if ($this->access->isGatewayNode($serving)) {
                try {
                    $this->metricsAccess->revoke();
                } catch (Throwable $revoke) {
                    Log::warning('node_access.metrics_revoke_failed', ['consumer_node_id' => $consumer->id, 'exception' => $revoke->getMessage()]);
                }
            }

            throw $failure;
        }

        if ($this->access->isGatewayNode($serving)) {
            $this->metricsAccess->revoke();
        }

        $selfLockout = $wasDeleted && $caller->is($consumer) && $this->access->isGatewayNode($serving);

        return new RemovedNodeAccessData(
            consumerNode: NodeAccessNodeData::fromModel($consumer),
            servingNode: NodeAccessNodeData::fromModel($serving),
            alreadyAbsent: ! $wasDeleted,
            selfLockout: $selfLockout,
        );
    }

    private function requireActive(Node $node): void
    {
        if ($node->status !== LifecycleStatus::Active) {
            throw new ModelNotFoundException()->setModel(Node::class, [$node->id]);
        }
    }
}
