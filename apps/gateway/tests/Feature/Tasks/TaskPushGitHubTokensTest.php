<?php

declare(strict_types=1);

use App\Domain\GitHub\GitHubApi;
use App\Domain\GitHub\GitHubAppCredentials;
use App\Domain\GitHub\GitHubAppStore;
use App\Domain\GitHub\RepositoryPullRequestAccess;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskPrepIntent;
use App\Domain\Tasks\TaskPublishIntent;
use App\Domain\Tasks\TaskPushGitHubTokens;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\AgentView\WebSocketEndpoint;
use App\Infrastructure\Swamp\SwampCommandStep;
use App\Models\Operation;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Mockery\MockInterface;
use Tests\Support\SwampNodeFixture;

uses(LazilyRefreshDatabase::class);

const PUSH_RUN = '6b69badf-4f06-4f2e-82d7-21b62c1f587a';

/** A GitHub that serves one push token, and a Swamp socket that records every vault put. */
function pushWorld(bool $installed = true): object
{
    SwampNodeFixture::ensure();
    app(GitHubAppStore::class)->put(new GitHubAppCredentials(4242, 'push', 'push', 'hardimpactdev', 'organization', 'https://github.com/apps/push', 'unused'));
    app()->instance(GitHubApi::class, Mockery::mock(GitHubApi::class, function (MockInterface $github) use ($installed): void {
        $github->shouldReceive('repositoryInstallation')->andReturn($installed ? 7 : null);
        $github->shouldReceive('repositoryPullRequestToken')->andReturn('push-token-value');
    }));
    $socket = new class implements WebSocketClient
    {
        /** @var list<array<string,mixed>> */
        public array $frames = [];

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

/** @return array{0:Operation,1:array<string,array{vault:string,key:string}>} */
function pushOperation(string $kind, array $input = [], OperationStatus $status = OperationStatus::Running): array
{
    $project = Project::query()->firstOrCreate(['slug' => 'orbit'], ['name' => 'orbit', 'repository_url' => 'https://github.com/hardimpactdev/orbit.git', 'default_branch' => 'main', 'root' => '.']);
    $group = Task::query()->create(['project_id' => $project->id, 'title' => 'Push', 'brief' => 'Push it', 'status' => TaskGroupStatus::Running]);
    $names = ['github_token' => ['vault' => 'orbit-control-inputs', 'key' => 'task-publish-token-'.fake()->uuid()],
        'git_authorization' => ['vault' => 'orbit-control-inputs', 'key' => 'task-publish-token-'.fake()->uuid()]];
    $input = $input + ($kind === 'tasks.prep'
        ? ['publish' => ['repository' => 'hardimpactdev/orbit'], 'publish_secrets' => $names]
        : ['repository' => 'hardimpactdev/orbit', 'execution_secrets' => $names]);
    $operation = Operation::query()->create(['kind' => $kind, 'target_type' => 'task_group', 'target_id' => $group->id, 'node_id' => SwampNodeFixture::Id,
        'status' => $status, 'state' => ['swamp_run_id' => PUSH_RUN], 'input' => $input]);

    return [$operation, $names];
}

it('mints the push token of a publish when its step asks, and writes it only under the admitted vault keys', function (string $kind): void {
    $socket = pushWorld();
    [$operation, $names] = pushOperation($kind);

    $copies = app(TaskPushGitHubTokens::class)->issue($operation, SwampNodeFixture::Id, PUSH_RUN);

    expect($copies)->toBe($names)
        ->and(array_map(fn (array $frame): array => [$frame['payload']['vaultName'], $frame['payload']['key'], $frame['payload']['value']], $socket->frames))
        ->toBe([['orbit-control-inputs', $names['github_token']['key'], 'push-token-value'],
            ['orbit-control-inputs', $names['git_authorization']['key'], 'AUTHORIZATION: basic '.base64_encode('x-access-token:push-token-value')]])
        ->and(json_encode($copies))->not->toContain('push-token-value');
})->with(['a publish' => ['tasks.publish'], 'a prep run' => ['tasks.prep']]);

it('lets a publish and a prep run ask for the token at the numeric Operation mint', function (string $kind): void {
    expect(app(DirectOperation::class)->definition($kind)['github_token_issuer'])->toBe(TaskPushGitHubTokens::class);
})->with(['tasks.publish', 'tasks.prep']);

// A prep run reaches its publish step 43 to 90 minutes after admission, and an installation token lives one hour.
arch('the admission of a publish and of a prep run mints nothing and writes no vault value')
    ->expect([TaskPublishIntent::class, TaskPrepIntent::class])
    ->not->toUse([RepositoryPullRequestAccess::class, SwampCommandStep::class, GitHubApi::class]);

it('refuses a run that is not the Operation run, a caller that is not Swamp, and an Operation that ended', function (): void {
    $socket = pushWorld();
    [$operation] = pushOperation('tasks.publish');
    $ended = pushOperation('tasks.publish', status: OperationStatus::Failed)[0];
    $issue = fn (Operation $subject, int $caller, string $run) => fn () => app(TaskPushGitHubTokens::class)->issue($subject, $caller, $run);

    expect($issue($operation, SwampNodeFixture::Id, 'ffffffff-4f06-4f2e-82d7-21b62c1f587a'))->toThrow(ResourceOperationException::class, 'another Swamp run')
        ->and($issue($operation, 12, PUSH_RUN))->toThrow(ResourceOperationException::class, 'does not permit a push token')
        ->and($issue($ended, SwampNodeFixture::Id, PUSH_RUN))->toThrow(ResourceOperationException::class, 'does not permit a push token')
        ->and($socket->frames)->toBe([]);
});

it('refuses a repository that is not the Project repository, and a kind that holds no push token', function (): void {
    $socket = pushWorld();
    [$other] = pushOperation('tasks.publish', ['repository' => 'someone/else']);
    [$check] = pushOperation('tasks.check');

    expect(fn () => app(TaskPushGitHubTokens::class)->issue($other, SwampNodeFixture::Id, PUSH_RUN))->toThrow(ResourceOperationException::class, 'does not permit a push token')
        ->and(fn () => app(TaskPushGitHubTokens::class)->issue($check, SwampNodeFixture::Id, PUSH_RUN))->toThrow(ResourceOperationException::class, 'does not permit a push token')
        ->and($socket->frames)->toBe([]);
});

it('fails with the publish code when the App is not installed on the repository', function (): void {
    pushWorld(installed: false);
    [$operation] = pushOperation('tasks.publish');

    expect(fn () => app(TaskPushGitHubTokens::class)->issue($operation, SwampNodeFixture::Id, PUSH_RUN))
        ->toThrow(fn (ResourceOperationException $exception) => expect($exception->getMessage())->toContain('cannot publish'));
});
