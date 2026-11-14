<?php

declare(strict_types=1);

use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskHierarchyException;
use App\Domain\Tasks\TaskPrMode;
use App\Domain\Tasks\TaskPrModeChoice;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

function prModeGroup(?TaskPrMode $group = null, ?TaskPrMode $project = null): Task
{
    $owner = Project::query()->create(['name' => 'Modes', 'slug' => 'modes', 'repository_url' => 'https://github.com/example/modes.git', 'default_branch' => 'main', 'task_pr_mode' => $project]);

    return Task::query()->create(['project_id' => $owner->id, 'title' => 'Group', 'brief' => 'Brief', 'status' => TaskGroupStatus::Running, 'pr_mode' => $group])->refresh();
}

it('resolves the mode from the group, then the Project, then the Gateway, and names the source', function (?TaskPrMode $group, ?TaskPrMode $project, string $gateway, TaskPrMode $mode, string $source): void {
    config(['orbit.tasks.pr_mode' => $gateway]);

    $choice = TaskPrModeChoice::of(prModeGroup($group, $project));

    expect($choice->mode)->toBe($mode)->and($choice->source)->toBe($source);
})->with([
    'group wins' => [TaskPrMode::PrWhenDone, TaskPrMode::ReviewBeforePr, 'review_before_pr', TaskPrMode::PrWhenDone, 'group'],
    'project next' => [null, TaskPrMode::PrWhenDone, 'review_before_pr', TaskPrMode::PrWhenDone, 'project'],
    'gateway last' => [null, null, 'pr_when_done', TaskPrMode::PrWhenDone, 'gateway'],
]);

it('defaults to review_before_pr, which is how every group worked before modes existed', function (): void {
    expect(config('orbit.tasks.pr_mode'))->toBe('review_before_pr')
        ->and(TaskPrModeChoice::of(prModeGroup())->mode)->toBe(TaskPrMode::ReviewBeforePr);
});

it('refuses a Gateway setting that is no mode', function (): void {
    config(['orbit.tasks.pr_mode' => 'merge_when_green']);

    expect(fn () => TaskPrModeChoice::of(prModeGroup()))->toThrow(function (ResourceOperationException $exception): void {
        expect($exception->errorCode)->toBe('tasks.pr_mode_setting_invalid')->and($exception->status)->toBe(503);
    });
});

it('keeps the mode and the refusal off subtasks', function (string $column, mixed $value): void {
    $group = prModeGroup();

    expect(fn () => Task::query()->create(['parent_id' => $group->id, 'position' => 1, 'title' => 'Child', 'brief' => 'Brief', 'status' => 'todo', $column => $value]))
        ->toThrow(TaskHierarchyException::class, "A subtask cannot set {$column}.");
})->with([
    'pr_mode' => ['pr_mode', TaskPrMode::PrWhenDone],
    'publish_refusal' => ['publish_refusal', ['code' => 'tasks.copy_publish_held', 'check_id' => 1, 'at' => '2026-11-14T00:00:00Z']],
]);

it('stores no mode on a new group or subtask, so both inherit', function (): void {
    $group = prModeGroup();
    $child = Task::query()->create(['parent_id' => $group->id, 'position' => 1, 'title' => 'Child', 'brief' => 'Brief', 'status' => 'todo']);

    expect($group->pr_mode)->toBeNull()->and($group->publish_refusal)->toBeNull()
        ->and($child->refresh()->getAttributes()['pr_mode'] ?? null)->toBeNull();
});
