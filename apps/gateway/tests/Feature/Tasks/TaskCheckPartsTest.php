<?php

declare(strict_types=1);

use App\Domain\Operations\OperationKinds;
use App\Domain\Operations\OperationStatus;
use App\Domain\Tasks\TaskCheckKind;
use App\Domain\Tasks\TaskCheckProjection;
use App\Domain\Tasks\TaskCheckStatus;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskStatus;
use App\Models\Operation;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskCheck;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

/** A check of a fresh subtask that ends with RESULT, as the check workflows write its receipt. */
function partsChecked(array $result, ?Closure $json = null): TaskCheck
{
    $project = Project::query()->create(['name' => 'Trial', 'slug' => 'trial', 'repository_url' => 'https://github.com/example/trial.git', 'default_branch' => 'main', 'root' => 'public']);
    $group = Task::query()->create(['project_id' => $project->id, 'title' => 'Group', 'brief' => 'Brief', 'status' => TaskGroupStatus::Running]);
    $subtask = $group->tasks()->create(['title' => 'Step', 'brief' => 'Do it', 'position' => 1, 'status' => TaskStatus::Running]);
    $check = TaskCheck::query()->create(['task_id' => $subtask->id, 'kind' => TaskCheckKind::Handoff, 'status' => TaskCheckStatus::Running, 'pid' => 1,
        'process_started' => 'x', 'head_before' => '', 'tree_before' => '', 'started_at' => now()]);
    $operation = Operation::query()->create(['kind' => OperationKinds::TasksCheck, 'target_type' => OperationKinds::TaskGroupTarget, 'target_id' => $group->id,
        'node_id' => null, 'input' => ['subtask' => $subtask->id, 'phase' => 'handoff', 'check_id' => $check->id, 'group_id' => $group->id],
        'status' => OperationStatus::Running, 'sequence' => 1, 'attempts' => 1, 'state' => ['swamp_run_id' => '6b69badf-4f06-4f2e-82d7-21b62c1f587a']]);
    app(TaskCheckProjection::class)->apply($operation, ['run_id' => '6b69badf-4f06-4f2e-82d7-21b62c1f587a', 'status' => 'succeeded',
        'started_at' => now()->subMinute()->toIso8601String(), 'finished_at' => now()->toIso8601String(),
        'result' => ['instance_receipt' => ['result_json' => json_encode(['check_id' => $check->id, 'outcome' => 'failed', 'exit_code' => 1, 'output' => 'Task check FAILED',
            'head_before' => str_repeat('a', 40), 'tree_before' => str_repeat('b', 40), 'head' => str_repeat('a', 40), 'tree' => str_repeat('b', 40),
            'changed_paths' => [], 'failed_step' => null, 'evidence' => null, 'phase' => 'handoff', 'deliverables' => [], 'ci' => null, ...$result], JSON_THROW_ON_ERROR)
        |> ($json ?? static fn (string $text): string => $text)]]]);

    return $check->refresh();
}

it('stores the part table of a check next to its output', function (): void {
    $parts = [['name' => 'ci-plan', 'result' => 'passed', 'seconds' => 0.5], ['name' => 'pest apps/gateway', 'result' => 'FAILED', 'seconds' => 41.2],
        ['name' => 'fixtures', 'result' => 'not selected', 'seconds' => 0]];

    $check = partsChecked(['parts' => $parts]);

    expect($check->parts)->toBe($parts)
        ->and($check->output)->toBe('Task check FAILED');
});

it('stores no part table for a check whose command wrote no receipt', function (): void {
    expect(partsChecked([])->parts)->toBeNull();
});

it('stores no part table that is not one, and settles the check all the same', function (mixed $parts, ?Closure $json): void {
    $check = partsChecked(['parts' => $parts], $json);

    expect($check->parts)->toBeNull()
        ->and($check->status)->toBe(TaskCheckStatus::Failed);
})->with([
    'an empty object' => [new stdClass, null],
    'an unknown result' => [[['name' => 'pest', 'result' => 'failed', 'seconds' => 1]], null],
    'seconds beyond any float' => [[['name' => 'pest', 'result' => 'passed', 'seconds' => 1]], static fn (string $text): string => str_replace('"seconds":1', '"seconds":1e400', $text)],
]);
