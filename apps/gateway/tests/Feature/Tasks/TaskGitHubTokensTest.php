<?php

declare(strict_types=1);

use App\Actions\Tasks\StartDependencyBatchMergeAction;
use App\Actions\Tasks\StartTaskPullRequestStepAction;
use App\Domain\GitHub\GitHubAccessToken;
use App\Domain\GitHub\GitHubApi;
use App\Domain\GitHub\GitHubApiException;
use App\Domain\GitHub\GitHubAppCredentials;
use App\Domain\GitHub\GitHubAppStore;
use App\Domain\GitHub\GitHubTokenGrant;
use App\Domain\GitHub\RepositoryPullRequestAccess;
use App\Domain\GitHub\RepositoryReadAccess;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\TaskCancelIntent;
use App\Domain\Tasks\TaskGitHubTokens;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskPrepFixIntent;
use App\Domain\Tasks\TaskPrepIntent;
use App\Domain\Tasks\TaskProvisionIntent;
use App\Domain\Tasks\TaskPublishIntent;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\AgentView\WebSocketEndpoint;
use App\Infrastructure\GitHub\HttpGitHubApi;
use App\Infrastructure\Swamp\SwampCommandStep;
use App\Models\Activity;
use App\Models\Operation;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Mockery\MockInterface;
use Tests\Support\SwampNodeFixture;

uses(LazilyRefreshDatabase::class);

const TOKEN_RUN = '6b69badf-4f06-4f2e-82d7-21b62c1f587a';

/** A GitHub that serves one write token and one read token, and a Swamp socket that records every vault put. */
function tokenWorld(bool $installed = true, ?array &$scoped = []): object
{
    SwampNodeFixture::ensure();
    app(GitHubAppStore::class)->put(new GitHubAppCredentials(4242, 'push', 'push', 'hardimpactdev', 'organization', 'https://github.com/apps/push', 'unused'));
    app()->instance(GitHubApi::class, Mockery::mock(GitHubApi::class, function (MockInterface $github) use ($installed, &$scoped): void {
        $github->shouldReceive('repositoryInstallation')->andReturn($installed ? 7 : null);
        $github->shouldReceive('repositoryPullRequestToken')->andReturn('push-token-value');
        $github->shouldReceive('repositoryReadToken')->andReturn('read-token-value');
        $github->shouldReceive('installationReadToken')->andReturn('deps-token-value');
        $github->shouldReceive('scopedRepositoryToken')->andReturnUsing(function ($credentials, GitHubTokenGrant $grant) use (&$scoped): GitHubAccessToken {
            $scoped[] = $grant;

            return new GitHubAccessToken('step-token-value', '2026-11-06T08:00:00Z');
        });
    }));
    $socket = new class implements WebSocketClient
    {
        /** @var list<array<string,mixed>> */
        public array $frames = [];

        /** The number of vault puts after which the next one is refused. */
        public ?int $failAfter = null;

        private bool $open = false;

        public function connect(WebSocketEndpoint $endpoint, float $timeoutSeconds): void
        {
            $this->open = true;
        }

        public function send(array $message): void
        {
            $this->frames[] = $message;
        }

        public function receive(float $timeoutSeconds): array
        {
            if ($this->failAfter !== null && count($this->frames) > $this->failAfter) {
                throw new RuntimeException('vault put refused');
            }
            $frame = end($this->frames);

            return [['id' => $frame['id'], 'type' => 'vault.put', 'payload' => ['data' => [
                'vaultName' => $frame['payload']['vaultName'], 'secretKey' => $frame['payload']['key'], 'vaultType' => 'local_encryption']]]];
        }

        public function close(): void
        {
            $this->open = false;
        }

        public function isConnected(): bool
        {
            return $this->open;
        }
    };
    app()->instance(WebSocketClient::class, $socket);
    config(['swamp.address' => 'swamp.test', 'swamp.ca_path' => '/tmp/ca.pem', 'swamp.run_token' => 'run-token']);

    return $socket;
}

/** The vault descriptors and values that the socket saw. */
function tokenPuts(object $socket): array
{
    return array_map(fn (array $frame): array => [$frame['payload']['vaultName'], $frame['payload']['key'], $frame['payload']['value']], $socket->frames);
}

