<?php

declare(strict_types=1);

namespace App\Domain\TaskVms;

use App\Actions\TaskVms\FailTaskVmAction;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationPrivateBindings;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Operations\DirectOperationPublicView;
use App\Domain\Operations\OperationStatus;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/** Record native outcome and VM state; Swamp owns provider, enrollment and runtime validation. */
final readonly class TaskVmProvisionProjection implements DirectOperationProjection, DirectOperationPublicView
{
    public function __construct(private TaskVmProvisionIntent $intent, private FailTaskVmAction $fail, private TaskVmEnrollmentProjection $enrollment) {}

    public function input(Operation $operation): array
    {
        return $this->intent->input($operation);
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
            $vm = $this->intent->owned($operation, lock: true);
            $status = OperationStatus::from(ValidatedData::string($data['status']));
            $projected = app(DirectOperation::class)->project($operation, $data, $result);
            if ($status === OperationStatus::Running) {
                $provider = ValidatedData::object($result['provider'] ?? []);
                if ($provider !== []) {
                    $vm->update(Arr::only($provider, ['provider_id', 'address', 'ssh_host_key']));
                }
                $this->enrollment->recordPublic($vm, ValidatedData::object($operation->input['enrollment']), $result);
            }
            if ($status === OperationStatus::Succeeded) {
                $this->enrollment->record($vm, ValidatedData::object($operation->input['enrollment']), ValidatedData::object($result['enrollment'] ?? []));
            } elseif ($status === OperationStatus::Failed) {
                $this->fail->execute($vm, [TaskVmState::Provisioning], ValidatedData::string($data['error_code']), 'The task VM provisioning failed.', 'The task VM provisioning failed.');
            }

            return $projected;
        });
    }
}
