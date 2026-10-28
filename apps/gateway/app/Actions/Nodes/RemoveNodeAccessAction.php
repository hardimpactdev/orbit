<?php

declare(strict_types=1);

namespace App\Actions\Nodes;

use App\Actions\Operations\StartOperationAction;
use App\Data\Nodes\NodeAccessNodeData;
use App\Data\Nodes\RemovedNodeAccessData;
use App\Domain\Nodes\NodeAccessAuthorizer;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\AwaitOperation;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use App\Models\NodeAccess;
use App\Models\NodeRole;
use App\Models\Operation;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

final readonly class RemoveNodeAccessAction
{
    public function __construct(
        private NodeAccessAuthorizer $access,
        private StartOperationAction $operations,
    ) {}

    public function start(Node $consumer, Node $serving, Node $caller, ?string $requestId = null): RemovedNodeAccessData|Operation
    {
        $this->requireActive($consumer);
        $this->requireActive($serving);

        return DB::transaction(function () use ($consumer, $serving, $caller, $requestId): RemovedNodeAccessData|Operation {
            $wasDeleted = NodeAccess::query()
                ->where('consumer_node_id', $consumer->id)
                ->where('serving_node_id', $serving->id)
                ->exists();
            $gateway = $this->access->isGatewayNode($serving);
            $result = new RemovedNodeAccessData(
                consumerNode: NodeAccessNodeData::fromModel($consumer),
                servingNode: NodeAccessNodeData::fromModel($serving),
                alreadyAbsent: ! $wasDeleted,
                selfLockout: $wasDeleted && $caller->is($consumer) && $gateway,
            );
            if (! $gateway || ! NodeRole::query()->where('role', RoleName::Metrics)->exists()) {
                NodeAccess::query()->where('consumer_node_id', $consumer->id)->where('serving_node_id', $serving->id)->delete();

                return $result;
            }

            return $this->operations->execute('node.access_remove', 'node', $serving->id, $serving->id,
                ['access' => $result->toArray(), 'consumer_node_id' => $consumer->id, 'caller_node_id' => $caller->id], 'operator', $requestId);
        });
    }

    public function execute(Node $consumer, Node $serving, Node $caller): RemovedNodeAccessData
    {
        $result = $this->start($consumer, $serving, $caller);
        if ($result instanceof Operation) {
            $result = app(AwaitOperation::class)->execute($result);
            if ($result->status !== OperationStatus::Succeeded) {
                throw new ResourceOperationException($result->error_code ?? ($result->status === OperationStatus::Cancelled ? 'operation.cancelled' : 'operation.failed'),
                    'The Node Operation did not succeed.', 409, details: ['operation_id' => $result->id]);
            }

            return RemovedNodeAccessData::from($result->input['access']);
        }

        return $result;
    }

    private function requireActive(Node $node): void
    {
        if ($node->status !== LifecycleStatus::Active) {
            throw new ModelNotFoundException()->setModel(Node::class, [$node->id]);
        }
    }
}
