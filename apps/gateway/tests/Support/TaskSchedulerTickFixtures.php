<?php

declare(strict_types=1);

use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tasks\AgentDriverException;
use App\Domain\Tasks\AgentDriverRegistry;
use App\Domain\Tasks\AgentObservation;
use App\Domain\Tasks\AgentSpawner;
use App\Domain\Tasks\AgentThreadState;
use App\Domain\Tasks\QuestionAsker;
use App\Domain\Tasks\QuestionStatus;
use App\Domain\Tasks\TaskBaseBranchFetcher;
use App\Domain\Tasks\TaskBriefCoverage;
use App\Domain\Tasks\TaskCheckReading;
use App\Domain\Tasks\TaskCheckRunner;
use App\Domain\Tasks\TaskExtensionState;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskPullRequestException;
use App\Domain\Tasks\TaskPullRequestPublisher;
use App\Domain\Tasks\TaskScheduler;
use App\Domain\Tasks\TaskSettleMetrics;
use App\Domain\Tasks\TaskSettleMetricsCollector;
use App\Domain\Tasks\TaskStatus;
use App\Domain\Tasks\TaskThreadRole;
use App\Domain\Tasks\TaskTurnPullRequest;
use App\Domain\Tasks\TaskTurnReceipts;
use App\Domain\Tasks\TaskWorkspaceDiffReader;
use App\Domain\Tasks\TaskWorkspaceLease;
use App\Domain\Tasks\TaskWorkspaceMcp;
use App\Domain\Tasks\TaskWorkspaceSigner;
use App\Domain\Tasks\TaskWorkspaceStateReader;
use App\Domain\TaskVms\TaskVmProvider;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Tasks\Pi\PiDriver;
use App\Models\Activity;
use App\Models\AgentThread;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Project;
use App\Models\ProjectLifecycleStep;
use App\Models\Task;
use App\Models\TaskCheck;
use App\Models\TaskComment;
use App\Models\TaskQuestion;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Feature\GitHub\GitHubTestSupport;
use Tests\Support\AcceptingTaskWorkspaceMcp;
use Tests\Support\AgentCommandDispatcher;
use Tests\Support\AgentSnapshotReader;
use Tests\Support\FakeAgentDriver;
use Tests\Support\FakeTaskCheckRunner;
use Tests\Support\FakeTaskTurnReceipts;
use Tests\Support\NullTaskWorkspaceDiffReader;
use Tests\Support\RecordingTaskVmProvider;
use Tests\Support\UnreachableSshExecutor;

use function Pest\Laravel\mock;

function tick_bind_defaults(): void
{
    test_bind_snapshot_driver();
    // The tick Node's address is not a test host. A test that reaches it over SSH sees it fail at once.
    app()->instance(SshExecutor::class, new UnreachableSshExecutor);
    // Workspace removal of a task VM group destroys the VM; the provider records it.
    app()->instance(TaskVmProvider::class, new RecordingTaskVmProvider);
    app()->instance(TaskWorkspaceMcp::class, new AcceptingTaskWorkspaceMcp);
    tick_workspace();
}

/** The task VM provider of the test. It records each VM that workspace removal destroys. */
function tick_vm_provider(): RecordingTaskVmProvider
{
    $provider = app(TaskVmProvider::class);
    assert($provider instanceof RecordingTaskVmProvider);

    return $provider;
}

/**
 * The names of the task VMs that workspace removal destroyed, in order.
 *
 * @return list<string>
 */
function tick_vm_destroyed(): array
{
    return array_values(array_map(
        static fn (string $call): string => substr($call, strlen('destroy ')),
        array_filter(tick_vm_provider()->vmCalls, static fn (string $call): bool => str_starts_with($call, 'destroy ')),
    ));
}

/** Makes every task VM destroy fail with the exception, after recording it. */
function tick_vm_destroy_fails(Throwable $failure): void
{
    tick_vm_provider()->onDestroy = static function () use ($failure): never {
        throw $failure;
    };
}

/** A running group on its own task VM. */
function tick_group(): Task
{
    $project = Project::query()->create([
        'name' => 'tick-app',
        'slug' => 'tick-app',
        'repository_url' => 'git@example.test:tick.git',
        'default_branch' => 'main',
        'task_check' => 'composer check',
        'root' => 'public',
    ]);
    $node = Node::query()->create([
        'name' => 'tick-node',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '10.44.0.212',
        'wireguard_ip' => '10.44.0.212',
    ]);
    $group = Task::topLevel()->create([
        'implementer_agent_driver' => 'pi', 'reviewer_agent_driver' => 'pi',
        'project_id' => $project->id,
        'title' => 'Tick routing',
        'brief' => 'Observe, classify, and execute.',
        'status' => TaskGroupStatus::Running,
    ]);
    $instance = Instance::query()->create([
        'task_workspace_owner_id' => $group->id,
        'project_id' => $project->id,
        'node_id' => $node->id,
        'name' => 'task-21',
        'checkout_path' => '/srv/orbit/apps/tick-app/task-21',
        'starting_commit' => str_repeat('a', 40),
        'branch' => 'task-21',
        'status' => 'source_resolved',
    ]);
    $group->taskable()->associate($instance);
    $group->save();
    Task::query()->create([
        'parent_id' => $group->id,
        'position' => 1,
        'title' => 'Models',
        'brief' => 'Store the records.',
        'status' => TaskStatus::Running,
        'started_at' => now(),
    ]);

    test_link_agent_threads($group);

    return test_vm_group($group);
}

