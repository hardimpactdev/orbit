<?php

declare(strict_types=1);

use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\TaskVms\TaskVmState;
use App\Models\Node;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Support\Facades\DB;

/** @return array<string, list<array<string, mixed>>> */
function projectDestroyTaskHistory(Project $project, TaskGroupStatus $status, TaskVmState $vmState = TaskVmState::Destroyed): array
{
    $group = Task::topLevel()->create([
        'project_id' => $project->id,
        'title' => 'Ship the feature',
        'brief' => 'Implement the feature.',
        'status' => $status,
    ]);
    $task = $group->children()->create(['title' => 'Implement', 'brief' => 'Make the change.']);
    $threadId = DB::table('agent_threads')->insertGetId([
        'task_group_id' => $group->id, 'task_id' => $task->id, 'role' => 'implementer',
        'external_id' => "thread-{$group->id}", 'runtime_key' => "runtime-{$group->id}",
    ]);
    DB::table('agent_thread_send_leases')->insert([
        'agent_thread_id' => $threadId, 'owner_token' => "owner-{$group->id}", 'expires_at' => now(),
    ]);
    $commentId = DB::table('task_comments')->insertGetId([
        'task_group_id' => $group->id, 'task_id' => $task->id, 'agent_thread_id' => $threadId,
        'type' => 'ready_for_review', 'body' => 'Ready.', 'author' => 'implementer', 'posted_at' => now(),
    ]);
    $checkId = DB::table('task_checks')->insertGetId([
        'task_id' => $task->id, 'task_comment_id' => $commentId, 'status' => 'passed',
        'pid' => 123, 'process_started' => '123', 'head_before' => str_repeat('a', 40),
        'tree_before' => str_repeat('b', 40), 'started_at' => now(),
    ]);
    DB::table('task_questions')->insert([
        'task_id' => $group->id, 'subtask_id' => $task->id, 'attempt' => 1,
        'asked_by' => 'implementer', 'question' => 'Which approach?', 'status' => 'answered',
        'asked_at' => now(), 'opened_comment_id' => $commentId,
    ]);
    DB::table('task_reviewed_commits')->insert([
        'task_id' => $task->id, 'review_task_id' => $task->id,
        'sha' => str_repeat('a', 40), 'source' => 'review',
    ]);
    DB::table('task_github_review_scans')->insert(['group_id' => $group->id, 'snapshot' => '{}']);
    DB::table('task_github_review_observations')->insert([
        'group_id' => $group->id, 'repository' => 'acme/site', 'pull_request_number' => 1,
        'reviewer_id' => 1, 'review_id' => 1, 'source' => '{}', 'latest' => '{}',
    ]);
    DB::table('task_github_review_consumptions')->insert([
        'group_id' => $group->id, 'repository' => 'acme/site', 'pull_request_number' => 1,
        'review_id' => 1, 'identity' => 'review', 'operator_task_id' => $task->id,
        'fixup_id' => $task->id, 'digest' => 'digest', 'packet' => '{}', 'created_at' => now(),
    ]);
    DB::table('task_github_review_feedback')->insert(['group_id' => $group->id, 'causes' => '[]']);
    // Released leases retain their historical Instance id after the Instance is gone.
    $leaseId = DB::table('task_workspace_leases')->insertGetId([
        'instance_id' => 1000 + $group->id, 'group_id' => $group->id, 'task_id' => $task->id,
        'kind' => 'implementer', 'owner' => 'owner', 'status' => 'released',
        'thread_id' => $threadId, 'check_id' => $checkId,
    ]);
    DB::table('task_workspace_operations')->insert([
        'lease_id' => $leaseId, 'operation' => "check-{$group->id}", 'binding' => 'workspace',
        'connection' => '{}', 'status' => 'completed',
    ]);
    foreach ([['task_group_id' => $group->id], ['task_id' => $task->id]] as $subject) {
        DB::table('jev_decisions')->insert([
            ...$subject, 'purpose' => 'brief_coverage', 'call_started_at' => now(),
            'task_ids' => json_encode([$task->id], JSON_THROW_ON_ERROR),
            'questions' => '{}', 'input_state' => '{}',
        ]);
    }
    DB::table('problem_fingerprints')->insert([
        'task_group_id' => $group->id, 'fingerprint' => "problem-{$group->id}",
        'source' => 'task', 'evidence' => '{}',
    ]);
    DB::table('task_vms')->insert([
        'group_id' => $group->id, 'provider' => 'incus', 'name' => "retired-{$group->id}",
        'state' => $vmState->value, 'wireguard_ip' => "10.99.0.{$group->id}",
        'destroyed_at' => $vmState === TaskVmState::Destroyed ? now() : null,
    ]);

    $tables = [
        'tasks', 'agent_threads', 'agent_thread_send_leases', 'task_comments', 'task_checks',
        'task_questions', 'task_reviewed_commits', 'task_github_review_scans',
        'task_github_review_observations', 'task_github_review_consumptions',
        'task_github_review_feedback', 'task_workspace_leases', 'task_workspace_operations',
        'jev_decisions', 'problem_fingerprints', 'task_vms',
    ];

    return collect($tables)->mapWithKeys(static fn (string $table): array => [
        $table => DB::table($table)->get()->map(static fn (object $row): array => (array) $row)->all(),
    ])->all();
}