/** @return array{0:Operation,1:array<string,array{vault:string,key:string}>} */
function pushOperation(string $kind, array $input = [], OperationStatus $status = OperationStatus::Running): array
{
    $project = Project::query()->firstOrCreate(['slug' => 'orbit'], ['name' => 'orbit', 'repository_url' => 'https://github.com/hardimpactdev/orbit.git', 'default_branch' => 'main', 'root' => '.']);
    $group = Task::query()->create(['project_id' => $project->id, 'title' => 'Push', 'brief' => 'Push it', 'status' => TaskGroupStatus::Running]);
    $names = ['github_token' => ['vault' => 'orbit-control-inputs', 'key' => 'task-publish-token-'.fake()->uuid()],
        'git_authorization' => ['vault' => 'orbit-control-inputs', 'key' => 'task-publish-token-'.fake()->uuid()]];
    $input = $input + (in_array($kind, ['tasks.prep', 'tasks.review_round'], true)
        ? ['publish' => ['repository' => 'hardimpactdev/orbit'], 'publish_secrets' => $names]
        : ['repository' => 'hardimpactdev/orbit', 'execution_secrets' => $names]);
    $operation = Operation::query()->create(['kind' => $kind, 'target_type' => 'task_group', 'target_id' => $group->id, 'node_id' => SwampNodeFixture::Id,
        'status' => $status, 'state' => ['swamp_run_id' => TOKEN_RUN], 'input' => $input]);

    return [$operation, $names];
}

/** @return array{0:Operation,1:array{vault:string,key:string}} a provision, or a prep run whose provision child holds the intent */
function cloneOperation(string $kind, array $overrides = [], string $source = 'github_app'): array
{
    $project = Project::query()->firstOrCreate(['slug' => 'orbit'], ['name' => 'orbit', 'repository_url' => 'https://github.com/hardimpactdev/orbit.git', 'default_branch' => 'main', 'root' => '.']);
    $project->forceFill(['source_access' => $source])->save();
    $group = Task::query()->create(['project_id' => $project->id, 'title' => 'Clone', 'brief' => 'Clone it', 'status' => TaskGroupStatus::Reserved]);
    $reference = ['vault' => 'orbit-control-inputs', 'key' => 'task-provision-git-'.fake()->numberBetween(1, 900)];
    $intent = [...['project_id' => $project->id, 'git_authorization' => $reference, 'workspace' => ['repository' => $project->repository_url]], ...$overrides];
    $operation = Operation::query()->create(['kind' => $kind, 'target_type' => 'task_group', 'target_id' => $group->id, 'node_id' => SwampNodeFixture::Id,
        'status' => OperationStatus::Running, 'state' => ['swamp_run_id' => TOKEN_RUN], 'input' => match ($kind) {
            'tasks.prep' => ['provision' => $intent],
            default => $intent,
        }]);

    return [$operation, $reference];
}

it('mints the push token of a publish when its step asks, and writes it only under the admitted vault keys', function (string $kind): void {
    $socket = tokenWorld();
    [$operation, $names] = pushOperation($kind);

    $copies = app(TaskGitHubTokens::class)->issue($operation, SwampNodeFixture::Id, TOKEN_RUN, 'publish');

    expect($copies)->toBe($names)
        ->and(tokenPuts($socket))
        ->toBe([['orbit-control-inputs', $names['github_token']['key'], 'push-token-value'],
            ['orbit-control-inputs', $names['git_authorization']['key'], 'AUTHORIZATION: basic '.base64_encode('x-access-token:push-token-value')]])
        ->and(json_encode($copies))->not->toContain('push-token-value');
})->with(['a publish' => ['tasks.publish'], 'a prep run' => ['tasks.prep'], 'a review round' => ['tasks.review_round']]);

it('mints the clone credential of a workspace when the clone step asks, and only a read credential', function (string $kind): void {
    $socket = tokenWorld();
    [$operation, $reference] = cloneOperation($kind);

    $copies = app(TaskGitHubTokens::class)->issue($operation, SwampNodeFixture::Id, TOKEN_RUN, 'clone');

    expect($copies)->toBe(['git_authorization' => $reference])
        ->and(tokenPuts($socket))->toBe([['orbit-control-inputs', $reference['key'], 'Authorization: Basic '.base64_encode('x-access-token:read-token-value')]]);
})->with(['a provision' => ['tasks.provision'], 'a prep run' => ['tasks.prep']]);

