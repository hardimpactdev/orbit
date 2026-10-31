<?php

declare(strict_types=1);

namespace App\Domain\Schedules;

use App\Domain\Operations\OperationSecretSource;
use App\Domain\Operations\OperationSecretValue;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;
use App\Models\Operation;
use App\Models\Schedule;

/** Existing member private sources, transported only inside the owning parent callback. */
final readonly class ScheduleInstanceMaterial implements OperationSecretSource
{
    public function __construct(private ScheduleOperationSecrets $secrets) {}

    public function name(): string
    {
        return 'schedule.instance_installations';
    }

    public function targetTypes(): array
    {
        return ['instance'];
    }

    public function ownerId(Operation $operation): ?int
    {
        return $operation->kind === 'schedule.install_instance_definitions' && $operation->target_type === 'instance'
            && $operation->target_id === ($operation->input['instance_id'] ?? null)
            && Instance::query()->whereKey($operation->target_id)->where('node_id', $operation->node_id)->exists()
            ? $operation->target_id : null;
    }

    public function resolve(Operation $operation, int $id, string $key, string $parameter): OperationSecretValue
    {
        if ($this->ownerId($operation) !== $id) {
            throw new ResourceOperationException('operation.execution_forbidden', 'The installation does not belong to this Instance and Node.', 422);
        }
        $values = [];
        $redactions = [];
        foreach (ScheduleInstanceOperation::members($operation) as $member) {
            $record = Schedule::query()->findOrFail($member['id']);
            if (! Instance::isMorphType($record->target_type) || $record->target_id !== $id) {
                throw new ResourceOperationException('operation.execution_forbidden', 'The runtime member belongs to another owner.', 422);
            }
            $value = $this->secrets->resolve(ScheduleInstanceOperation::member($operation, $member), $id, $key, 'command');
            $values[(string) $member['id']] = ['command' => $value->value];
            $redactions = [...$redactions, ...$value->strings()];
        }

        return new OperationSecretValue($values, $redactions);
    }
}