beforeEach(function (): void {
    $operator = $this->markAsGateway(Node::query()->create([
        'name' => 'operator', 'status' => LifecycleStatus::Active,
        'public_ssh_host' => '192.0.2.2', 'wireguard_ip' => '10.44.0.2',
    ]));
    $this->withServerVariables(['REMOTE_ADDR' => $operator->wireguard_ip]);
});

describe('Project removal with task history', function (): void {
    it('deletes cancelled and completed groups and all their dependent rows', function (): void {
        $project = Project::query()->create(['name' => 'Acme', 'slug' => 'acme', 'repository_url' => 'https://github.com/acme/site.git']);
        projectDestroyTaskHistory($project, TaskGroupStatus::Cancelled);
        $history = projectDestroyTaskHistory($project, TaskGroupStatus::Completed);
        $other = Project::query()->create(['name' => 'Other', 'slug' => 'other', 'repository_url' => 'https://github.com/acme/other.git']);
        $allHistory = projectDestroyTaskHistory($other, TaskGroupStatus::Backlog);
        $unrelatedHistory = [];
        foreach ($allHistory as $table => $rows) {
            $unrelatedHistory[$table] = array_values(array_filter($rows, static fn (array $row): bool => ! in_array($row, $history[$table], true)));
        }

        $this->deleteJson("/api/v1/projects/{$project->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $project->id);

        $this->assertModelMissing($project);
        $this->assertModelExists($other);
        foreach ($history as $table => $rows) {
            foreach ($rows as $row) {
                $this->assertDatabaseMissing($table, $row);
            }
            expect(DB::table($table)->get()->map(static fn (object $row): array => (array) $row)->all())
                ->toBe($unrelatedHistory[$table]);
        }
        expect(DB::select('PRAGMA foreign_key_check'))->toBeEmpty();
    });

    it('returns 409 and preserves every row when a finished group has a VM that is not destroyed', function (TaskVmState $state): void {
        $project = Project::query()->create(['name' => 'Acme', 'slug' => 'acme', 'repository_url' => 'https://github.com/acme/site.git']);
        projectDestroyTaskHistory($project, TaskGroupStatus::Completed);
        $history = projectDestroyTaskHistory($project, TaskGroupStatus::Cancelled, $state);
        $vmName = $history['task_vms'][1]['name'];

        $this->deleteJson("/api/v1/projects/{$project->id}")
            ->assertConflict()
            ->assertJsonPath('error.code', 'project.task_vm_not_destroyed')
            ->assertJsonPath('error.message', "Project [acme] still has task VM [{$vmName}] in state [{$state->value}].");

        $this->assertModelExists($project);
        foreach ($history as $table => $rows) {
            expect(DB::table($table)->get()->map(static fn (object $row): array => (array) $row)->all())
                ->toBe($rows);
        }
    })->with([
        TaskVmState::Provisioning, TaskVmState::Ready, TaskVmState::Parked,
        TaskVmState::Destroying, TaskVmState::Failed,
    ]);

    it('rolls back task history cleanup when deleting the Project fails', function (): void {
        $project = Project::query()->create(['name' => 'Acme', 'slug' => 'acme', 'repository_url' => 'https://github.com/acme/site.git']);
        $history = projectDestroyTaskHistory($project, TaskGroupStatus::Completed);
        DB::statement("CREATE TRIGGER refuse_project_delete BEFORE DELETE ON projects BEGIN SELECT RAISE(ABORT, 'test deletion failure'); END");

        $this->deleteJson("/api/v1/projects/{$project->id}")->assertServerError();

        $this->assertModelExists($project);
        foreach ($history as $table => $rows) {
            expect(DB::table($table)->get()->map(static fn (object $row): array => (array) $row)->all())
                ->toBe($rows);
        }
    });

    it('returns 409 and preserves every row when one group is unfinished', function (TaskGroupStatus $status): void {
        $project = Project::query()->create(['name' => 'Acme', 'slug' => 'acme', 'repository_url' => 'https://github.com/acme/site.git']);
        projectDestroyTaskHistory($project, TaskGroupStatus::Cancelled);
        projectDestroyTaskHistory($project, TaskGroupStatus::Completed);
        $history = projectDestroyTaskHistory($project, $status);

        $this->deleteJson("/api/v1/projects/{$project->id}")
            ->assertConflict()
            ->assertJsonPath('error.code', 'project.has_task_groups')
            ->assertJsonPath('error.message', 'Project [acme] still has task groups.');

        $this->assertModelExists($project);
        foreach ($history as $table => $rows) {
            expect(DB::table($table)->get()->map(static fn (object $row): array => (array) $row)->all())
                ->toBe($rows);
        }
    })->with([
        TaskGroupStatus::Backlog, TaskGroupStatus::Todo, TaskGroupStatus::Reserved, TaskGroupStatus::Running,
        TaskGroupStatus::Reviewing, TaskGroupStatus::Settling, TaskGroupStatus::WaitingForReview, TaskGroupStatus::Failed,
    ]);
});
