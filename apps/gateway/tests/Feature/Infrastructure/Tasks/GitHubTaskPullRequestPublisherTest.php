<?php

declare(strict_types=1);

use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tasks\TaskPullRequestException;
use App\Domain\TaskVms\TaskVmPublishState;
use App\Infrastructure\AppDev\DevelopmentSshExecutor;
use App\Infrastructure\Ssh\HostKey;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Infrastructure\Tasks\GitHubTaskPullRequestPublisher;
use App\Jobs\TaskVms\PublishTaskVmCommit;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskVm;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\GitHub\GitHubTestSupport;
use Tests\Support\AppDevFakeSshExecutor;

function publisher_group(string $checkout, string $repository = 'git@github.com:acme/shop.git'): Task
{
    $project = Project::query()->create(['name' => 'Shop', 'slug' => 'shop', 'repository_url' => $repository, 'default_branch' => 'main']);
    $node = Node::query()->create(['name' => 'publish-node', 'status' => LifecycleStatus::Active, 'platform' => 'linux', 'public_ssh_host' => '10.44.0.150', 'wireguard_ip' => '10.44.0.150', 'user' => 'orbit']);
    $group = Task::topLevel()->create(['project_id' => $project->id, 'title' => 'Export orders', 'brief' => 'Add an export.', 'status' => 'reviewing']);
    $instance = Instance::query()->create(['task_workspace_owner_id' => $group->id, 'project_id' => $project->id, 'node_id' => $node->id, 'name' => 'task-7', 'checkout_path' => $checkout, 'branch' => 'task-7', 'status' => 'source_resolved']);
    $group->taskable()->associate($instance);
    $group->save();

    return test_vm_group($group);
}

/** GitHub already has `$commit` at the branch: the queued publish job pushed it. */
function publisher_pushed(Task $group, string $commit, ?string $branch = null, TaskVmPublishState $state = TaskVmPublishState::Pushed, ?string $error = null): void
{
    TaskVm::query()->where('group_id', $group->id)->update([
        'publish_commit' => $commit, 'publish_branch' => $branch ?? 'task-'.$group->id, 'publish_state' => $state,
        'publish_error' => $error, 'publish_queued_at' => now(),
    ]);
}

function publisher(SshExecutor $transport): GitHubTaskPullRequestPublisher
{
    app()->instance(DevelopmentSshExecutor::class, new DevelopmentSshExecutor(
        $transport,
        new class implements SshKeyProvider
        {
            public function privateKeyPath(): string
            {
                return '/home/orbit/.orbit/ssh/id_ed25519';
            }

            public function publicKey(): string
            {
                return 'ssh-ed25519 AAAA';
            }
        },
        new class implements KnownHostsStore
        {
            public function path(): string
            {
                return '/home/orbit/.orbit/ssh/known_hosts';
            }

            public function put(string $host, int $port, HostKey $key): void {}
        },
    ));

    return app(GitHubTaskPullRequestPublisher::class);
}

function publisher_github(int $pullStatus = 201, int $reviewersStatus = 201): void
{
    $reviewers = $reviewersStatus === 201
        ? Http::response(['requested_reviewers' => [['login' => 'reviewbot']]], 201)
        : Http::response(['message' => 'Review cannot be requested from pull request author.'], $reviewersStatus);
    Http::fake([
        'https://api.github.com/repos/acme/shop/installation' => Http::response(['id' => 9]),
        'https://api.github.com/app/installations/9/access_tokens' => Http::response(['token' => 'ghs_publish'], 201),
        'https://api.github.com/repos/acme/shop/pulls/11/requested_reviewers' => $reviewers,
        'https://api.github.com/repos/acme/shop/pulls/12/requested_reviewers' => $reviewers,
        'https://api.github.com/repos/acme/shop/pulls?*' => Http::response([[
            'html_url' => 'https://github.com/acme/shop/pull/12',
            'number' => 12,
            'user' => ['login' => 'orbit-bot'],
        ]]),
        'https://api.github.com/repos/acme/shop/pulls' => Http::response($pullStatus === 201 ? [
            'html_url' => 'https://github.com/acme/shop/pull/11',
            'number' => 11,
            'user' => ['login' => 'orbit-bot'],
        ] : ['message' => 'A pull request already exists'], $pullStatus),
    ]);
}

beforeEach(function (): void {
    Http::preventStrayRequests();
    config(['orbit.tasks.review_request_logins' => []]);
});