it('writes the clone header of a sync run as the Gateway starts it, and refuses a step that asks for it', function (): void {
    $socket = tokenWorld();
    [$operation, $reference] = cloneOperation('tasks.sync');

    $input = app(DirectOperation::class)->workflowInputs($operation);

    expect($input['intent']['git_authorization'])->toBe($reference)
        ->and(tokenPuts($socket))->toBe([['orbit-control-inputs', $reference['key'], 'Authorization: Basic '.base64_encode('x-access-token:read-token-value')]])
        ->and(fn () => app(TaskGitHubTokens::class)->issue($operation, SwampNodeFixture::Id, TOKEN_RUN, 'clone'))->toThrow(ResourceOperationException::class, 'does not permit this token');
});

it('writes the Composer and npm configuration of the setup steps from one read token, and nothing else', function (string $kind): void {
    $socket = tokenWorld();
    [$operation] = cloneOperation($kind);
    $intent = $kind === 'tasks.prep' ? 'provision' : null;
    $references = ['composer' => ['vault' => 'orbit-control-inputs', 'key' => 'task-provision-deps-composer-7'], 'npm' => ['vault' => 'orbit-control-inputs', 'key' => 'task-provision-deps-npm-7'], 'token' => ['vault' => 'orbit-control-inputs', 'key' => 'task-provision-deps-token-7']];
    $input = $operation->input;
    $intent === null ? $input['dependency_auth'] = $references : $input[$intent]['dependency_auth'] = $references;
    $operation->forceFill(['input' => $input])->save();

    $copies = app(TaskGitHubTokens::class)->issue($operation, SwampNodeFixture::Id, TOKEN_RUN, 'dependencies');

    expect($copies)->toEqual($references)
        ->and(tokenPuts($socket))->toBe([
            ['orbit-control-inputs', 'task-provision-deps-composer-7', '{"http-basic":{"github.com":{"username":"x-access-token","password":"deps-token-value"}}}'],
            ['orbit-control-inputs', 'task-provision-deps-npm-7', "//npm.pkg.github.com/:_authToken=deps-token-value\n"],
            ['orbit-control-inputs', 'task-provision-deps-token-7', 'deps-token-value'],
        ]);
})->with(['a provision' => ['tasks.provision'], 'a prep run' => ['tasks.prep']]);

it('writes the same configuration for a package install, whose intent holds the key names itself', function (): void {
    $socket = tokenWorld();
    [$operation] = cloneOperation('tasks.package');
    $references = ['composer' => ['vault' => 'orbit-control-inputs', 'key' => 'task-provision-deps-composer-9'], 'npm' => ['vault' => 'orbit-control-inputs', 'key' => 'task-provision-deps-npm-9'], 'token' => ['vault' => 'orbit-control-inputs', 'key' => 'task-provision-deps-token-9']];
    $operation->forceFill(['input' => [...$operation->input, 'dependency_auth' => $references]])->save();

    expect(app(TaskGitHubTokens::class)->issue($operation, SwampNodeFixture::Id, TOKEN_RUN, 'dependencies'))->toEqual($references)
        ->and(array_column(tokenPuts($socket), 1))->toBe(['task-provision-deps-composer-9', 'task-provision-deps-npm-9', 'task-provision-deps-token-9']);
});

it('refuses a package install every purpose but dependencies', function (string $purpose): void {
    $socket = tokenWorld();
    [$operation] = cloneOperation('tasks.package');

    expect(fn () => app(TaskGitHubTokens::class)->issue($operation, SwampNodeFixture::Id, TOKEN_RUN, $purpose))->toThrow(ResourceOperationException::class, 'does not permit this token')
        ->and($socket->frames)->toBe([]);
})->with(['clone', 'publish', 'pull_request', 'dependency_merge']);

it('writes an empty configuration, and records no issue, when the App cannot supply a token', function (): void {
    $socket = tokenWorld(installed: false);
    [$operation] = cloneOperation('tasks.provision');
    $operation->forceFill(['input' => [...$operation->input, 'dependency_auth' => [
        'composer' => ['vault' => 'orbit-control-inputs', 'key' => 'task-provision-deps-composer-8'], 'npm' => ['vault' => 'orbit-control-inputs', 'key' => 'task-provision-deps-npm-8'], 'token' => ['vault' => 'orbit-control-inputs', 'key' => 'task-provision-deps-token-8']]]])->save();

    app(TaskGitHubTokens::class)->issue($operation, SwampNodeFixture::Id, TOKEN_RUN, 'dependencies');

    expect(array_column(tokenPuts($socket), 2))->toBe(['{}', "# no private access\n", 'no-token-minted'])
        ->and(Activity::query()->where('command', 'operation:github:mint')->count())->toBe(0);
});

