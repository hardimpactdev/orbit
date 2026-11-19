<?php

declare(strict_types=1);

use App\Actions\Tasks\CreateTaskGroupAction;
use App\Actions\Tasks\UpdateTaskAction;
use App\Data\Tasks\UpdateTaskData;
use App\Domain\Extensions\ExtensionStore;
use App\Domain\Tasks\AssistanceKind;
use App\Domain\Tasks\TaskAssistance;
use App\Domain\Tasks\TaskCheckKind;
use App\Domain\Tasks\TaskCheckStatus;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskStatus;
use App\Models\AgentThread;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskCheck;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Orbit\Extensions\Conn\Models\ConnEnvironment;

uses(LazilyRefreshDatabase::class);

beforeEach(function (): void {
    Queue::fake();
    Http::preventStrayRequests();
    app(ExtensionStore::class)->set('conn', true);
    app(ExtensionStore::class)->set('tasks', true);
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-17 12:00:00'));
    $node = fn (string $name, string $ip): Node => Node::withoutEvents(fn (): Node => Node::query()->create(['name' => $name, 'status' => 'active',
        'platform' => 'linux', 'public_ssh_host' => $ip, 'wireguard_ip' => $ip, 'user' => 'orbit']));
    $this->beast = $node('beast', '10.44.0.7');
    $this->phone = $node('phone', '10.44.0.77');
    $this->phone->accessibleNodes()->attach($this->beast->id);
    // A caller with node access, but not to the Node that serves the workspaces.
    $this->stranger = $node('stranger', '10.44.0.78');
    $this->stranger->accessibleNodes()->attach($this->phone->id);
    $this->project = Project::query()->create(['name' => 'Orbit', 'slug' => 'orbit', 'code' => 'ORB', 'repository_url' => 'https://github.com/hardimpactdev/orbit.git',
        'default_branch' => 'main', 'root' => '.']);
});

afterEach(fn () => CarbonImmutable::setTestNow());

/** A top-level task in the test Project, with its own workspace when `$workspace` names it. */
function panelTask(string $title, ?string $workspace = null, array $attributes = []): Task
{
    $group = Task::topLevel()->create(['project_id' => test()->project->id, 'title' => $title, 'brief' => 'Brief of '.$title, 'status' => TaskGroupStatus::Running, ...$attributes]);
    if ($workspace !== null) {
        $instance = Instance::query()->forceCreate(['node_id' => test()->beast->id, 'project_id' => test()->project->id, 'name' => $workspace, 'branch' => $workspace,
            'checkout_path' => '/workspace/'.$workspace, 'root' => '.', 'status' => 'active', 'first_setup_pending' => false, 'task_workspace_owner_id' => $group->id]);
        $group->update(['taskable_type' => 'instance', 'taskable_id' => $instance->id]);
    }

    return $group->refresh();
}

function panelEnvironment(Task $group, string $id): ConnEnvironment
{
    $environment = ConnEnvironment::query()->create(['environment_id' => $id, 'label' => 'orbit / '.$id, 'url' => 'http://10.44.0.7:3840',
        'node_id' => test()->beast->id, 'admin_session' => 'session-'.$id, 'admin_session_expires_at' => now()->addDays(20), 'registered_at' => now()]);
    $environment->forceFill(['instance_id' => $group->taskable_id])->save();

    return $environment;
}

function panelThread(Task $group, Task $subtask, string $role, string $externalId, array $attributes = []): AgentThread
{
    return AgentThread::query()->forceCreate(['task_group_id' => $group->id, 'task_id' => $subtask->id, 'node_id' => test()->beast->id, 'driver' => 't3',
        'runtime_key' => 'instance:'.$group->taskable_id, 'external_id' => $externalId, 'role' => $role, ...$attributes]);
}

function panelCall(Node $caller, string $path): TestResponse
{
    return test()->withServerVariables(['REMOTE_ADDR' => $caller->wireguard_ip])->getJson('/api/v1/conn/'.$path);
}