it('queues the push from the task VM and reports it pending until GitHub has the commit', function (): void {
    GitHubTestSupport::storeApp();
    publisher_github();
    Queue::fake();
    $group = publisher_group('/srv/orbit/apps/shop/workspace');
    $commit = str_repeat('a', 40);

    expect(fn () => publisher(new AppDevFakeSshExecutor)->publish($group, 'Body', $commit))
        ->toThrow(TaskPullRequestException::class, 'Orbit is pushing commit '.$commit.' to task-'.$group->id.'.');

    Queue::assertPushed(PublishTaskVmCommit::class, 1);
    expect(TaskVm::query()->where('group_id', $group->id)->sole()->publish_state)->toBe(TaskVmPublishState::Queued);
    Http::assertNotSent(static fn (Request $request): bool => $request->method() === 'POST' && $request->url() === 'https://api.github.com/repos/acme/shop/pulls');
});

it('opens the pull request with a pull request token once the commit is pushed', function (): void {
    GitHubTestSupport::storeApp();
    publisher_github();
    $group = publisher_group('/srv/orbit/apps/shop/workspace');
    publisher_pushed($group, str_repeat('a', 40));

    $url = publisher(new AppDevFakeSshExecutor)->publish($group, "Adds the export.\n", str_repeat('a', 40));

    expect($url)->toBe('https://github.com/acme/shop/pull/11');
    Http::assertSent(static fn (Request $request): bool => str_ends_with($request->url(), '/access_tokens')
        && $request->data() === ['repositories' => ['shop'], 'permissions' => ['contents' => 'write', 'pull_requests' => 'write', 'workflows' => 'write']]);
    Http::assertSent(static fn (Request $request): bool => $request->method() === 'POST' && $request->url() === 'https://api.github.com/repos/acme/shop/pulls'
        && $request->data() === ['title' => 'Export orders', 'head' => 'task-'.Task::topLevel()->sole()->id, 'base' => 'main', 'body' => "Adds the export.\n"]
        && $request->hasHeader('Authorization', 'Bearer ghs_publish'));
    Http::assertNotSent(static fn (Request $request): bool => str_contains($request->url(), '/requested_reviewers'));
});

it('refuses a push that does not name a commit sha', function (): void {
    GitHubTestSupport::storeApp();
    publisher_github();
    $transport = new AppDevFakeSshExecutor;

    expect(fn () => publisher($transport)->push(publisher_group('/srv/orbit/apps/shop/workspace'), 'HEAD'))
        ->toThrow(TaskPullRequestException::class, 'The approved commit is not a Git SHA.');
    expect($transport->commands)->toBe([]);
});

it('uses the open pull request that already has the task branch as its head', function (): void {
    GitHubTestSupport::storeApp();
    publisher_github(422);
    $group = publisher_group('/srv/orbit/apps/shop/workspace');
    publisher_pushed($group, str_repeat('a', 40));

    expect(publisher(new AppDevFakeSshExecutor)->publish($group, 'Body', str_repeat('a', 40)))->toBe('https://github.com/acme/shop/pull/12');
    Http::assertNotSent(static fn (Request $request): bool => str_contains($request->url(), '/requested_reviewers'));
});

it('requests the configured reviewers after opening a pull request', function (): void {
    GitHubTestSupport::storeApp();
    publisher_github();
    config(['orbit.tasks.review_request_logins' => ['reviewbot', 'orbit-bot']]);
    $group = publisher_group('/srv/orbit/apps/shop/workspace');
    publisher_pushed($group, str_repeat('a', 40));

    $url = publisher(new AppDevFakeSshExecutor)->publish($group, 'Body', str_repeat('a', 40));

    expect($url)->toBe('https://github.com/acme/shop/pull/11');
    Http::assertSent(static fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === 'https://api.github.com/repos/acme/shop/pulls/11/requested_reviewers'
        && $request->data() === ['reviewers' => ['reviewbot']]
        && $request->hasHeader('Authorization', 'Bearer ghs_publish'));
});

it('requests the configured reviewers when it reuses an open pull request', function (): void {
    GitHubTestSupport::storeApp();
    publisher_github(422);
    config(['orbit.tasks.review_request_logins' => ['reviewbot']]);
    $group = publisher_group('/srv/orbit/apps/shop/workspace');
    publisher_pushed($group, str_repeat('a', 40));

    expect(publisher(new AppDevFakeSshExecutor)->publish($group, 'Body', str_repeat('a', 40)))->toBe('https://github.com/acme/shop/pull/12');
    Http::assertSent(static fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === 'https://api.github.com/repos/acme/shop/pulls/12/requested_reviewers'
        && $request->data() === ['reviewers' => ['reviewbot']]);
});

