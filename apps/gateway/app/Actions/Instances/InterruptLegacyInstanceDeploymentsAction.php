<?php

declare(strict_types=1);

namespace App\Actions\Instances;

use App\Domain\Instances\Deployment\InstanceDeploymentExecution;
use App\Domain\Instances\Deployment\InstanceDeploymentIntent;
use App\Domain\Operations\OperationOutcome;
use App\Domain\Operations\OperationStatus;
use App\Domain\Operations\OperationStepStatus;
use App\Domain\Operations\OperationStore;
use App\Models\Operation;
use Illuminate\Support\Facades\DB;

/** Cutover only, after the PHP workers drain. Never dispatches or inspects a Node. */
final readonly class InterruptLegacyInstanceDeploymentsAction
{
    public function __construct(private OperationStore $store, private InstanceDeploymentExecution $execution) {}

    public function execute(): void
    {
        Operation::query()->whereIn('kind', ['instance.deploy', 'instance.rollback'])
            ->whereIn('status', ['queued', 'running', 'waiting'])->eachById(function (Operation $candidate): void {
                DB::transaction(function () use ($candidate): void {
                    $operation = Operation::query()->lockForUpdate()->findOrFail($candidate->id);
                    if ($operation->status->terminal() || ! InstanceDeploymentIntent::legacy($operation)) {
                        return;
                    }
                    foreach ($operation->steps as $step) {
                        if (! in_array($step->status, [OperationStepStatus::Succeeded, OperationStepStatus::Failed, OperationStepStatus::Cancelled], true)) {
                            $this->store->change($operation, [], $step, ['status' => OperationStepStatus::Failed,
                                'error_code' => 'deployment.interrupted', 'finished_at' => now()]);
                        }
                    }
                    $result = $this->execution->result($operation);
                    $this->execution->complete($operation, OperationOutcome::failed('deployment.interrupted', $result));
                    $this->store->change($operation, ['status' => OperationStatus::Failed, 'error_code' => 'deployment.interrupted',
                        'failed_step' => $operation->current_step, 'current_step' => null, 'finished_at' => now(),
                        'lock_key' => null, 'result' => $result]);
                });
            });
    }
}
