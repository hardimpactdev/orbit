<?php

declare(strict_types=1);

namespace App\Domain\TaskVms;

use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Swamp\SwampSshExecutionInput;
use App\Models\Node;
use App\Models\TaskVm;
use App\Support\ValidatedData;

/** Public Incus host and VM identity. */
final readonly class TaskVmProviderWorkflowInput
{
    public function __construct(private TaskVmSettings $settings, private SwampSshExecutionInput $ssh) {}

    /** @return array{provider_input:array<string,mixed>,execution_secrets:array<string,array{vault:string,key:string}>} */
    public function capture(TaskVm $vm): array
    {
        $host = Node::query()->findOrFail($vm->host_node_id);
        $location = $this->settings->host($host->id);
        if (! TaskVmName::accepts($vm)) {
            throw new ResourceOperationException('task_vm.provider_identity_invalid', 'The VM provider identity is unavailable.', 409);
        }

        return [
            'provider_input' => ['task_vm_id' => $vm->id, 'name' => $vm->name, 'provider' => $vm->provider->value,
                'vm' => ['project' => $location->project, 'name' => $vm->name, 'pool' => $location->pool],
                'host_ssh_execution' => $this->ssh->forNode($host)],
            'execution_secrets' => [],
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
