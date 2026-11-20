<?php

declare(strict_types=1);

use App\Models\Project;
use App\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('copies the recorded ending into the watched_pr_state of completed groups, and leaves every other row alone', function (): void {
    $project = Project::query()->create(['name' => 'orbit', 'slug' => 'orbit', 'repository_url' => 'https://github.com/hardimpactdev/orbit.git', 'default_branch' => 'main', 'root' => '.']);
    $merged = '2026-11-19 23:47:46';
    $cases = [
        ['open', 'merged', 'merged'],
        [null, 'merged', 'merged'],
        ['open', 'closed', 'closed'],
        ['merged', 'merged', 'merged'],
        ['open', null, 'open'],
        ['closed', null, 'closed'],
    ];
    $groups = [];
    foreach ($cases as [$state, $completion, $expected]) {
        $group = Task::query()->create(['project_id' => $project->id, 'title' => 'Group', 'brief' => 'Brief', 'watched_pr_state' => $state,
            'watched_pr_completion' => $completion, 'merged_at' => $completion === 'merged' ? $merged : null]);
        $groups[] = [$group, $expected, $group->merged_at];
    }
    $parent = $groups[0][0];
    // The model refuses these fields on a subtask; a row written outside it stays out of the repair.
    $child = $parent->tasks()->create(['title' => 'Child', 'brief' => 'Brief', 'position' => 1]);
    DB::table('tasks')->where('id', $child->id)->update(['watched_pr_state' => 'open', 'watched_pr_completion' => 'merged']);

    $migration = require database_path('migrations/2027_03_13_000000_repair_watched_pr_state_of_completed_groups.php');
    $migration->up();
    $migration->up();

    foreach ($groups as [$group, $expected, $mergedAt]) {
        $fresh = $group->fresh();
        expect($fresh->watched_pr_state)->toBe($expected)->and($fresh->merged_at?->toIso8601String())->toBe($mergedAt?->toIso8601String());
    }
    expect($child->fresh()->watched_pr_state)->toBe('open');
});
