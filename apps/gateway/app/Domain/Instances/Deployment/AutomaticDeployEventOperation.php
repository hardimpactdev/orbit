<?php

declare(strict_types=1);

namespace App\Domain\Instances\Deployment;

use App\Data\Instances\AutomaticDeployEventData;
use App\Domain\Operations\OperationKind;
use App\Domain\Operations\OperationOutcome;
use App\Domain\Operations\OperationRun;
use App\Domain\Operations\OperationStepPlan;
use App\Models\Activity;
use App\Models\Instance;
use App\Models\InstanceDeployment;
use App\Models\Operation;
use App\Support\ValidatedData;

final readonly class AutomaticDeployEventOperation implements OperationKind
{
    public function __construct(private AutomaticDeployAlerts $alerts, private AutomaticDeployPolicyWriter $writer) {}

    public function run(Operation $operation, OperationRun $run): OperationOutcome
    {
        $instance = Instance::query()->find($operation->target_id);
        if ($instance === null) {
            return OperationOutcome::failed('operation.target_missing');
        }
        $this->writer->assertSwamp($instance);
        $data = AutomaticDeployEventData::from(array_diff_key($operation->input, ['caller_node_id' => true]));
        $run->plan([new OperationStepPlan('delivery', resumable: false)]);
        $receipt = $run->step('delivery', function () use ($instance, $data, $operation): array {
            $result = $data->result === null ? null : AutomaticDeployResult::from($data->result);
            if ($data->event === 'activity') {
                if ($result === null || ! $result->isNotable()) {
                    return ['outcome' => 'skipped', 'reason' => 'not_notable'];
                }
                $activity = Activity::query()->create([
                    'log_name' => 'deployments', 'description' => 'auto_deploy.'.$result->value,
                    'subject_type' => Instance::MorphAlias, 'subject_id' => $instance->id,
                    'properties' => ['instance_id' => $instance->id, 'commit' => $data->commit, 'operation_id' => $data->operationId, 'error_code' => $data->errorCode, 'message' => $data->message],
                    'request_id' => $operation->request_id, 'command' => AutomaticDeployHistory::Trigger,
                    'status' => $result->isError() ? 'failed' : 'completed', 'error_code' => $data->errorCode,
                ]);

                return ['activity_id' => $activity->id];
            }
            if ($data->event === 'stalled') {
                assert(is_string($data->commit) && is_string($data->since) && $result instanceof AutomaticDeployResult);

                return $this->alerts->stalled($instance, $data->commit, $data->since, new AutomaticDeployOutcome($instance->id, $result, $data->commit, $data->operationId, $data->errorCode, $data->message));
            }
            $deployment = InstanceDeployment::query()->where('instance_id', $instance->id)->find($data->deploymentId);
            if ($deployment === null) {
                return ['outcome' => 'skipped', 'reason' => 'deployment_missing'];
            }
            if ($data->event === 'failed' && $deployment->kind === 'deploy' && $deployment->triggered_by === AutomaticDeployHistory::Trigger && $deployment->status === 'failed' && $deployment->error_code !== AutomaticDeployHistory::Paused) {
                return $this->alerts->failed($instance, $deployment);
            }
            if ($data->event === 'paused' && ($deployment->kind === InstanceDeployment::Rollback || $deployment->triggered_by !== AutomaticDeployHistory::Trigger)) {
                return $this->alerts->paused($instance, $deployment);
            }

            return ['outcome' => 'skipped', 'reason' => 'deployment_not_applicable'];
        });

        return OperationOutcome::succeeded(ValidatedData::object($receipt));
    }

    public function abandon(Operation $operation, OperationOutcome $failure, ?string $failedStep): OperationOutcome
    {
        return $failure;
    }
}