/** @return array{thread: array<string, mixed>} */
function tick_checked_thread(string $status, ?string $threadId = null): array
{
    return ['thread' => [
        'session' => ['status' => $status],
        'latestTurn' => ['id' => tick_observed_turn($threadId ?? 'checked-thread'), 'state' => 'done'],
        'activities' => [[
            'id' => 'check-1',
            'kind' => 'command.completed',
            'output' => 'composer check',
            'exitCode' => 0,
            'createdAt' => '2026-09-22T12:00:00Z',
        ]],
    ]];
}

/** A completed turn reported by the fake runtime, advanced only after an accepted command. */
function tick_observed_turn(string $threadId, ?string $fallback = null): string
{
    $turns = app()->bound(AgentCommandDispatcher::class)
        ? (get_object_vars(app(AgentCommandDispatcher::class))['turnIds'] ?? []) : [];

    return $turns[$threadId] ?? $fallback ?? $threadId.'-turn';
}

function tick_workspace(?string $branch = null): void
{
    app()->instance(TaskWorkspaceStateReader::class, new readonly class($branch) implements TaskWorkspaceStateReader
    {
        public function __construct(private ?string $branch) {}

        public function headCommit(Instance $instance): ?string
        {
            return null;
        }

        public function currentBranch(Instance $instance): ?string
        {
            return $this->branch;
        }
    });
}

/** @param object{turnId: string, error: string} $state */
function tick_pi_failure(object $state): void
{
    Http::fake(function (Request $request) use ($state) {
        if (str_ends_with($request->url(), '/messages')) {
            return Http::response(['duplicate' => false], 202);
        }
        if (! str_contains($request->url(), '/sessions/'.($state->sessionId ?? 'implementer-thread'))) {
            return null;
        }

        return Http::response([
            'kind' => 'snapshot', 'run' => 'run-1', 'sequence' => 4,
            'session' => ['id' => $state->sessionId ?? 'implementer-thread'],
            'state' => isset($state->state) ? $state->state : 'failed', 'error' => $state->error, 'turnId' => $state->turnId, 'entries' => [],
            'providerError' => $state->providerError ?? null,
        ]);
    });
}

/** @return list<string> */
function tick_pi_message_keys(): array
{
    return collect(Http::recorded())
        ->map(fn (array $pair): Request => $pair[0])
        ->filter(fn (Request $request): bool => str_ends_with($request->url(), '/messages'))
        ->map(fn (Request $request): string => (string) $request['key'])
        ->values()
        ->all();
}

function tick_pi_implementer(): Task
{
    $group = tick_group();
    $other = new FakeAgentDriver('example');
    $other->observation = new AgentObservation(AgentThreadState::Idle);
    AgentThread::query()->whereKey($group->reviewer_agent_thread_id)->update(['driver' => 'example']);
    app()->instance(AgentDriverRegistry::class, new AgentDriverRegistry([$other, app(PiDriver::class)]));
    $task = $group->tasks->sole();
    $thread = AgentThread::query()->findOrFail($task->implementer_agent_thread_id);
    $thread->update(['driver' => 'pi']);
    $thread->node?->update([
        'settings' => ['pi' => ['token' => 'pi-node-token-with-more-than-32-characters', 'url' => 'http://10.44.0.212:3774']],
    ]);
    app(TaskExtensionState::class)->enable();
    app()->instance(TaskWorkspaceDiffReader::class, new NullTaskWorkspaceDiffReader);
    app()->instance(AgentSnapshotReader::class, new class implements AgentSnapshotReader
    {
        public function snapshot(Node $node, string $threadId): ?array
        {
            return ['thread' => ['session' => ['status' => 'idle']]];
        }
    });

    return $task;
}

function tick_dispatcher(): AgentCommandDispatcher
{
    return new class implements AgentCommandDispatcher
    {
        /** @var list<array<string, mixed>> */
        public array $commands = [];

        /** @var array<string, string> */
        public array $turnIds = [];

        public function dispatch(Node $node, array $command): array
        {
            $this->commands[] = $command;
            if (in_array($command['type'] ?? null, ['create', 'send'], true) && ! ($command['deferOpeningTurn'] ?? false)) {
                $this->turnIds[(string) ($command['threadId'] ?? '')] = (string) ($command['commandId'] ?? 'accepted-'.count($this->commands));
            }

            return ['sequence' => count($this->commands), 'thread_id' => (string) ($command['threadId'] ?? '')];
        }
    };
}