it('still publishes when the requested reviewers POST fails', function (): void {
    GitHubTestSupport::storeApp();
    publisher_github(201, 422);
    config(['orbit.tasks.review_request_logins' => ['reviewbot']]);
    Log::spy();
    $group = publisher_group('/srv/orbit/apps/shop/workspace');
    publisher_pushed($group, str_repeat('a', 40));

    expect(publisher(new AppDevFakeSshExecutor)->publish($group, 'Body', str_repeat('a', 40)))->toBe('https://github.com/acme/shop/pull/11');
    Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message, array $context): bool => $message === 'The task pull request reviewers could not be requested.'
        && $context['pull_request'] === 11 && $context['reviewers'] === ['reviewbot']);
});

it('refuses to publish without an App, for another host, or after a failed push job', function (bool $app, string $repository, string $message): void {
    if ($app) {
        GitHubTestSupport::storeApp();
    }
    publisher_github();
    $group = publisher_group('/srv/orbit/apps/shop/workspace', $repository);
    publisher_pushed($group, str_repeat('a', 40), state: TaskVmPublishState::Failed, error: 'The task branch could not be pushed. rejected');

    expect(fn () => publisher(new AppDevFakeSshExecutor)->publish($group, 'Body', str_repeat('a', 40)))
        ->toThrow(TaskPullRequestException::class, $message);
})->with([
    'no App' => [false, 'git@github.com:acme/shop.git', 'The Gateway GitHub App is not registered.'],
    'another host' => [true, 'git@gitlab.com:acme/shop.git', 'The Project repository is not on github.com.'],
    'failed push job' => [true, 'git@github.com:acme/shop.git', 'The task branch could not be pushed. rejected'],
]);

it('names the permissions GitHub has not granted instead of an unreachable GitHub', function (): void {
    GitHubTestSupport::storeApp();
    Http::fake([
        'https://api.github.com/repos/acme/shop/installation' => Http::response(['id' => 9]),
        'https://api.github.com/app/installations/9/access_tokens' => Http::response(['message' => 'The permissions requested are not granted to this installation.'], 422),
    ]);

    expect(fn () => publisher(new AppDevFakeSshExecutor)->publish(publisher_group('/srv/orbit/apps/shop/workspace'), 'Body', str_repeat('a', 40)))
        ->toThrow(TaskPullRequestException::class, 'The pull request could not be opened: GitHub refused the Project token request (422): The permissions requested are not granted to this installation.');
});

it('still reports an unreachable GitHub when the token request fails on the server side', function (): void {
    GitHubTestSupport::storeApp();
    Http::fake([
        'https://api.github.com/repos/acme/shop/installation' => Http::response(['id' => 9]),
        'https://api.github.com/app/installations/9/access_tokens' => Http::response(['message' => 'Server Error'], 502),
    ]);

    expect(fn () => publisher(new AppDevFakeSshExecutor)->publish(publisher_group('/srv/orbit/apps/shop/workspace'), 'Body', str_repeat('a', 40)))
        ->toThrow(TaskPullRequestException::class, 'The pull request could not be opened: GitHub could not be reached or refused the Project credential.');
});

it('pushes a reviewed commit to an incoming pull request branch, never to task-{id}', function (): void {
    GitHubTestSupport::storeApp();
    publisher_github();
    Queue::fake();
    $group = publisher_group('/srv/orbit/apps/shop/workspace');
    $group->update(['pr_branch' => 'cursor/login-throttle']);

    expect(fn () => publisher(new AppDevFakeSshExecutor)->push($group, str_repeat('a', 40)))->toThrow(TaskPullRequestException::class);

    expect(TaskVm::query()->where('group_id', $group->id)->sole()->publish_branch)->toBe('cursor/login-throttle');
    Queue::assertPushed(PublishTaskVmCommit::class, static fn (PublishTaskVmCommit $job): bool => $job->branch === 'cursor/login-throttle');
});

it('refuses an incoming pull request branch that is not a valid branch name', function (): void {
    GitHubTestSupport::storeApp();
    publisher_github();
    Queue::fake();
    $group = publisher_group('/srv/orbit/apps/shop/workspace');
    $group->update(['pr_branch' => 'bad..branch']);

    expect(fn () => publisher(new AppDevFakeSshExecutor)->push($group, str_repeat('a', 40)))->toThrow(TaskPullRequestException::class);
    Queue::assertNothingPushed();
});

