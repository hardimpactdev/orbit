<?php

declare(strict_types=1);

namespace App\Domain\Schedules;

use App\Domain\Operations\OperationSecretSource;
use App\Domain\Operations\OperationSecretValue;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Schedule;
use App\Support\ValidatedData;

final readonly class ScheduleOperationSecrets implements OperationSecretSource
{
    public function name(): string
    {
        return 'schedule.command';
    }

    public function targetTypes(): array
    {
        return ['instance_schedules', 'node_schedules'];
    }

    public function ownerId(Operation $operation): ?int
    {
        $scheduleId = $operation->input['id'] ?? null;
        if (! is_string($scheduleId)) {
            return null;
        }
        $schedule = Schedule::query()->whereKey($scheduleId)->first();
        if ($schedule === null || $schedule->target_id !== $operation->target_id || $schedule->host_node_id !== $operation->node_id) {
            return null;
        }
        $ownerType = Instance::isMorphType($schedule->target_type) ? 'instance_schedules'
            : ($schedule->target_type === Node::class ? 'node_schedules' : null);

        if ($ownerType !== $operation->target_type) {
            return null;
        }
        $placed = $ownerType === 'instance_schedules'
            ? Instance::query()->whereKey($schedule->target_id)->where('node_id', $operation->node_id)->exists()
            : $schedule->target_id === $operation->node_id && Node::query()->whereKey($schedule->target_id)->exists();

        return $placed ? $schedule->target_id : null;
    }

    public function resolve(Operation $operation, int $id, string $key, string $parameter): OperationSecretValue
    {
        if ($this->ownerId($operation) !== $id) {
            throw new ResourceOperationException('operation.execution_forbidden', 'The Schedule command does not belong to this target.', 422);
        }
        $schedule = Schedule::query()->whereKey(ValidatedData::string($operation->input['id']))->firstOrFail();
        if (! hash_equals(ValidatedData::string($operation->input['command_hash']), hash('sha256', $schedule->command))) {
            throw new ResourceOperationException('operation.execution_forbidden', 'The Schedule command changed after admission.', 422);
        }

        return new OperationSecretValue($schedule->command);
    }
}
