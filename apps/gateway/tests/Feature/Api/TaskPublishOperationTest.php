<?php

declare(strict_types=1);

use App\Domain\Operations\OperationRunner;
use App\Domain\Operations\OperationStatus;
use App\Domain\Operations\OperationStore;
use App\Domain\Tasks\TaskCheckRunner;
use App\Domain\Tasks\TaskExtensionState;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\TaskVms\TaskVmPublishState;
use App\Domain\TaskVms\TaskVmState;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Processes\ProcessRunner;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Infrastructure\Tasks\GitHubTaskPullRequestPublisher;
use App\Jobs\TaskVms\PublishTaskVmCommit;
use App\Jobs\TaskVms\ResumeTaskVm;
use App\Models\AgentThread;
use App\Models\Operation;
use App\Models\Task;
use App\Models\TaskCheck;
use App\Models\TaskVm;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Process\Process;
use Tests\Feature\GitHub\GitHubTestSupport;
use Tests\Support\GitRemoteRedirect;
use Tests\Support\StreamingLocalShellSshExecutor;
use Tests\Support\TaskOperationFixture;
use Tests\Support\TestOrbitHome;

beforeEach(function (): void {
    enable_operation_queue();
    TaskOperationFixture::bindDefaults();
    app(TaskExtensionState::class)->enable();
    $this->travelTo(now()->startOfSecond());
});

/** @return array{Task, TaskCheck} */
function publish_operation_group(): array
{
    $group = TaskOperationFixture::create();
    Task::topLevel()->whereKey($group->id)->update(['orchestration' => 'external']);
    $group->refresh();
    $group->tasks()->update(['implementer_agent_thread_id' => null]);
    AgentThread::query()->where('task_group_id', $group->id)->delete();
    $group->project->update(['repository_url' => 'https://github.com/acme/shop.git']);
    test()->markAsGateway($group->taskable->node);
    test()->withServerVariables(['REMOTE_ADDR' => $group->taskable->node->wireguard_ip]);
    $check = $group->tasks()->firstOrFail()->checks()->create([
        'kind' => 'handoff', 'status' => 'passed', 'commit_sha' => str_repeat('a', 40),
        'pid' => 0, 'process_started' => '', 'head_before' => str_repeat('a', 40), 'tree_before' => str_repeat('b', 40), 'started_at' => now(),
    ]);

    task_checked_operation($check);

    return [$group, $check];
}

function publish_operation_start(Task $group, TaskCheck $check, array $extra = [], ?string $key = null): Operation
{
    $response = test()->postJson('/api/v1/task-groups/'.$group->id.'/publish', ['check' => $check->id, ...$extra], $key === null ? [] : ['Idempotency-Key' => $key])->assertAccepted()->assertJsonPath('data.kind', 'tasks.publish');

    return Operation::query()->findOrFail($response->json('data.id'));
}

function publish_operation_read(Operation $operation): void
{
    test()->travel(10)->seconds();
    app(OperationRunner::class)->read($operation->id);
}

function publish_operation_github(array $existing = [], int $writeStatus = 201): void
{
    GitHubTestSupport::storeApp();
    Http::preventStrayRequests();
    Http::fake([
        'https://api.github.com/repos/acme/shop/installation' => Http::response(['id' => 9]),
        'https://api.github.com/app/installations/9/access_tokens' => Http::response(['token' => 'ghs_publish'], 201),
        'https://api.github.com/repos/acme/shop/pulls?*' => Http::response($existing),
        'https://api.github.com/repos/acme/shop/pulls*' => Http::response(['number' => 12, 'html_url' => 'https://github.com/acme/shop/pull/12'], $writeStatus),
    ]);
}

it('queues the approval push once and reads success without polling the VM', function (): void {
    [$group, $check] = publish_operation_group();
    Queue::fake([PublishTaskVmCommit::class]);
    $operation = publish_operation_start($group, $check);
    app(OperationRunner::class)->run($operation->id);
    expect($operation->refresh()->status)->toBe(OperationStatus::Waiting);
    expect($operation->current_step)->toBe('push');
    Queue::assertPushed(PublishTaskVmCommit::class, fn ($job): bool => $job->commit === $check->commit_sha && $job->branch === 'task-'.$group->id);
    publish_operation_read($operation);
    expect($operation->refresh()->status)->toBe(OperationStatus::Waiting);
    app(TaskCheckRunner::class)->head = str_repeat('c', 40);
    TaskVm::forGroup($group)->update(['publish_state' => TaskVmPublishState::Pushed]);

    publish_operation_read($operation);

    expect($operation->refresh()->status)->toBe(OperationStatus::Succeeded);
    expect($operation->result)->toBe(['branch' => 'task-'.$group->id, 'commit' => $check->commit_sha, 'pull_request_number' => null, 'pull_request_url' => null]);
    expect($operation->attempts)->toBe(1);
    expect($group->refresh()->status)->toBe(TaskGroupStatus::Running);
    expect($group->settled_at)->toBeNull();
    Queue::assertPushed(PublishTaskVmCommit::class, 1);
});

