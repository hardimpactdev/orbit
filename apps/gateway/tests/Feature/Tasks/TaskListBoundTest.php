<?php

declare(strict_types=1);

use App\Actions\Tasks\CreateTaskGroupAction;
use App\Domain\Instances\InstanceSourceLayout;
use App\Domain\Instances\InstanceState;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\OperationStatus;
use App\Domain\Tasks\TaskBroadcasts;
use App\Domain\Tasks\TaskCheckKind;
use App\Domain\Tasks\TaskCheckStatus;
use App\Domain\Tasks\TaskExtensionState;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskPrMode;
use App\Domain\Tasks\TaskStatus;
use App\Models\AgentThread;
use App\Models\Cluster;
use App\Models\Instance;
use App\Models\InstanceRuntime;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Project;
use App\Models\Route;
use App\Models\Task;
use App\Models\TaskCheck;
use App\Models\TaskReviewedCommit;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

uses(LazilyRefreshDatabase::class);

/** Long text and JSON that a list never reads, by table (docs/reference/tasks.md#list-tasks). */
const TaskListHeavyColumns = [
    'tasks' => ['completion_summary', 'fixup_problem', 'pending_resolution_reason', 'pending_resolution_message', 'handoff_reopen_reason', 'provider_retry', 'maintenance', 'deliverable_correction_resume'],
    'task_checks' => ['output', 'deliverable_evidence'],
    'agent_threads' => ['questions', 'error', 'observation_error'],
    'operations' => ['input', 'result', 'state'],
];

beforeEach(function (): void {
    $gateway = Node::withoutEvents(fn (): Node => Node::query()->create([
        'name' => 'gateway', 'status' => 'active', 'platform' => 'linux', 'public_ssh_host' => '10.44.0.2', 'wireguard_ip' => '10.44.0.2', 'user' => 'orbit',
    ]));
    $gateway->roles()->create(['role' => RoleName::Gateway, 'status' => 'active']);
    $this->withServerVariables(['REMOTE_ADDR' => '10.44.0.2']);
    app(TaskExtensionState::class)->enable();
    $this->orbit = Project::query()->create(['name' => 'orbit', 'slug' => 'orbit', 'repository_url' => 'https://github.com/hardimpactdev/orbit.git', 'default_branch' => 'main', 'root' => 'public']);
    $this->other = Project::query()->create(['name' => 'other', 'slug' => 'other', 'repository_url' => 'https://github.com/hardimpactdev/other.git', 'default_branch' => 'main', 'root' => 'public']);
});

/** A group as a long-lived board holds it: subtasks with long briefs, summaries, deliverables and check output, and threads with tokens. */
function taskListGroup(Project $project, TaskGroupStatus $status, int $tokens): Task
{
    $group = Task::topLevel()->create(['project_id' => $project->id, 'title' => 'Group', 'brief' => str_repeat('b', 8000), 'status' => $status, 'started_at' => now()->subMinutes(10)]);
    foreach ([1, 2] as $position) {
        $subtask = $group->tasks()->create([
            'position' => $position, 'title' => 'Subtask '.$position, 'brief' => str_repeat('s', 16 * 1024), 'status' => TaskStatus::Completed,
            'completion_summary' => str_repeat('c', 32 * 1024), 'tokens' => 7,
            'deliverables' => array_map(static fn (int $n): array => ['id' => 'd'.$n, 'type' => 'review', 'description' => str_repeat('d', 1000)], range(1, 8)),
            'started_at' => now()->subMinutes(9), 'settled_at' => now()->subMinutes(4),
        ]);
        TaskCheck::query()->create(['task_id' => $subtask->id, 'pid' => 0, 'process_started' => '', 'head_before' => '', 'tree_before' => '', 'status' => TaskCheckStatus::Passed, 'started_at' => now()->subMinutes(5), 'finished_at' => now()->subMinutes(4), 'exit_code' => 0, 'output' => str_repeat('o', 16 * 1024), 'changed_paths' => ['a.php']]);
        AgentThread::query()->create(['task_group_id' => $group->id, 'task_id' => $subtask->id, 'driver' => 'pi', 'runtime_key' => 'node:1', 'external_id' => "session-{$group->id}-{$position}", 'role' => 'implementer', 'tokens' => $tokens, 'questions' => array_fill(0, 50, ['q' => str_repeat('q', 200)])]);
    }

    return $group;
}

