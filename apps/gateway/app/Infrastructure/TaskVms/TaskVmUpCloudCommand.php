<?php

declare(strict_types=1);

namespace App\Infrastructure\TaskVms;

use App\Domain\Operations\OperationTransport;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\TaskVms\TaskVmException;
use App\Domain\TaskVms\TaskVmProviderKind;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\Operation;
use App\Models\TaskVm;
use App\Support\ValidatedData;

/** One provider primitive. Credentials arrive only after kit v2 has authorized and hydrated the reference. */
final readonly class TaskVmUpCloudCommand implements OperationTransport
{
    use TaskVmTransportSchema;

    public function __construct(private UpCloudApi $api, private UpCloudGuest $guest, private SshKeyProvider $keys, private GuestBootstrap $bootstrap) {}

    /** @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function run(Operation $operation, array $run, #[\SensitiveParameter] array $input): array
    {
        $vm = TaskVm::query()->findOrFail(ValidatedData::integer($input['task_vm_id']));
        if ($vm->provider !== TaskVmProviderKind::UpCloud || $vm->id !== $operation->target_id || $vm->id !== ($operation->input['task_vm_id'] ?? null)) {
            throw new ResourceOperationException('operation.execution_forbidden', 'The provider VM does not match this Operation.', 422);
        }
        $provider = new UpCloudTaskVmProvider(new UpCloudServers($this->api->authenticated(ValidatedData::string($input['token']))), $this->guest, $this->keys, $this->bootstrap);
        if (($input['action'] ?? null) === 'delete') {
            app(TaskVmPreservation::class)->assertDeletion($operation, $vm);
        }
        try {
            match ($input['action'] ?? null) {
                'create' => $provider->allocate($vm, $this->keys->publicKey()),
                'start' => $provider->requestStart($vm),
                'stop' => $provider->requestStop($vm),
                'hard_stop' => $provider->requestStop($vm, hard: true),
                'delete' => $provider->destroy($vm),
                default => throw new ResourceOperationException('operation.execution_forbidden', 'The provider effect is not declared.', 422),
            };
        } catch (TaskVmException $error) {
            if (in_array($error->errorCode, ['task_vm.upcloud_failed', 'task_vm.upcloud_timeout'], true)) {
                // No receipt is cached for an uncertain provider reply. The same key reconciles by owned server label.
                throw $error;
            }

            return ['error_code' => $error->errorCode];
        }

        return ['ready' => true, 'provider_id' => $vm->refresh()->provider_id];
    }
}
