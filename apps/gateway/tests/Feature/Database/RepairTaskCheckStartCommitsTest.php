<?php

declare(strict_types=1);

use App\Models\Project;
use App\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('restores a check candidate mistakenly stored as the subtask base and preserves other bases', function (): void {
    $project = Project::query()->create(['name' => 'orbit', 'slug' => 'orbit', 'repository_url' => 'https://github.com/hardimpactdev/orbit.git', 'default_branch' => 'main', 'root' => '.']);
    $group = Task::query()->create(['project_id' => $project->id, 'title' => 'Group', 'brief' => 'Brief']);
    $base = str_repeat('a', 40);
    $candidate = str_repeat('b', 40);
    $explicit = str_repeat('c', 40);
    $cases = [
        [$candidate, $base, $base],
        [$explicit, $base, $explicit],
        [$candidate, null, $candidate],
        [$candidate, 'invalid', $candidate],
        [$candidate, $candidate, $candidate],
    ];
    $tasks = [];
    foreach ($cases as $position => [$recorded, $admitted, $expected]) {
        $task = $group->tasks()->create(['title' => 'Child', 'brief' => 'Brief', 'position' => $position + 1, 'subtask_start_commit' => $recorded]);
        $task->checks()->create(['kind' => 'handoff', 'status' => 'failed', 'pid' => 0, 'process_started' => '', 'head_before' => $candidate,
            'tree_before' => $candidate, 'deliverable_evidence' => ['start' => $admitted], 'started_at' => now()]);
        // A later retry cannot replace the first check's evidence.
        $task->checks()->create(['kind' => 'handoff', 'status' => 'failed', 'pid' => 0, 'process_started' => '', 'head_before' => $candidate,
            'tree_before' => $candidate, 'deliverable_evidence' => ['start' => $explicit], 'started_at' => now()]);
        $tasks[] = [$task, $expected];
    }
    $migration = require database_path('migrations/2027_01_31_000000_repair_task_check_start_commits.php');
    $migration->up();
    $migration->up();

    foreach ($tasks as [$task, $expected]) {
        expect($task->fresh()->subtask_start_commit)->toBe($expected);
    }
});
