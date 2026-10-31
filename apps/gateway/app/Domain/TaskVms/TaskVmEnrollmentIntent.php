<?php

declare(strict_types=1);

namespace App\Domain\TaskVms;

use App\Actions\Processes\RecordProcessIntentAction;
use App\Data\Processes\AddProcessData;
use App\Domain\Nodes\RoleName;
use App\Domain\Processes\DesiredProcessState;
use App\Domain\Processes\ProcessOperationAdmission;
use App\Domain\Processes\ProcessRuntime;
use App\Domain\Processes\ProcessTarget;
use App\Domain\Processes\ProcessTargetType;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentDelivery;
use App\Infrastructure\Nodes\NodeAgentSshExecutor;
use App\Infrastructure\Nodes\NodeBootstrapCommandFactory;
use App\Infrastructure\Processes\SystemdProcessRenderer;
use App\Infrastructure\Tasks\Pi\PiConnection;
use App\Infrastructure\TaskVms\TaskVmRuntime;
use App\Models\Node;
use App\Models\Process;
use App\Models\TaskVm;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/** Shared intent capture for enrollment and its provisioning parent. No host effects or child Operations. */
final readonly class TaskVmEnrollmentIntent
{
    public function __construct(private TaskVmSettings $settings, private ModelKeyIssuer $keys,
        private NodeAgentSshExecutor $agents, private AgentDelivery $delivery,
        private RecordProcessIntentAction $processes, private SystemdProcessRenderer $systemd,
        private PiConnection $pi, private NodeBootstrapCommandFactory $bootstrap, private ProcessOperationAdmission $admissions) {}

    /** @return array<string,mixed> */
    public function capture(TaskVm $vm): array
    {
        return DB::transaction(function () use ($vm): array {
            $vm->refresh();
            if ($vm->state !== TaskVmState::Provisioning || $vm->group === null || $vm->group->parent_id !== null) {
                throw new TaskVmException('task_vm.enrollment_invalid', 'Enrollment requires the provisioning VM owned by a task group.');
            }
            $origin = $this->keys->origin();
            if (($vm->ai_router_origin !== null && $vm->ai_router_origin !== $origin)
                || $this->settings->piArtifactPath === null || $this->settings->piArtifactSha256 === null
                || $this->settings->piModels === [] || $vm->pi_token === null) {
                throw new TaskVmException('task_vm.invalid_config', 'Enrollment requires the pinned Pi artifact, models and group credentials.');
            }
            $this->keys->assertReady();
            $node = $this->reserveNode($vm);
            $pin = $this->delivery->pin($node);
            $process = $this->recordPiProcess($node);
            $installation = Arr::only($this->admissions->inputFor($process, 'create', target: $this->processTarget($node)),
                ['process_id', 'name', 'runtime', 'content_hash', 'desired', 'on_demand', 'activation_pending']);
            if ($vm->model_key === null) {
                $vm->update(['model_key' => bin2hex(random_bytes(32)), 'ai_router_origin' => $origin]);
            }

            return [
                'task_vm_id' => $vm->id, 'guest_node_id' => $node->id,
                'execution_node_id' => $this->executionNode($vm)->id,
                'provider' => $vm->provider->value, 'name' => $vm->name, 'user' => $node->user, 'pi_url' => $this->pi->baseUrl($node),
                'agent_sha256' => $pin, 'agent' => $this->agents->configurationForRoot($node, null),
                'bootstrap' => $this->bootstrap->input($node, 'orbit'),
                'node' => ['node_id' => $node->id, 'name' => $node->name, 'user' => $node->user,
                    'platform' => $node->platform, 'architecture' => $node->architecture, 'wireguard_ip' => $node->wireguard_ip,
                    'cluster_id' => $node->cluster_id, 'ssh_jump_node_id' => $node->ssh_jump_node_id],
                'runtime' => ['guest_node_id' => $node->id, 'user' => $node->user, 'agent_sha256' => $pin,
                    'pi_sha256' => $this->settings->piArtifactSha256, 'origin' => $origin, 'models' => $this->settings->piModels,
                    'process' => [...$installation, 'agent_sha256' => $pin, 'retired' => null]],
            ];
        });
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

    private function executionNode(TaskVm $vm): Node
    {
        $node = $vm->provider === TaskVmProviderKind::Incus ? $vm->hostNode
            : Node::query()->where('status', LifecycleStatus::Active)->whereHas('roles', static fn ($query) => $query
                ->where('role', RoleName::Gateway)->where('status', LifecycleStatus::Active))->first();
        if ($node === null || $node->status !== LifecycleStatus::Active) {
            throw new TaskVmException('task_vm.host_unavailable', 'Enrollment requires its admitted active execution Node.');
        }

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

    /** Render only desired content; enrollment does not require an already-active Node. */
    private function processTarget(Node $node): ProcessTarget
    {
        return new ProcessTarget(node: $node, user: 'orbit', checkoutPath: '/home/orbit');
    }

    /** @return array{runtime:string,content:string} Private material for the shared Process lower action. */
    public function installation(TaskVm $vm): array
    {
        $node = $vm->node ?? throw new TaskVmException('task_vm.not_enrolled', 'The runtime has no admitted Node.');
        $process = Process::query()->where('owner_type', Node::class)->where('owner_id', $node->id)->where('name', 'pi-server')->sole();

        return ['runtime' => 'systemd', 'content' => $this->systemd->render($process, $this->processTarget($node))];
    }
}
