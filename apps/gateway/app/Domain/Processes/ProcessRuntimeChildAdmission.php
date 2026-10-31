<?php

declare(strict_types=1);

namespace App\Domain\Processes;

use App\Domain\Operations\OperationChildAdmission;
use App\Domain\Operations\OperationChildIntent;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\TaskVms\TaskVmState;
use App\Infrastructure\Tasks\Pi\PiConnection;
use App\Infrastructure\TaskVms\TaskVmRuntime;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Process;
use App\Models\TaskVm;

/** Admits recorded Instance runtime children or the fixed Process of a task VM. */
final readonly class ProcessRuntimeChildAdmission implements OperationChildAdmission
{
    public function __construct(private ProcessOperationAdmission $admission, private ProcessTargetResolver $targets, private PiConnection $pi) {}

    public function admit(Operation $parent, array $intent): Operation
    {
        $id = $intent['input']['process_id'] ?? null;
        $verb = match ($parent->kind) {
            'process.install_instance_definitions', 'task_vm.prepare_runtime' => 'create',
            'process.restart_instance' => 'restart',
            default => null,
        };
        if (! is_int($id) || $verb === null || ! OperationChildIntent::same($intent['input'], ['process_id' => $id])
            || $intent['kind'] !== 'process.'.$verb || $parent->target_type !== ($parent->kind === 'task_vm.prepare_runtime' ? 'task_vm' : 'instance')
            || $intent['node_id'] !== $parent->node_id) {
            $this->refuse();
        }
        $process = Process::query()->whereKey($id)->firstOrFail();
        if ($parent->kind === 'task_vm.prepare_runtime') {
            $this->assertTaskVmProcess($parent, $process);

            return $this->admission->execute($process, 'create');
        }
        if (! Instance::isMorphType($process->owner_type) || $process->owner_id !== $parent->target_id
            || $this->targets->forInspection($process)->node->id !== $parent->node_id) {
            $this->refuse();
        }
        if ($verb === 'restart' && ($process->status !== LifecycleStatus::Active || $process->desired_state !== DesiredProcessState::Running || ! $process->restart_on_deploy)) {
            $this->refuse();
        }
        if ($verb === 'create' && ($process->source_definition_id === null || $process->desired_state !== DesiredProcessState::Stopped || in_array($process->status, [LifecycleStatus::Active, LifecycleStatus::Removing], true))) {
            $this->refuse();
        }

        return $this->admission->execute($process, $verb);
    }

    private function assertTaskVmProcess(Operation $parent, Process $process): void
    {
        $vm = TaskVm::query()->find($parent->target_id);
        if ($vm === null || $vm->state !== TaskVmState::Provisioning || $vm->node_id !== $parent->node_id || $vm->node_id === null
            || $process->owner_type !== Node::class || $process->owner_id !== $vm->node_id
            || $process->source_definition_id !== null || $process->retired_runtime !== null
            || $process->name !== 'pi-server' || $process->runtime !== ProcessRuntime::Systemd
            || $process->desired_state !== DesiredProcessState::Running || $process->status === LifecycleStatus::Removing
            || $process->restart_policy !== 'always' || ! $process->keep_alive || ! $process->restart_on_deploy) {
            $this->refuse('task VM');
        }
        $target = $this->targets->forInspection($process);
        $home = $target->defaultWorkingDirectory;
        $configuration = ['environment_file' => '', 'command' => [
            $home.'/.local/bin/pi-server', 'serve', '--host='.$target->node->wireguard_ip, '--port='.$this->pi->port(),
            '--token-file='.$home.'/.pi/agent/orbit-token', '--allow-provider='.TaskVmRuntime::PiProvider,
        ]];
        if ($process->working_directory !== $home || ! OperationChildIntent::same($process->runtime_config, $configuration)) {
            $this->refuse('task VM');
        }
    }

    private function refuse(string $owner = 'Instance'): never
    {
        throw new ResourceOperationException('operation.child_forbidden', 'This Process is not an eligible runtime child of the '.$owner.'.', 422);
    }
}
