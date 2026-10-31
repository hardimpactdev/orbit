<?php

declare(strict_types=1);

namespace App\Domain\Processes;

use App\Domain\Operations\OperationSecretSource;
use App\Domain\Operations\OperationSecretValue;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;
use App\Models\Operation;
use App\Models\Process;

/** Existing member private sources, transported only inside the owning parent callback. */
final readonly class ProcessInstanceMaterial implements OperationSecretSource
{
    public function __construct(private ProcessOperationSecrets $secrets) {}

    public function name(): string
    {
        return 'process.instance_installations';
    }

    public function targetTypes(): array
    {
        return ['instance'];
    }

    public function ownerId(Operation $operation): ?int
    {
        return $operation->kind === 'process.install_instance_definitions' && $operation->target_type === 'instance'
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
        foreach (ProcessInstanceOperation::members($operation) as $member) {
            $record = Process::query()->findOrFail($member['id']);
            if (! Instance::isMorphType($record->owner_type) || $record->owner_id !== $id) {
                throw new ResourceOperationException('operation.execution_forbidden', 'The runtime member belongs to another owner.', 422);
            }
            $value = $this->secrets->resolve(ProcessInstanceOperation::member($operation, $member), $record->id, $key, 'installation');
            $values[(string) $member['id']] = ['installation' => $value->value];
            $redactions = [...$redactions, ...$value->strings()];
        }

        return new OperationSecretValue($values, $redactions);
    }
}
