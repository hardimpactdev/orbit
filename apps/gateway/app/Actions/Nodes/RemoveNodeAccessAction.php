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

        // The transaction begins IMMEDIATE, so the edge read, its delete and every transaction hook's writes hold one write lock.
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

            return true;
        });

        if ($this->access->isGatewayNode($serving)) {
            $this->metricsAccess->revoke();
        }
        if ($wasDeleted) {
            // After the outermost commit: an enclosing transaction must commit the delete before an extension acts on it.
            DB::afterCommit(function () use ($consumer, $serving): void {
                foreach ($this->hooks as $hook) {
                    $hook->removed($consumer, $serving);
                }
            });
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
