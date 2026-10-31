<?php

declare(strict_types=1);

namespace App\Infrastructure\TaskVms;

use App\Domain\Operations\DirectOperationPrivateBindings;
use App\Domain\Operations\OperationSecretOutputStore;
use App\Domain\Operations\OperationSecretSource;
use App\Domain\Operations\OperationSecretValue;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\TaskVms\TaskVmProviderKind;
use App\Models\Operation;
use App\Models\TaskVm;

/** One existing SSH host-key pair, staged before its native provider create and never replaced. */
final readonly class TaskVmProviderBootstrapStore implements OperationSecretOutputStore, OperationSecretSource
{
    public function name(): string
    {
        return 'task_vm.provider_bootstrap';
    }

    public function targetTypes(): array
    {
        return ['task_vm', 'task_group'];
    }

    public function ownerId(Operation $operation): ?int
    {
        if (! in_array($operation->kind, ['task_vm.provision', 'tasks.provision'], true)) {
            return null;
        }
        $vm = TaskVmEnrollmentMaterialSource::owned($operation);
        if ($vm?->provider !== TaskVmProviderKind::UpCloud
            || app(DirectOperationPrivateBindings::class)->ownerNode($operation, 'provider_bootstrap') !== $vm->node_id) {
            return null;
        }

        return $vm->id;
    }

    public function resolve(Operation $operation, int $id, string $key, string $parameter): OperationSecretValue
    {
        if ($id !== $this->ownerId($operation) || $parameter !== 'provider_bootstrap') {
            $this->refuse();
        }

        return new OperationSecretValue(TaskVm::query()->findOrFail($id)->provider_bootstrap);
    }

    public function store(Operation $operation, int $ownerId, #[\SensitiveParameter] mixed $value): void
    {
        if ($ownerId !== $this->ownerId($operation) || ! is_array($value) || count($value) !== 2
            || ! is_string($value['private'] ?? null) || ! is_string($value['public'] ?? null)
            || strlen($value['private']) > 16384 || strlen($value['public']) > 1024
            || preg_match('/\A-----BEGIN OPENSSH PRIVATE KEY-----\n[A-Za-z0-9+\/=\n]+\n-----END OPENSSH PRIVATE KEY-----\n?\z/D', $value['private']) !== 1
            || preg_match('/\Assh-ed25519 [A-Za-z0-9+\/]+={0,2}(?: [!-~]+)?\z/D', $value['public']) !== 1) {
            $this->refuse();
        }
        $pair = ['private' => $value['private'], 'public' => $value['public']];
        $vm = TaskVm::query()->findOrFail($ownerId);
        if ($vm->provider_bootstrap === null) {
            if ($vm->ssh_host_key !== null && $vm->ssh_host_key !== $pair['public']) {
                $this->refuse();
            }
            $vm->forceFill(['provider_bootstrap' => $pair]);
            TaskVm::query()->whereKey($ownerId)->whereNull('provider_bootstrap')
                ->where(static fn ($query) => $query->whereNull('ssh_host_key')->orWhere('ssh_host_key', $pair['public']))
                ->update(['provider_bootstrap' => $vm->getAttributes()['provider_bootstrap'], 'ssh_host_key' => $pair['public']]);
        }
        $vm->refresh();
        if ($vm->provider_bootstrap !== $pair || $vm->ssh_host_key !== $pair['public']) {
            $this->refuse();
        }
    }

    private function refuse(): never
    {
        throw new ResourceOperationException('operation.secret_output_unavailable', 'Provider host keys require their admitted VM and cannot be replaced.', 409);
    }
}
