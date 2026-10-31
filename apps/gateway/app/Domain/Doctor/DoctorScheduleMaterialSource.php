<?php

declare(strict_types=1);

namespace App\Domain\Doctor;

use App\Domain\Operations\DirectOperationPrivateBindings;
use App\Domain\Operations\OperationSecretSource;
use App\Domain\Operations\OperationSecretValue;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Schedule;
use App\Support\ValidatedData;
use Illuminate\Support\Arr;

/** Stored Schedule specifications are available only to their admitted host. */
final readonly class DoctorScheduleMaterialSource implements OperationSecretSource
{
    public function name(): string
    {
        return 'doctor.schedules';
    }

    public function targetTypes(): array
    {
        return ['node'];
    }

    public function ownerId(Operation $operation): ?int
    {
        return $operation->target_type === 'node' && $operation->target_id === $operation->node_id ? $operation->node_id : null;
    }

    public function resolve(Operation $operation, int $id, string $key, string $parameter): OperationSecretValue
    {
        $caller = request()->getUserResolver()();
        if (! $caller instanceof Node || $id !== $this->ownerId($operation) || $parameter !== 'schedules') {
            $this->refuse();
        }
        app(DirectOperationPrivateBindings::class)->readable($operation, ['schedules' => $this->name()], $caller->id);
        $material = [];
        foreach (Arr::array($operation->input, 'nodes') as $value) {
            $node = ValidatedData::object($value);
            if ($node['id'] !== $caller->id) {
                continue;
            }
            foreach (Arr::array($node, 'resources.schedules') as $value) {
                $row = ValidatedData::object($value);
                $schedule = Schedule::query()->findOrFail(ValidatedData::string($row['id']));
                if ($schedule->host_node_id !== $caller->id || ! hash_equals(ValidatedData::string($row['command_sha256']), hash('sha256', $schedule->command))) {
                    $this->refuse();
                }
                $material[(string) $schedule->id] = $schedule->command;
            }
        }

        return new OperationSecretValue($material);
    }

    private function refuse(): never
    {
        throw new ResourceOperationException('operation.execution_forbidden', 'The Schedule specification must match its admitted Node and digest.', 422);
    }
}