it('recovers a lost push outcome before completing publication or a waiting cancel', function (bool $cancel): void {
    [$group, $check] = publish_operation_group();
    publish_operation_github();
    $runner = new class(app(ProcessRunner::class)) implements ProcessRunner
    {
        public int $pushes = 0;

        public function __construct(private ProcessRunner $runner) {}

        public function run(ProcessInvocation $invocation): CommandResult
        {
            if (in_array('push', $invocation->arguments, true)) {
                $this->pushes++;
            }

            return $this->runner->run($invocation);
        }
    };
    app()->instance(ProcessRunner::class, $runner);
    $this->mock(SshKeyProvider::class)->shouldReceive('privateKeyPath')->andReturn('/keys/private');
    $this->mock(KnownHostsStore::class)->shouldReceive('path')->andReturn('/keys/known_hosts');
    $transport = new StreamingLocalShellSshExecutor;
    app()->instance(SshExecutor::class, $transport);
    $root = TestOrbitHome::scratch('publish-lost-outcome');
    mkdir($root, 0700, true);
    $git = static fn (string $directory, string ...$arguments): string => trim((new Process(['git', '-C', $directory, '-c', 'user.name=t', '-c', 'user.email=t@t', ...$arguments]))->mustRun()->getOutput());
    (new Process(['git', 'init', '--quiet', '--bare', '-b', 'main', $root.'/origin.git']))->mustRun();
    (new Process(['git', 'init', '--quiet', '-b', 'main', $root.'/vm']))->mustRun();
    $git($root.'/vm', 'commit', '--quiet', '--allow-empty', '-m', 'Start');
    $git($root.'/vm', 'push', '--quiet', $root.'/origin.git', 'main');
    $git($root.'/vm', 'commit', '--quiet', '--allow-empty', '-m', 'Checked');
    $commit = $git($root.'/vm', 'rev-parse', 'HEAD');
    $check->update(['commit_sha' => $commit]);
    $owner = Operation::query()->where('kind', 'tasks.check')->sole();
    $owner->update(['result' => [...$owner->result, 'commit' => $commit]]);
    app(TaskCheckRunner::class)->head = $commit;
    $group->taskable->forceFill(['checkout_path' => $root.'/vm'])->saveQuietly();
    GitRemoteRedirect::global($root.'/origin.git', 'https://github.com/acme/shop.git');
    Queue::fake([PublishTaskVmCommit::class]);

    try {
        $operation = publish_operation_start($group, $check, ['pull_request' => ['title' => 'Title', 'body' => 'Body']]);
        app(OperationRunner::class)->run($operation->id);
        $job = Queue::pushed(PublishTaskVmCommit::class)->sole();
        $publisher = app(GitHubTaskPullRequestPublisher::class);
        $job->handle($publisher);
        $vm = TaskVm::forGroup($group);
        expect($vm->publish_state)->toBe(TaskVmPublishState::Pushed);
        // GitHub accepted the push, but the worker disappeared before saving its outcome.
        $vm->update(['publish_state' => TaskVmPublishState::Queued]);
        expect($vm->publish_started_at)->not->toBeNull();
        if ($cancel) {
            $this->call('POST', '/api/v1/operations/'.$operation->id.'/cancel', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], content: '{}')->assertOk()->assertJsonPath('data.status', 'waiting');
            $this->postJson('/api/v1/task-groups/'.$group->id.'/cancel')->assertConflict()->assertJsonPath('error.code', 'operation.target_busy');
        }
        $transfers = count($transport->sent);

        $job->handle($publisher);
        publish_operation_read($operation);

        expect($vm->refresh()->publish_state)->toBe(TaskVmPublishState::Pushed);
        expect($runner->pushes)->toBe(1);
        expect($transport->sent)->toHaveCount($transfers);
        expect($git($root.'/origin.git', 'rev-parse', 'refs/heads/task-'.$group->id))->toBe($commit);
        expect($operation->refresh()->status)->toBe($cancel ? OperationStatus::Cancelled : OperationStatus::Succeeded);
        expect($operation->lock_key)->toBeNull();
        $opened = static fn (Request $request): bool => $request->method() === 'POST' && $request->url() === 'https://api.github.com/repos/acme/shop/pulls';
        expect(Http::recorded($opened))->toHaveCount($cancel ? 0 : 1);
        if ($cancel) {
            expect($group->refresh()->pr_url)->toBeNull();
            $this->postJson('/api/v1/task-groups/'.$group->id.'/cancel')->assertSuccessful();
            expect($group->refresh()->status)->toBe(TaskGroupStatus::Cancelled);
        } else {
            expect($operation->result['pull_request_number'])->toBe(12);
            publish_operation_read($operation);
            expect(Http::recorded($opened))->toHaveCount(1);
        }
    } finally {
        GitRemoteRedirect::clear();
        TestOrbitHome::clearScratch();
    }
})->with(['finish publication' => [false], 'finish cancellation' => [true]]);

