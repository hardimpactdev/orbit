<?php

declare(strict_types=1);

use App\Domain\Tasks\TaskGroupMetricsRefresher;
use App\Domain\Tasks\TaskGroupStatus;
use App\Models\AgentThread;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(LazilyRefreshDatabase::class);

it('shows recorded usage without contacting historical agent sessions', function (): void {
    Http::preventStrayRequests();
    $project = Project::query()->create(['name' => 'orbit', 'slug' => 'orbit', 'repository_url' => 'https://github.com/hardimpactdev/orbit.git', 'default_branch' => 'main', 'root' => '.']);
    $group = Task::topLevel()->create(['project_id' => $project->id, 'title' => 'Allow', 'brief' => 'B', 'status' => TaskGroupStatus::WaitingForReview]);
    $subtask = $group->tasks()->create(['title' => 'Implement', 'brief' => 'B']);
    $author = AgentThread::query()->create(['task_group_id' => $group->id, 'task_id' => $subtask->id, 'driver' => 'pi', 'runtime_key' => 'node:1', 'external_id' => 'unavailable-author', 'role' => 'implementer', 'tokens' => 100, 'lines_added' => 7, 'lines_deleted' => 2]);
    $reviewer = AgentThread::query()->create(['task_group_id' => $group->id, 'task_id' => $subtask->id, 'driver' => 'pi', 'runtime_key' => 'node:1', 'external_id' => 'unavailable-reviewer', 'role' => 'reviewer', 'tokens' => 20]);
    $subtask->update(['implementer_agent_thread_id' => $author->id, 'tokens' => 100, 'lines_added' => 7, 'lines_deleted' => 2, 'line_diff' => 9]);

    $shown = app(TaskGroupMetricsRefresher::class)->refresh($group);

    expect($shown->tokens)->toBe(120)
        ->and($shown->tasks->sole()->tokens)->toBe(100)
        ->and($shown->tasks->sole()->line_diff)->toBe(9);
    expect($author->fresh()->observation_error)->toBeNull();
    expect($reviewer->fresh()->observation_error)->toBeNull();
});