/** @return array{0: Task, 1: Task, 2: TaskComment, 3: TaskQuestion} */
function tick_held_relay(): array
{
    $group = tick_group();
    $task = $group->tasks->sole();
    AgentThread::query()->where('task_group_id', $group->id)->where('role', 'reviewer')->update(['task_id' => $task->id]);
    $resolution = TaskComment::query()->create([
        'task_id' => $task->id, 'task_group_id' => $group->id, 'type' => 'resolution',
        'body' => 'Use the public mirror.', 'author' => 'operator', 'posted_at' => now(),
    ]);
    test_update_task($task, [
        'direction_relay_comment_id' => $resolution->id,
        'assistance_requested' => false, 'assistance_kind' => null, 'assistance_reason' => null,
    ]);
    test_update_task($group, ['assistance_requested' => false, 'assistance_kind' => null, 'assistance_reason' => null]);
    $question = TaskQuestion::query()->create([
        'task_id' => $group->id, 'subtask_id' => $task->id, 'attempt' => 1, 'asked_by' => QuestionAsker::Reviewer,
        'question' => 'Which mirror?', 'status' => QuestionStatus::Escalated, 'asked_at' => now(), 'escalated_at' => now(),
        'resolution_comment_id' => $resolution->id,
    ]);

    return [$group->fresh(['project', 'tasks', 'taskable']) ?? $group, $task->fresh() ?? $task, $resolution, $question];
}

function tick_relay_runtime(FakeTaskTurnReceipts $receipts, AgentCommandDispatcher $dispatcher, object $state): void
{
    app(TaskExtensionState::class)->enable();
    app()->instance(TaskWorkspaceDiffReader::class, new NullTaskWorkspaceDiffReader);
    app()->instance(TaskTurnReceipts::class, $receipts);
    app()->instance(AgentCommandDispatcher::class, $dispatcher);
    $reader = new class($state) implements AgentSnapshotReader
    {
        public function __construct(private object $state) {}

        public function snapshot(Node $node, string $threadId): ?array
        {
            if ($threadId === 'implementer-thread') {
                $turnId = property_exists($this->state, 'turnId') ? $this->state->turnId : tick_observed_turn($threadId);
                $messageId = isset($this->state->messageId) && is_string($this->state->messageId) ? $this->state->messageId : null;
                $thread = ['session' => ['status' => $this->state->implementer]];
                if ($turnId !== '') {
                    $thread['latestTurn'] = ['id' => $turnId, 'state' => 'done'];
                }
                if ($messageId !== null) {
                    $thread['messages'] = [['id' => $messageId, 'role' => 'user', 'text' => 'Accepted.', 'createdAt' => '2026-10-08T00:00:00Z']];
                }

                return ['thread' => $thread];
            }

            $reviewer = isset($this->state->reviewer) && is_string($this->state->reviewer) ? $this->state->reviewer : 'done';
            if ($threadId === 'reviewer-thread' && AgentThread::query()->where('external_id', $threadId)->whereNull('task_id')->exists()) {
                $reviewer = $this->state->sharedReviewer ?? 'done';
            }

            return ['thread' => [
                'session' => ['status' => $reviewer],
                'latestTurn' => ['id' => $this->state->reviewerTurnId ?? tick_observed_turn($threadId), 'state' => 'done'],
            ]];
        }
    };
    app()->instance(AgentSnapshotReader::class, $reader);
    app()->instance(AgentDriverRegistry::class, test_snapshot_registry(dispatcher: $dispatcher, reader: $reader));
}

/**
 * A consult whose reviewer turn id becomes the command id of the accepted send.
 *
 * @return array{0: Task, 1: Task, 2: FakeTaskTurnReceipts, 3: AgentCommandDispatcher, 4: object}
 */
