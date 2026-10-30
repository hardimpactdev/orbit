<?php

declare(strict_types=1);

namespace App\Domain\DatabaseServers;

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\OperationSecretSource;
use App\Domain\Operations\OperationSecretValue;
use App\Domain\Shared\ResourceOperationException;
use App\Models\DatabaseExecutionIntent;
use App\Models\Operation;
use Illuminate\Support\Arr;

/** Resolves only protected admitted parameters after matching their target and Node. */
final readonly class DatabaseIntentSource implements OperationSecretSource
{
    public function targetTypes(): array
    {
        return ['database_server', 'instance', 'database_connection'];
    }

    public function name(): string
    {
        return 'database.intent';
    }

    public function ownerId(Operation $operation): ?int
    {
        $id = $operation->input['intent_id'] ?? null;
        if (! is_int($id)) {
            return null;
        }
        $intent = DatabaseExecutionIntent::query()->find($id);

        return $intent !== null && $intent->target_type === $operation->target_type
            && $intent->target_id === $operation->target_id && $intent->node_id === $operation->node_id ? $id : null;
    }

    public function resolve(Operation $operation, int $id, string $key, string $parameter): OperationSecretValue
    {
        if ($this->ownerId($operation) !== $id) {
            throw new ResourceOperationException('operation.execution_forbidden', 'The database intent belongs to another target.', 422);
        }
        $intent = DatabaseExecutionIntent::recorded($id);
        if ($key === '' && $operation->kind === 'database_user.create' && DirectOperation::owns($operation)
            && in_array($parameter, ['password', 'sql'], true)) {
            $key = 'database-apply';
        }
        $command = $intent->payload['commands'][$key] ?? null;
        if (! is_array($command) || ! in_array($parameter, $command['protected'] ?? [], true) || ! Arr::has($command['input'], $parameter)) {
            throw new ResourceOperationException('operation.execution_forbidden', 'This parameter is not protected database intent.', 422);
        }

        $value = Arr::get($command['input'], $parameter);
        if (in_array($parameter, ['environment', 'payload'], true) && $value === []) {
            $value = (object) [];
        }

        return new OperationSecretValue($value, $command['redactions'] ?? []);
    }
}
