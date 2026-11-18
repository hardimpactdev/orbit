<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Domain;

use App\Domain\Operations\OperationKinds;
use App\Domain\Operations\OperationSecretSource;
use App\Domain\Operations\OperationSecretValue;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;
use Orbit\Extensions\Conn\Models\ConnLaunchIntent;
use stdClass;

final readonly class ConnLaunchDispatchSource implements OperationSecretSource
{
    public const string Kind = OperationKinds::ConnLaunchDispatch;

    public const string Field = 'dispatch_request';

    public const string Name = 'conn.launch.dispatch';

    public function name(): string
    {
        return self::Name;
    }

    public function targetTypes(): array
    {
        return ['instance'];
    }

    public function ownerId(Operation $operation): ?int
    {
        $id = $operation->input['launch_operation_id'] ?? null;
        if ($operation->kind !== self::Kind || $operation->target_type !== 'instance' || $operation->status->terminal() || ! is_int($id)) {
            return null;
        }
        $launch = Operation::query()->where('kind', OperationKinds::ConnLaunch)->find($id);

        return $launch !== null && $launch->cancel_requested_at === null && ! $launch->status->terminal()
            && isset($launch->state['dispatch_committed_at']) && ($launch->state['launch_dispatch_operation_id'] ?? null) === $operation->id
            && $launch->node_id === $operation->node_id && $launch->triggered_by === $operation->triggered_by
            && ConnLaunchChild::instanceId($launch) === $operation->target_id ? $id : null;
    }

    public function resolve(Operation $operation, int $id, string $key, string $parameter): OperationSecretValue
    {
        if ($key !== '' || $parameter !== self::Field || $this->ownerId($operation) !== $id) {
            $this->refuse();
        }
        $encoded = ConnLaunchIntent::query()->find($id)?->dispatch;
        $frozen = $encoded === null ? null : json_decode($encoded, flags: JSON_THROW_ON_ERROR);
        if (! $frozen instanceof stdClass || ! ($frozen->request ?? null) instanceof stdClass
            || ! ($frozen->request->initialMessage ?? null) instanceof stdClass || ! is_string($text = $frozen->request->initialMessage->text ?? null)) {
            $this->refuse();
        }

        return new OperationSecretValue($frozen, [$text, json_encode($text, JSON_THROW_ON_ERROR)]);
    }

    private function refuse(): never
    {
        throw new ResourceOperationException('operation.execution_forbidden', 'The frozen launch does not belong to this native Operation.', 422);
    }
}
