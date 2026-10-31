<?php

declare(strict_types=1);

namespace App\Infrastructure\TaskVms;

use App\Domain\Operations\DirectOperationPrivateBindings;
use App\Domain\Operations\OperationSecretSource;
use App\Domain\Operations\OperationSecretValue;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\TaskVms\TaskVmEnrollmentIntent;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Infrastructure\WireGuard\VpnConfigurationRepository;
use App\Infrastructure\WireGuard\WireGuardServerConfigRenderer;
use App\Models\Node;
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
        if (! in_array($operation->kind, ['task_vm.enroll', 'task_vm.prepare_runtime', 'task_vm.provision', 'tasks.provision'], true)) {
            return null;
        }
        $id = $operation->input['task_vm_id'] ?? null;
        $vm = is_int($id) ? TaskVm::query()->find($id) : null;
        if ($vm === null || $vm->node_id === null
            || ($operation->target_type === 'task_vm' ? $vm->id !== $operation->target_id
                : ($operation->target_type !== 'task_group' || $vm->group_id !== $operation->target_id))) {
            return null;
        }
        $enrollment = $operation->input['enrollment'] ?? $operation->input;

        return is_array($enrollment) && ($enrollment['task_vm_id'] ?? null) === $vm->id
            && ($enrollment['guest_node_id'] ?? null) === $vm->node_id ? $vm : null;
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
        if ($this->field !== 'enrollment_network') {
            $this->refuse();
        }
        $node = $vm->node ?? throw new ResourceOperationException('task_vm.not_enrolled', 'The VM has no admitted Node.', 409);
        $vpn = app(VpnConfigurationRepository::class)->forPeer($node);
        if ($owner !== $vpn->server->id || $vpn->server->ssh_host_key_type !== 'ssh-ed25519'
            || $vpn->server->ssh_host_key === null || $vpn->server->wireguard_ip === null) {
            $this->refuse();
        }
        if ($node->wireguard_public_key === null) {
            return new OperationSecretValue(null);
        }

        return new OperationSecretValue(['hub' => ['address' => $vpn->server->wireguard_ip,
            'publicKey' => $vpn->server->ssh_host_key_type.' '.$vpn->server->ssh_host_key,
            'identity' => app(SshKeyProvider::class)->privateKeyPath()],
            'configuration' => app(WireGuardServerConfigRenderer::class)->render($vpn, Node::query()->get())], [$vpn->serverPrivateKey]);
    }

    private function refuse(): never
    {
        throw new ResourceOperationException('operation.execution_forbidden', 'Enrollment material requires its admitted VM and owning Node.', 422);
    }
}