it('opens or updates the watched pull request without coverage or reviewers', function (bool $existing): void {
    [$group, $check] = publish_operation_group();
    publish_operation_github($existing ? [['number' => 12, 'html_url' => 'https://github.com/acme/shop/pull/12', 'state' => 'open', 'merged_at' => null]] : []);
    Queue::fake([PublishTaskVmCommit::class]);
    config(['orbit.tasks.review_request_logins' => 'reviewer']);
    $operation = publish_operation_start($group, $check, ['pull_request' => ['title' => 'Exact title', 'body' => 'Exact body']]);
    app(OperationRunner::class)->run($operation->id);
    TaskVm::forGroup($group)->update(['publish_state' => TaskVmPublishState::Pushed]);

    publish_operation_read($operation);

    expect($operation->refresh()->status)->toBe(OperationStatus::Succeeded);
    expect($operation->result['pull_request_number'])->toBe(12);
    expect($operation->result['pull_request_url'])->toBe('https://github.com/acme/shop/pull/12');
    expect($group->refresh()->watched_pr_number)->toBe(12);
    expect($group->watched_pr_url)->toBe('https://github.com/acme/shop/pull/12');
    expect($group->watched_pr_state)->toBe('open');
    expect($group->status)->toBe(TaskGroupStatus::Running);
    Http::assertSent(fn (Request $request): bool => $request->method() === ($existing ? 'PATCH' : 'POST') && $request->url() === 'https://api.github.com/repos/acme/shop/pulls'.($existing ? '/12' : '') && $request['title'] === 'Exact title' && $request['body'] === 'Exact body' && ($existing || ($request['base'] === 'main' && $request['head'] === 'task-'.$group->id)));
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'requested_reviewers'));
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'typesafe'));
})->with([false, true]);

it('looks up the open pull request again after an interrupted pull request step', function (): void {
    [$group, $check] = publish_operation_group();
    publish_operation_github();
    Queue::fake([PublishTaskVmCommit::class]);
    $operation = publish_operation_start($group, $check, ['pull_request' => ['title' => 'Title', 'body' => 'Body']]);
    app(OperationRunner::class)->run($operation->id);
    TaskVm::forGroup($group)->update(['publish_state' => TaskVmPublishState::Pushed]);
    publish_operation_read($operation);
    $operation->refresh()->forceFill(['status' => 'queued', 'lock_key' => 'task_group:'.$group->id, 'finished_at' => null])->save();
    $operation->steps()->where('name', 'pull_request')->update(['status' => 'running', 'finished_at' => null]);
    $state = $operation->state;
    unset($state['steps']['pull_request']);
    $operation->forceFill(['state' => $state])->save();
    Http::swap(new Factory);
    publish_operation_github([['number' => 12, 'html_url' => 'https://github.com/acme/shop/pull/12', 'state' => 'open', 'merged_at' => null]]);

    app(OperationRunner::class)->run($operation->id);

    expect($operation->refresh()->status)->toBe(OperationStatus::Succeeded);
    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST' && $request->url() === 'https://api.github.com/repos/acme/shop/pulls');
    Http::assertSent(fn (Request $request): bool => $request->method() === 'PATCH' && $request->url() === 'https://api.github.com/repos/acme/shop/pulls/12');
    Queue::assertPushed(PublishTaskVmCommit::class, 1);
});