/**
 * GitHub with pull request #12 for the task branch: a new open answers 422, and the list finds #12. GraphQL answers
 * each draft mutation with the state it asked for, or refuses it.
 */
function publisher_github_draft(bool $conflicting = false, bool $mutates = true, bool $draft = true, string $state = 'open'): void
{
    Http::fake([
        'https://api.github.com/repos/acme/shop/installation' => Http::response(['id' => 9]),
        'https://api.github.com/app/installations/9/access_tokens' => Http::response(['token' => 'ghs_publish'], 201),
        'https://api.github.com/repos/acme/shop/pulls?*' => Http::response([[
            'html_url' => 'https://github.com/acme/shop/pull/12', 'number' => 12, 'user' => ['login' => 'orbit-bot'],
            'state' => $state, 'draft' => $draft, 'node_id' => 'PR_kwDOdraft12',
        ]]),
        'https://api.github.com/repos/acme/shop/pulls' => Http::response(['message' => 'A pull request already exists'], 422),
        'https://api.github.com/repos/acme/shop/pulls/12' => static fn (Request $request) => $request->method() === 'PATCH' ? Http::response([]) : Http::response([
            'state' => $state, 'merged' => false, 'mergeable' => ! $conflicting, 'mergeable_state' => $conflicting ? 'dirty' : 'clean',
            'head' => ['sha' => str_repeat('a', 40)], 'base' => ['ref' => 'main'], 'body' => 'Draft', 'draft' => $draft, 'node_id' => 'PR_kwDOdraft12',
        ]),
        'https://api.github.com/graphql' => static function (Request $request) use ($mutates) {
            $mutation = str_contains((string) $request['query'], 'convertPullRequestToDraft') ? 'convertPullRequestToDraft' : 'markPullRequestReadyForReview';

            return Http::response($mutates
                ? ['data' => [$mutation => ['pullRequest' => ['isDraft' => $mutation === 'convertPullRequestToDraft']]]]
                : ['errors' => [['message' => 'Resource not accessible by integration']]]);
        },
    ]);
}

/** The GraphQL draft mutations GitHub received, in order. @return list<string> */
function publisher_draft_mutations(): array
{
    return array_values(array_map(
        static fn (array $sent): string => str_contains((string) $sent[0]['query'], 'convertPullRequestToDraft') ? 'draft' : 'ready',
        Http::recorded(static fn (Request $request): bool => $request->url() === 'https://api.github.com/graphql')->all(),
    ));
}

it('opens a draft for the handoff commit and reports the pull request that runs its CI', function (): void {
    GitHubTestSupport::storeApp();
    Http::fake([
        'https://api.github.com/repos/acme/shop/installation' => Http::response(['id' => 9]),
        'https://api.github.com/app/installations/9/access_tokens' => Http::response(['token' => 'ghs_publish'], 201),
        'https://api.github.com/repos/acme/shop/pulls' => Http::response([
            'html_url' => 'https://github.com/acme/shop/pull/11', 'number' => 11, 'user' => ['login' => 'orbit-bot'], 'draft' => true, 'node_id' => 'PR_11',
        ], 201),
        'https://api.github.com/repos/acme/shop/pulls/11' => Http::response([
            'state' => 'open', 'merged' => false, 'mergeable' => null, 'head' => ['sha' => str_repeat('a', 40)], 'base' => ['ref' => 'main'], 'draft' => true,
        ]),
    ]);
    $group = publisher_group('/srv/orbit/apps/shop/workspace');
    publisher_pushed($group, str_repeat('a', 40));

    $pullRequest = publisher(new AppDevFakeSshExecutor)->publishHandoff($group, str_repeat('a', 40));

    expect($pullRequest->headSha)->toBe(str_repeat('a', 40))
        ->and($pullRequest->conflicts())->toBeFalse()
        ->and($group->fresh()?->pr_url)->toBeNull();
    Http::assertSent(static fn (Request $request): bool => $request->method() === 'POST' && $request->url() === 'https://api.github.com/repos/acme/shop/pulls'
        && $request['draft'] === true && $request['head'] === 'task-'.Task::topLevel()->sole()->id && $request['base'] === 'main');
});

it('makes the stored pull request a draft before a handoff pushes unreviewed work to it', function (): void {
    GitHubTestSupport::storeApp();
    publisher_github_draft(conflicting: true, draft: false);
    $group = publisher_group('/srv/orbit/apps/shop/workspace');
    $group->update(['pr_url' => 'https://github.com/acme/shop/pull/12']);
    publisher_pushed($group, str_repeat('a', 40));

    expect(publisher(new AppDevFakeSshExecutor)->publishHandoff($group, str_repeat('a', 40))->conflicts())->toBeTrue()
        ->and(publisher_draft_mutations())->toBe(['draft']);
    Http::assertNotSent(static fn (Request $request): bool => $request->method() === 'POST' && str_ends_with($request->url(), '/pulls'));
});

