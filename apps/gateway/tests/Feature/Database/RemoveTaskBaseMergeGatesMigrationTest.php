<?php

declare(strict_types=1);

use App\Domain\Tasks\TaskCheckStatus;
use App\Domain\Tasks\TaskReviewedCommitSource;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskReviewedCommit;
use Illuminate\Support\Facades\DB;

it('removes every retired integration check and converts every reviewed source without losing its evidence', function (): void {
    $project = Project::query()->create(['name' => 'Migration', 'slug' => 'migration', 'repository_url' => 'https://github.com/acme/migration.git']);
    $stamp = '2026-10-01 12:00:00';
    $preservedChecks = [];
    $reviewed = [];
    foreach (['settling', 'completed', 'cancelled', 'failed'] as $status) {
        $group = Task::topLevel()->create(['project_id' => $project->id, 'title' => $status, 'brief' => 'Stored evidence.', 'status' => $status]);
        $review = Task::query()->create(['parent_id' => $group->id, 'position' => 1, 'title' => 'Final review', 'brief' => 'Reviewed.', 'status' => 'completed', 'type' => 'final_review']);
        foreach (TaskCheckStatus::cases() as $checkStatus) {
            foreach (['base_merge', 'baseline', 'handoff'] as $kind) {
                $id = DB::table('task_checks')->insertGetId(['task_id' => $group->id, 'kind' => $kind, 'status' => $checkStatus->value,
                    'pid' => 123, 'process_started' => 'stored', 'head_before' => str_repeat('a', 40), 'tree_before' => str_repeat('b', 40), 'started_at' => $stamp]);
                if ($kind !== 'base_merge') {
                    $preservedChecks[] = $id;
                }
            }
        }
        foreach (['base_merge', 'orbit_push', 'pull_request_review'] as $index => $source) {
            $id = DB::table('task_reviewed_commits')->insertGetId(['task_id' => $group->id, 'sha' => str_repeat((string) ($index + 1), 40), 'source' => $source,
                'review_task_id' => $review->id, 'pushed_at' => $stamp, 'github_review_id' => 991, 'created_at' => $stamp, 'updated_at' => $stamp]);
            $reviewed[$id] = ['source' => $source === 'base_merge' ? 'orbit_push' : $source, 'review_task_id' => $review->id];
        }
    }

    $migration = require database_path('migrations/2026_12_01_000000_remove_task_base_merge_gates.php');
    $migration->up();
    $migration->up();

    expect(DB::table('task_checks')->orderBy('id')->pluck('id')->all())->toBe($preservedChecks)
        ->and(TaskReviewedCommit::query()->count())->toBe(count($reviewed));
    foreach ($reviewed as $id => $evidence) {
        $row = TaskReviewedCommit::query()->findOrFail($id);
        expect($row->source)->toBe(TaskReviewedCommitSource::from($evidence['source']))
            ->and($row->review_task_id)->toBe($evidence['review_task_id'])
            ->and($row->github_review_id)->toBe(991)
            ->and($row->getRawOriginal('pushed_at'))->toBe($stamp)
            ->and($row->getRawOriginal('updated_at'))->toBe($stamp);
    }
});
