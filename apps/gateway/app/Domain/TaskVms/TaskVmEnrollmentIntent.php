<?php

declare(strict_types=1);

namespace App\Domain\TaskVms;

use App\Actions\Processes\RecordProcessIntentAction;
use App\Data\Processes\AddProcessData;
use App\Domain\Nodes\RoleName;
use App\Domain\Processes\DesiredProcessState;
use App\Domain\Processes\ProcessRuntime;
use App\Domain\Processes\ProcessTarget;
use App\Domain\Processes\ProcessTargetType;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentDelivery;
use App\Infrastructure\Nodes\NodeAgentSshExecutor;
use App\Infrastructure\Processes\SystemdProcessRenderer;
use App\Infrastructure\Tasks\Pi\PiConnection;
use App\Infrastructure\TaskVms\TaskVmRuntime;
use App\Models\Node;
use App\Models\Process;
use App\Models\TaskVm;
use Illuminate\Support\Facades\DB;

/** The Node, `app-dev` role and `pi-server` Process of a task start's VM, and the files of that Node. No host effects or child Operations. */
final readonly class TaskVmEnrollmentIntent
{
    public function __construct(private TaskVmSettings $settings, private NodeAgentSshExecutor $agents, private AgentDelivery $delivery,
        private RecordProcessIntentAction $processes, private SystemdProcessRenderer $systemd, private PiConnection $pi) {}

    /**
     * The Node of a task start's VM, its `app-dev` role and its `pi-server` Process, made at admission and deleted at its release. Mints
     * nothing: the keys come from {@see TaskVmSlotIdentity}, and the Pi token from the task start.
     */
    public function slotNode(TaskVm $vm): Node
    {
        return DB::transaction(function () use ($vm): Node {
            $vm->refresh();
            if ($vm->state !== TaskVmState::Provisioning || $vm->slot_project_id === null) {
                throw new TaskVmException('task_vm.enrollment_invalid', 'A task start requires its provisioning slot.');
            }
            $node = $this->reserveNode($vm);
            $this->delivery->pin($node);
            $this->recordPiProcess($node);

            return $node;
        });
    }

    /**
     * The files of a slot's Node that a task start writes: the agent configuration and the `pi-server` unit.
     *
     * @return array{agent:array<string,mixed>,unit_name:string,unit_path:string,unit:string}
     */
    public function slotFiles(Node $node): array
    {
        $process = Process::query()->where('owner_type', Node::class)->where('owner_id', $node->id)->where('name', 'pi-server')->sole();

        return ['agent' => $this->agents->configurationForRoot($node, null), 'unit_name' => $this->systemd->unitName($process),
            'unit_path' => $this->systemd->unitPath($process), 'unit' => $this->systemd->render($process, $this->processTarget($node))];
    }

    private function reserveNode(TaskVm $vm): Node
    {
        $node = $vm->node_id === null ? null : Node::query()->find($vm->node_id);
        if (($vm->node_id !== null && $node === null)
            || ($node !== null && (TaskVm::query()->where('node_id', $node->id)->whereKeyNot($vm->id)->exists()
                || $node->name !== $vm->name || $node->user !== 'orbit' || $node->platform !== 'linux'
                || $node->architecture !== 'x86_64' || $node->wireguard_ip !== $vm->wireguard_ip
                || $node->cluster_id !== $this->settings->devClusterId || $node->ssh_jump_node_id !== $vm->host_node_id))
            || ($node === null && Node::query()->where('name', $vm->name)->exists())) {
            throw new TaskVmException('task_vm.node_ownership_conflict', 'The VM does not own this enrollment Node.');
        }
        if ($node === null) {
            $node = Node::query()->create(['name' => $vm->name, 'status' => LifecycleStatus::Provisioning,
                'user' => 'orbit', 'platform' => 'linux', 'architecture' => 'x86_64',
                'wireguard_ip' => $vm->wireguard_ip, 'public_ssh_host' => $vm->address ?? $vm->wireguard_ip,
                'cluster_id' => $this->settings->devClusterId, 'ssh_jump_node_id' => $vm->host_node_id]);
            $vm->update(['node_id' => $node->id]);
            $vm->setRelation('node', $node);
        }

        $node->roles()->firstOrCreate(['role' => RoleName::AppDev], ['status' => LifecycleStatus::Provisioning]);

        return $node;
    }

    private function recordPiProcess(Node $node): Process
    {
        $target = $this->processTarget($node);
        $home = $target->defaultWorkingDirectory;
        $data = new AddProcessData(targetType: ProcessTargetType::Node, targetId: $node->id, name: 'pi-server', runtime: ProcessRuntime::Systemd,
            command: [$home.'/.local/bin/pi-server', 'serve', '--host='.$node->wireguard_ip, '--port='.$this->pi->port(),
                '--token-file='.$home.'/.pi/agent/orbit-token', '--allow-provider='.TaskVmRuntime::PiProvider],
            image: null, workingDirectory: null, environment: [], ports: [], volumes: [], restartPolicy: 'always', start: true, keepAlive: true);
        $process = $this->processes->execute($data, $target)['process'];
        if ($process->source_definition_id !== null || $process->retired_runtime !== null
            || $process->desired_state !== DesiredProcessState::Running || $process->status === LifecycleStatus::Removing) {
            throw new ResourceOperationException('process.name_taken', 'The recorded pi-server Process has a different definition.', 409);
        }

        return $process;
    }

    /** Render only desired content; the Node is not active yet. */
    private function processTarget(Node $node): ProcessTarget
    {
        return new ProcessTarget(node: $node, user: 'orbit', checkoutPath: '/home/orbit');
    }
}