it('revokes the setup token when a vault copy fails, because the workflow revoke step does not run after a failed mint', function (): void {
    $socket = tokenWorld();
    $socket->failAfter = 2;
    app(GitHubApi::class)->shouldReceive('revokeInstallationToken')->once()
        ->withArgs(fn (GitHubAccessToken $credential): bool => $credential->reveal() === 'deps-token-value')->andReturn(true);
    [$operation] = cloneOperation('tasks.provision');
    $operation->forceFill(['input' => [...$operation->input, 'dependency_auth' => [
        'composer' => ['vault' => 'orbit-control-inputs', 'key' => 'task-provision-deps-composer-9'], 'npm' => ['vault' => 'orbit-control-inputs', 'key' => 'task-provision-deps-npm-9'], 'token' => ['vault' => 'orbit-control-inputs', 'key' => 'task-provision-deps-token-9']]]])->save();

    expect(fn () => app(TaskGitHubTokens::class)->issue($operation, SwampNodeFixture::Id, TOKEN_RUN, 'dependencies'))->toThrow('The Swamp execution copy could not be stored.')
        ->and(Activity::query()->where('command', 'operation:github:mint')->count())->toBe(0);
});

it('asks GitHub for an installation read token without a repository filter', function (): void {
    openssl_pkey_export(openssl_pkey_new(['private_key_bits' => 2048]), $pem);
    Http::fake(['api.github.com/app/installations/7/access_tokens' => Http::response(['token' => 'ghs_installationtoken'], 201)]);

    $token = app(HttpGitHubApi::class)->installationReadToken(new GitHubAppCredentials(4242, 'deps', 'deps', 'hardimpactdev', 'organization', 'https://github.com/apps/deps', $pem), 7);

    expect($token)->toBe('ghs_installationtoken');
    Http::assertSent(fn (Request $request): bool => $request->data() === ['permissions' => ['contents' => 'read']]);
});

it('refuses the dependencies purpose for a kind that runs no setup, and for an intent without both files', function (): void {
    $socket = tokenWorld();
    [$publish] = pushOperation('tasks.publish');
    [$provision] = cloneOperation('tasks.provision');
    $issue = fn (Operation $subject) => fn () => app(TaskGitHubTokens::class)->issue($subject, SwampNodeFixture::Id, TOKEN_RUN, 'dependencies');

    expect($issue($publish))->toThrow(ResourceOperationException::class, 'does not permit this token')
        ->and($issue($provision))->toThrow(ResourceOperationException::class, 'does not permit this token')
        ->and($socket->frames)->toBe([]);
});

it('writes an empty clone credential for a repository the App does not cover, so the clone reads it anonymously', function (): void {
    $socket = tokenWorld(installed: false);
    [$operation, $reference] = cloneOperation('tasks.provision');

    app(TaskGitHubTokens::class)->issue($operation, SwampNodeFixture::Id, TOKEN_RUN, 'clone');

    expect(tokenPuts($socket))->toBe([['orbit-control-inputs', $reference['key'], '']]);
});

it('mints the write token of a pull request step when the step asks, with the permissions of its kind', function (string $kind, array $permissions): void {
    $grants = [];
    $socket = tokenWorld(scoped: $grants);
    $project = Project::query()->firstOrCreate(['slug' => 'orbit'], ['name' => 'orbit', 'repository_url' => 'https://github.com/hardimpactdev/orbit.git', 'default_branch' => 'main', 'root' => '.']);
    $group = Task::query()->create(['project_id' => $project->id, 'title' => 'Step', 'brief' => 'Step it', 'status' => TaskGroupStatus::WaitingForReview]);
    $reference = ['vault' => 'orbit-control-inputs', 'key' => 'pr-step-write-'.fake()->uuid()];
    $operation = Operation::query()->create(['kind' => $kind, 'target_type' => 'task_group', 'target_id' => $group->id, 'node_id' => SwampNodeFixture::Id,
        'status' => OperationStatus::Running, 'state' => ['swamp_run_id' => TOKEN_RUN],
        'input' => ['repository' => 'hardimpactdev/orbit', 'execution_secrets' => ['write_token' => $reference]]]);

    $copies = app(TaskGitHubTokens::class)->issue($operation, SwampNodeFixture::Id, TOKEN_RUN, 'pull_request');

    expect($copies)->toBe(['write_token' => $reference])
        ->and(tokenPuts($socket))->toBe([['orbit-control-inputs', $reference['key'], 'step-token-value']])
        ->and($grants)->toHaveCount(1)
        ->and($grants[0]->permissions)->toBe($permissions);
})->with([
    'ready' => ['tasks.pr_ready', ['pull_requests' => 'write']],
    'review round comment' => ['tasks.review_round', ['pull_requests' => 'write']],
    'merge' => ['tasks.pr_merge', ['contents' => 'write', 'pull_requests' => 'write']],
]);

