<?php

declare(strict_types=1);

namespace App\Domain\TaskVms;

use App\Actions\TaskVms\FailTaskVmAction;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/** Record native outcome and VM state; Swamp owns provider, enrollment and runtime validation. */
final readonly class TaskVmProvisionProjection implements DirectOperationProjection
{
    public function __construct(private TaskVmProvisionIntent $intent, private FailTaskVmAction $fail, private TaskVmEnrollmentProjection $enrollment) {}

    public function input(Operation $operation): array
    {
        return $this->intent->input($operation);
    }

    public function apply(Operation $operation, array $data): Operation
    {
        return DB::transaction(function () use ($operation, $data): Operation {
            $operation = Operation::query()->lockForUpdate()->findOrFail($operation->id);
            $result = ValidatedData::object($data['result'] ?? []);
            if ($operation->status->terminal()) {
                return app(DirectOperation::class)->project($operation, $data, $result);
            }
            $vm = $this->intent->owned($operation, lock: true);
            $status = OperationStatus::from(ValidatedData::string($data['status']));
            $projected = app(DirectOperation::class)->project($operation, $data, $result);
            if ($status === OperationStatus::Succeeded) {
                if (($result['task_vm_id'] ?? null) !== $vm->id || ($result['guest_node_id'] ?? null) !== $vm->node_id || ($result['ready'] ?? null) !== true) {
                    throw new ResourceOperationException('operation.invalid_result', 'The native provision result is incomplete.', 422);
                }
                $provider = ValidatedData::object($result['provider'] ?? []);
                if (($provider['image_fingerprint'] ?? null) !== $vm->image_fingerprint || ($provider['running'] ?? null) !== true || ($provider['settled'] ?? null) !== true) {
                    throw new ResourceOperationException('operation.invalid_result', 'The provider result does not confirm the admitted image.', 422);
                }
                $vm->update(Arr::only($provider, ['provider_id', 'address']));
                $this->enrollment->record($vm, ValidatedData::object($operation->input['enrollment']), ValidatedData::object($result['enrollment'] ?? []));
            } elseif ($status === OperationStatus::Failed) {
                $this->fail->execute($vm, [TaskVmState::Provisioning], ValidatedData::string($data['error_code']), 'The task VM provisioning failed.', 'The task VM provisioning failed.');
            }

            return $projected;
        });
    }
}