function tick_consult_exchange(bool $loseResponse, ?array $receiptContents = null): array
{
    $group = tick_group();
    $task = $group->tasks->sole();
    $answer = 'Yes. The contract allows the intl extension.';
    $receipts = new FakeTaskTurnReceipts($receiptContents ?? [
        FakeTaskTurnReceipts::contents('blocked', 'The intl extension is missing.', 'May I install php8.5-intl?'),
        FakeTaskTurnReceipts::contents('answered', $answer, cause: 'missed_contract'),
        null,
    ]);
    $state = (object) [
        'implementer' => 'done',
        'reviewer' => 'done',
        'turnId' => '',
        'messageId' => null,
        'reviewerTurnId' => '',
        'reviewerError' => null,
        'loseResponse' => $loseResponse,
        'unavailable' => false,
    ];
    $dispatcher = new class($state) implements AgentCommandDispatcher
    {
        /** @var list<array<string, mixed>> */
        public array $commands = [];

        public function __construct(private object $state) {}

        public function dispatch(Node $node, array $command): array
        {
            $this->commands[] = $command;
            if (($command['type'] ?? null) === 'send' && ($command['threadId'] ?? null) !== 'implementer-thread') {
                $this->state->reviewerTurnId = (string) ($command['commandId'] ?? '');
                if ($this->state->loseResponse === true) {
                    $this->state->loseResponse = false;

                    throw new AgentDriverException('response lost');
                }
            }
            if (($command['type'] ?? null) === 'send' && ($command['threadId'] ?? null) === 'implementer-thread') {
                $this->state->turnId = (string) ($command['commandId'] ?? '');
                $this->state->messageId = (string) ($command['commandId'] ?? '');
                if (($this->state->loseImplementerResponse ?? false) === true) {
                    $this->state->loseImplementerResponse = false;

                    throw new AgentDriverException('implementer response lost');
                }
            }

            return ['sequence' => count($this->commands), 'thread_id' => (string) ($command['threadId'] ?? '')];
        }
    };
    app(TaskExtensionState::class)->enable();
    app()->instance(TaskWorkspaceDiffReader::class, new NullTaskWorkspaceDiffReader);
    app()->instance(TaskTurnReceipts::class, $receipts);
    app()->instance(AgentCommandDispatcher::class, $dispatcher);
    $reader = new class($state) implements AgentSnapshotReader
    {
        public function __construct(private object $state) {}

        public function snapshot(Node $node, string $threadId): ?array
        {
            if ($this->state->unavailable === true) {
                return null;
            }
            if ($threadId === 'implementer-thread') {
                $turnId = is_string($this->state->turnId) ? $this->state->turnId : '';
                $thread = ['session' => ['status' => $this->state->implementer]];
                if ($turnId !== '') {
                    $thread['latestTurn'] = ['id' => $turnId, 'state' => 'done'];
                }
                if (is_string($this->state->messageId) && $this->state->messageId !== '') {
                    $thread['messages'] = [['id' => $this->state->messageId, 'role' => 'user', 'text' => 'Accepted.', 'createdAt' => '2026-10-08T00:00:00Z']];
                }

                return ['thread' => $thread];
            }
            $failed = $this->state->reviewer === 'error';
            $thread = [
                'session' => ['status' => $this->state->reviewer, 'lastError' => $this->state->reviewerError],
                'error' => $this->state->reviewerError,
            ];
            if (is_string($this->state->reviewerTurnId) && $this->state->reviewerTurnId !== '') {
                $thread['latestTurn'] = [
                    'id' => $this->state->reviewerTurnId,
                    'state' => $failed ? 'error' : 'done',
                    'error' => $this->state->reviewerError,
                ];
                $thread['messages'] = [['id' => $this->state->reviewerTurnId, 'role' => 'user', 'text' => 'Consult.', 'createdAt' => '2026-10-08T00:00:00Z']];
            }

            return ['thread' => $thread];
        }
    };
    app()->instance(AgentSnapshotReader::class, $reader);
    app()->instance(AgentDriverRegistry::class, test_snapshot_registry(dispatcher: $dispatcher, reader: $reader));

    return [$group, $task, $receipts, $dispatcher, $state];
}

/** @return list<array<string, mixed>> */
function tick_reviewer_starts(AgentCommandDispatcher $dispatcher): array
{
    return array_values(array_filter(
        $dispatcher->commands,
        static fn (array $command): bool => ($command['type'] ?? null) === 'send' && ($command['threadId'] ?? null) !== 'implementer-thread',
    ));
}

/** A settling group whose pull request the tick reads through the faked GitHub App. */
function tick_settling_group(): Task
{
    $group = tick_group();
    $group->project->update(['repository_url' => 'https://github.com/acme/orbit.git']);
    $group->update(['status' => TaskGroupStatus::Settling, 'pr_url' => 'https://github.com/acme/orbit/pull/42']);
    $group->tasks()->update(['status' => TaskStatus::Completed]);
    app(TaskExtensionState::class)->enable();
    GitHubTestSupport::storeApp();

    return $group;
}

/** A subtask an operator appended. Its fixup identity stays null, so the cap ignores it. */
function tick_appended_subtask(Task $group): Task
{
    return Task::query()->create([
        'parent_id' => $group->id,
        'position' => ((int) $group->tasks()->max('position')) + 1,
        'title' => 'Address the finding',
        'brief' => 'Fix the review.',
        'status' => TaskStatus::Todo,
        'deliverables' => [[
            'id' => 'composer-check', 'type' => 'command', 'description' => 'Run composer check',
            'command' => 'composer check', 'directory' => '.',
        ]],
    ]);
}

/** One earlier fixup. Every status counts toward the cap. An approved commit, when given, is what the fixup pushed. */
function tick_spent_fixup(Task $group, string $problem, TaskStatus $status = TaskStatus::Completed, ?string $headSha = null, ?string $commit = null): Task
{
    $task = Task::query()->create([
        'parent_id' => $group->id,
        'position' => ((int) $group->tasks()->max('position')) + 1,
        'title' => 'Earlier fix',
        'brief' => 'Already tried.',
        'status' => $status,
        'fixup_problem' => $problem,
        'fixup_head_sha' => $headSha,
        'deliverables' => [[
            'id' => 'composer-check', 'type' => 'command', 'description' => 'Run composer check',
            'command' => 'composer check', 'directory' => '.',
        ]],
    ]);
    if ($commit !== null) {
        TaskComment::query()->create([
            'task_group_id' => $group->id, 'task_id' => $task->id, 'type' => 'approved', 'body' => 'Approved.',
            'author' => 'reviewer', 'review_attempt' => 1, 'commit_sha' => $commit, 'posted_at' => now(),
        ]);
    }

    return $task;
}

/** @param  array<string, mixed>  $overrides */
function tick_open_pull(array $overrides = []): array
{
    return [
        'merged' => false,
        'state' => 'open',
        'mergeable' => true,
        'mergeable_state' => 'clean',
        'head' => ['sha' => 'abc123'],
        'base' => ['ref' => 'main'],
        ...$overrides,
    ];
}

