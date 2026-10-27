<?php

declare(strict_types=1);

use App\Domain\Tasks\TaskGroupStatus;
use App\Models\Project;
use App\Models\Task;
use Carbon\CarbonImmutable;

function lifecycle_group(string $status): Task
{
    $project = Project::query()->create([
        'name' => 'Lifecycle', 'code' => strtoupper(substr($status, 0, 3)), 'slug' => 'lifecycle-'.$status,
        'repository_url' => 'git@example.test:lifecycle-'.$status.'.git', 'default_branch' => 'main',
    ]);

    return Task::query()->create(['project_id' => $project->id, 'title' => 'Feature', 'brief' => 'Brief.', 'status' => $status]);
}

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('records the first time a task enters todo and keeps it', function (): void {
    CarbonImmutable::setTestNow('2026-10-27 09:00:00');
    $group = lifecycle_group('backlog');
    expect($group->ready_at)->toBeNull();

    CarbonImmutable::setTestNow('2026-10-27 10:00:00');
    $group->update(['status' => TaskGroupStatus::Todo]);
    $group->update(['status' => TaskGroupStatus::Backlog]);
    CarbonImmutable::setTestNow('2026-10-27 11:00:00');
    $group->update(['status' => TaskGroupStatus::Todo]);

    expect($group->fresh()->ready_at?->toDateTimeString())->toBe('2026-10-27 10:00:00')
        ->and(lifecycle_group('todo')->ready_at?->toDateTimeString())->toBe('2026-10-27 11:00:00');
});

it('records the first time a task ends and never stamps a subtask', function (): void {
    CarbonImmutable::setTestNow('2026-10-27 09:00:00');
    $group = lifecycle_group('running');
    $subtask = Task::query()->create(['parent_id' => $group->id, 'position' => 1, 'title' => 'Step', 'brief' => 'Step.']);
    $subtask->update(['status' => 'completed']);
    expect($group->fresh()->ended_at)->toBeNull();

    CarbonImmutable::setTestNow('2026-10-27 10:00:00');
    $group->update(['status' => TaskGroupStatus::Cancelled]);
    CarbonImmutable::setTestNow('2026-10-27 11:00:00');
    $group->update(['status' => TaskGroupStatus::Completed]);

    expect($group->fresh()->ended_at?->toDateTimeString())->toBe('2026-10-27 10:00:00')
        ->and($subtask->fresh()->ended_at)->toBeNull();
});