/** Copies one real group's stored rows into many more groups, fast enough for the per-test time limit. */
function taskListBoard(Project $project, int $groups): void
{
    $template = taskListGroup($project, TaskGroupStatus::Completed, 1);
    $group = (array) DB::table('tasks')->find($template->id);
    $subtasks = DB::table('tasks')->where('parent_id', $template->id)->orderBy('position')->get()->map(static fn (object $row): array => (array) $row);
    $checks = DB::table('task_checks')->get()->keyBy('task_id')->map(static fn (object $row): array => (array) $row);
    $threads = DB::table('agent_threads')->get()->keyBy('task_id')->map(static fn (object $row): array => (array) $row);
    foreach (range(2, $groups) as $n) {
        $groupId = DB::table('tasks')->insertGetId([...$group, 'id' => null, 'status' => $n % 3 === 0 ? TaskGroupStatus::Running->value : TaskGroupStatus::Completed->value]);
        foreach ($subtasks as $subtask) {
            $subtaskId = DB::table('tasks')->insertGetId([...$subtask, 'id' => null, 'parent_id' => $groupId]);
            DB::table('task_checks')->insert([...$checks[$subtask['id']], 'id' => null, 'task_id' => $subtaskId]);
            DB::table('agent_threads')->insert([...$threads[$subtask['id']], 'id' => null, 'task_group_id' => $groupId, 'task_id' => $subtaskId, 'external_id' => "session-{$subtaskId}", 'tokens' => $n]);
        }
    }
}

/** @param  list<string>  $queries */
function taskListSelectsNoHeavyColumn(array $queries): void
{
    foreach ($queries as $sql) {
        if (preg_match('/^select (.*?) from "(\w+)"/s', $sql, $match) !== 1 || ! isset(TaskListHeavyColumns[$match[2]])) {
            continue;
        }
        expect($match[1])->not->toMatch('/(^|, )("\w+"\.)?\*/');
        foreach (TaskListHeavyColumns[$match[2]] as $column) {
            expect($match[1])->not->toContain('"'.$column.'"');
        }
    }
}

/** The workspace runs on its own Node, which makes its group a task copy (TaskInstanceWorkspace::forGroup). */
function taskListCopyRuntime(Instance $workspace, int $n): void
{
    Node::query()->whereKey($workspace->node_id)->update(['instance_bridge' => '10.253.0.0/24']);
    InstanceRuntime::query()->create(['node_id' => $workspace->node_id, 'power_state' => 'running', 'runtime_version' => 'test', 'agent_version' => 'test', 'instance_id' => $workspace->id,
        'incus_project' => 'orbit-instances', 'container_name' => 'instance-'.$workspace->id, 'bridge_address' => '10.253.0.'.(4 + $n), 'seed_commit' => str_repeat('a', 40)]);
}

/** @return array{0: TestResponse, 1: list<string>} the response and the reads it ran */
function taskListRead(string $query = ''): array
{
    app(TaskBroadcasts::class)->flush();
    $queries = [];
    DB::listen(static function ($event) use (&$queries): void {
        if (str_starts_with($event->sql, 'select')) {
            $queries[] = $event->sql;
        }
    });
    $response = test()->getJson('/api/v1/tasks'.$query)->assertOk();
    DB::flushQueryLog();

    return [$response, $queries];
}

