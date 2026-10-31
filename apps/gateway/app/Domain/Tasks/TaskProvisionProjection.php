<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Domain\Instances\InstanceState;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationPrivateBindings;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Operations\DirectOperationPublicView;
use App\Domain\TaskVms\TaskVmEnrollmentProjection;
use App\Domain\TaskVms\TaskVmProvisionIntent;
use App\Domain\TaskVms\TaskVmState;
use App\Models\Instance;
use App\Models\Operation;
use App\Models\Task;
use App\Support\ValidatedData;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/** State projection only. Swamp owns source, setup, runtime and readiness. */
final readonly class TaskProvisionProjection implements DirectOperationProjection, DirectOperationPublicView
{
    public function __construct(private TaskVmProvisionIntent $vms, private TaskVmEnrollmentProjection $enrollment) {}

    public function input(Operation $operation): array
    {
        $input = $this->vms->input($operation);
        $group = Task::topLevel()->findOrFail($operation->target_id);

        return [...Arr::except($input, ['git_read']), 'eligible' => $input['eligible'] && $group->status === TaskGroupStatus::Reserved];
    }

    public function assertPublicParticipant(Operation $operation, int $callerNodeId): void
    {
        app(DirectOperationPrivateBindings::class)->assertParticipant($operation, $callerNodeId);
    }

    public function publicInput(Operation $operation, int $callerNodeId): array
    {
        $this->assertPublicParticipant($operation, $callerNodeId);

        return $this->input($operation);
    }

    public function apply(Operation $operation, array $data): Operation
    {
        return DB::transaction(function () use ($operation, $data): Operation {
            $operation = Operation::query()->lockForUpdate()->findOrFail($operation->id);
            $result = ValidatedData::object($data['result'] ?? []);
            if ($operation->status->terminal()) {
                return app(DirectOperation::class)->project($operation, $data, $result);
            }
            $vm = $this->vms->owned($operation, lock: true);
            $group = Task::topLevel()->lockForUpdate()->findOrFail($operation->target_id);
            $instance = Instance::query()->where('task_workspace_owner_id', $group->id)
                ->where('node_id', $vm->node_id)->where('project_id', $group->project_id)->findOrFail(ValidatedData::integer($operation->input['instance_id']));
            $projected = app(DirectOperation::class)->project($operation, $data, $result);
            $intent = ValidatedData::object($operation->input['enrollment']);
            if ($data['status'] === 'running') {
                $vm->update(Arr::only(ValidatedData::object($result['provider'] ?? []), ['provider_id', 'address', 'ssh_host_key']));
                $this->enrollment->recordPublic($vm, $intent, $result);
                if (($result['phase'] ?? null) === 'vm-ready') {
                    $this->enrollment->record($vm, $intent, ValidatedData::object($result['enrollment']));
                }
            } elseif ($data['status'] === 'succeeded') {
                $this->enrollment->record($vm, $intent, ValidatedData::object($result['enrollment'] ?? []));
                $source = ValidatedData::object($result['source']);
                $instance->update(['branch' => $source['branch'], 'starting_commit' => $source['commit'],
                    'selected_php_version' => $source['php_version'], 'source_is_laravel' => $source['laravel'],
                    'status' => InstanceState::SourceResolved, 'first_setup_pending' => false, 'failed_step' => null, 'error_code' => null]);
                $group->update(['status' => TaskGroupStatus::Running, 'started_at' => $group->started_at ?? now(),
                    'reserved_at' => null, 'capacity_wait_reason' => null]);
            } elseif ($data['status'] === 'failed') {
                if ($vm->state === TaskVmState::Provisioning) {
                    $vm->update(['state' => TaskVmState::Failed, 'error_code' => $data['error_code'], 'error_message' => 'Native task provisioning failed.']);
                }
                $instance->update(['failed_step' => $result['failed_step'] ?? 'provision', 'error_code' => $data['error_code']]);
                $group->update(['status' => TaskGroupStatus::Failed, 'settled_at' => now(), 'reserved_at' => null, 'capacity_wait_reason' => null]);
            } elseif ($data['status'] === 'cancelled') {
                $group->update(['status' => TaskGroupStatus::Cancelled, 'settled_at' => now(), 'reserved_at' => null, 'capacity_wait_reason' => null]);
            }

            return $projected;
        });
    }
}