/**
 * @param  list<array<string, mixed>>  $pulls
 * @param  array<string, list<array<string, mixed>>>  $checks  check runs keyed by head sha
 */
function tick_watch_pulls(array $pulls, array $checks = [], int $updateStatus = 422, string $updateMessage = 'merge conflict between base and head'): void
{
    $sequence = Http::sequence();
    foreach ($pulls as $body) {
        $sequence->push($body);
    }

    $fake = [
        'https://api.github.com/repos/acme/orbit/installation' => static fn () => Http::response(['id' => 9]),
        'https://api.github.com/app/installations/9/access_tokens' => static fn (Request $request) => Http::response(['token' => 'ghs_watch', 'permissions' => $request->data()['permissions']], 201),
        'https://api.github.com/repos/acme/orbit/pulls/42' => $sequence,
        'https://api.github.com/repos/acme/orbit/pulls/42/update-branch' => Http::response(['message' => $updateMessage], $updateStatus),
    ];
    foreach ($checks as $sha => $runs) {
        $fake['https://api.github.com/repos/acme/orbit/commits/'.$sha.'/check-runs*'] = Http::response(['total_count' => count($runs), 'check_runs' => $runs]);
    }
    $fake['https://api.github.com/repos/acme/orbit/commits/*/check-suites*'] = Http::response(['total_count' => 0, 'check_suites' => []]);
    Http::preventStrayRequests();
    Http::fake($fake);
}

/** @return object{spawned: list<int>, fetched: list<string>, events: list<string>, turnFetches: int, fastForwards: int, missingRefOk: bool} */
function tick_running_agents(bool $fetchFails = false, bool $fastForwardFails = false): object
{
    $agents = new class($fetchFails, $fastForwardFails) implements AgentSpawner, TaskBaseBranchFetcher
    {
        public int $turnFetches = 0;

        public int $fastForwards = 0;

        public bool $missingRefOk = false;

        /** @var list<int> */
        public array $spawned = [];

        /** @var array<string, string> */
        public array $spawnedTurns = [];

        /** @var list<string> */
        public array $fetched = [];

        /** @var list<string> */
        public array $events = [];

        public function __construct(private bool $fetchFails, private bool $fastForwardFails = false) {}

        public function reserveReviewer(Task $task): ?int
        {

            return null;
        }

        public function spawnReviewer(Task $task): ?int
        {
            return null;
        }

        public function spawnImplementer(Task $task): ?int
        {
            $this->spawned[] = $task->id;
            $this->events[] = 'spawn';

            $thread = AgentThread::query()->find($task->implementer_agent_thread_id)
                ?? test_agent_thread($task->parent, 'fixup-implementer-'.$task->id, $task);
            app(TaskWorkspaceLease::class)->turn($task->parent, TaskThreadRole::Implementer, 'thread:'.$thread->id, $thread->id);
            app(TaskWorkspaceLease::class)->dispatch($thread);
            $this->spawnedTurns[$thread->external_id] = 'spawned-implementer-'.$task->id;

            return $thread->id;
        }

        public function requestReview(Task $task): void {}

        public function fetch(Task $group, string $base): void
        {
            $this->fetched[] = $base;
            $this->events[] = 'fetch';
            if ($this->fetchFails) {
                throw new TaskPullRequestException('The base branch could not be fetched.');
            }
        }

        public function fastForward(Task $group, bool $missingRefOk = false): void
        {
            $this->fastForwards++;
            $this->missingRefOk = $missingRefOk;
            $this->events[] = 'fast-forward';
            if ($this->fastForwardFails) {
                throw new TaskPullRequestException('The task branch could not be fetched.');
            }
        }

        public function resetToDefault(Task $group): string
        {
            return str_repeat('c', 40);
        }

        public function mergeBase(Task $group): string
        {
            return str_repeat('c', 40);
        }

        public function moveTo(Task $group, string $sha): void {}

        public function fetchForTurn(Task $group): void
        {
            $this->turnFetches++;
            $this->events[] = 'turn-fetch';
            if ($this->fetchFails) {
                throw new TaskPullRequestException('The base branch could not be fetched.');
            }
        }
    };
    app()->instance(AgentSpawner::class, $agents);
    app()->instance(TaskBaseBranchFetcher::class, $agents);
    // The previous review has settled. A generic running/null snapshot in these
    // implementer fixtures must not describe the group's old shared reviewer.
    app()->instance(AgentDriverRegistry::class, test_snapshot_registry(reader: new class($agents) implements AgentSnapshotReader
    {
        public function __construct(private object $agents) {}

        public function snapshot(Node $node, string $threadId): ?array
        {
            if (isset($this->agents->spawnedTurns[$threadId])) {
                $snapshot = tick_checked_thread('done', $threadId);
                $snapshot['thread']['latestTurn'] = ['id' => $this->agents->spawnedTurns[$threadId], 'state' => 'completed'];

                return $snapshot;
            }
            $thread = AgentThread::query()->where('external_id', $threadId)->first();
            if ($thread?->role === 'reviewer' && ! Task::query()->where('parent_id', $thread->task_group_id)->where('status', TaskStatus::Reviewing)->exists()) {
                return tick_checked_thread('done', $threadId);
            }

            return app(AgentSnapshotReader::class)->snapshot($node, $threadId);
        }
    }));

    return $agents;
}

