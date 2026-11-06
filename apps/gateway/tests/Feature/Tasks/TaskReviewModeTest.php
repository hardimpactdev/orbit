<?php

declare(strict_types=1);

use App\Data\Tasks\CreateTaskGroupData;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskReviewMode;
use App\Http\Requests\Tasks\CreateTaskGroupRequest;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Validator;

uses(LazilyRefreshDatabase::class);

/** @return array<string, mixed> */
function createRules(): array
{
    return (new CreateTaskGroupRequest)->rules();
}

it('accepts manual and automatic as the review mode of a new group and refuses anything else', function (): void {
    $rules = collect(createRules())->except('project_id')->all();
    $base = ['title' => 'T', 'brief' => 'B'];

    expect(Validator::make($base, $rules)->passes())->toBeTrue()
        ->and(Validator::make([...$base, 'review' => 'manual'], $rules)->passes())->toBeTrue()
        ->and(Validator::make([...$base, 'review' => 'automatic'], $rules)->passes())->toBeTrue()
        ->and(Validator::make([...$base, 'review' => 'both'], $rules)->fails())->toBeTrue()
        ->and(Validator::make([...$base, 'review' => true], $rules)->fails())->toBeTrue();
});

it('defaults a new group to manual review', function (): void {
    $data = new CreateTaskGroupData(projectId: 1, title: 'T', brief: 'B', status: TaskGroupStatus::Backlog, tasks: []);

    expect($data->review)->toBe(TaskReviewMode::Manual);
});

it('stores the review mode of a group and defaults it to manual', function (): void {
    $project = Project::query()->create(['name' => 'orbit', 'slug' => 'orbit', 'repository_url' => 'https://github.com/hardimpactdev/orbit.git', 'default_branch' => 'main', 'root' => '.']);
    $manual = Task::topLevel()->create(['project_id' => $project->id, 'title' => 'A', 'brief' => 'B', 'status' => TaskGroupStatus::Backlog]);
    $automatic = Task::topLevel()->create(['project_id' => $project->id, 'title' => 'C', 'brief' => 'D', 'status' => TaskGroupStatus::Backlog, 'review_mode' => TaskReviewMode::Automatic]);

    expect($manual->fresh()->review_mode)->toBe(TaskReviewMode::Manual)
        ->and($automatic->fresh()->review_mode)->toBe(TaskReviewMode::Automatic);
});

it('keeps the review mode off subtasks, so a saved subtask can still be updated', function (): void {
    $project = Project::query()->create(['name' => 'orbit', 'slug' => 'orbit', 'repository_url' => 'https://github.com/hardimpactdev/orbit.git', 'default_branch' => 'main', 'root' => '.']);
    $group = Task::topLevel()->create(['project_id' => $project->id, 'title' => 'A', 'brief' => 'B', 'status' => TaskGroupStatus::Backlog]);
    $subtask = $group->tasks()->create(['title' => 'S', 'brief' => 'B']);

    $saved = Task::query()->findOrFail($subtask->id);
    $saved->update(['title' => 'S2']);

    expect($saved->getRawOriginal('review_mode'))->toBeNull()->and($saved->fresh()->title)->toBe('S2');
});
