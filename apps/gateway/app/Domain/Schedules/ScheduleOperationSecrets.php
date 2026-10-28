<?php

declare(strict_types=1);

namespace App\Domain\Schedules;

use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;
use App\Models\Schedule;
use App\Support\ValidatedData;

final readonly class ScheduleOperationSecrets
{
    public function resolve(string $source, int|string $id, Operation $operation): string
    {
        $schedule = Schedule::query()->findOrFail($id);
        if ($source !== 'schedule.command' || $schedule->id !== ($operation->input['id'] ?? null)
            || $schedule->host_node_id !== $operation->node_id
            || ! hash_equals(ValidatedData::string($operation->input['command_hash']), hash('sha256', $schedule->command))) {
            throw new ResourceOperationException('operation.execution_forbidden', 'The secret reference does not belong to this Schedule.', 422);
        }

        return $schedule->command;
    }
}