/** @return list<string> */
function tick_assistance_reasons(): array
{
    return Activity::query()->where('log_name', 'tasks')->whereIn('description', ['assistance requested', 'assistance changed'])
        ->whereIn('subject_id', Task::topLevel()->select('id'))
        ->orderBy('id')->get()->map(static fn (Activity $activity): mixed => $activity->properties?->get('reason'))->all();
}

/** @return array{review_workspace_head: string, review_workspace_tree: string} */
function tick_review_baseline(): array
{
    return [
        'review_workspace_head' => str_repeat('a', 40),
        'review_workspace_tree' => str_repeat('b', 40),
    ];
}

function tick_review(array $receipts, bool $onBranch = true, bool $last = false): array
{
    $group = tick_group();
    $task = $group->tasks->sole();
    if (! $last) {
        Task::query()->create(['parent_id' => $group->id, 'position' => 2, 'title' => 'Routes', 'brief' => 'Add the routes.', 'status' => TaskStatus::Todo]);
    }
    $group->update(['status' => TaskGroupStatus::Reviewing]);
    $task->update(['status' => TaskStatus::Reviewing, 'review_notified_attempt' => $task->review_attempt, 'review_notified_turn_id' => 'handoff-turn', ...tick_review_baseline()]);
    app(TaskExtensionState::class)->enable();
    app()->instance(AgentCommandDispatcher::class, tick_dispatcher());
    app()->instance(AgentSnapshotReader::class, new class implements AgentSnapshotReader
    {
        public function snapshot(Node $node, string $threadId): ?array
        {
            return ['thread' => ['session' => ['status' => 'done'], 'latestTurn' => ['id' => tick_observed_turn($threadId, 'review-turn'), 'state' => 'completed']]];
        }
    });
    tick_workspace(branch: $onBranch ? 'task-'.$group->id : 'main');
    $receipts = new FakeTaskTurnReceipts($receipts);
    app()->instance(TaskTurnReceipts::class, $receipts);
    $signer = new class implements TaskWorkspaceSigner
    {
        /** @var list<string> */
        public array $messages = [];

        public bool $fails = false;

        public function commit(Instance $instance, string $message, bool $allowEmpty = false): ?string
        {
            $this->messages[] = $message;
            if ($this->fails) {
                return null;
            }
            $sha = str_repeat('c', 40);
            $checks = app(TaskCheckRunner::class);
            if ($checks instanceof FakeTaskCheckRunner) {
                $checks->head = $sha;
            }

            return $sha;
        }
    };
    app()->instance(TaskWorkspaceSigner::class, $signer);
    $publisher = new class implements TaskPullRequestPublisher
    {
        /** @var list<int> */
        public array $pushes = [];

        /** @var list<string> */
        public array $bodies = [];

        public int $pushFailures = 0;

        public function publish(Task $group, string $body, string $commit): string
        {
            $this->bodies[] = $body;

            return 'https://github.com/acme/orbit/pull/42';
        }

        public function pushHandoff(Task $group, string $commit): void
        {
            throw new LogicException('This fake pushes no handoff.');
        }

        public function deleteHandoffBranch(Task $group): void {}

        public function push(Task $group, string $commit): void
        {
            $this->pushes[] = $group->id;
            if ($this->pushFailures > 0) {
                $this->pushFailures--;

                throw new TaskPullRequestException('The task branch could not be pushed.');
            }
        }
    };
    app()->instance(TaskPullRequestPublisher::class, $publisher);

    return [$group->fresh(['project', 'tasks', 'taskable']) ?? $group, $task, $receipts, $signer, $publisher];
}

/** @param list<string> $changes */
function tick_final_approval(array $changes = ['Tasks store their records.']): string
{
    return json_encode([
        'outcome' => 'approved',
        'summary' => 'Checked the feature.',
        'pull_request' => ['summary' => 'Adds tick routing.', 'changes' => $changes, 'breaking' => []],
        'nonce' => bin2hex(random_bytes(8)),
    ], JSON_THROW_ON_ERROR);
}

/** @param list<list<string>> $missing */
function tick_publishing(array $missing = [[]], int $failures = 0): object
{
    $coverage = new class($missing) implements TaskBriefCoverage
    {
        public int $calls = 0;

        public ?int $approvalCommentId = null;

        /** @var list<string>|null */
        public ?array $approvalChanges = null;

        /** @param list<list<string>> $missing */
        public function __construct(private array $missing) {}

        public function missing(Task $group, TaskTurnPullRequest $pullRequest, ?int $approvalCommentId = null, ?array $approvalChanges = null): array
        {
            $this->calls++;
            $this->approvalCommentId = $approvalCommentId;
            $this->approvalChanges = $approvalChanges;

            return array_shift($this->missing) ?? [];
        }
    };
    $publisher = new class($failures) implements TaskPullRequestPublisher
    {
        /** @var list<string> */
        public array $bodies = [];

        /** @var list<int> */
        public array $pushes = [];

        public function __construct(private int $failures) {}

        public function publish(Task $group, string $body, string $commit): string
        {
            $this->bodies[] = $body;
            if ($this->failures-- > 0) {
                throw new TaskPullRequestException('The task branch could not be pushed.');
            }

            return 'https://github.com/acme/orbit/pull/42';
        }

        public function pushHandoff(Task $group, string $commit): void
        {
            throw new LogicException('This fake pushes no handoff.');
        }

        public function deleteHandoffBranch(Task $group): void {}

        public function push(Task $group, string $commit): void
        {
            $this->pushes[] = $group->id;
        }
    };
    app()->instance(TaskBriefCoverage::class, $coverage);
    app()->instance(TaskPullRequestPublisher::class, $publisher);
    mock(TaskSettleMetricsCollector::class)->shouldReceive('collect')->andReturn(new TaskSettleMetrics(tokens: 40, lineDiff: 12, durationMs: 1500));

    return (object) ['coverage' => $coverage, 'publisher' => $publisher];
}