it('returns a publish job failure without retrying', function (string $code): void {
    [$group, $check] = publish_operation_group();
    Queue::fake([PublishTaskVmCommit::class]);
    $operation = publish_operation_start($group, $check);
    app(OperationRunner::class)->run($operation->id);
    TaskVm::forGroup($group)->update(['publish_state' => TaskVmPublishState::Failed, 'publish_error' => 'Publish failed.', 'publish_error_code' => $code]);

    publish_operation_read($operation);

    expect($operation->refresh()->status)->toBe(OperationStatus::Failed);
    expect($operation->error_code)->toBe($code);
    expect($operation->failed_step)->toBe('push');
    expect($operation->lock_key)->toBeNull();
    Queue::assertPushed(PublishTaskVmCommit::class, 1);
})->with(['tasks.not_fast_forward', 'tasks.workflows_permission', 'tasks.push_failed']);

it('starts one fresh job for a new keyed operation after failure while replaying the old failure', function (): void {
    [$group, $check] = publish_operation_group();
    Queue::fake([PublishTaskVmCommit::class]);
    $first = publish_operation_start($group, $check, key: 'publish.first');
    app(OperationRunner::class)->run($first->id);
    $vm = TaskVm::forGroup($group);
    $vm->update(['publish_state' => TaskVmPublishState::Failed, 'publish_error' => 'Permission refused.', 'publish_error_code' => 'tasks.workflows_permission']);
    publish_operation_read($first);
    expect($first->refresh()->status)->toBe(OperationStatus::Failed);
    $this->postJson('/api/v1/task-groups/'.$group->id.'/publish', ['check' => $check->id], ['Idempotency-Key' => 'publish.first'])->assertAccepted()->assertJsonPath('data.id', $first->id)->assertJsonPath('data.status', 'failed');
    Queue::assertPushed(PublishTaskVmCommit::class, 1);

    $second = publish_operation_start($group, $check, key: 'publish.second');
    app(OperationRunner::class)->run($second->id);

    expect($second->refresh()->status)->toBe(OperationStatus::Waiting);
    expect($vm->refresh()->publish_error_code)->toBeNull();
    expect($vm->publish_error)->toBeNull();
    Queue::assertPushed(PublishTaskVmCommit::class, 2);
    $vm->update(['publish_state' => TaskVmPublishState::Pushed]);
    publish_operation_read($second);
    expect($second->refresh()->status)->toBe(OperationStatus::Succeeded);
    expect($second->result['commit'])->toBe($check->commit_sha);
});

it('fails a refused pull request write and retains the published commit', function (): void {
    [$group, $check] = publish_operation_group();
    publish_operation_github(writeStatus: 403);
    Queue::fake([PublishTaskVmCommit::class]);
    $operation = publish_operation_start($group, $check, ['pull_request' => ['title' => 'Title', 'body' => 'Body']]);
    app(OperationRunner::class)->run($operation->id);
    TaskVm::forGroup($group)->update(['publish_state' => TaskVmPublishState::Pushed]);

    publish_operation_read($operation);

    expect($operation->refresh()->status)->toBe(OperationStatus::Failed);
    expect($operation->error_code)->toBe('tasks.publish_failed');
    expect($operation->failed_step)->toBe('pull_request');
    expect(TaskVm::forGroup($group)->publish_state)->toBe(TaskVmPublishState::Pushed);
});

it('uses an already published commit without another job', function (): void {
    [$group, $check] = publish_operation_group();
    TaskVm::forGroup($group)->update(['publish_commit' => $check->commit_sha, 'publish_branch' => 'task-'.$group->id, 'publish_state' => TaskVmPublishState::Pushed]);
    Queue::fake([PublishTaskVmCommit::class]);
    $operation = publish_operation_start($group, $check);

    app(OperationRunner::class)->run($operation->id);

    expect($operation->refresh()->status)->toBe(OperationStatus::Succeeded);
    expect($operation->result['commit'])->toBe($check->commit_sha);
    Queue::assertNotPushed(PublishTaskVmCommit::class);
});