it('pushes nothing for a stored pull request that already merged', function (): void {
    GitHubTestSupport::storeApp();
    publisher_github_draft(draft: false, state: 'closed');
    Queue::fake();
    $group = publisher_group('/srv/orbit/apps/shop/workspace');
    $group->update(['pr_url' => 'https://github.com/acme/shop/pull/12']);

    expect(publisher(new AppDevFakeSshExecutor)->publishHandoff($group, str_repeat('a', 40))->state->value)->toBe('closed')
        ->and(publisher_draft_mutations())->toBe([]);
    Queue::assertNothingPushed();
});

it('waits for the push before it opens the draft', function (): void {
    GitHubTestSupport::storeApp();
    publisher_github_draft();
    Queue::fake();
    $group = publisher_group('/srv/orbit/apps/shop/workspace');

    expect(fn () => publisher(new AppDevFakeSshExecutor)->publishHandoff($group, str_repeat('a', 40)))
        ->toThrow(TaskPullRequestException::class, 'Orbit is pushing commit');
    Http::assertNotSent(static fn (Request $request): bool => str_contains($request->url(), '/pulls'));
});

it('publishes the task through the draft the handoffs opened instead of a second pull request', function (): void {
    GitHubTestSupport::storeApp();
    publisher_github_draft();
    $group = publisher_group('/srv/orbit/apps/shop/workspace');
    publisher_pushed($group, str_repeat('a', 40));

    $url = publisher(new AppDevFakeSshExecutor)->publish($group, "Adds the export.\n", str_repeat('a', 40));

    expect($url)->toBe('https://github.com/acme/shop/pull/12')
        ->and(publisher_draft_mutations())->toBe(['ready']);
    Http::assertSent(static fn (Request $request): bool => $request->method() === 'PATCH' && $request->url() === 'https://api.github.com/repos/acme/shop/pulls/12'
        && $request->data() === ['title' => 'Export orders', 'body' => "Adds the export.\n"]);
    Http::assertSent(static fn (Request $request): bool => $request->url() === 'https://api.github.com/graphql' && $request['variables'] === ['id' => 'PR_kwDOdraft12']);
    Http::assertSentCount(6);
});

it('fails the publication when GitHub keeps the pull request a draft', function (): void {
    GitHubTestSupport::storeApp();
    publisher_github_draft(mutates: false);
    $group = publisher_group('/srv/orbit/apps/shop/workspace');
    publisher_pushed($group, str_repeat('a', 40));

    expect(fn () => publisher(new AppDevFakeSshExecutor)->publish($group, 'Body', str_repeat('a', 40)))
        ->toThrow(TaskPullRequestException::class, 'The pull request could not be opened');
});

it('makes the stored pull request ready again when it pushes an approved commit', function (bool $draft, array $mutations): void {
    GitHubTestSupport::storeApp();
    publisher_github_draft(draft: $draft);
    $group = publisher_group('/srv/orbit/apps/shop/workspace');
    $group->update(['pr_url' => 'https://github.com/acme/shop/pull/12']);
    publisher_pushed($group, str_repeat('a', 40));

    publisher(new AppDevFakeSshExecutor)->push($group, str_repeat('a', 40));

    expect(publisher_draft_mutations())->toBe($mutations);
})->with(['a draft from a handoff' => [true, ['ready']], 'a ready pull request' => [false, []]]);

it('gives back the handoff draft: closes the one it opened, or makes a stored pull request ready', function (bool $stored, int $closes, array $mutations): void {
    GitHubTestSupport::storeApp();
    publisher_github_draft();
    $group = publisher_group('/srv/orbit/apps/shop/workspace');
    $group->update(['pr_url' => $stored ? 'https://github.com/acme/shop/pull/12' : null]);

    publisher(new AppDevFakeSshExecutor)->releaseHandoffDraft($group);

    expect(Http::recorded(static fn (Request $request): bool => $request->method() === 'PATCH' && $request->data() === ['state' => 'closed'])->count())->toBe($closes)
        ->and(publisher_draft_mutations())->toBe($mutations);
})->with([
    'a draft the handoffs opened' => [false, 1, []],
    'a stored pull request a handoff made a draft' => [true, 0, ['ready']],
]);
