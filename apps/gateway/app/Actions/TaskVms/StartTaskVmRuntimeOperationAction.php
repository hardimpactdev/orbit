<?php

declare(strict_types=1);

namespace App\Actions\TaskVms;

use App\Actions\Operations\StartOperationAction;
use App\Data\Processes\AddProcessData;
use App\Domain\Processes\DesiredProcessState;
use App\Domain\Processes\ProcessRuntime;
use App\Domain\Processes\ProcessSpecification;
use App\Domain\Processes\ProcessTargetResolver;
use App\Domain\Processes\ProcessTargetType;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\TaskVms\ModelKeyIssuer;
use App\Domain\TaskVms\TaskVmCapabilities;
use App\Domain\TaskVms\TaskVmException;
use App\Domain\TaskVms\TaskVmSettings;
use App\Domain\TaskVms\TaskVmState;
use App\Infrastructure\Tasks\Pi\PiConnection;
use App\Infrastructure\TaskVms\TaskVmRuntime;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Process;
use App\Models\TaskVm;
use Illuminate\Support\Facades\DB;

/** Admits runtime intent and opaque credential references. No credential leaves its encrypted VM row. */
final readonly class StartTaskVmRuntimeOperationAction
{
    public function __construct(private StartOperationAction $start, private TaskVmSettings $settings, private ModelKeyIssuer $keys) {}

    public function execute(int $taskVmId): ?Operation
    {
        TaskVmCapabilities::require('children');
        TaskVmCapabilities::require('receipts');
        TaskVmCapabilities::requireComposition('task_vm.prepare_runtime', ['process.create']);
        TaskVmCapabilities::requireOwner('node.add');

        return DB::transaction(function () use ($taskVmId): ?Operation {
            $vm = TaskVm::query()->lockForUpdate()->find($taskVmId);
            if ($vm?->state !== TaskVmState::Provisioning) {
                return null;
            }
            $this->assertEnrolled($vm);
            TaskVmCapabilities::requireOwner('process.create');
            $input = $this->materialize($vm);
            $existing = Operation::query()->where('kind', 'task_vm.prepare_runtime')->where('target_type', 'task_vm')
                ->where('target_id', $vm->id)->whereNotIn('status', ['failed', 'cancelled'])->latest('id')->first();
            if ($existing !== null) {
                return $existing;
            }

            return $this->start->execute('task_vm.prepare_runtime', 'task_vm', $vm->id, $vm->node_id, $input, 'task-vm', null);
        });
    }

    /** @return array<string, mixed> */
    public function materialize(TaskVm $vm): array
    {
        $this->assertEnrolled($vm);
        $origin = $this->keys->origin();
        if ($vm->ai_router_origin !== null && $vm->ai_router_origin !== $origin) {
            throw new TaskVmException('task_vm.invalid_config', 'The model router origin changed while the VM holds a key.');
        }
        if ($this->settings->piArtifactPath === null || $this->settings->piArtifactSha256 === null || $this->settings->piModels === [] || $vm->pi_token === null) {
            throw new TaskVmException('task_vm.invalid_config', 'Runtime preparation requires the pinned Pi artifact, models and the group token.');
        }
        $this->keys->assertReady();
        if ($vm->model_key === null) {
            $vm->update(['model_key' => bin2hex(random_bytes(32)), 'ai_router_origin' => $origin]);
        }

        $this->recordPiProcess($vm);

        return $this->snapshot($vm);
    }

    /** Records intent only; the owning Process child performs installation. */
    private function recordPiProcess(TaskVm $vm): void
    {
        $node = $vm->node ?? throw new TaskVmException('task_vm.not_enrolled', 'The runtime has no Node.');
        $target = app(ProcessTargetResolver::class)->forNodeAdmission($node);
        $home = $target->defaultWorkingDirectory;
        $data = new AddProcessData(targetType: ProcessTargetType::Node, targetId: $node->id, name: 'pi-server', runtime: ProcessRuntime::Systemd,
            command: [$home.'/.local/bin/pi-server', 'serve', '--host='.$node->wireguard_ip, '--port='.app(PiConnection::class)->port(),
                '--token-file='.$home.'/.pi/agent/orbit-token', '--allow-provider='.TaskVmRuntime::PiProvider],
            image: null, workingDirectory: null, environment: [], ports: [], volumes: [], restartPolicy: 'always', start: true, keepAlive: true);
        $specifications = app(ProcessSpecification::class);
        $attributes = $specifications->attributes($data, $target);
        $process = Process::query()->where('owner_type', Node::class)->where('owner_id', $node->id)->where('name', 'pi-server')->lockForUpdate()->first();
        if ($process !== null) {
            if (! $specifications->matches($process, $attributes) || $process->source_definition_id !== null || $process->retired_runtime !== null
                || $process->desired_state !== DesiredProcessState::Running || $process->status === LifecycleStatus::Removing) {
                throw new ResourceOperationException('process.name_taken', 'The recorded pi-server Process has a different definition.', 409);
            }

            return;
        }
        Process::query()->create(['owner_type' => Node::class, 'owner_id' => $node->id, 'name' => 'pi-server', ...$attributes,
            'desired_state' => DesiredProcessState::Running, 'status' => LifecycleStatus::Provisioning]);
    }

    private function assertEnrolled(TaskVm $vm): void
    {
        if ($vm->node?->status !== LifecycleStatus::Active || $vm->group_id === null) {
            throw new TaskVmException('task_vm.not_enrolled', 'Runtime preparation requires the group\'s active task VM Node.');
        }
    }

    /** @return array<string, mixed> */
    public function snapshot(TaskVm $vm): array
    {
        return [
            'task_vm_id' => $vm->id, 'node_id' => $vm->node_id, 'user' => $vm->node?->user,
            'process_id' => Process::query()->where('owner_type', Node::class)->where('owner_id', $vm->node_id)->where('name', 'pi-server')->first()?->id,
            'origin' => $vm->ai_router_origin, 'sha256' => $this->settings->piArtifactSha256, 'models' => $this->settings->piModels,
            'artifact' => $this->settings->piArtifactPath, 'deadline_at' => now()->addSeconds(1500)->getTimestamp(),
            'model_key' => ['$secret' => 'task_vm.model_key', 'id' => $vm->id],
            'token' => ['$secret' => 'task_vm.pi_token', 'id' => $vm->id],
        ];
    }
}
