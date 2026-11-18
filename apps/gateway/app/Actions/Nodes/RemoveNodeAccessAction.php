<?php

declare(strict_types=1);

namespace App\Actions\Nodes;

use App\Data\Nodes\NodeAccessNodeData;
use App\Data\Nodes\RemovedNodeAccessData;
use App\Domain\Metrics\MetricsAccessRevoker;
use App\Domain\Nodes\NodeAccessAuthorizer;
use App\Domain\Nodes\NodeAccessRemovalHook;
use App\Domain\Shared\LifecycleStatus;
use App\Models\Node;
use App\Models\NodeAccess;
use Illuminate\Container\Attributes\Tag;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

final readonly class RemoveNodeAccessAction
{
    /** @param iterable<NodeAccessRemovalHook> $hooks */
    public function __construct(
        private NodeAccessAuthorizer $access,
        private MetricsAccessRevoker $metricsAccess,
        #[Tag(NodeAccessRemovalHook::class)] private iterable $hooks = [],
    ) {}

    public function execute(Node $consumer, Node $serving, Node $caller): RemovedNodeAccessData
    {
        $this->requireActive($consumer);
        $this->requireActive($serving);

        // The deletion and its hooks commit together before any fallible side effect, so no committed deletion misses a hook.
        // A failure there or in a hook's after-commit work still revokes Metrics access, so an open Grafana session is checked again.
        try {
            $wasDeleted = DB::transaction(function () use ($consumer, $serving): bool {
                $deleted = NodeAccess::query()
                    ->where('consumer_node_id', $consumer->id)
                    ->where('serving_node_id', $serving->id)
                    ->delete() === 1;

                if ($deleted) {
                    foreach ($this->hooks as $hook) {
                        $hook->removed($consumer, $serving);
                    }
                }

                return $deleted;
            });
        } finally {
            if ($this->access->isGatewayNode($serving)) {
                $this->metricsAccess->revoke();
            }
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
