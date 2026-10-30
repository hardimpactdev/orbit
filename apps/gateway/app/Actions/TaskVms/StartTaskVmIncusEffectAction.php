<?php

declare(strict_types=1);

namespace App\Actions\TaskVms;

use App\Actions\Operations\StartOperationAction;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\OperationIdempotency;
use App\Domain\TaskVms\TaskVmAgentExecution;
use App\Domain\TaskVms\TaskVmCapabilities;
use App\Domain\TaskVms\TaskVmException;
use App\Domain\TaskVms\TaskVmProviderKind;
use App\Domain\TaskVms\TaskVmSettings;
use App\Infrastructure\AgentCommands\AgentDelivery;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\Operation;
use App\Models\TaskVm;

/** Records one fixed host effect. It neither contacts the host nor changes the VM's lifecycle state. */
final readonly class StartTaskVmIncusEffectAction
{
    public function __construct(private StartOperationAction $start, private TaskVmSettings $settings, private SshKeyProvider $keys) {}

    public function execute(TaskVm $vm, string $verb): Operation
    {
        TaskVmCapabilities::require('receipts');
        $input = $this->snapshot($vm, $verb);
        if (app(DirectOperation::class)->writer('task_vm.incus_'.$verb) === 'direct') {
            $intent = new Operation(['kind' => 'task_vm.incus_'.$verb,
                'target_type' => 'task_vm', 'target_id' => $vm->id, 'node_id' => $vm->host_node_id,
                'input' => $input]);
            $intent->parent_operation_id = app(OperationIdempotency::class)->parent?->id;
            app(TaskVmAgentExecution::class)->assertIntent($intent);
            $input['agent_sha256'] = app(AgentDelivery::class)->pin($vm->incusHostNode());
        }

        return $this->start->execute('task_vm.incus_'.$verb, 'task_vm', $vm->id, $vm->host_node_id, $input, 'task-vm', null);
    }

    /** @return array{task_vm_id:int,vm:array{project:string,name:string,cidr:string},operation:array<string,mixed>} */
    public function snapshot(TaskVm $vm, string $verb): array
    {
        if ($vm->provider !== TaskVmProviderKind::Incus || ! in_array($verb, ['create', 'start', 'stop', 'delete'], true)) {
            throw new TaskVmException('task_vm.invalid_effect', 'The VM does not admit this Incus effect.');
        }
        $host = $this->settings->host((int) $vm->host_node_id);
        $node = $vm->incusHostNode();
        $operation = ['kind' => $verb];
        if ($verb === 'create') {
            $resources = $vm->size->resources();
            $operation += [
                'image' => $vm->image_fingerprint ?? $host->imageAlias,
                'expected_fingerprint' => $vm->recipe_identity === null ? null : $vm->image_fingerprint,
                'base_alias' => $host->imageAlias, 'gateway_public_key' => $this->keys->publicKey(),
                'cpus' => $resources->cpusOn($host), 'memory' => $resources->memoryOn($host), 'disk' => $resources->diskOn($host),
                'network' => $host->network, 'pool' => $host->pool, 'topology' => $resources->testNodes > 0,
            ];
        } elseif ($verb === 'delete') {
            $operation['pool'] = $host->pool;
        }

        return ['task_vm_id' => $vm->id, 'vm' => ['project' => $host->project, 'name' => $vm->name, 'cidr' => $host->cidr], 'operation' => $operation];
    }
}