it('mints the read and the write token of a dependency merge when its first step asks', function (): void {
    $grants = [];
    $socket = tokenWorld(scoped: $grants);
    $project = Project::query()->firstOrCreate(['slug' => 'orbit'], ['name' => 'orbit', 'repository_url' => 'https://github.com/hardimpactdev/orbit.git', 'default_branch' => 'main', 'root' => '.']);
    $group = Task::query()->create(['project_id' => $project->id, 'title' => 'Batch', 'brief' => 'Batch it', 'status' => TaskGroupStatus::Running]);
    $references = ['write_token' => ['vault' => 'orbit-control-inputs', 'key' => 'dependency-merge-write-'.fake()->uuid()],
        'read_token' => ['vault' => 'orbit-control-inputs', 'key' => 'dependency-merge-read-'.fake()->uuid()]];
    $operation = Operation::query()->create(['kind' => 'tasks.dependency_merge', 'target_type' => 'task_group', 'target_id' => $group->id, 'node_id' => SwampNodeFixture::Id,
        'status' => OperationStatus::Running, 'state' => ['swamp_run_id' => TOKEN_RUN], 'input' => ['repository' => 'hardimpactdev/orbit', 'execution_secrets' => $references]]);

    $copies = app(TaskGitHubTokens::class)->issue($operation, SwampNodeFixture::Id, TOKEN_RUN, 'dependency_merge');

    expect($copies)->toEqual($references)
        ->and(array_column(tokenPuts($socket), 1))->toBe([$references['read_token']['key'], $references['write_token']['key']])
        ->and(array_map(fn ($grant) => $grant->permissions, $grants))->toBe([
            ['contents' => 'read', 'pull_requests' => 'read', 'checks' => 'read', 'actions' => 'read'],
            ['contents' => 'write', 'pull_requests' => 'write'],
        ]);
});

it('refuses a dependency merge token for another kind or purpose, and for an intent without both keys', function (): void {
    $socket = tokenWorld();
    $project = Project::query()->firstOrCreate(['slug' => 'orbit'], ['name' => 'orbit', 'repository_url' => 'https://github.com/hardimpactdev/orbit.git', 'default_branch' => 'main', 'root' => '.']);
    $group = Task::query()->create(['project_id' => $project->id, 'title' => 'Batch', 'brief' => 'Batch it', 'status' => TaskGroupStatus::Running]);
    $reference = fn (string $name): array => ['vault' => 'orbit-control-inputs', 'key' => 'dependency-merge-'.$name.'-'.fake()->uuid()];
    $make = fn (string $kind, array $secrets): Operation => Operation::query()->create(['kind' => $kind, 'target_type' => 'task_group', 'target_id' => $group->id,
        'node_id' => SwampNodeFixture::Id, 'status' => OperationStatus::Running, 'state' => ['swamp_run_id' => TOKEN_RUN],
        'input' => ['repository' => 'hardimpactdev/orbit', 'execution_secrets' => $secrets]]);
    $both = ['write_token' => $reference('write'), 'read_token' => $reference('read')];
    $issue = fn (Operation $subject, string $purpose) => fn () => app(TaskGitHubTokens::class)->issue($subject, SwampNodeFixture::Id, TOKEN_RUN, $purpose);

    expect($issue($make('tasks.dependency_merge', $both), 'pull_request'))->toThrow(ResourceOperationException::class, 'does not permit this token')
        ->and($issue($make('tasks.pr_merge', $both), 'dependency_merge'))->toThrow(ResourceOperationException::class, 'does not permit this token')
        ->and($issue($make('tasks.dependency_merge', ['write_token' => $reference('write')]), 'dependency_merge'))->toThrow(ResourceOperationException::class, 'does not permit this token')
        ->and($socket->frames)->toBe([]);
});

