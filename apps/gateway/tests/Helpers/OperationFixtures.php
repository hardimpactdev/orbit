<?php

declare(strict_types=1);

use App\Domain\Gateway\GatewayQueue;
use App\Domain\Operations\OperationStatus;
use App\Jobs\RunOperation;
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
