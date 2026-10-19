<?php

declare(strict_types=1);

use App\Domain\Tasks\TaskCheckStatus;
use App\Domain\Tasks\TaskReviewedCommitSource;
use App\Domain\Tasks\TaskWorkspaceLease;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskReviewedCommit;
use App\Models\TaskWorkspaceLeaseRecord;
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
                if ($kind === 'base_merge' && $checkStatus === TaskCheckStatus::Running) {
                    continue;
                }
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

function retired_gate_check(Task $group, string $status = 'passed'): int
{
    return (int) DB::table('task_checks')->insertGetId(['task_id' => $group->id, 'kind' => 'base_merge', 'status' => $status,
        'pid' => 123, 'process_started' => 'stored', 'head_before' => str_repeat('a', 40), 'tree_before' => str_repeat('b', 40), 'started_at' => now()]);
}

function retired_gate_group(): Task
{
    $project = Project::query()->create(['name' => 'Migration', 'slug' => 'migration', 'repository_url' => 'https://github.com/acme/migration.git']);

    return Task::topLevel()->create(['project_id' => $project->id, 'title' => 'Migration', 'brief' => 'Stored evidence.', 'status' => 'settling']);
}

it('refuses a running retired check before changing any checks, reviews or leases', function (): void {
    $group = retired_gate_group();
    $check = retired_gate_check($group, 'running');
    retired_gate_check($group);
    DB::table('task_reviewed_commits')->insert(['task_id' => $group->id, 'sha' => str_repeat('a', 40), 'source' => 'base_merge']);
    DB::table('task_workspace_leases')->insert(['instance_id' => 123, 'active_instance_id' => 123, 'group_id' => $group->id,
        'kind' => 'check', 'owner' => 'check:'.$check, 'status' => 'held', 'check_id' => $check]);
    $tables = ['task_checks', 'task_reviewed_commits', 'task_workspace_leases'];
    $before = [];
    foreach ($tables as $table) {
        $before[$table] = DB::table($table)->get()->toJson();
    }
    $migration = require database_path('migrations/2026_12_01_000000_remove_task_base_merge_gates.php');

    expect(fn () => $migration->up())->toThrow(RuntimeException::class, 'A base_merge check is running');

    foreach ($tables as $table) {
        expect(DB::table($table)->get()->toJson())->toBe($before[$table]);
    }
});

it('cancels retired holders and waiters so the next workspace actor can acquire', function (string $kind, string $status): void {
    $group = retired_gate_group();
    $node = Node::query()->create(['name' => 'migration-node', 'status' => 'active', 'platform' => 'linux', 'public_ssh_host' => '192.0.2.1']);
    $instance = Instance::query()->create(['project_id' => $group->project_id, 'node_id' => $node->id, 'name' => 'migration-workspace', 'task_workspace_owner_id' => $group->id, 'checkout_path' => '/tmp/migration-workspace', 'status' => 'active']);
    $group->taskable()->associate($instance);
    $group->save();
    $check = retired_gate_check($group);
    $lease = TaskWorkspaceLeaseRecord::query()->create(['instance_id' => $instance->id, 'group_id' => $group->id,
        'active_instance_id' => $status === 'held' ? $instance->id : null, 'kind' => $kind, 'owner' => 'old-actor', 'status' => $status,
        'check_id' => $kind === 'check' ? $check : null]);
    $preserved = DB::table('task_workspace_leases')->insertGetId(['instance_id' => 999, 'active_instance_id' => 999, 'group_id' => $group->id,
        'kind' => 'handoff', 'owner' => 'unrelated', 'status' => 'held']);
    $migration = require database_path('migrations/2026_12_01_000000_remove_task_base_merge_gates.php');

    $migration->up();
    $released = $lease->fresh()->getRawOriginal('released_at');
    $migration->up();

    expect($lease->fresh()->status)->toBe('cancelled')
        ->and($lease->fresh()->getAttribute('active_instance_id'))->toBeNull()
        ->and($released)->not->toBeNull()
        ->and($lease->fresh()->getRawOriginal('released_at'))->toBe($released)
        ->and(DB::table('task_workspace_leases')->where('id', $preserved)->value('status'))->toBe('held')
        ->and(app(TaskWorkspaceLease::class)->acquire($group->fresh(), 'publication', 'after-migration')->status)->toBe('held');
})->with([
    'main merge waiter' => ['main_merge', 'queued'],
    'main merge holder' => ['main_merge', 'held'],
    'deleted check holder' => ['check', 'held'],
    'deleted check waiter' => ['check', 'queued'],
]);

it('clears retired delta-review identities without changing other fixup budgets', function (): void {
    $group = retired_gate_group();
    foreach (['todo', 'reviewing', 'completed', 'cancelled'] as $position => $status) {
        $group->tasks()->create(['position' => $position + 1, 'title' => 'Final review', 'brief' => 'Review.', 'type' => 'final_review',
            'status' => $status, 'fixup_problem' => 'base-merge-review']);
    }
    $fixup = $group->tasks()->create(['position' => 5, 'title' => 'CI fixup', 'brief' => 'Fix it.', 'status' => 'completed', 'fixup_problem' => 'check:Required checks']);
    $migration = require database_path('migrations/2026_12_01_000000_remove_task_base_merge_gates.php');

    $migration->up();
    $migration->up();

    expect($group->tasks()->where('type', 'final_review')->whereNotNull('fixup_problem')->count())->toBe(0)
        ->and($group->tasks()->get()->filter(fn (Task $task): bool => $task->isSettlingFixup())->modelKeys())->toBe([$fixup->id]);
});

it('records inherited approval only for converted heads with unambiguous reviewed evidence', function (array $approvals, bool $reviewedApproval, ?string $expected): void {
    $group = retired_gate_group();
    $review = $group->tasks()->create(['position' => 1, 'title' => 'Review', 'brief' => 'Review.', 'type' => 'final_review', 'status' => 'completed']);
    $head = str_repeat('a', 40);
    $original = str_repeat('b', 40);
    $later = str_repeat('c', 40);
    $converted = DB::table('task_reviewed_commits')->insertGetId(['task_id' => $group->id, 'sha' => $head, 'source' => 'base_merge', 'review_task_id' => $review->id]);
    foreach ([$original, $later] as $sha) {
        if ($reviewedApproval) {
            DB::table('task_reviewed_commits')->insert(['task_id' => $group->id, 'sha' => $sha, 'source' => 'orbit_push', 'review_task_id' => $review->id]);
        }
    }
    foreach ($approvals as $character) {
        DB::table('task_comments')->insert(['task_group_id' => $group->id, 'task_id' => $review->id, 'type' => 'approved',
            'body' => 'Reviewed.', 'author' => 'reviewer', 'posted_at' => now(), 'commit_sha' => str_repeat($character, 40)]);
    }
    $migration = require database_path('migrations/2026_12_01_000000_remove_task_base_merge_gates.php');

    $migration->up();
    $migration->up();

    expect(DB::table('task_reviewed_commits')->where('id', $converted)->value('inherited_approval_sha'))->toBe($expected)
        ->and(DB::table('task_reviewed_commits')->where('id', '!=', $converted)->whereNotNull('inherited_approval_sha')->count())->toBe(0);
})->with([
    'one approval' => [['b'], true, str_repeat('b', 40)],
    'repeated same approval' => [['b', 'b'], true, str_repeat('b', 40)],
    'ambiguous approvals' => [['b', 'c'], true, null],
    'unreviewed approval' => [['b'], false, null],
    'no approval' => [[], true, null],
]);