it('refuses ended groups', function (string $mode, string $status, string $code): void {
    [$group, $check] = publish_operation_group();
    Task::topLevel()->whereKey($group->id)->update(['orchestration' => $mode, 'status' => $status]);

    $this->postJson('/api/v1/task-groups/'.$group->id.'/publish', ['check' => $check->id])->assertConflict()->assertJsonPath('error.code', $code);

    $this->assertDatabaseCount('operations', 1); // only the completed check owner
})->with([['external', 'completed', 'tasks.group_closed'], ['external', 'cancelled', 'tasks.group_closed'], ['external', 'failed', 'tasks.group_closed']]);

it('refuses a missing workspace', function (): void {
    [$group, $check] = publish_operation_group();
    $group->taskable()->dissociate();
    $group->save();

    $this->postJson('/api/v1/task-groups/'.$group->id.'/publish', ['check' => $check->id])->assertConflict()->assertJsonPath('error.code', 'tasks.no_workspace');

    $this->assertDatabaseCount('operations', 1); // only the completed check owner
});

it('requires a passed handoff commit of this group', function (string $kind, string $status, ?string $commit, bool $foreign): void {
    [$group, $check] = publish_operation_group();
    $check->update(['kind' => $kind, 'status' => $status, 'commit_sha' => $commit]);
    if ($foreign) {
        $other = Task::topLevel()->create(['title' => 'Other', 'brief' => 'Other.']);
        $check->task->update(['parent_id' => $other->id]);
    }

    $this->postJson('/api/v1/task-groups/'.$group->id.'/publish', ['check' => $check->id])->assertUnprocessable()->assertJsonPath('error.code', 'tasks.commit_not_checked');

    $this->assertDatabaseCount('operations', 1); // only the completed check owner
})->with([['handoff', 'failed', null, false], ['handoff', 'changed', str_repeat('a', 40), false], ['baseline', 'passed', str_repeat('a', 40), false], ['handoff', 'passed', null, false], ['handoff', 'passed', str_repeat('a', 40), true]]);

it('refuses moved HEAD at admission', function (): void {
    [$group, $check] = publish_operation_group();
    app(TaskCheckRunner::class)->head = str_repeat('c', 40);

    $this->postJson('/api/v1/task-groups/'.$group->id.'/publish', ['check' => $check->id])->assertConflict()->assertJsonPath('error.code', 'tasks.head_moved');

    $this->assertDatabaseCount('operations', 1); // only the completed check owner
});

it('resumes a parked VM first and checks HEAD after resume', function (bool $moved): void {
    [$group, $check] = publish_operation_group();
    $vm = TaskVm::forGroup($group);
    $vm->update(['state' => TaskVmState::Parked]);
    Queue::fake([PublishTaskVmCommit::class, ResumeTaskVm::class]);
    $operation = publish_operation_start($group, $check);
    app(OperationRunner::class)->run($operation->id);
    expect($operation->refresh()->current_step)->toBe('workspace_ready');
    Queue::assertPushed(ResumeTaskVm::class, 1);
    Queue::assertNotPushed(PublishTaskVmCommit::class);
    $vm->update(['state' => TaskVmState::Ready]);
    app(TaskCheckRunner::class)->head = str_repeat($moved ? 'c' : 'a', 40);

    publish_operation_read($operation);

    expect($operation->refresh()->status)->toBe($moved ? OperationStatus::Failed : OperationStatus::Waiting);
    if ($moved) {
        expect($operation->error_code)->toBe('tasks.head_moved');
        Queue::assertNotPushed(PublishTaskVmCommit::class);
    } else {
        Queue::assertPushed(PublishTaskVmCommit::class, 1);
    }
})->with([false, true]);

it('invalidates an unstarted push so its late job does nothing', function (): void {
    [$group, $check] = publish_operation_group();
    Queue::fake([PublishTaskVmCommit::class]);
    $operation = publish_operation_start($group, $check);
    app(OperationRunner::class)->run($operation->id);

    $this->call('POST', '/api/v1/operations/'.$operation->id.'/cancel', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], content: '{}')->assertOk()->assertJsonPath('data.status', 'cancelled');
    (new PublishTaskVmCommit(TaskVm::forGroup($group)->id, $check->commit_sha, 'task-'.$group->id))->handle(app(GitHubTaskPullRequestPublisher::class));

    expect(TaskVm::forGroup($group)->publish_state)->toBeNull();
    expect($operation->refresh()->lock_key)->toBeNull();
    expect($group->refresh()->status)->toBe(TaskGroupStatus::Running);
});