/**
 * A running task whose idle implementer ended its turn with ready_for_review, with the given check readings.
 *
 * @param  list<TaskCheckReading>  $readings
 * @return array{Task, Task, FakeTaskCheckRunner}
 */
function tick_checking(array $readings): array
{
    $group = tick_group();
    app(TaskExtensionState::class)->enable();
    app()->instance(AgentCommandDispatcher::class, tick_dispatcher());
    app()->instance(AgentSnapshotReader::class, new class implements AgentSnapshotReader
    {
        public function snapshot(Node $node, string $threadId): ?array
        {
            return tick_checked_thread('done', $threadId);
        }
    });
    $checks = new FakeTaskCheckRunner($readings);
    app()->instance(TaskCheckRunner::class, $checks);

    return [$group, $group->tasks->sole(), $checks];
}

/**
 * Reports each thread in the given session status. Each thread's latest turn is "{thread}-turn".
 *
 * @param  array<string, string>  $states
 */
function tick_thread_states(array $states): AgentSnapshotReader
{
    return new class($states) implements AgentSnapshotReader
    {
        /** @param array<string, string> $states */
        public function __construct(public array $states) {}

        public function snapshot(Node $node, string $threadId): ?array
        {
            return ['thread' => [
                'session' => ['status' => $this->states[$threadId] ?? 'idle'],
                'latestTurn' => ['id' => tick_observed_turn($threadId), 'state' => 'completed'],
            ]];
        }
    };
}

/**
 * A running subtask with deliverables whose implementer hands off with the given confirmations, and a check that
 * passes with the given evidence.
 *
 * @param  list<array<string, string|bool>>  $deliverables
 * @param  array<string, string>  $confirmations
 * @param  array<string, mixed>|null  $evidence
 * @param  list<TaskCheckReading>|null  $readings  the check readings; null passes once with the evidence
 * @return array{Task, Task, FakeTaskCheckRunner, FakeTaskTurnReceipts}
 */
function tick_deliverables(array $deliverables, array $confirmations, ?array $evidence, ?array $readings = null, ?array $receipts = null): array
{
    [$group, $task, $checks] = tick_checking($readings ?? [FakeTaskCheckRunner::passed($evidence)]);
    $task->update(['deliverables' => $deliverables, 'subtask_start_commit' => str_repeat('5', 40)]);
    $fake = new FakeTaskTurnReceipts($receipts ?? [FakeTaskTurnReceipts::contents('ready_for_review', 'Done.', null, $confirmations)]);
    app()->instance(TaskTurnReceipts::class, $fake);

    return [$group, $task->fresh() ?? $task, $checks, $fake];
}

/** @return list<array<string, string>> */
function tick_all_deliverables(): array
{
    return [
        ['id' => 'reference-page', 'type' => 'file', 'description' => 'Document the export', 'path' => 'docs/reference/*.md', 'change' => 'modified'],
        ['id' => 'export-test', 'type' => 'command', 'description' => 'Test the export', 'command' => 'vendor/bin/pest tests/Feature/ExportTest.php', 'directory' => 'apps/gateway'],
        ['id' => 'web-tests', 'type' => 'command', 'description' => 'The web tests pass', 'command' => 'bun test', 'directory' => 'apps/web'],
        ['id' => 'error-copy', 'type' => 'review', 'description' => 'Errors name the subtask'],
    ];
}

/** @return array<string, string> */
function tick_all_confirmations(): array
{
    return ['reference-page' => 'Export section', 'export-test' => 'ExportTest', 'web-tests' => 'bun test passes', 'error-copy' => 'Named in each error'];
}

/**
 * Evidence where every deliverable of tick_all_deliverables() passes, changed by the given overrides.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function tick_evidence(array $overrides = []): array
{
    return [...[
        'start' => str_repeat('5', 40),
        'diff' => [
            ['status' => 'M', 'path' => 'docs/reference/tasks.md'],
            ['status' => 'A', 'path' => 'apps/gateway/tests/Feature/ExportTest.php'],
        ],
        'commands' => [
            'export-test' => ['exit_code' => 0, 'output' => ''],
            'web-tests' => ['exit_code' => 0, 'output' => "12 pass\n"],
        ],
    ], ...$overrides];
}

/** The implementer's reminder after two ticks: the handoff check starts, then passes. */
function tick_deliverable_reminder(): string
{
    app(TaskScheduler::class)->tick();
    app(TaskScheduler::class)->tick();

    return app(AgentCommandDispatcher::class)->commands[0]['message']['text'] ?? '';
}