it('mints no push token for a cancel: origin has the approved work before a cancel is admitted', function (): void {
    $socket = tokenWorld();
    [$operation] = pushOperation('tasks.cancel', ['push' => ['remote' => 'https://github.com/hardimpactdev/orbit.git',
        'github_token' => ['vault' => 'orbit-control-inputs', 'key' => 'task-publish-token-'.fake()->uuid()]]]);

    expect(fn () => app(TaskGitHubTokens::class)->issue($operation, SwampNodeFixture::Id, TOKEN_RUN, 'publish'))
        ->toThrow(ResourceOperationException::class, 'does not permit this token')
        ->and($socket->frames)->toBe([]);
});

it('refuses a pull request token for a kind that holds no pull request step, and a step token for another repository', function (): void {
    $socket = tokenWorld();
    [$publish] = pushOperation('tasks.publish');
    [$elsewhere] = pushOperation('tasks.pr_ready', ['repository' => 'someone/else', 'execution_secrets' => ['write_token' => ['vault' => 'orbit-control-inputs', 'key' => 'pr-ready-write-'.fake()->uuid()]]]);
    $issue = fn (Operation $subject) => fn () => app(TaskGitHubTokens::class)->issue($subject, SwampNodeFixture::Id, TOKEN_RUN, 'pull_request');

    expect($issue($publish))->toThrow(ResourceOperationException::class, 'does not permit this token')
        ->and($issue($elsewhere))->toThrow(ResourceOperationException::class, 'does not permit this token')
        ->and($socket->frames)->toBe([]);
});

it('reports a step token the App cannot mint as an unreadable GitHub', function (): void {
    tokenWorld();
    app()->instance(GitHubApi::class, Mockery::mock(GitHubApi::class, function (MockInterface $github): void {
        $github->shouldReceive('scopedRepositoryToken')->andThrow(GitHubApiException::unavailable());
    }));
    $project = Project::query()->firstOrCreate(['slug' => 'orbit'], ['name' => 'orbit', 'repository_url' => 'https://github.com/hardimpactdev/orbit.git', 'default_branch' => 'main', 'root' => '.']);
    $group = Task::query()->create(['project_id' => $project->id, 'title' => 'Step', 'brief' => 'Step it', 'status' => TaskGroupStatus::WaitingForReview]);
    $operation = Operation::query()->create(['kind' => 'tasks.pr_merge', 'target_type' => 'task_group', 'target_id' => $group->id, 'node_id' => SwampNodeFixture::Id,
        'status' => OperationStatus::Running, 'state' => ['swamp_run_id' => TOKEN_RUN],
        'input' => ['repository' => 'hardimpactdev/orbit', 'execution_secrets' => ['write_token' => ['vault' => 'orbit-control-inputs', 'key' => 'pr-merge-write-'.fake()->uuid()]]]]);

    expect(fn () => app(TaskGitHubTokens::class)->issue($operation, SwampNodeFixture::Id, TOKEN_RUN, 'pull_request'))
        ->toThrow(fn (ResourceOperationException $exception) => expect($exception->errorCode)->toBe('tasks_pr.github_unreadable'));
});

it('lets a publish and a prep run ask for a token at the numeric Operation mint', function (string $kind): void {
    expect(app(DirectOperation::class)->definition($kind)['github_token_issuer'])->toBe(TaskGitHubTokens::class);
})->with(['tasks.publish', 'tasks.prep', 'tasks.package', 'tasks.dependency_merge', 'tasks.prep_fix', 'tasks.pr_ready', 'tasks.pr_merge', 'tasks.review_round', 'proof.command']);

it('gives a sync run, a build run, a task start and a cancel no token mint: the Gateway writes the read tokens of the first two, a task start reads the public repository, and origin has a cancel\'s approved work', function (string $kind): void {
    expect(app(DirectOperation::class)->definition($kind))->not->toHaveKey('github_token_issuer');
})->with(['tasks.sync', 'tasks.build', 'tasks.provision', 'tasks.cancel']);

it('lets a check ask for its tokens at the same mint, and refuses a purpose it does not hold', function (): void {
    tokenWorld();
    [$check] = pushOperation('tasks.check', ['phase' => 'handoff', 'check_name' => 'Required checks']);

    expect(app(DirectOperation::class)->definition('tasks.check')['github_token_issuer'])->toBe(TaskGitHubTokens::class)
        ->and(fn () => app(TaskGitHubTokens::class)->issue($check, SwampNodeFixture::Id, TOKEN_RUN, 'publish'))
        ->toThrow(ResourceOperationException::class, 'does not permit this token');
});

