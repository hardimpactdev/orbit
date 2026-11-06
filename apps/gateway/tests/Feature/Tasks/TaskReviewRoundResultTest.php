<?php

declare(strict_types=1);

use App\Domain\Operations\OperationKinds;
use App\Domain\Operations\OperationStatus;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskReviewRoundResult;
use App\Domain\Tasks\TaskReviewRoundVerdict;
use App\Models\Operation;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskReviewRound;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

/** A review round whose reviewer turn ended with the given status. */
function endedRound(OperationStatus $status): array
{
    $project = Project::query()->create(['name' => 'orbit', 'slug' => 'orbit', 'repository_url' => 'https://github.com/hardimpactdev/orbit.git', 'default_branch' => 'main', 'root' => '.']);
    $group = Task::topLevel()->create(['project_id' => $project->id, 'title' => 'A', 'brief' => 'B', 'status' => TaskGroupStatus::Running]);
    $operation = Operation::query()->create(['kind' => OperationKinds::TasksTurn, 'target_type' => OperationKinds::TaskGroupTarget, 'target_id' => $group->id, 'node_id' => null,
        'input' => [], 'status' => $status, 'sequence' => 1, 'attempts' => 1]);
    $round = TaskReviewRound::query()->create(['task_group_id' => $group->id, 'head_sha' => str_repeat('a', 40), 'round' => 1, 'operation_id' => $operation->id]);

    return [$operation, $round];
}

it('records a pass when the reviewer approved', function (): void {
    [$operation, $round] = endedRound(OperationStatus::Succeeded);

    TaskReviewRoundResult::record($operation, ['receipt' => ['outcome' => 'approved', 'summary' => 'Nothing to fix.']]);

    expect($round->fresh()->verdict)->toBe(TaskReviewRoundVerdict::Pass)->and($round->fresh()->summary)->toBe('Nothing to fix.');
});

it('records the findings when the reviewer asked for changes', function (): void {
    [$operation, $round] = endedRound(OperationStatus::Succeeded);

    TaskReviewRoundResult::record($operation, ['receipt' => ['outcome' => 'changes_requested', 'summary' => 'docs/a.md: missing case']]);

    expect($round->fresh()->verdict)->toBe(TaskReviewRoundVerdict::Findings)->and($round->fresh()->summary)->toBe('docs/a.md: missing case');
});

it('records a failed round for a blocked reviewer, a missing receipt or a turn that did not succeed', function (OperationStatus $status, array $result): void {
    [$operation, $round] = endedRound($status);

    TaskReviewRoundResult::record($operation, $result);

    expect($round->fresh()->verdict)->toBe(TaskReviewRoundVerdict::Failed);
})->with([
    'blocked' => [OperationStatus::Succeeded, ['receipt' => ['outcome' => 'blocked', 'summary' => 'No access', 'question' => 'How?']]],
    'no receipt' => [OperationStatus::Succeeded, ['receipt' => null]],
    'failed turn' => [OperationStatus::Failed, ['receipt' => null]],
]);

it('records a round once and ignores an operation that is no round', function (): void {
    [$operation, $round] = endedRound(OperationStatus::Succeeded);

    TaskReviewRoundResult::record($operation, ['receipt' => ['outcome' => 'approved', 'summary' => 'Fine.']]);
    TaskReviewRoundResult::record($operation, ['receipt' => ['outcome' => 'changes_requested', 'summary' => 'Late.']]);
    $other = Operation::query()->create(['kind' => OperationKinds::TasksTurn, 'target_type' => OperationKinds::TaskGroupTarget, 'target_id' => $round->task_group_id, 'node_id' => null,
        'input' => [], 'status' => OperationStatus::Succeeded, 'sequence' => 1, 'attempts' => 1]);
    TaskReviewRoundResult::record($other, ['receipt' => ['outcome' => 'approved', 'summary' => 'Not a round.']]);

    expect($round->fresh()->verdict)->toBe(TaskReviewRoundVerdict::Pass)->and(TaskReviewRound::query()->count())->toBe(1);
});