/**
 * A baseline that failed on `$failed`, and GitHub's view of main: the `Required checks` result on the failed
 * commit and on the tip, and how the tip relates to the failed commit.
 *
 * @return array{Task, Task, FakeTaskCheckRunner}
 */
function tick_red_main_baseline(string $slug, string $failed, string $tip, string $failedConclusion, string $tipConclusion, string $status = 'ahead'): array
{
    app(TaskExtensionState::class)->enable();
    $group = tick_baseline_group($slug, 'composer check', [], '10.51.0.10');
    tick_running_agents();
    $runner = new FakeTaskCheckRunner([TaskCheckReading::finished(1, str_repeat('a', 40), str_repeat('b', 40), [], "main was red\n")]);
    app()->instance(TaskCheckRunner::class, $runner);
    app(TaskScheduler::class)->tick();
    app(TaskScheduler::class)->tick();
    $task = $group->tasks()->sole();
    expect($task->assistance_requested)->toBeTrue();
    // The fake runner starts every check on aaaa…; the failed baseline ran on the red main commit.
    TaskCheck::query()->where('task_id', $task->id)->update(['head_before' => $failed]);

    $group->project->update(['repository_url' => "https://github.com/acme/{$slug}.git"]);
    GitHubTestSupport::storeApp();
    $runs = static fn (string $sha, string $conclusion): array => ['total_count' => 1, 'check_runs' => [[
        'id' => 7, 'name' => 'Required checks', 'head_sha' => $sha,
        'status' => $conclusion === 'pending' ? 'in_progress' : 'completed', 'conclusion' => $conclusion === 'pending' ? null : $conclusion,
        'html_url' => "https://github.com/acme/{$slug}/runs/7", 'app' => ['slug' => 'github-actions'],
    ]]];
    Http::preventStrayRequests();
    Http::fake([
        "https://api.github.com/repos/acme/{$slug}/installation" => Http::response(['id' => 9]),
        'https://api.github.com/app/installations/9/access_tokens' => Http::response(['token' => 'ghs_read'], 201),
        "https://api.github.com/repos/acme/{$slug}/commits?*" => Http::response([['sha' => $tip, 'parents' => [['sha' => $failed]]]]),
        "https://api.github.com/repos/acme/{$slug}/compare/{$failed}...{$tip}*" => Http::response([
            'status' => $status, 'base_commit' => ['sha' => $failed], 'merge_base_commit' => ['sha' => $status === 'ahead' ? $failed : str_repeat('d', 40)],
        ]),
        "https://api.github.com/repos/acme/{$slug}/commits/{$failed}/check-runs*" => Http::response($runs($failed, $failedConclusion)),
        "https://api.github.com/repos/acme/{$slug}/commits/{$tip}/check-runs*" => Http::response($runs($tip, $tipConclusion)),
        "https://api.github.com/repos/acme/{$slug}/commits/*/check-suites*" => Http::response(['total_count' => 0, 'check_suites' => []]),
    ]);

    return [$group, $task, $runner];
}

/**
 * A fresh running task whose baseline has not started.
 *
 * @param  list<array{name: string, command: string, timeout_seconds: int, position: int}>  $steps
 */
function tick_baseline_group(string $slug, ?string $taskCheck, array $steps, string $ip): Task
{
    $project = Project::query()->create([
        'name' => $slug,
        'slug' => $slug,
        'repository_url' => "git@example.test:{$slug}.git",
        'default_branch' => 'main',
        'task_check' => $taskCheck,
    ]);
    $node = Node::query()->create([
        'name' => $slug.'-node',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => $ip,
        'wireguard_ip' => $ip,
    ]);
    $group = Task::topLevel()->create([
        'implementer_agent_driver' => 'pi', 'reviewer_agent_driver' => 'pi',
        'project_id' => $project->id,
        'title' => $slug,
        'brief' => 'Baseline setup only.',
        'status' => TaskGroupStatus::Running,
    ]);
    $instance = Instance::query()->create([
        'task_workspace_owner_id' => $group->id,
        'project_id' => $project->id,
        'node_id' => $node->id,
        'name' => $slug,
        'checkout_path' => '/tmp/tasks-'.$slug,
        'starting_commit' => str_repeat('a', 40),
        'status' => 'source_resolved',
    ]);
    $group->taskable()->associate($instance);
    $group->save();
    Task::query()->create([
        'parent_id' => $group->id,
        'position' => 1,
        'title' => 'First',
        'brief' => 'First subtask',
        'status' => TaskStatus::Running,
    ]);
    foreach ($steps as $step) {
        ProjectLifecycleStep::query()->create([
            'project_id' => $project->id,
            'phase' => 'setup',
            'name' => $step['name'],
            'command' => $step['command'],
            'timeout_seconds' => $step['timeout_seconds'],
            'position' => $step['position'],
        ]);
    }

    return $group->fresh(['project', 'tasks', 'taskable']) ?? $group;
}
