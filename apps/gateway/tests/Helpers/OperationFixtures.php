<?php

declare(strict_types=1);

use App\Actions\Instances\DeployDefaultInstanceAction;
use App\Actions\Instances\DeployInstanceAction;
use App\Actions\Instances\RollbackInstanceAction;
use App\Domain\Gateway\GatewayQueue;
use App\Domain\Instances\Deployment\DeploymentStepName;
use App\Domain\Instances\Deployment\OperationDeploymentSteps;
use App\Domain\Operations\OperationKinds;
use App\Domain\Operations\OperationRun;
use App\Domain\Operations\OperationStatus;
use App\Domain\Operations\OperationStepPlan;
use App\Domain\Operations\OperationStore;
use App\Domain\Shared\ResourceOperationException;
use App\Jobs\RunOperation;
use App\Models\Instance;
use App\Models\Operation;
use App\Models\TaskCheck;
use Illuminate\Support\Facades\DB;

/** Turns the Gateway queue on for a test, so a start request queues its operation on the database test queue. */
function enable_operation_queue(): void
{
    config(['orbit.gateway_queue.connection' => 'services-valkey']);
}

/**
 * Runs the operation jobs that wait on the `operations` queue of the database test queue, in order, as Horizon would,
 * and returns how many ran.
 */
function run_operation_jobs(): int
{
    $ran = 0;

    while (($row = DB::table('jobs')->where('queue', GatewayQueue::Operations->value)->orderBy('id')->first()) !== null) {
        DB::table('jobs')->where('id', $row->id)->delete();
        $payload = json_decode($row->payload, true, flags: JSON_THROW_ON_ERROR);
        $job = unserialize($payload['data']['command']);
        assert($job instanceof RunOperation);
        app()->call([$job, 'handle']);
        $ran++;
    }

    return $ran;
}

/**
 * The steps a worker hands a deploy or rollback action: a running operation on the Instance, planned with the action's
 * own plan, run through {@see OperationDeploymentSteps}. A test calls the action directly with them, without the queue.
 * The operation holds no lock, so a test can run several. An action that refuses the Instance gets an empty plan and
 * refuses before its first step.
 */
function operation_steps(Instance $instance, DeployInstanceAction|DeployDefaultInstanceAction|RollbackInstanceAction $action): OperationDeploymentSteps
{
    $operation = Operation::query()->create([
        'kind' => $action instanceof RollbackInstanceAction ? OperationKinds::InstanceRollback : OperationKinds::InstanceDeploy,
        'target_type' => OperationKinds::InstanceTarget,
        'target_id' => $instance->id,
        'node_id' => $instance->node_id,
        'input' => [],
        'status' => OperationStatus::Running,
        'sequence' => 1,
        'attempts' => 1,
        'triggered_by' => 'test',
    ]);

    try {
        $plan = $action->plan($instance);
    } catch (ResourceOperationException) {
        $plan = [];
    }

    $run = new OperationRun($operation, app(OperationStore::class));
    $run->plan(array_map(
        static fn (DeploymentStepName $step): OperationStepPlan => new OperationStepPlan($step->name(), $step->resumable()),
        $plan,
    ));

    return new OperationDeploymentSteps($run, static function (): void {});
}

/** A completed no-policy handoff operation, for consumer tests that do not execute a local check. */
function task_checked_operation(TaskCheck $check): Operation
{
    $task = $check->task()->firstOrFail();
    $check->update(['head_after' => $check->head_before, 'tree_after' => $check->tree_before]);

    return Operation::query()->create([
        'kind' => 'tasks.check', 'target_type' => 'task_group', 'target_id' => $task->parent_id,
        'input' => ['subtask' => $task->id, 'phase' => 'handoff'], 'status' => OperationStatus::Succeeded,
        'sequence' => 1, 'attempts' => 1, 'state' => ['check_id' => $check->id, 'ci' => null],
        'result' => ['outcome' => 'passed', 'check_id' => $check->id, 'commit' => $check->commit_sha,
            'head' => $check->head_after, 'tree' => $check->tree_after, 'ci' => null],
    ]);
}
