<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Domain\Instances\InstanceState;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Shared\ResourceOperationException;
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
final readonly class TaskProvisionProjection implements DirectOperationProjection
{
    public function __construct(private TaskVmProvisionIntent $vms, private TaskVmEnrollmentProjection $enrollment) {}

    public function input(Operation $operation): array
    {
        $input = $this->vms->input($operation);
        $intent = ValidatedData::object($input['intent']);
        $group = Task::topLevel()->findOrFail($operation->target_id);

        return [...$input, 'intent' => [...$intent, 'eligible' => ($intent['eligible'] ?? false) && $group->status === TaskGroupStatus::Reserved]];
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
            if (in_array($data['status'], ['succeeded', 'failed', 'interrupted', 'cancelled'], true) && ($result['ready'] ?? false) === true) {
                $provider = ValidatedData::object($result['provider'] ?? []);
                if (($result['task_vm_id'] ?? null) !== $vm->id || ($result['guest_node_id'] ?? null) !== $vm->node_id
                    || ($provider['image_fingerprint'] ?? null) !== $vm->image_fingerprint
                    || ($provider['running'] ?? null) !== true || ($provider['settled'] ?? null) !== true) {
                    throw new ResourceOperationException('operation.invalid_result', 'The Tasks result does not confirm its admitted VM and image.', 422);
                }
                $vm->update(Arr::only($provider, ['provider_id', 'address']));
                $this->enrollment->record($vm, $intent, ValidatedData::object($result['enrollment']));
                $vm->refresh();
            }
            if ($data['status'] === 'succeeded') {
                if (($result['ready'] ?? null) !== true) {
                    throw new ResourceOperationException('operation.invalid_result', 'Tasks provision requires confirmed VM readiness.', 422);
                }
                $source = ValidatedData::object($result['source']);
                $instance->update(['branch' => $source['branch'], 'starting_commit' => $source['commit'],
                    'selected_php_version' => $source['php_version'], 'source_is_laravel' => $source['laravel'],
                    'status' => InstanceState::SourceResolved, 'first_setup_pending' => false, 'failed_step' => null, 'error_code' => null]);
                $group->update(['status' => TaskGroupStatus::Running, 'started_at' => $group->started_at ?? now(),
                    'reserved_at' => null, 'capacity_wait_reason' => null]);
            } elseif (in_array($data['status'], ['failed', 'interrupted'], true)) {
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
