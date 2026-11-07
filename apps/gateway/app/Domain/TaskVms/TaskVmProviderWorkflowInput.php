<?php

declare(strict_types=1);

namespace App\Domain\TaskVms;

use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Swamp\SwampCommandStep;
use App\Infrastructure\Swamp\SwampSshExecutionInput;
use App\Infrastructure\TaskVms\UpCloudApi;
use App\Models\Node;
use App\Models\TaskVm;
use App\Support\ValidatedData;

/** Public provider identity and descriptor-only UpCloud execution copy. */
final readonly class TaskVmProviderWorkflowInput
{
    public function __construct(private TaskVmSettings $settings, private SwampSshExecutionInput $ssh, private SwampCommandStep $commands, private UpCloudApi $upcloud) {}

    /** @return array{provider_input:array<string,mixed>,execution_secrets:array<string,array{vault:string,key:string}>} */
    public function capture(TaskVm $vm): array
    {
        $incus = $vm->provider === TaskVmProviderKind::Incus;
        $host = $incus ? Node::query()->findOrFail($vm->host_node_id) : null;
        $location = $host instanceof Node ? $this->settings->host($host->id) : null;
        if ($vm->name !== 'tvm-'.$vm->id) {
            throw new ResourceOperationException('task_vm.provider_identity_invalid', 'The VM provider identity is unavailable.', 409);
        }

        return [
            'provider_input' => ['task_vm_id' => $vm->id, 'name' => $vm->name, 'provider' => $vm->provider->value,
                'provider_id' => $vm->provider_id, 'vm' => $location === null ? null : ['project' => $location->project, 'name' => $vm->name, 'pool' => $location->pool],
                'host_ssh_execution' => $host instanceof Node ? $this->ssh->forNode($host) : null],
            'execution_secrets' => $incus ? [] : ['upcloud' => $this->commands->executionCopy('orbit-control-inputs', 'task-vm-upcloud-token', $this->upcloud->token())],
        ];
    }

    /** @param array<string,mixed> $input
     * @return array{intent:array<string,mixed>,execution_secrets:array<string,mixed>}
     */
    public static function envelope(array $input): array
    {
        $copies = ValidatedData::object($input['execution_secrets'] ?? []);
        unset($input['execution_secrets']);

        return ['intent' => $input, 'execution_secrets' => $copies];
    }
}
