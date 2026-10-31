<?php

declare(strict_types=1);

namespace App\Actions\TaskVms;

use App\Actions\Operations\StartOperationAction;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\TaskVms\TaskVmImageBuildState;
use App\Domain\TaskVms\TaskVmSettings;
use App\Infrastructure\AgentCommands\AgentDelivery;
use App\Infrastructure\TaskVms\TaskVmHostAudit;
use App\Models\Node;
use App\Models\Operation;
use App\Models\TaskVmImageBuild;
use Illuminate\Support\Facades\DB;

/** Records host cleanup intent. Swamp owns inventory, deletion, waits and observation. */
final readonly class StartTaskVmSweepOperationAction
{
    public function __construct(private StartOperationAction $operations, private TaskVmSettings $settings, private AgentDelivery $agents) {}

    public function execute(int $nodeId, string $provider): Operation
    {
        $existing = Operation::query()->where('kind', 'task_vm.sweep_host')->where('target_type', 'task_vm_sweep')->where('target_id', $nodeId)->whereNotIn('status', ['succeeded', 'failed', 'cancelled'])->first();
        if ($existing !== null) {
            return $existing;
        }
        $node = Node::query()->findOrFail($nodeId);
        $input = ['node_id' => $nodeId, 'ci_runner' => $node->roles()->where('role', RoleName::CiRunner)->where('status', LifecycleStatus::Active)->exists(), 'provider' => $provider, 'deadline_at' => now()->addMinutes(30)->getTimestamp(), 'agent_sha256' => $this->agents->pin($node)];
        if ($provider === 'incus') {
            $host = $this->settings->host($nodeId);
            $input['host'] = ['project' => $host->project, 'pool' => $host->pool, 'cidr' => $host->cidr, 'operator_owned' => $host->operatorOwned];
        } elseif ($provider === 'upcloud' && $this->settings->upcloud !== null
            && $node->roles()->where('role', RoleName::Gateway)->where('status', LifecycleStatus::Active)->exists()) {
            $input['host'] = null;
            $input['upcloud_token'] = ['$secret' => 'upcloud.api', 'id' => $nodeId];
        } else {
            throw new ResourceOperationException('task_vm.invalid_config', 'A sweep requires its configured host or active Gateway provider.', 409);
        }

        return DB::transaction(function () use ($node, $provider, $input): Operation {
            // Retire expired build intent before dispatch. The existing conditional update arbitrates with resume;
            // no remote inventory, execution claim or worker lease is created here.
            $retired = [];
            if ($provider === 'incus') {
                foreach (TaskVmImageBuild::query()->where('host_node_id', $node->id)->where('state', '!=', TaskVmImageBuildState::Running)->orderBy('id')->get() as $build) {
                    if ($build->builder_vm_name !== null && TaskVmHostAudit::claimBuildCleanup($node, $build->builder_vm_name)) {
                        $retired[] = $build->id;
                    }
                }
            }

            return $this->operations->execute('task_vm.sweep_host', 'task_vm_sweep', $node->id, $node->id,
                [...$input, 'retired_build_ids' => $retired], 'task-vm', null);
        });
    }
}
