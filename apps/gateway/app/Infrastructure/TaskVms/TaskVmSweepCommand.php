<?php

declare(strict_types=1);

namespace App\Infrastructure\TaskVms;

use App\Actions\TaskVms\FailTaskVmAction;
use App\Domain\Operations\OperationEffectTransport;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\TaskVms\TaskVmFailedException;
use App\Domain\TaskVms\TaskVmSettings;
use App\Domain\TaskVms\TaskVmState;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\AgentCommands\AgentEffectReceipts;
use App\Models\Node;
use App\Models\Operation;
use App\Models\TaskVm;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\Cache;

/** Every deletion rechecks current ownership. Replaying one sweep never counts as a second missing observation. */
final readonly class TaskVmSweepCommand implements OperationEffectTransport
{
    use TaskVmTargetReceipt;
    use TaskVmTransportSchema;

    public function __construct(private TaskVmSweepRead $reads,
        private TaskVmSettings $settings, private UpCloudApi $api, private FailTaskVmAction $failures, private AgentCommandRunner $agents) {}

    /** @param array<string,mixed> $run
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function run(Operation $operation, array $run, #[\SensitiveParameter] array $input): array
    {
        $snapshot = $this->reads->read($operation);
        if (($input['action'] ?? null) === 'observe') {
            $sweep = ValidatedData::integer($operation->input['sweep_id']);
            foreach ($snapshot['live_ids'] as $id) {
                $key = 'task-vms:sweep-missing:'.$id;
                if (! in_array($id, $snapshot['missing_ids'], true)) {
                    Cache::forget($key);

                    continue;
                }
                $previous = Cache::get($key);
                if ($previous === $sweep) {
                    continue;
                }
                if (is_int($previous)) {
                    $vm = TaskVm::query()->find(ValidatedData::integer($id));
                    if ($vm !== null) {
                        $message = "Task VM [{$vm->name}] is gone from its host.";
                        $this->failures->execute($vm, [TaskVmState::Ready, TaskVmState::Parked], 'task_vm.vm_missing', $message, TaskVmFailedException::stoppedReason('task_vm.vm_missing', $message));
                    }
                }
                Cache::put($key, $sweep, 3600);
            }

            return ['ready' => true];
        }
        $deletion = ValidatedData::object($input['deletion']);
        $owned = array_any($snapshot['deletions'], static fn (array $candidate): bool => $candidate === $deletion);
        // Absence from the current orphan list is never authority to remove a newly claimed resource.
        if (! $owned) {
            return ['ready' => true];
        }
        $node = Node::query()->findOrFail($operation->node_id);
        if ($operation->input['provider'] === 'incus') {
            $host = $this->settings->host($node->id);
            if ($deletion['kind'] === 'instance') {
                $name = ValidatedData::string($deletion['name']);
                if (str_starts_with($name, 'tvm-image-') && ! TaskVmHostAudit::claimBuildCleanup($node, $name)) {
                    return ['ready' => true];
                }
                $result = $this->agents->run($node, 'incus.vm', AgentEffectReceipts::input($run, ['vm' => ['project' => $host->project, 'name' => $name, 'cidr' => $host->cidr], 'operation' => ['kind' => 'remove_instance']]));
                if (($result['ready'] ?? null) !== true) {
                    throw new ResourceOperationException('task_vm.host_command_failed', 'The orphan deletion was not confirmed.', 502);
                }
            } elseif ($deletion['kind'] === 'volume') {
                $name = ValidatedData::string($deletion['name']);
                $result = $this->agents->run($node, 'incus.volume', AgentEffectReceipts::input($run, ['location' => ['project' => $host->project, 'pool' => $host->pool], 'operation' => ['kind' => 'remove', 'name' => $name]]));
                if (($result['name'] ?? null) !== $name) {
                    throw new ResourceOperationException('task_vm.host_command_failed', 'The orphan volume deletion was not confirmed.', 502);
                }
            } else {
                throw new ResourceOperationException('operation.execution_forbidden', 'The orphan kind is not declared.', 422);
            }
        } else {
            $servers = new UpCloudServers($this->api->authenticated(ValidatedData::string($input['token'])));
            if ($deletion['kind'] === 'server' && ($input['action'] ?? null) === 'stop') {
                $server = $servers->find(ValidatedData::string($deletion['name']), ValidatedData::string($deletion['label']), ValidatedData::string($deletion['value']), ValidatedData::string($deletion['uuid']));
                if ($server !== null && $server->state !== 'stopped') {
                    $servers->stop($server->uuid, null);
                }
            } elseif ($deletion['kind'] === 'server') {
                $servers->delete(ValidatedData::string($deletion['name']), ValidatedData::string($deletion['label']), ValidatedData::string($deletion['value']), ValidatedData::string($deletion['uuid']));
            } elseif ($deletion['kind'] === 'template') {
                $servers->deleteTemplate(ValidatedData::string($deletion['uuid']));
            } else {
                throw new ResourceOperationException('operation.execution_forbidden', 'The orphan kind is not declared.', 422);
            }
        }

        return ['ready' => true];
    }

    /** @param array<string,mixed> $run */
    public function effectful(Operation $operation, array $run): bool
    {
        $input = ValidatedData::object($run['input']);

        return ($input['provider'] ?? null) === 'incus' && ($input['action'] ?? null) === 'delete';
    }

    /** @param array<string,mixed> $run
     * @param  array<string,mixed>  $input
     * @return array{effect_key:string,state:string,result:array<string,mixed>|null,sanitized:bool,effect_nonce:string|null,effect_created_at:int|null,exit_code:int|null,finished_at:string|null}
     */
    public function receipt(Operation $operation, array $run, #[\SensitiveParameter] array $input): array
    {
        $deletion = ValidatedData::object($input['deletion']);
        $receipt = $this->targetReceipt(Node::query()->findOrFail($operation->node_id), $deletion['kind'] === 'instance' ? 'incus.vm' : 'incus.volume', ValidatedData::string($run['effect_key'] ?? null));
        if ($receipt['state'] === 'succeeded') {
            $receipt['result'] = ['ready' => true];
        }

        return $receipt;
    }
}