describe('conn:task:list', function (): void {
    it('lists top-level tasks newest first in the scope, filtered and paged like task:list', function (): void {
        $first = panelTask('First', 'first');
        $second = panelTask('Second', 'second', ['status' => TaskGroupStatus::Completed]);
        $third = panelTask('Third');
        $first->tasks()->create(['title' => 'A', 'brief' => 'A']);
        $other = Project::query()->create(['name' => 'Other', 'slug' => 'other', 'repository_url' => 'https://github.com/x/other.git', 'default_branch' => 'main', 'root' => '.']);
        Task::topLevel()->create(['project_id' => $other->id, 'title' => 'Elsewhere', 'brief' => 'B']);

        $page = panelCall($this->phone, 'tasks?project='.$this->project->id.'&limit=2')->assertOk();
        $next = panelCall($this->phone, 'tasks?project='.$this->project->id.'&limit=2&cursor='.$page->json('meta.next_cursor'))->assertOk();

        expect(array_column($page->json('data'), 'id'))->toBe([$third->id, $second->id])
            ->and($page->json('meta.next_cursor'))->toBe((string) $second->id)
            ->and(array_column($next->json('data'), 'id'))->toBe([$first->id])
            ->and($next->json('meta.next_cursor'))->toBeNull()
            ->and(array_column(panelCall($this->phone, 'tasks?status=completed')->json('data'), 'id'))->toBe([$second->id])
            ->and(array_column(panelCall($this->phone, 'tasks?instance='.$first->taskable_id)->json('data'), 'id'))->toBe([$first->id]);
        panelCall($this->phone, 'tasks?limit=101')->assertStatus(422);
    });

    it('finds the tasks of one pull request by repository and number, watched or published, in any case', function (): void {
        $published = panelTask('Published', attributes: ['pr_url' => 'https://github.com/hardimpactdev/orbit/pull/12', 'pr_opened_at' => '2026-11-17T10:00:00Z']);
        $watched = panelTask('Watched', attributes: ['watched_pr_url' => 'https://github.com/HardImpactDev/Orbit/pull/34', 'watched_pr_number' => 34]);
        panelTask('Other number', attributes: ['pr_url' => 'https://github.com/hardimpactdev/orbit/pull/123']);
        $find = fn (string $query): array => panelCall($this->phone, 'tasks?'.$query)->assertOk()->json('data');

        expect(array_column($find('repository=hardimpactdev/orbit&pull_request=12'), 'id'))->toBe([$published->id])
            ->and($find('repository=hardimpactdev/orbit&pull_request=12')[0]['pr_opened_at'])->toBe('2026-11-17T10:00:00+00:00')
            ->and(array_column($find('repository=hardimpactdev/ORBIT&pull_request=34'), 'id'))->toBe([$watched->id])
            ->and($find('repository=hardimpactdev/other&pull_request=12'))->toBe([])
            ->and($find('repository=hardimpactdev/orbit&pull_request=99'))->toBe([]);
        panelCall($this->phone, 'tasks?pull_request=12')->assertStatus(422);
        panelCall($this->phone, 'tasks?repository=hardimpactdev/orbit/pull&pull_request=12')->assertStatus(422);
    });

    it('shows a row\'s project, workspace, pull request, subtask counts and times', function (): void {
        $group = panelTask('Row', 'row', ['pr_url' => 'https://github.com/hardimpactdev/orbit/pull/3990']);
        foreach ([TaskStatus::Completed, TaskStatus::Reviewing, TaskStatus::Running, TaskStatus::Todo] as $status) {
            $group->tasks()->create(['title' => $status->value, 'brief' => 'B'])->update(['status' => $status]);
        }
        $environment = panelEnvironment($group, 'env-row');

        $row = panelCall($this->phone, 'tasks')->assertOk()->json('data.0');

        expect($row)->toBe([
            'id' => $group->id, 'title' => 'Row', 'status' => 'running', 'attention' => null,
            'project' => ['id' => $this->project->id, 'slug' => 'orbit', 'name' => 'Orbit', 'code' => 'ORB'],
            'workspace' => ['instance_id' => $group->taskable_id, 'name' => 'row', 'status' => 'active', 'power_state' => null,
                'node' => ['id' => $this->beast->id, 'name' => 'beast'], 'branch' => 'row', 'conn_environment_id' => $environment->environment_id],
            'pull_request' => ['number' => 3990, 'url' => 'https://github.com/hardimpactdev/orbit/pull/3990', 'state' => null],
            'subtask_counts' => ['total' => 4, 'todo' => 1, 'running' => 2, 'completed' => 1, 'failed' => 0, 'cancelled' => 0],
            'created_at' => '2026-11-17T12:00:00+00:00', 'updated_at' => '2026-11-17T12:00:00+00:00', 'last_activity_at' => null,
            'deployed_at' => null, 'pr_opened_at' => null,
        ]);

        $group->update(['watched_pr_number' => 4000, 'watched_pr_url' => 'https://github.com/hardimpactdev/orbit/pull/4000', 'watched_pr_state' => 'open']);
        Operation::query()->forceCreate(['kind' => 'tasks.copy_turn', 'target_type' => 'task_group', 'target_id' => $group->id, 'status' => 'succeeded',
            'input' => [], 'state' => [], 'started_at' => '2026-11-17 12:05:00', 'finished_at' => '2026-11-17 12:09:00']);
        $group->update(['deployed_at' => '2026-11-17 12:30:00']);
        $row = panelCall($this->phone, 'tasks')->json('data.0');
        expect($row['deployed_at'])->toBe('2026-11-17T12:30:00+00:00');
        expect($row['pull_request'])->toBe(['number' => 4000, 'url' => 'https://github.com/hardimpactdev/orbit/pull/4000', 'state' => 'open'])
            ->and($row['last_activity_at'])->toBe('2026-11-17T12:09:00+00:00');
    });

    it('says what a task asks: a current thread\'s question before the task\'s own call for help', function (): void {
        $group = panelTask('Asks', 'asks');
        TaskAssistance::apply($group, AssistanceKind::Direction, 'Which schema?', 'The brief names two.');
        $subtask = $group->tasks()->create(['title' => 'A', 'brief' => 'B']);
        expect(panelCall($this->phone, 'tasks')->json('data.0.attention'))->toBe(['kind' => 'direction', 'question' => 'Which schema?', 'reason' => 'The brief names two.']);

        $thread = panelThread($group, $subtask, 'implementer', 'orbit:asks', ['state' => 'asking_for_input',
            'questions' => [['request_id' => 'r1', 'question' => 'Which table?'], ['request_id' => 'r2', 'question' => 'Which column?']]]);
        $subtask->update(['implementer_agent_thread_id' => $thread->id]);

        expect(panelCall($this->phone, 'tasks')->json('data.0.attention'))->toBe(['kind' => 'question', 'question' => 'Which table?', 'reason' => null]);
    });

    it('names the workspace\'s Conn server only to a caller who reaches it, and no workspace once it is gone', function (): void {
        $group = panelTask('Scoped', 'scoped');
        panelTask('Bare', 'bare');
        panelEnvironment($group, 'env-scoped');

        $phone = collect(panelCall($this->phone, 'tasks')->json('data'))->keyBy('title');
        expect($phone['Scoped']['workspace']['conn_environment_id'])->toBe('env-scoped')
            ->and($phone['Bare']['workspace']['conn_environment_id'])->toBeNull()
            ->and(panelCall($this->stranger, 'tasks')->json('data.1.workspace.conn_environment_id'))->toBeNull();

        // A workspace's removal deregisters its server first (docs/reference/conn.md#sleep-and-removal).
        ConnEnvironment::query()->where('instance_id', $group->taskable_id)->delete();
        Instance::query()->whereKey($group->taskable_id)->delete();
        expect(panelCall($this->phone, 'tasks')->json('data.1.workspace'))->toBeNull();
    });
});