it('defers cancellation after start and holds group ending until the job ends', function (string $result): void {
    [$group, $check] = publish_operation_group();
    Queue::fake([PublishTaskVmCommit::class]);
    $operation = publish_operation_start($group, $check, ['pull_request' => ['title' => 'Title', 'body' => 'Body']]);
    app(OperationRunner::class)->run($operation->id);
    $vm = TaskVm::forGroup($group);
    $vm->update(['publish_started_at' => now()]);

    $this->call('POST', '/api/v1/operations/'.$operation->id.'/cancel', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], content: '{}')->assertOk()->assertJsonPath('data.status', 'waiting');
    $this->postJson('/api/v1/task-groups/'.$group->id.'/cancel')->assertConflict()->assertJsonPath('error.code', 'operation.target_busy');
    publish_operation_read($operation);
    expect($operation->refresh()->status)->toBe(OperationStatus::Waiting);
    expect($operation->cancel_requested_at)->not->toBeNull();
    expect($operation->lock_key)->toBe('task_group:'.$group->id);
    expect($group->refresh()->status)->toBe(TaskGroupStatus::Running);
    $vm->update(['publish_state' => $result]);

    publish_operation_read($operation);

    expect($operation->refresh()->status)->toBe(OperationStatus::Cancelled);
    expect($operation->lock_key)->toBeNull();
    expect($group->refresh()->watched_pr_url)->toBeNull();
})->with(['pushed', 'failed']);

it('replays a keyed publish before later admission and refuses changed input', function (): void {
    [$group, $check] = publish_operation_group();
    TaskVm::forGroup($group)->update(['publish_commit' => $check->commit_sha, 'publish_branch' => 'task-'.$group->id, 'publish_state' => TaskVmPublishState::Pushed]);
    $operation = publish_operation_start($group, $check, key: 'publish.c1');
    app(OperationRunner::class)->run($operation->id);
    $group->update(['status' => TaskGroupStatus::Cancelled]);
    app(TaskCheckRunner::class)->head = str_repeat('c', 40);

    $this->postJson('/api/v1/task-groups/'.$group->id.'/publish', ['check' => $check->id], ['Idempotency-Key' => 'publish.c1'])->assertAccepted()->assertJsonPath('data.id', $operation->id)->assertJsonPath('data.status', 'succeeded');
    $this->postJson('/api/v1/task-groups/'.$group->id.'/publish', ['check' => $check->id + 1], ['Idempotency-Key' => 'publish.c1'])->assertConflict()->assertJsonPath('error.code', 'operation.idempotency_conflict');
    $this->assertDatabaseCount('operations', 2); // completed check owner plus the new operation
});

it('validates the closed typed publish input', function (array $input): void {
    [$group] = publish_operation_group();

    $this->postJson('/api/v1/task-groups/'.$group->id.'/publish', $input)->assertUnprocessable()->assertJsonPath('error.code', 'validation.failed');

    $this->assertDatabaseCount('operations', 1); // only the completed check owner
})->with([[[]], [['check' => '1']], [['check' => 0]], [['check' => 1, 'command' => 'true']], [['check' => 1, 'pull_request' => ['title' => 'Title']]], [['check' => 1, 'pull_request' => ['body' => 'Body']]], [['check' => 1, 'pull_request' => null]], [['check' => 1, 'pull_request' => ['title' => 'Title', 'body' => 'Body', 'base' => 'other']]]]);

it('requires Gateway access and enabled Tasks', function (): void {
    [$group, $check] = publish_operation_group();
    $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.254']);
    $this->postJson('/api/v1/task-groups/'.$group->id.'/publish', ['check' => $check->id])->assertForbidden();
    $this->withServerVariables(['REMOTE_ADDR' => $group->taskable->node->wireguard_ip]);
    app(TaskExtensionState::class)->disable();

    $this->postJson('/api/v1/task-groups/'.$group->id.'/publish', ['check' => $check->id])->assertConflict()->assertJsonPath('error.code', 'extension.disabled');
    $this->assertDatabaseCount('operations', 1); // only the completed check owner
});

it('holds the group across operation kinds', function (): void {
    [$group, $check] = publish_operation_group();
    $holder = app(OperationStore::class)->create('tasks.check', 'task_group', $group->id, null, [], 'test', null);

    $this->postJson('/api/v1/task-groups/'.$group->id.'/publish', ['check' => $check->id])->assertConflict()->assertJsonPath('error.code', 'operation.target_busy')->assertJsonPath('error.details.operation_id', $holder->id);

    $this->assertDatabaseCount('operations', 2); // completed check owner plus the new operation
});