describe('the task list', function (): void {
    it('reads a page of a large board in a fixed number of queries, without long text or JSON columns', function (): void {
        taskListBoard($this->orbit, 220);

        [$page, $pageQueries] = taskListRead();
        [, $fullQueries] = taskListRead('?limit=100');

        $page->assertJsonCount(20, 'data')->assertJsonPath('data.0.subtasks.0.check.status', 'passed')
            ->assertJsonMissingPath('data.0.subtasks.0.completion_summary')->assertJsonMissingPath('data.0.subtasks.0.fixup_problem')
            ->assertJsonMissingPath('data.0.subtasks.0.check.output');
        expect(count($pageQueries))->toBeLessThanOrEqual(16)
            ->and(count($fullQueries))->toBe(count($pageQueries));
        taskListSelectsNoHeavyColumn([...$pageQueries, ...$fullQueries]);
    });

    it('keeps the tokens and duration of each row', function (): void {
        $this->freezeSecond();
        $group = taskListGroup($this->orbit, TaskGroupStatus::Completed, 50);
        AgentThread::query()->create(['task_group_id' => $group->id, 'driver' => 'pi', 'runtime_key' => 'node:1', 'external_id' => AgentThread::PendingPrefix.'x', 'role' => 'reviewer', 'tokens' => 1000]);
        AgentThread::query()->create(['task_group_id' => $group->id, 'driver' => 'pi', 'runtime_key' => 'node:1', 'external_id' => 'negative', 'role' => 'reviewer', 'tokens' => -5]);
        $silent = taskListGroup($this->orbit, TaskGroupStatus::Completed, 1);
        AgentThread::query()->where('task_group_id', $silent->id)->update(['tokens' => null]);

        [$response] = taskListRead();

        $response->assertJsonPath('data.0.id', $silent->id)->assertJsonPath('data.0.tokens', null)
            ->assertJsonPath('data.1.id', $group->id)->assertJsonPath('data.1.tokens', 100)->assertJsonPath('data.1.duration_ms', 10 * 60 * 1000)
            ->assertJsonPath('data.1.subtasks.0.tokens', 7)->assertJsonPath('data.1.subtasks.0.duration_ms', 5 * 60 * 1000);
    });

    it('shows each field of the detail but the long text a list leaves out', function (): void {
        $this->freezeSecond();
        $group = taskListGroup($this->orbit, TaskGroupStatus::Reserved, 3);
        $node = Node::withoutEvents(fn (): Node => Node::query()->create(['name' => 'tvm-1', 'status' => 'active', 'platform' => 'linux', 'wireguard_ip' => '10.44.0.137', 'public_ssh_host' => '10.44.0.137', 'user' => 'orbit']));
        $workspace = Instance::query()->create(['project_id' => $this->orbit->id, 'node_id' => $node->id, 'name' => 'workspace', 'source_layout' => InstanceSourceLayout::Checkout, 'checkout_path' => '/home/orbit/apps/orbit/workspace', 'task_workspace_owner_id' => $group->id, 'status' => InstanceState::Reserved]);
        taskListCopyRuntime($workspace, 1);
        $group->update(['taskable_type' => $workspace->getMorphClass(), 'taskable_id' => $workspace->id, 'capacity_wait_reason' => 'task places full', 'pr_branch' => 'feature', 'pr_mode' => TaskPrMode::PrWhenDone]);
        $group->tasks()->firstOrFail()->update(['fixup_problem' => 'A failed check.']);
        $cluster = Cluster::query()->create(['name' => 'dev', 'tld' => 'test']);
        $route = Route::withoutEvents(fn (): Route => Route::query()->create(['kind' => 'app', 'project_id' => $this->orbit->id, 'cluster_id' => $cluster->id, 'provenance' => 'explicit',
            'publication' => 'private', 'domain' => 'workspace.orbit.test', 'status' => 'pending']));
        $route->targets()->create(['instance_id' => $workspace->id, 'position' => 1]);
        $route->forceFill(['status' => 'active'])->saveQuietly();
        TaskReviewedCommit::query()->create(['task_id' => $group->id, 'sha' => str_repeat('a', 40), 'source' => 'orbit_push']);
        Operation::query()->create(['kind' => 'tasks.provision', 'target_type' => 'task_group', 'target_id' => $group->id, 'input' => [], 'status' => OperationStatus::Waiting]);

        [$list] = taskListRead();
        $detail = $this->getJson('/api/v1/tasks/'.$group->id)->assertOk()->json('data');

        $expected = [...array_diff_key($detail, ['publication' => true]), 'subtasks' => array_map(static function (array $subtask): array {
            unset($subtask['completion_summary'], $subtask['fixup_problem'], $subtask['check']['output']);

            return $subtask;
        }, $detail['subtasks'])];
        expect($list->json('data.0'))->toBe($expected)
            ->and($expected['preview_url'])->toBe('https://workspace.orbit.test')->and($expected)->toHaveKey('vm_power')->and($expected['vm_power'])->toBeNull()->and($expected)->not->toHaveKey('capacity_wait')
            ->and($expected['review_and_merge']['reviewed_commits'])->toHaveCount(1)->and($expected['resolved_agents'])->not->toBe([])
            ->and($expected['pr_mode'])->toBe('pr_when_done')->and($expected['pr_mode_source'])->toBe('group')
            ->and($detail['publication']['state'] ?? null)->toBe('working');
    });

    it('lists task copies in the same number of reads as one, without their publication', function (): void {
        $node = Node::withoutEvents(fn (): Node => Node::query()->create(['name' => 'tvm-1', 'status' => 'active', 'platform' => 'linux', 'wireguard_ip' => '10.44.0.137', 'public_ssh_host' => '10.44.0.137', 'user' => 'orbit']));
        foreach (range(1, 3) as $n) {
            $group = taskListGroup($this->orbit, TaskGroupStatus::Running, $n);
            $workspace = Instance::query()->create(['project_id' => $this->orbit->id, 'node_id' => $node->id, 'name' => 'workspace-'.$n, 'source_layout' => InstanceSourceLayout::Checkout, 'checkout_path' => '/home/orbit/apps/orbit/workspace-'.$n, 'task_workspace_owner_id' => $group->id, 'status' => InstanceState::Reserved]);
            taskListCopyRuntime($workspace, $n);
            $group->update(['taskable_type' => $workspace->getMorphClass(), 'taskable_id' => $workspace->id]);
        }

        [, $one] = taskListRead('?limit=1');
        [$page, $three] = taskListRead('?limit=3');

        expect(count($three))->toBe(count($one))
            ->and(array_column($page->json('data'), 'pr_mode_source'))->toBe(['gateway', 'gateway', 'gateway'])
            ->and(array_filter($page->json('data'), static fn (array $row): bool => array_key_exists('publication', $row)))->toBe([]);
        taskListSelectsNoHeavyColumn($three);
    });

    it('reads the review of tasks with no subtask from the newest reviewer turn and handoff check only, in the list and the compact list', function (): void {
        $reviews = [];
        foreach (['approved', 'reviewing', 'not_approved'] as $n => $expected) {
            $group = Task::topLevel()->create(['project_id' => $this->orbit->id, 'title' => 'Alone '.$n, 'brief' => 'Brief', 'status' => TaskGroupStatus::Running]);
            $work = CreateTaskGroupAction::openWork($group, [['id' => 'tests', 'type' => 'command', 'description' => 'Tests pass', 'command' => 'vendor/bin/pest']]);
            $thread = static fn (string $role): AgentThread => AgentThread::query()->create(['task_group_id' => $group->id, 'task_id' => $work->id, 'driver' => 't3',
                'runtime_key' => 'node:1', 'external_id' => "{$role}-{$group->id}", 'role' => $role]);
            $implementer = $thread('implementer');
            $reviewer = $thread('reviewer');
            $group->update(['reviewer_agent_thread_id' => $reviewer->id]);
            $work->update(['status' => $expected === 'reviewing' ? TaskStatus::Running : TaskStatus::Completed]);
            foreach (['1', '2'] as $sha) {
                TaskCheck::query()->create(['task_id' => $work->id, 'kind' => TaskCheckKind::Handoff, 'pid' => 0, 'process_started' => '', 'head_before' => '', 'tree_before' => '',
                    'status' => TaskCheckStatus::Passed, 'commit_sha' => str_repeat($sha, 40), 'started_at' => now(), 'finished_at' => now(), 'exit_code' => 0,
                    'output' => str_repeat('o', 16 * 1024)]);
            }
            // An older reviewer turn on the first check, an implementer turn, then the newest reviewer turn.
            $turn = fn (AgentThread $by, string $sha, string $status): Operation => Operation::query()->create(['kind' => 'tasks.copy_turn', 'target_type' => 'task_group',
                'target_id' => $group->id, 'status' => $status, 'input' => ['thread_id' => $by->id, 'subtask' => $work->id, 'candidate' => str_repeat($sha, 40),
                    'prompt' => str_repeat('p', 16 * 1024)], 'result' => ['state' => 'done', 'ended' => true]]);
            $turn($reviewer, '1', 'succeeded');
            $turn($implementer, '2', 'succeeded');
            $turn($reviewer, $expected === 'not_approved' ? '1' : '2', $expected === 'reviewing' ? 'running' : 'succeeded');
            $reviews[$group->id] = $expected;
        }

        [$list, $three] = taskListRead('?limit=3');
        [$compact, $compactQueries] = taskListRead('?limit=3&compact=true');
        [, $one] = taskListRead('?limit=1');

        // The list is newest first.
        expect(array_column($list->json('data'), 'work_review', 'id'))->toBe(array_reverse($reviews, true))
            ->and(array_column($compact->json('data'), 'work_review', 'id'))->toBe(array_reverse($reviews, true))
            ->and(count($three))->toBe(count($one));
        taskListSelectsNoHeavyColumn([...$three, ...$compactQueries]);
    });

    it('pages newest first by cursor until the last page', function (): void {
        $ids = array_map(fn (int $n): int => taskListGroup($this->orbit, TaskGroupStatus::Backlog, $n)->id, range(1, 25));
        $newest = array_reverse($ids);

        [$first] = taskListRead('?limit=10');
        $first->assertJsonPath('meta.next_cursor', (string) $newest[9]);
        expect(array_column($first->json('data'), 'id'))->toBe(array_slice($newest, 0, 10));

        [$second] = taskListRead('?limit=10&cursor='.$newest[9]);
        expect(array_column($second->json('data'), 'id'))->toBe(array_slice($newest, 10, 10));

        [$last] = taskListRead('?limit=10&cursor='.$newest[19]);
        $last->assertJsonPath('meta.next_cursor', null);
        expect(array_column($last->json('data'), 'id'))->toBe(array_slice($newest, 20));
    });

    it('pages within the status and Project filters', function (): void {
        $running = [];
        foreach (range(1, 12) as $n) {
            $group = taskListGroup($n % 2 === 0 ? $this->orbit : $this->other, $n % 3 === 0 ? TaskGroupStatus::Running : TaskGroupStatus::Backlog, $n);
            if ($n % 3 === 0 && $n % 2 === 0) {
                $running[] = $group->id;
            }
        }
        $running = array_reverse($running);

        [$first] = taskListRead('?limit=1&status=running&project_id='.$this->orbit->id);
        expect(array_column($first->json('data'), 'id'))->toBe([$running[0]]);
        [$second] = taskListRead('?limit=1&status=running&project_id='.$this->orbit->id.'&cursor='.$first->json('meta.next_cursor'));
        expect(array_column($second->json('data'), 'id'))->toBe([$running[1]])->and($second->json('meta.next_cursor'))->toBeNull();
    });

    it('refuses a limit or cursor out of form', function (string $query): void {
        $this->getJson('/api/v1/tasks?'.$query)->assertUnprocessable()->assertJsonPath('error.code', 'validation.failed');
    })->with(['limit=0', 'limit=101', 'cursor=abc', 'cursor=0']);

    it('refuses a page with parent_id, which lists every subtask of one group', function (string $page): void {
        $group = taskListGroup($this->orbit, TaskGroupStatus::Running, 1);

        $this->getJson('/api/v1/tasks?parent_id='.$group->id.'&'.$page)->assertUnprocessable()->assertJsonPath('error.code', 'validation.failed')
            ->assertJsonPath('error.details.parent_id', fn (mixed $messages): bool => $messages !== null);
        $this->getJson('/api/v1/tasks?parent_id='.$group->id)->assertOk()->assertJsonCount(2, 'data');
    })->with(['limit=5', 'cursor=10']);

    it('lists the subtasks of a parent in a fixed number of queries, without long text or JSON columns', function (): void {
        $group = taskListGroup($this->orbit, TaskGroupStatus::Running, 1);
        foreach (range(3, 40) as $position) {
            $subtask = $group->tasks()->create(['position' => $position, 'title' => 'More', 'brief' => str_repeat('s', 16 * 1024), 'completion_summary' => str_repeat('c', 32 * 1024)]);
            TaskCheck::query()->create(['task_id' => $subtask->id, 'pid' => 0, 'process_started' => '', 'head_before' => '', 'tree_before' => '', 'status' => TaskCheckStatus::Failed, 'started_at' => now(), 'output' => str_repeat('o', 16 * 1024)]);
        }

        [$response, $queries] = taskListRead('?parent_id='.$group->id);

        $response->assertJsonCount(40, 'data')->assertJsonPath('data.0.position', 1)->assertJsonMissingPath('data.0.completion_summary')
            ->assertJsonPath('data.39.check.status', 'failed')->assertJsonMissingPath('data.39.check.output')->assertJsonPath('meta.next_cursor', null);
        expect(count($queries))->toBeLessThanOrEqual(10);
        taskListSelectsNoHeavyColumn($queries);
    });
});
