<?php

declare(strict_types=1);

namespace App\Infrastructure\TaskVms;

use App\Domain\Operations\DirectOperationPrivateBindings;
use App\Domain\Operations\OperationSecretSource;
use App\Domain\Operations\OperationSecretValue;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\TaskVms\TaskVmEnrollmentIntent;
use App\Domain\TaskVms\TaskVmEnrollmentOwner;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\Operation;
use App\Models\TaskVm;

/** Desired private files and existing connection material. Native steps own every effect. */
final readonly class TaskVmEnrollmentMaterialSource implements OperationSecretSource
{
    public function __construct(private string $field) {}

    public function name(): string
    {
        return 'task_vm.'.$this->field;
    }

    public function targetTypes(): array
    {
        return ['task_vm', 'task_group'];
    }

    public function ownerId(Operation $operation): ?int
    {
        return self::owned($operation)?->id;
    }

    public static function owned(Operation $operation): ?TaskVm
    {
        return TaskVmEnrollmentOwner::owned($operation);
    }

    public function resolve(Operation $operation, int $id, string $key, string $parameter): OperationSecretValue
    {
        $vm = self::owned($operation);
        if ($vm === null || $vm->id !== $id || $parameter !== $this->field) {
            $this->refuse();
        }
        $owner = app(DirectOperationPrivateBindings::class)->ownerNode($operation, $this->field);
        if ($this->field === 'installation') {
            if ($owner !== $vm->node_id) {
                $this->refuse();
            }

            return new OperationSecretValue(app(TaskVmEnrollmentIntent::class)->installation($vm));
        }
        if ($this->field === 'first_visit') {
            if ($owner === $vm->node_id && $vm->provider->value === 'incus') {
                return new OperationSecretValue(null);
            }
            if ($owner !== $vm->node_id || $vm->provider->value !== 'upcloud') {
                $this->refuse();
            }
            if ($vm->address === null || $vm->ssh_host_key === null) {
                return new OperationSecretValue(null);
            }

            return new OperationSecretValue(['kind' => 'ssh', 'address' => $vm->address,
                'publicKey' => $vm->ssh_host_key, 'identity' => app(SshKeyProvider::class)->privateKeyPath()]);
        }
        $this->refuse();
    }

    private function refuse(): never
    {
        throw new ResourceOperationException('operation.execution_forbidden', 'Enrollment material requires its admitted VM and owning Node.', 422);
    }
}