it('mints the write token, the checks token and the Git header of a handoff check, and only for a handoff', function (): void {
    $socket = tokenWorld();
    app(GitHubApi::class)->shouldReceive('repositoryChecksToken')->andReturn('checks-token-value');
    $names = ['github_token' => ['vault' => 'orbit-control-inputs', 'key' => 'task-check-write-'.fake()->uuid()],
        'checks_token' => ['vault' => 'orbit-control-inputs', 'key' => 'task-check-read-'.fake()->uuid()],
        'git_authorization' => ['vault' => 'orbit-control-inputs', 'key' => 'task-check-git-'.fake()->uuid()]];
    [$check] = pushOperation('tasks.check', ['phase' => 'handoff', 'check_name' => 'Required checks', 'execution_secrets' => $names]);

    $copies = app(TaskGitHubTokens::class)->issue($check, SwampNodeFixture::Id, TOKEN_RUN);

    expect($copies)->toBe($names)
        ->and(array_column(tokenPuts($socket), 1))->toBe(array_column($names, 'key'));

    $check->update(['input' => [...$check->input, 'phase' => 'baseline']]);
    expect(fn () => app(TaskGitHubTokens::class)->issue($check, SwampNodeFixture::Id, TOKEN_RUN))
        ->toThrow(ResourceOperationException::class, 'does not permit this token');
});

it('refuses a run that is not the Operation run, a caller that is not Swamp, an Operation that ended, and a missing or unknown purpose', function (): void {
    $socket = tokenWorld();
    [$operation] = pushOperation('tasks.publish');
    $ended = pushOperation('tasks.publish', status: OperationStatus::Failed)[0];
    $issue = fn (Operation $subject, int $caller, string $run, ?string $purpose = 'publish') => fn () => app(TaskGitHubTokens::class)->issue($subject, $caller, $run, $purpose);

    expect($issue($operation, SwampNodeFixture::Id, 'ffffffff-4f06-4f2e-82d7-21b62c1f587a'))->toThrow(ResourceOperationException::class, 'another Swamp run')
        ->and($issue($operation, 12, TOKEN_RUN))->toThrow(ResourceOperationException::class, 'does not permit this token')
        ->and($issue($ended, SwampNodeFixture::Id, TOKEN_RUN))->toThrow(ResourceOperationException::class, 'does not permit this token')
        ->and($issue($operation, SwampNodeFixture::Id, TOKEN_RUN, null))->toThrow(ResourceOperationException::class, 'does not permit this token')
        ->and($issue($operation, SwampNodeFixture::Id, TOKEN_RUN, 'merge'))->toThrow(ResourceOperationException::class, 'does not permit this token')
        ->and($socket->frames)->toBe([]);
});

it('refuses a purpose that the kind does not hold, and a repository that is not the Project repository', function (): void {
    $socket = tokenWorld();
    [$other] = pushOperation('tasks.publish', ['repository' => 'someone/else']);
    [$publish] = pushOperation('tasks.publish');
    [$check] = pushOperation('tasks.check');
    [$provision] = cloneOperation('tasks.provision');
    [$elsewhere] = cloneOperation('tasks.provision', ['workspace' => ['repository' => 'https://github.com/someone/else.git']]);
    $issue = fn (Operation $subject, string $purpose) => fn () => app(TaskGitHubTokens::class)->issue($subject, SwampNodeFixture::Id, TOKEN_RUN, $purpose);

    expect($issue($other, 'publish'))->toThrow(ResourceOperationException::class, 'does not permit this token')
        ->and($issue($publish, 'clone'))->toThrow(ResourceOperationException::class, 'does not permit this token')
        ->and($issue($check, 'publish'))->toThrow(ResourceOperationException::class, 'does not permit this token')
        ->and($issue($provision, 'publish'))->toThrow(ResourceOperationException::class, 'does not permit this token')
        ->and($issue($elsewhere, 'clone'))->toThrow(ResourceOperationException::class, 'does not permit this token')
        ->and($socket->frames)->toBe([]);
});

it('fails with the publish code when the App is not installed on the repository', function (): void {
    tokenWorld(installed: false);
    [$operation] = pushOperation('tasks.publish');

    expect(fn () => app(TaskGitHubTokens::class)->issue($operation, SwampNodeFixture::Id, TOKEN_RUN, 'publish'))
        ->toThrow(fn (ResourceOperationException $exception) => expect($exception->getMessage())->toContain('cannot publish'));
});

