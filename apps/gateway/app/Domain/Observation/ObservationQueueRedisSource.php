<?php

declare(strict_types=1);

namespace App\Domain\Observation;

use App\Domain\DatabaseConnections\DatabaseDriver;
use App\Domain\Operations\DirectOperationPrivateBindings;
use App\Domain\Operations\OperationSecretSource;
use App\Domain\Operations\OperationSecretValue;
use App\Domain\Shared\ResourceOperationException;
use App\Models\DatabaseConnection;
use App\Models\Node;
use App\Models\Operation;
use Illuminate\Support\Arr;

/** Existing registered queue Redis login, resolved only for its admitted Gateway worker. */
final readonly class ObservationQueueRedisSource implements OperationSecretSource
{
    public function name(): string
    {
        return 'observation.queue_redis';
    }

    public function targetTypes(): array
    {
        return ['node'];
    }

    public function ownerId(Operation $operation): ?int
    {
        return $operation->kind === 'observation.maintain' && $operation->target_type === 'node' && (int) $operation->target_id === $operation->node_id ? $operation->node_id : null;
    }

    public function resolve(Operation $operation, int $id, string $key, string $parameter): OperationSecretValue
    {
        $caller = request()->getUserResolver()();
        if (! $caller instanceof Node || $caller->id !== $operation->node_id || app(DirectOperationPrivateBindings::class)->ownerNode($operation, 'queue_redis') !== $operation->node_id) {
            throw new ResourceOperationException('operation.execution_forbidden', 'Only the admitted Gateway worker may read queue credentials.', 422);
        }
        $redis = Arr::array($operation->input, 'redis', []);
        $connection = DatabaseConnection::query()->find(Arr::integer($redis, 'connection_id'));
        if ($id !== $this->ownerId($operation) || $parameter !== 'queue_redis' || ! $connection instanceof DatabaseConnection || $connection->driver !== DatabaseDriver::Redis
            || $connection->slug !== config('orbit.gateway_queue.connection') || $connection->host !== Arr::string($redis, 'host') || ($connection->port ?? 6379) !== Arr::integer($redis, 'port')) {
            throw new ResourceOperationException('operation.execution_forbidden', 'Queue credentials must match the admitted registered connection.', 422);
        }

        return new OperationSecretValue(['username' => $connection->username, 'password' => $connection->password]);
    }
}
