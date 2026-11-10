<?php

declare(strict_types=1);

use App\Domain\Fleet\FleetReboot;
use App\Domain\Fleet\FleetRebootGitHubTokens;
use App\Domain\GitHub\GitHubAccessToken;
use App\Domain\GitHub\GitHubApi;
use App\Domain\GitHub\GitHubAppCredentials;
use App\Domain\GitHub\GitHubAppStore;
use App\Domain\GitHub\GitHubRepository;
use App\Domain\GitHub\GitHubTokenGrant;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Models\Operation;
use App\Models\Project;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Mockery\MockInterface;
use Tests\Support\SwampNodeFixture;

uses(LazilyRefreshDatabase::class);

const REBOOT_RUN = '6b69badf-4f06-4f2e-82d7-21b62c1f5871';

beforeEach(function (): void {
    SwampNodeFixture::ensure();
    app(GitHubAppStore::class)->put(new GitHubAppCredentials(4242, 'push', 'push', 'hardimpactdev', 'organization', 'https://github.com/apps/push', 'unused'));
    $this->grants = [];
    app()->instance(GitHubApi::class, Mockery::mock(GitHubApi::class, function (MockInterface $github): void {
        // The App is installed on hardimpactdev only.
        $github->shouldReceive('repositoryInstallation')->andReturnUsing(static fn ($credentials, GitHubRepository $repository): ?int => $repository->owner === 'hardimpactdev' ? 7 : null);
        $github->shouldReceive('scopedRepositoryToken')->andReturnUsing(function ($credentials, GitHubTokenGrant $grant): GitHubAccessToken {
            $this->grants[] = [$grant->repository->owner.'/'.$grant->repository->name, $grant->permissions];

            return new GitHubAccessToken('actions-token-'.$grant->repository->name, '2026-11-10T08:00:00Z');
        });
    }));
    $this->frames = [];
    $socket = Mockery::mock(WebSocketClient::class);
    $socket->shouldReceive('connect', 'close')->andReturnNull();
    $socket->shouldReceive('isConnected')->andReturnTrue();
    $socket->shouldReceive('send')->andReturnUsing(function (array $frame): void {
        $this->frames[] = $frame;
    });
    $socket->shouldReceive('receive')->andReturnUsing(function (): array {
        $frame = end($this->frames);

        return [['id' => $frame['id'], 'type' => 'vault.put', 'payload' => ['data' => ['vaultName' => $frame['payload']['vaultName'],
            'secretKey' => $frame['payload']['key'], 'vaultType' => 'local_encryption']]]];
    });
    app()->instance(WebSocketClient::class, $socket);
    config(['swamp.address' => 'swamp.test', 'swamp.ca_path' => '/tmp/ca.pem', 'swamp.run_token' => 'run-token']);
});

function rebootTokenPass(string $status = 'running', array $repositories = ['hardimpactdev/orbit', 'other/elsewhere']): Operation
{
    return Operation::query()->create(['kind' => FleetReboot::Kind, 'target_type' => 'fleet', 'target_id' => 1, 'node_id' => SwampNodeFixture::Id,
        'status' => $status, 'state' => ['swamp_run_id' => REBOOT_RUN], 'input' => ['intent' => ['ci_repositories' => $repositories]]]);
}

it('mints one Actions write token per admitted repository the App is installed on, into the pass vault key', function (): void {
    $pass = rebootTokenPass();

    $copies = app(FleetRebootGitHubTokens::class)->issue($pass, SwampNodeFixture::Id, REBOOT_RUN, 'ci_rerun');

    expect($this->grants)->toBe([['hardimpactdev/orbit', ['actions' => 'write']]])
        ->and($copies)->toBe(['github_actions' => ['vault' => 'orbit-control-inputs', 'key' => 'fleet-reboot-github-operation-'.$pass->id]])
        ->and($this->frames[0]['payload']['key'])->toBe('fleet-reboot-github-operation-'.$pass->id)
        ->and(json_decode($this->frames[0]['payload']['value'], true))->toBe(['hardimpactdev/orbit' => 'actions-token-orbit']);
});

it('mints only for the running pass, on the Swamp Node, for its own run, with the rerun purpose', function (string $case): void {
    $pass = rebootTokenPass($case === 'finished' ? 'succeeded' : 'running');
    if ($case === 'cancel') {
        $pass->update(['cancel_requested_at' => now()]);
    }
    [$caller, $run, $purpose] = match ($case) {
        'caller' => [SwampNodeFixture::Id + 1, REBOOT_RUN, 'ci_rerun'],
        'purpose' => [SwampNodeFixture::Id, REBOOT_RUN, 'publish'],
        'no-purpose' => [SwampNodeFixture::Id, REBOOT_RUN, null],
        'run' => [SwampNodeFixture::Id, '6b69badf-4f06-4f2e-82d7-21b62c1f5872', 'ci_rerun'],
        default => [SwampNodeFixture::Id, REBOOT_RUN, 'ci_rerun'],
    };

    expect(fn () => app(FleetRebootGitHubTokens::class)->issue($pass, $caller, $run, $purpose))
        ->toThrow(fn (ResourceOperationException $exception) => expect($exception->errorCode)->toBeIn(['operation.github_token_forbidden', 'operation.run_mismatch']))
        ->and($this->grants)->toBe([]);
})->with(['caller', 'purpose', 'no-purpose', 'finished', 'cancel', 'run']);

it('lets a grant ask for Actions write, which the App already holds', function (): void {
    expect(GitHubTokenGrant::from(['repository' => 'hardimpactdev/orbit', 'permissions' => ['actions' => 'write']])->permissions)->toBe(['actions' => 'write']);
});

it('admits the GitHub repositories of the Projects read through the App as the CI repositories', function (): void {
    Project::query()->create(['slug' => 'orbit', 'name' => 'orbit', 'repository_url' => 'https://github.com/hardimpactdev/orbit.git', 'default_branch' => 'main', 'root' => '.']);
    Project::query()->create(['slug' => 'mealou', 'name' => 'mealou', 'repository_url' => 'git@github.com:hardimpactdev/mealou.git', 'default_branch' => 'main', 'root' => '.']);
    Project::query()->create(['slug' => 'cli', 'name' => 'cli', 'repository_url' => 'https://github.com/nckrtl/private.git', 'default_branch' => 'main', 'root' => '.', 'source_access' => 'gh_cli']);

    expect(FleetReboot::ciRepositories())->toBe(['hardimpactdev/mealou', 'hardimpactdev/orbit']);
});