// A prep run reaches its publish step 43 to 90 minutes after admission, a provision can wait for capacity before it clones, and an installation token lives one hour.
arch('the admission of a publish, a prep run and a provision mints nothing and writes no vault value for a repository credential')
    ->expect([TaskPublishIntent::class, TaskPrepIntent::class, TaskPrepFixIntent::class, TaskProvisionIntent::class])
    ->not->toUse([RepositoryPullRequestAccess::class, RepositoryReadAccess::class, GitHubApi::class]);

arch('the admission of a publish, of a prep run and of its corrections does not itself write a vault value')
    ->expect([TaskPublishIntent::class, TaskPrepIntent::class, TaskPrepFixIntent::class])
    ->not->toUse([SwampCommandStep::class]);

arch('the admission of a pull request step, a dependency merge and a cancel mints no token')
    ->expect([StartTaskPullRequestStepAction::class, StartDependencyBatchMergeAction::class, TaskCancelIntent::class])
    ->not->toUse([RepositoryPullRequestAccess::class, GitHubApi::class, GitHubAppStore::class]);

it('mints the write token of the merge of a pull request outside every task group, for the Project the operation targets', function (): void {
    $grants = [];
    $socket = tokenWorld(scoped: $grants);
    $project = Project::query()->firstOrCreate(['slug' => 'orbit'], ['name' => 'orbit', 'repository_url' => 'https://github.com/hardimpactdev/orbit.git', 'default_branch' => 'main', 'root' => '.']);
    $reference = ['vault' => 'orbit-control-inputs', 'key' => 'pr-merge-write-'.fake()->uuid()];
    $operation = Operation::query()->create(['kind' => 'project.pr_merge', 'target_type' => 'project', 'target_id' => $project->id, 'node_id' => SwampNodeFixture::Id,
        'status' => OperationStatus::Running, 'state' => ['swamp_run_id' => TOKEN_RUN],
        'input' => ['repository' => 'hardimpactdev/orbit', 'execution_secrets' => ['write_token' => $reference]]]);

    $copies = app(TaskGitHubTokens::class)->issue($operation, SwampNodeFixture::Id, TOKEN_RUN, 'pull_request');

    expect($copies)->toBe(['write_token' => $reference])
        ->and(tokenPuts($socket))->toBe([['orbit-control-inputs', $reference['key'], 'step-token-value']])
        ->and($grants[0]->permissions)->toBe(['contents' => 'write', 'pull_requests' => 'write']);
});

it('refuses the token of a project merge for another repository, another purpose, a finished operation or another caller', function (): void {
    $socket = tokenWorld();
    $project = Project::query()->firstOrCreate(['slug' => 'orbit'], ['name' => 'orbit', 'repository_url' => 'https://github.com/hardimpactdev/orbit.git', 'default_branch' => 'main', 'root' => '.']);
    $make = fn (string $repository, OperationStatus $status = OperationStatus::Running): Operation => Operation::query()->create(['kind' => 'project.pr_merge', 'target_type' => 'project',
        'target_id' => $project->id, 'node_id' => SwampNodeFixture::Id, 'status' => $status, 'state' => ['swamp_run_id' => TOKEN_RUN],
        'input' => ['repository' => $repository, 'execution_secrets' => ['write_token' => ['vault' => 'orbit-control-inputs', 'key' => 'pr-merge-write-'.fake()->uuid()]]]]);
    $issue = fn (Operation $subject, string $purpose = 'pull_request', int $caller = SwampNodeFixture::Id) => fn () => app(TaskGitHubTokens::class)->issue($subject, $caller, TOKEN_RUN, $purpose);

    expect($issue($make('someone/else')))->toThrow(ResourceOperationException::class, 'does not permit this token')
        ->and($issue($make('hardimpactdev/orbit'), 'clone'))->toThrow(ResourceOperationException::class, 'does not permit this token')
        ->and($issue($make('hardimpactdev/orbit', OperationStatus::Succeeded)))->toThrow(ResourceOperationException::class, 'does not permit this token')
        ->and($issue($make('hardimpactdev/orbit'), 'pull_request', SwampNodeFixture::Id + 1))->toThrow(ResourceOperationException::class, 'does not permit this token')
        ->and($socket->frames)->toBe([]);
});
