<?php

declare(strict_types=1);

use App\Domain\Operations\OperationKinds;
use App\Domain\Operations\OperationStatus;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskParkIntent;
use App\Domain\Tasks\TaskStatus;
use App\Models\Operation;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

/** A group with one subtask in the given status, as a prep run leaves it. */
function parkGroup(TaskStatus $status): Task
{
    $project = Project::query()->firstOrCreate(['slug' => 'orbit'], ['name' => 'orbit', 'repository_url' => 'https://github.com/hardimpactdev/orbit.git', 'default_branch' => 'main', 'root' => '.']);
    $group = Task::topLevel()->create(['project_id' => $project->id, 'title' => 'Prepare', 'brief' => 'B', 'status' => TaskGroupStatus::Running]);
    $group->tasks()->create(['title' => 'Draft the spec', 'brief' => 'B', 'status' => $status]);

    return $group;
}

function parkOperation(Task $group, string $kind, OperationStatus $status): void
{
    Operation::query()->create(['kind' => $kind, 'target_type' => OperationKinds::TaskGroupTarget, 'target_id' => $group->id, 'node_id' => null,
        'input' => [], 'status' => $status, 'sequence' => 1, 'attempts' => 1]);
}

it('parks a group whose subtask is still todo, as a prep run leaves it', function (): void {
    expect(TaskParkIntent::idle(parkGroup(TaskStatus::Todo)))->toBeTrue();
});

it('parks a group whose subtasks have ended', function (TaskStatus $status): void {
    expect(TaskParkIntent::idle(parkGroup($status)))->toBeTrue();
})->with([TaskStatus::Completed, TaskStatus::Failed, TaskStatus::Cancelled]);

it('refuses a group with a subtask that runs or waits for review', function (TaskStatus $status): void {
    expect(TaskParkIntent::idle(parkGroup($status)))->toBeFalse();
})->with([TaskStatus::Reserved, TaskStatus::Running, TaskStatus::Reviewing]);

it('refuses a group while a turn, a check or a prep run is running', function (string $kind): void {
    $group = parkGroup(TaskStatus::Todo);
    parkOperation($group, $kind, OperationStatus::Running);

    expect(TaskParkIntent::idle($group))->toBeFalse();
})->with([OperationKinds::TasksTurn, OperationKinds::TasksCheck, OperationKinds::TasksPrep]);

it('parks a group whose turn and check have ended', function (): void {
    $group = parkGroup(TaskStatus::Todo);
    parkOperation($group, OperationKinds::TasksTurn, OperationStatus::Succeeded);
    parkOperation($group, OperationKinds::TasksCheck, OperationStatus::Failed);

    expect(TaskParkIntent::idle($group))->toBeTrue();
});