describe('conn:task:show', function (): void {
    it('reads a task with its subtasks in order, metrics, and every started thread with its Conn thread', function (): void {
        $group = panelTask('Detail', 'detail', ['tokens' => 0, 'lines_added' => 40, 'lines_deleted' => 7, 'started_at' => now()->subMinutes(10)]);
        $first = $group->tasks()->create(['position' => 1, 'title' => 'First', 'brief' => 'First brief', 'deliverables' => [
            ['id' => 'tests', 'type' => 'file', 'description' => 'The tests', 'path' => 'tests/A.php', 'change' => 'created'],
        ]]);
        $second = $group->tasks()->create(['position' => 2, 'title' => 'Second', 'brief' => 'Second brief']);
        $first->update(['status' => TaskStatus::Running, 'started_at' => now()->subMinutes(9), 'tokens' => 300]);
        // The subtask completes the normal way, five minutes after its start: its duration ends there.
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-17 11:56:00'));
        app(UpdateTaskAction::class)->execute($group->refresh(), $first->refresh(), new UpdateTaskData(null, null, null, status: TaskStatus::Completed));
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-17 12:00:00'));
        $second->update(['status' => TaskStatus::Running, 'started_at' => now()->subMinutes(3)]);
        panelEnvironment($group, 'env-detail');
        $earlier = panelThread($group, $first, 'implementer', 'orbit:first-1', ['provider_instance' => 'claudeMedium', 'model' => 'claude-opus-5-5', 'effort' => 'medium',
            'state' => 'failed', 'tokens' => 100, 'created_at' => now()->subMinutes(9), 'observed_at' => now()->subMinutes(8)]);
        $replaced = panelThread($group, $first, 'implementer', 'orbit:first-2', ['provider_instance' => 'codexHigh', 'model' => 'gpt-6.1-sol', 'effort' => 'high',
            'state' => 'done', 'tokens' => 200, 'created_at' => now()->subMinutes(8)]);
        $reviewer = panelThread($group, $first, 'reviewer', 'orbit:review-1', ['model' => 'claude-sonnet-5-5', 'effort' => 'high', 'state' => 'done', 'tokens' => 50]);
        $asking = panelThread($group, $second, 'implementer', 'orbit:second-1', ['provider_instance' => 'claudeHigh', 'model' => 'claude-opus-5-5', 'effort' => 'high',
            'state' => 'asking_for_input', 'questions' => [['request_id' => 'r1', 'question' => 'Keep the index?']]]);
        panelThread($group, $second, 'reviewer', AgentThread::PendingPrefix.'reserved');
        $first->update(['implementer_agent_thread_id' => $replaced->id]);
        $second->update(['implementer_agent_thread_id' => $asking->id]);
        $group->update(['reviewer_agent_thread_id' => $reviewer->id]);

        $task = panelCall($this->phone, 'tasks/'.$group->id)->assertOk()->json('data');

        expect($task['brief'])->toBe('Brief of Detail')
            ->and($task['execution'])->toBe(['implementer_mode' => 'per_subtask'])
            ->and($task['attention'])->toBe(['kind' => 'question', 'question' => 'Keep the index?', 'reason' => null])
            ->and($task['workspace']['conn_environment_id'])->toBe('env-detail')
            // Tokens count every started thread once, the reviewer and the earlier attempt too; the lines are the branch snapshot.
            ->and($task['metrics'])->toBe(['duration_ms' => 600000, 'tokens' => 350, 'lines_added' => 40, 'lines_deleted' => 7])
            ->and($task['subtasks'])->toBe([
                ['id' => $first->id, 'position' => $first->refresh()->position, 'title' => 'First', 'brief' => 'First brief',
                    'deliverables' => [['id' => 'tests', 'type' => 'file', 'description' => 'The tests']], 'status' => 'completed', 'attention' => null,
                    'current_implementer_agent_id' => $replaced->id, 'metrics' => ['duration_ms' => 300000, 'tokens' => 300, 'lines_added' => null, 'lines_deleted' => null]],
                ['id' => $second->id, 'position' => $second->refresh()->position, 'title' => 'Second', 'brief' => 'Second brief', 'deliverables' => [], 'status' => 'running',
                    'attention' => ['kind' => 'question', 'question' => 'Keep the index?', 'reason' => null],
                    'current_implementer_agent_id' => $asking->id, 'metrics' => ['duration_ms' => 180000, 'tokens' => null, 'lines_added' => null, 'lines_deleted' => null]],
            ])
            ->and($task['agents'])->toBe([
                ['id' => $earlier->id, 'role' => 'implementer', 'subtask_ids' => [$first->id], 'current' => false, 'attempt' => 1, 'provider' => 'claude',
                    'model' => 'claude-opus-5-5', 'effort' => 'medium', 'state' => 'failed', 'needs_input' => false,
                    'created_at' => '2026-11-17T11:51:00+00:00', 'observed_at' => '2026-11-17T11:52:00+00:00', 'conn' => ['environment_id' => 'env-detail', 'thread_id' => 'orbit:first-1']],
                ['id' => $replaced->id, 'role' => 'implementer', 'subtask_ids' => [$first->id], 'current' => true, 'attempt' => 2, 'provider' => 'codex',
                    'model' => 'gpt-6.1-sol', 'effort' => 'high', 'state' => 'done', 'needs_input' => false,
                    'created_at' => '2026-11-17T11:52:00+00:00', 'observed_at' => null, 'conn' => ['environment_id' => 'env-detail', 'thread_id' => 'orbit:first-2']],
                ['id' => $reviewer->id, 'role' => 'reviewer', 'subtask_ids' => [$first->id], 'current' => true, 'attempt' => 1, 'provider' => 'claude',
                    'model' => 'claude-sonnet-5-5', 'effort' => 'high', 'state' => 'done', 'needs_input' => false,
                    'created_at' => '2026-11-17T12:00:00+00:00', 'observed_at' => null, 'conn' => ['environment_id' => 'env-detail', 'thread_id' => 'orbit:review-1']],
                ['id' => $asking->id, 'role' => 'implementer', 'subtask_ids' => [$second->id], 'current' => true, 'attempt' => 1, 'provider' => 'claude',
                    'model' => 'claude-opus-5-5', 'effort' => 'high', 'state' => 'asking_for_input', 'needs_input' => true,
                    'created_at' => '2026-11-17T12:00:00+00:00', 'observed_at' => null, 'conn' => ['environment_id' => 'env-detail', 'thread_id' => 'orbit:second-1']],
            ]);
    });

    it('gives no Conn thread without access, without a server, or once the workspace is gone', function (): void {
        $group = panelTask('Refs', 'refs');
        $subtask = $group->tasks()->create(['title' => 'A', 'brief' => 'B']);
        panelThread($group, $subtask, 'implementer', 'orbit:refs');
        $conn = fn (Node $caller): mixed => panelCall($caller, 'tasks/'.$group->id)->assertOk()->json('data.agents.0.conn');

        expect($conn($this->phone))->toBeNull();
        panelEnvironment($group, 'env-refs');
        expect($conn($this->phone))->toBe(['environment_id' => 'env-refs', 'thread_id' => 'orbit:refs'])
            ->and($conn($this->stranger))->toBeNull();
        ConnEnvironment::query()->where('instance_id', $group->taskable_id)->delete();
        expect($conn($this->phone))->toBeNull();
        Instance::query()->whereKey($group->taskable_id)->delete();
        expect($conn($this->phone))->toBeNull();
    });

    it('reads a task with no subtask with its work in place of subtasks, and the work\'s call for help as the task\'s', function (): void {
        $group = panelTask('Alone', 'alone', ['tokens' => 120, 'started_at' => now()->subMinutes(4)]);
        $work = CreateTaskGroupAction::openWork($group, [['id' => 'tests', 'type' => 'command', 'description' => 'The tests pass', 'command' => 'vendor/bin/pest']]);
        $work->update(['status' => TaskStatus::Reviewing, 'started_at' => now()->subMinutes(4)]);
        panelEnvironment($group, 'env-alone');
        $implementer = panelThread($group, $work, 'implementer', 'orbit:alone-1', ['model' => 'claude-opus-5-5', 'state' => 'done']);
        $reviewer = panelThread($group, $work, 'reviewer', 'orbit:alone-review', ['model' => 'claude-opus-5-5', 'state' => 'working']);
        $work->update(['implementer_agent_thread_id' => $implementer->id]);
        $group->update(['reviewer_agent_thread_id' => $reviewer->id]);
        $check = TaskCheck::query()->create(['task_id' => $work->id, 'kind' => TaskCheckKind::Handoff, 'status' => TaskCheckStatus::Passed, 'pid' => 1,
            'process_started' => 'x', 'head_before' => str_repeat('a', 40), 'tree_before' => str_repeat('2', 40), 'exit_code' => 0, 'output' => 'ok',
            'started_at' => now()->subMinute(), 'finished_at' => now()]);

        $row = panelCall($this->phone, 'tasks')->assertOk()->json('data.0');
        $task = panelCall($this->phone, 'tasks/'.$group->id)->assertOk()->json('data');

        expect($row['subtask_counts'])->toBe(['total' => 0, 'todo' => 0, 'running' => 0, 'completed' => 0, 'failed' => 0, 'cancelled' => 0])
            ->and($row['work_status'])->toBe('reviewing')
            ->and($row['attention'])->toBeNull()
            ->and($task['execution'])->toBe(['implementer_mode' => 'task'])
            ->and($task['subtasks'])->toBe([])
            ->and($task['work'])->toBe([
                'status' => 'reviewing',
                'deliverables' => [['id' => 'tests', 'type' => 'command', 'description' => 'The tests pass']],
                'current_implementer_agent_id' => $implementer->id,
                'check' => ['id' => $check->id, 'kind' => 'handoff', 'status' => 'passed', 'started_at' => '2026-11-17T11:59:00+00:00',
                    'finished_at' => '2026-11-17T12:00:00+00:00', 'exit_code' => 0, 'failed_step' => null],
            ])
            ->and(array_map(static fn (array $agent): array => [$agent['id'], $agent['role'], $agent['subtask_ids'], $agent['current'], $agent['attempt']], $task['agents']))
            ->toBe([[$implementer->id, 'implementer', [], true, 1], [$reviewer->id, 'reviewer', [], true, 1]]);

        TaskAssistance::apply($work->refresh(), AssistanceKind::Failure, null, 'The handoff check failed twice.');
        expect(panelCall($this->phone, 'tasks')->json('data.0.attention'))->toBe(['kind' => 'failure', 'question' => null, 'reason' => 'The handoff check failed twice.'])
            ->and(panelCall($this->phone, 'tasks/'.$work->id)->assertNotFound()->json('error.code'))->toBe('http.404');
    });

    it('keeps the panel of a task with subtasks free of the work fields', function (): void {
        $group = panelTask('Split', 'split');
        $group->tasks()->create(['position' => 1, 'title' => 'A', 'brief' => 'A']);
        $group->tasks()->create(['position' => 2, 'title' => 'B', 'brief' => 'B']);

        $task = panelCall($this->phone, 'tasks/'.$group->id)->assertOk()->json('data');

        expect($task)->not->toHaveKey('work')->not->toHaveKey('work_status')
            ->and($task['execution'])->toBe(['implementer_mode' => 'per_subtask'])
            ->and(panelCall($this->phone, 'tasks')->json('data.0'))->not->toHaveKey('work_status');
    });

    it('answers 404 for a subtask or an unknown task, and refuses while the tasks extension is off', function (): void {
        $group = panelTask('Parent');
        $subtask = $group->tasks()->create(['title' => 'A', 'brief' => 'B']);

        panelCall($this->phone, 'tasks/'.$subtask->id)->assertNotFound()->assertJsonPath('error.code', 'http.404');
        panelCall($this->phone, 'tasks/999999')->assertNotFound()->assertJsonPath('error.code', 'http.404');

        app(ExtensionStore::class)->set('tasks', false);
        panelCall($this->phone, 'tasks')->assertStatus(409)->assertJsonPath('error.code', 'extension.disabled');
        panelCall($this->phone, 'tasks/'.$group->id)->assertStatus(409)->assertJsonPath('error.code', 'extension.disabled');
    });
});
