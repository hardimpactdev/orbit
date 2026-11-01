<?php

declare(strict_types=1);

use App\Actions\Instances\ResolveAutomaticDeployAdmissionAction;
use App\Domain\GitHub\GitHubApi;
use App\Domain\GitHub\GitHubApiException;
use App\Domain\GitHub\GitHubCheckRun;
use App\Domain\GitHub\GitHubCommit;
use App\Domain\GitHub\GitHubCommitComparison;
use App\Domain\GitHub\GitHubComparisonStatus;
use App\Domain\Instances\Deployment\AutomaticDeployHistory;
use App\Domain\Instances\Deployment\AutomaticDeployPolicyWriter;
use App\Domain\Instances\Deployment\DeploymentResult;
use App\Domain\Instances\Deployment\InstanceDeploymentRecorder;
use App\Domain\Instances\Environment\InstanceEnvironmentOperationLock;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\OperationRequestFingerprint;
use App\Domain\Operations\OperationStore;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\SourceControl\RemoteBranchHeads;
use App\Http\Requests\Operations\StartsOperation;
use App\Models\Activity;
use App\Models\InstanceDeployment;
use App\Models\Node;
use App\Models\Operation;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Mockery\MockInterface;
use Orbit\Sdk\Requests\Deployments\ResolveAutomaticDeployAdmissionRequest;
use Tests\Feature\GitHub\GitHubTestSupport;
use Tests\Support\Orb220DeploymentApiFixture;

beforeEach(function (): void {
    enable_operation_queue();
    Http::preventStrayRequests();
    $this->fixture = Orb220DeploymentApiFixture::create();
    $this->fixture->nativeStart();
    $this->fixture->instance->update(['auto_deploy' => true, 'deployment_branch' => 'release']);
    $this->url = '/api/v1/instances/'.$this->fixture->instance->id;
    $this->sha = str_repeat('b', 40);
    $this->base = str_repeat('a', 40);
    $this->withServerVariables(['REMOTE_ADDR' => $this->fixture->caller->wireguard_ip]);
});

function policy_publication(object $test): array
{
    return ['resumed_after' => 0, 'last_deployment_id' => 0, 'history_version' => new AutomaticDeployHistory($test->fixture->instance)->snapshot()->version, 'result' => 'gate_pending', 'commit' => $test->sha,
        'head' => $test->sha, 'since' => '2026-10-27T22:00:00+00:00', 'error_code' => null, 'message' => null, 'pause' => null];
}

function policy_source(object $test): MockInterface
{
    GitHubTestSupport::storeApp();
    $test->fixture->instance->project->update(['repository_url' => 'https://github.com/acme/shop.git', 'source_access' => 'github_app', 'merge_check' => 'Required checks']);
    $github = Mockery::mock(GitHubApi::class);
    $github->shouldReceive('repositoryInstallation')->andReturn(17);
    app()->instance(GitHubApi::class, $github);

    return $github;
}

/** Seed an existing receipt without admitting a new production deployment. */
function policy_receipt_operation(object $test, string $key, string $commit, string $command = 'instance:deploy:automatic'): Operation
{
    $route = Route::getRoutes()->getByName($command);
    $start = StartsOperation::forRoute($route);

    return Operation::query()->create([
        'kind' => 'instance.deploy',
        'idempotency_key' => $key,
        'idempotency_fingerprint' => app(OperationRequestFingerprint::class)->make(
            $test->fixture->caller,
            $route->getName(),
            ['instance' => $test->fixture->instance],
            json_encode(['commit' => $commit], JSON_THROW_ON_ERROR),
            $start,
        ),
        'target_type' => 'instance',
        'target_id' => $test->fixture->instance->id,
        'node_id' => $test->fixture->owner->id,
        'status' => 'queued',
        'sequence' => 1,
        'attempts' => 0,
        'input' => ['caller_node_id' => $test->fixture->caller->id, 'commit' => $commit],
    ]);
}

it('requires Gateway authority for every policy contract even with target Node access', function (string $method, string $path): void {
    $caller = Node::query()->create(['name' => 'services', 'status' => 'active', 'platform' => 'linux', 'public_ssh_host' => '192.0.2.222', 'wireguard_ip' => '10.44.0.222', 'user' => 'orbit']);
    $caller->accessibleNodes()->attach($this->fixture->owner->id);
    $this->withServerVariables(['REMOTE_ADDR' => $caller->wireguard_ip]);
    $this->json($method, $this->url.$path)->assertForbidden()->assertJsonPath('error.code', 'node_access.required');
    $this->assertDatabaseCount('operations', 0);
})->with([
    ['GET', '/deployment-source/head'], ['GET', '/deployment-source/commits'], ['GET', '/deployment-source/checks'],
    ['GET', '/deploy/automatic/receipt'], ['POST', '/deploy/automatic/resolve'], ['PUT', '/auto-deploy/policy'], ['POST', '/auto-deploy/events'],
]);

it('reads the fresh deployment branch head rather than cached policy state', function (): void {
    $heads = Mockery::mock(RemoteBranchHeads::class);
    $heads->shouldReceive('head')->once()->withArgs(fn ($project, $branch): bool => $project->is($this->fixture->instance->project) && $branch === 'release')->andReturn($this->sha);
    app()->instance(RemoteBranchHeads::class, $heads);
    $this->fixture->instance->update(['auto_deploy_state' => ['head' => $this->base]]);
    $this->getJson($this->url.'/deployment-source/head')->assertOk()->assertJsonPath('data.head', $this->sha);
    $this->assertDatabaseCount('operations', 0);
});

it('refreshes the Project environment template when it reads the default branch head', function (): void {
    $github = policy_source($this);
    $this->fixture->instance->update(['deployment_branch' => 'main']);
    $heads = Mockery::mock(RemoteBranchHeads::class);
    $heads->shouldReceive('head')->twice()->andReturn($this->sha);
    app()->instance(RemoteBranchHeads::class, $heads);
    $github->shouldReceive('repositoryReadToken')->once()->andReturn('contents-token');
    $github->shouldReceive('fileBlob')->once()->with('contents-token', Mockery::any(), $this->sha, '.env.example')->andReturn(str_repeat('1', 40));
    $github->shouldReceive('blobContents')->once()->andReturn("APP_NAME=Shop\n");

    $this->getJson($this->url.'/deployment-source/head')->assertOk()->assertJsonPath('data.head', $this->sha);
    $this->getJson($this->url.'/deployment-source/head')->assertOk()->assertJsonPath('data.head', $this->sha);

    expect($this->fixture->instance->project->environmentTemplate()->sole()->checked_head)->toBe($this->sha);
});

it('leaves the template alone for a deployment branch other than the default branch', function (): void {
    $github = policy_source($this);
    $heads = Mockery::mock(RemoteBranchHeads::class);
    $heads->shouldReceive('head')->once()->andReturn($this->sha);
    app()->instance(RemoteBranchHeads::class, $heads);

    $this->getJson($this->url.'/deployment-source/head')->assertOk();

    $github->shouldNotHaveReceived('fileBlob');
    expect($this->fixture->instance->project->environmentTemplate()->exists())->toBeFalse();
});

it('still returns the head when the template refresh cannot read GitHub', function (): void {
    $github = policy_source($this);
    $this->fixture->instance->update(['deployment_branch' => 'main']);
    $heads = Mockery::mock(RemoteBranchHeads::class);
    $heads->shouldReceive('head')->once()->andReturn($this->sha);
    app()->instance(RemoteBranchHeads::class, $heads);
    $github->shouldReceive('repositoryReadToken')->once()->andThrow(new GitHubApiException('unavailable'));

    $this->getJson($this->url.'/deployment-source/head')->assertOk()->assertJsonPath('data.head', $this->sha);
});

it('walks first parents with strict ancestry evidence and excludes the merge side', function (): void {
    $github = policy_source($this);
    $side = str_repeat('d', 40);
    $head = str_repeat('c', 40);
    $github->shouldReceive('repositoryReadToken')->once()->andReturn('contents-token');
    $github->shouldReceive('branchCommits')->once()->with('contents-token', Mockery::any(), 'release')->andReturn([
        new GitHubCommit($head, [$this->sha, $side]), new GitHubCommit($side, [$this->base]),
        new GitHubCommit($this->sha, [$this->base]), new GitHubCommit($this->base, []),
    ]);
    $github->shouldReceive('compareCommits')->once()->with('contents-token', Mockery::any(), $this->base, $head)->andReturn(new GitHubCommitComparison(GitHubComparisonStatus::Ahead, $this->base, $this->base));
    $github->shouldReceive('compareCommits')->once()->with('contents-token', Mockery::any(), $this->base, $this->sha)->andReturn(new GitHubCommitComparison(GitHubComparisonStatus::Diverged, $this->base, $side));
    $this->getJson($this->url.'/deployment-source/commits?base='.$this->base)->assertOk()
        ->assertJsonPath('data.head', $head)->assertJsonCount(2, 'data.commits')->assertJsonPath('data.reached_base', true)->assertJsonPath('data.truncated', false)
        ->assertJsonPath('data.commits.0.sha', $head)->assertJsonPath('data.commits.0.descends_from_deployed', true)
        ->assertJsonPath('data.commits.1.sha', $this->sha)->assertJsonPath('data.commits.1.descends_from_deployed', false);
});

it('returns exact check evidence including a wrong App and a wrong SHA without inferring green', function (): void {
    $github = policy_source($this);
    $github->shouldReceive('repositoryChecksToken')->once()->andReturn('checks-token');
    $github->shouldReceive('checkRuns')->once()->with('checks-token', Mockery::any(), $this->sha, 'Required checks')->andReturn([
        new GitHubCheckRun('Required checks', 'success', null, status: 'completed', headSha: $this->base, appSlug: 'another-app'),
    ]);
    $this->getJson($this->url.'/deployment-source/checks?commit='.$this->sha)->assertOk()->assertJsonPath('data.gate', 'Required checks')
        ->assertJsonPath('data.runs.0.head_sha', $this->base)->assertJsonPath('data.runs.0.app', 'another-app');
});

it('returns empty check evidence and fails closed on an upstream read error', function (): void {
    $github = policy_source($this);
    $github->shouldReceive('repositoryChecksToken')->twice()->andReturn('checks-token');
    $github->shouldReceive('checkRuns')->once()->andReturn([])->ordered();
    $github->shouldReceive('checkRuns')->once()->andThrow(new GitHubApiException('upstream token secret'))->ordered();
    $this->getJson($this->url.'/deployment-source/checks?commit='.$this->sha)->assertOk()->assertJsonPath('data.runs', []);
    $this->getJson($this->url.'/deployment-source/checks?commit='.$this->sha)->assertStatus(502)->assertJsonPath('error.code', 'instance.auto_deploy_gate_unavailable')->assertDontSee('upstream token secret');
});

it('rejects unsupported access and invalid source inputs', function (): void {
    $this->getJson($this->url.'/deployment-source/commits?base='.$this->base)->assertConflict()->assertJsonPath('error.code', 'instance.auto_deploy_gate_unreadable');
    $this->getJson($this->url.'/deployment-source/commits?base=main')->assertUnprocessable();
    $this->getJson($this->url.'/deployment-source/checks?commit=main')->assertUnprocessable();
});

it('exposes resume and rollback blocks without a tick and explicit history kind', function (): void {
    $instance = $this->fixture->instance;
    $instance->update(['auto_deploy_resumed_after' => 12, 'auto_deploy_state' => ['blocked' => [$this->base]]]);
    InstanceDeployment::query()->create(['instance_id' => $instance->id, 'kind' => 'deploy', 'status' => 'succeeded', 'commit' => $this->sha, 'started_at' => now()]);
    $rollback = InstanceDeployment::query()->create(['instance_id' => $instance->id, 'kind' => 'rollback', 'status' => 'succeeded', 'commit' => $this->base, 'started_at' => now()]);
    $this->getJson($this->url)->assertOk()->assertJsonPath('data.auto_deploy.resumed_after', 12)->assertJsonPath('data.auto_deploy.blocked', [$this->base, $this->sha]);
    $this->getJson($this->url.'/deployments')->assertOk()->assertJsonPath('data.0.kind', 'rollback')->assertJsonPath('data.1.kind', 'deploy');
    $this->getJson('/api/v1/deployments/'.$rollback->id)->assertOk()->assertJsonPath('data.kind', 'rollback');
});

it('reads exact lost-response receipts and terminal evidence without a new start', function (): void {
    $operation = policy_receipt_operation($this, 'auto.lost', $this->sha);
    $lookup = $this->url.'/deploy/automatic/receipt?request_key=auto.lost&commit='.$this->sha;
    $this->getJson($lookup)->assertOk()->assertJsonPath('data.found', true)->assertJsonPath('data.request_key', 'auto.lost')
        ->assertJsonPath('data.operation.id', $operation->id)->assertJsonPath('data.operation.input.caller_node_id', $this->fixture->caller->id)->assertJsonPath('data.deployment', null);
    $row = InstanceDeployment::query()->create(['instance_id' => $this->fixture->instance->id, 'kind' => 'deploy', 'status' => 'succeeded',
        'triggered_by' => 'swamp:auto-deploy', 'commit' => $this->sha, 'started_at' => now(), 'finished_at' => now()]);
    $operation->update(['status' => 'succeeded', 'result' => ['deployment_id' => $row->id], 'finished_at' => now(), 'lock_key' => null]);
    $this->fixture->instance->update(['auto_deploy' => false]);
    $this->getJson($lookup)->assertOk()->assertJsonPath('data.operation.status', 'succeeded')->assertJsonPath('data.deployment.kind', 'deploy')->assertJsonPath('data.deployment.commit', $this->sha);
    $this->assertDatabaseCount('operations', 1);
    expect($this->fixture->deployment->preparedCommits)->toBe([]);
});

it('does not admit work for a missing receipt or match another commit or manual command', function (): void {
    $lookup = $this->url.'/deploy/automatic/receipt?request_key=unknown&commit='.$this->sha;
    $this->getJson($lookup)->assertOk()->assertJsonPath('data.found', false)->assertJsonPath('data.operation', null);
    $this->assertDatabaseCount('operations', 0);
    policy_receipt_operation($this, 'unknown', $this->base, 'instance:deploy');
    $this->getJson($lookup)->assertConflict()->assertJsonPath('error.code', 'operation.idempotency_conflict');
    $this->getJson($this->url.'/deploy/automatic/receipt?request_key=unknown&commit='.$this->base)->assertConflict()->assertJsonPath('error.code', 'operation.idempotency_conflict');
    $this->assertDatabaseCount('operations', 1);
});

it('binds receipt reads to the original caller', function (): void {
    policy_receipt_operation($this, 'auto', $this->sha);
    $caller = Node::query()->create(['name' => 'services', 'status' => 'active', 'platform' => 'linux', 'public_ssh_host' => '192.0.2.222', 'wireguard_ip' => '10.44.0.222', 'user' => 'orbit']);
    $caller->accessibleNodes()->attach($this->fixture->caller->id);
    $this->withServerVariables(['REMOTE_ADDR' => $caller->wireguard_ip]);
    $this->getJson($this->url.'/deploy/automatic/receipt?request_key=auto&commit='.$this->sha)->assertConflict()->assertJsonPath('error.code', 'operation.idempotency_conflict');
});

it('preserves worker-lost outcomes with missing deployment evidence', function (): void {
    policy_receipt_operation($this, 'lost.worker', $this->sha);
    Operation::query()->sole()->update(['status' => 'failed', 'error_code' => 'operation.worker_lost']);
    $this->getJson($this->url.'/deploy/automatic/receipt?request_key=lost.worker&commit='.$this->sha)->assertOk()
        ->assertJsonPath('data.operation.error_code', 'operation.worker_lost')->assertJsonPath('data.deployment', null);
    $this->assertDatabaseCount('operations', 1);
});

it('refuses automatic admission publication and delivery while PHP owns the policy', function (): void {
    app()->instance(AutomaticDeployPolicyWriter::class, AutomaticDeployPolicyWriter::Gateway);
    $this->getJson($this->url)->assertOk()->assertJsonPath('data.auto_deploy.policy_owner', 'gateway');
    $this->withHeader('Idempotency-Key', 'deploy')->postJson($this->url.'/deploy/automatic', ['commit' => $this->sha])->assertConflict()->assertJsonPath('error.code', 'instance.auto_deploy_policy_owner');
    $this->putJson($this->url.'/auto-deploy/policy', policy_publication($this))->assertConflict()->assertJsonPath('error.code', 'instance.auto_deploy_policy_owner');
    $this->withHeader('Idempotency-Key', 'event')->postJson($this->url.'/auto-deploy/events', ['event' => 'activity', 'result' => 'started'])->assertConflict()->assertJsonPath('error.code', 'instance.auto_deploy_policy_owner');
    $this->assertDatabaseCount('operations', 0);
    expect($this->fixture->instance->fresh()->auto_deploy_state)->toBeNull();
});

it('publishes through the shared Instance projection under the shipped Swamp ownership', function (): void {
    $this->fixture->instance->update(['auto_deploy_state' => ['blocked' => [$this->base]]]);
    $body = policy_publication($this);
    $body['pause'] = ['reason' => 'rollback', 'deployment_id' => 19, 'commit' => $this->sha, 'paused_at' => $body['since']];
    $body['result'] = 'paused';
    $this->putJson($this->url.'/auto-deploy/policy', $body)->assertOk()->assertJsonPath('data.auto_deploy.result', 'paused')
        ->assertJsonPath('data.auto_deploy.pause.deployment_id', 19)->assertJsonPath('data.auto_deploy.blocked', [$this->base]);
    $this->getJson($this->url)->assertOk()->assertJsonPath('data.auto_deploy.since', $body['since']);
    expect($this->fixture->instance->fresh()->auto_deploy_state)->not->toHaveKeys(['resumed_after', 'last_deployment_id', 'history_version', 'intent']);
});

it('rejects publication after resume or history changes and rejects owner injection', function (string $change): void {
    $body = policy_publication($this);
    if ($change === 'resume') {
        $this->fixture->instance->update(['auto_deploy_resumed_after' => 17]);
    } else {
        InstanceDeployment::query()->create(['instance_id' => $this->fixture->instance->id, 'kind' => 'deploy', 'status' => 'running', 'started_at' => now()]);
    }
    $this->putJson($this->url.'/auto-deploy/policy', $body)->assertConflict()->assertJsonPath('error.code', 'instance.auto_deploy_observation_changed');
    $this->putJson($this->url.'/auto-deploy/policy', [...$body, 'owner' => 'swamp'])->assertUnprocessable();
    expect($this->fixture->instance->fresh()->auto_deploy_state)->toBeNull();
})->with(['resume', 'history']);

it('requires stable event keys and validates inputs before admission', function (): void {
    $this->postJson($this->url.'/auto-deploy/events', ['event' => 'activity', 'result' => 'started'])->assertUnprocessable();
    $this->withHeader('Idempotency-Key', 'event.invalid')->postJson($this->url.'/auto-deploy/events', ['event' => 'failed'])->assertUnprocessable();
    $this->withHeader('Idempotency-Key', 'event.invalid')->postJson($this->url.'/auto-deploy/events', ['event' => 'stalled', 'commit' => 'main'])->assertUnprocessable();
    $this->withHeader('Idempotency-Key', 'event.invalid')->postJson($this->url.'/auto-deploy/events', ['event' => 'activity', 'result' => 'started', 'target' => 'gateway'])->assertUnprocessable();
    $this->assertDatabaseCount('operations', 0);
});

it('keeps policy delivery independent of an Instance deployment lock', function (): void {
    $deployment = policy_receipt_operation($this, 'deploy.busy', $this->sha);
    $deployment->update(['lock_key' => OperationStore::lockKey('instance', $this->fixture->instance->id)]);
    $this->withHeader('Idempotency-Key', 'event.busy')->postJson($this->url.'/auto-deploy/events', ['event' => 'activity', 'result' => 'busy'])->assertAccepted()->assertJsonPath('data.target_type', 'instance_policy');
    expect($deployment->fresh()->lock_key)->toBe('instance:'.$this->fixture->instance->id);
    $this->assertDatabaseCount('operations', 2);
});

it('requires valid receipt identity and conflicts on a changed automatic commit', function (): void {
    $this->getJson($this->url.'/deploy/automatic/receipt?request_key=bad/key&commit='.$this->sha)->assertUnprocessable();
    $this->getJson($this->url.'/deploy/automatic/receipt?request_key=valid&commit=main')->assertUnprocessable();
    policy_receipt_operation($this, 'auto.commit', $this->sha);
    $this->getJson($this->url.'/deploy/automatic/receipt?request_key=auto.commit&commit='.$this->base)->assertConflict()->assertJsonPath('error.code', 'operation.idempotency_conflict');
    $this->assertDatabaseCount('operations', 1);
});

it('bounds source reads and stops at a missing first parent', function (): void {
    $github = policy_source($this);
    $rows = [];
    for ($index = 30; $index >= 1; $index--) {
        $rows[] = new GitHubCommit(str_pad(dechex($index), 40, '0', STR_PAD_LEFT), [str_pad(dechex($index - 1), 40, '0', STR_PAD_LEFT)]);
    }
    $github->shouldReceive('repositoryReadToken')->twice()->andReturn('contents-token');
    $github->shouldReceive('branchCommits')->once()->andReturn($rows)->ordered();
    $github->shouldReceive('branchCommits')->once()->andReturn([new GitHubCommit($this->sha, [str_repeat('e', 40)])])->ordered();
    $github->shouldReceive('compareCommits')->times(21)->andReturn(new GitHubCommitComparison(GitHubComparisonStatus::Ahead, $this->base, $this->base));
    $this->getJson($this->url.'/deployment-source/commits?base='.$this->base)->assertOk()->assertJsonCount(20, 'data.commits')->assertJsonPath('data.reached_base', false)->assertJsonPath('data.truncated', true);
    $this->getJson($this->url.'/deployment-source/commits?base='.$this->base)->assertOk()->assertJsonCount(1, 'data.commits')->assertJsonPath('data.reached_base', false)->assertJsonPath('data.truncated', true);
});

it('publishes deploying state while a deployment holds the host lock', function (): void {
    $held = new class implements InstanceEnvironmentOperationLock
    {
        public function run(array $instanceIds, Closure $operation): mixed
        {
            throw new ResourceOperationException('env.operation_busy', 'The deployment holds the host lock.', 409);
        }
    };
    app()->instance(InstanceEnvironmentOperationLock::class, $held);
    $body = [...policy_publication($this), 'result' => 'deploying'];
    $this->putJson($this->url.'/auto-deploy/policy', $body)->assertOk()->assertJsonPath('data.auto_deploy.result', 'deploying');
    expect($this->fixture->instance->fresh()->auto_deploy_state['result'])->toBe('deploying');
});

it('rejects a publication observed before an existing deployment finished', function (): void {
    $recorder = app(InstanceDeploymentRecorder::class);
    $row = $recorder->start($this->fixture->instance, 'orbit:auto-deploy');
    $body = [...policy_publication($this), 'last_deployment_id' => $row->id, 'result' => 'deploying'];
    $this->getJson($this->url)->assertOk()->assertJsonPath('data.auto_deploy.history_version', $body['history_version']);
    $recorder->finish($row, DeploymentResult::checkedOut($this->sha), []);
    expect(InstanceDeployment::query()->max('id'))->toBe($row->id);
    $this->putJson($this->url.'/auto-deploy/policy', $body)->assertConflict()->assertJsonPath('error.code', 'instance.auto_deploy_observation_changed');
    expect($this->fixture->instance->fresh()->auto_deploy_state)->toBeNull();
    $body['history_version'] = $this->getJson($this->url)->assertOk()->json('data.auto_deploy.history_version');
    $body['result'] = 'up_to_date';
    $this->putJson($this->url.'/auto-deploy/policy', $body)->assertOk()->assertJsonPath('data.auto_deploy.result', 'up_to_date');
});

it('keeps the history version through caller renames and removals but changes it for a new deployment', function (): void {
    $caller = Node::query()->create(['name' => 'services', 'status' => 'active', 'platform' => 'linux', 'public_ssh_host' => '192.0.2.222', 'wireguard_ip' => '10.44.0.222', 'user' => 'orbit']);
    $row = InstanceDeployment::query()->create([
        'instance_id' => $this->fixture->instance->id, 'kind' => 'deploy', 'status' => 'succeeded',
        'commit' => $this->sha, 'started_at' => now(), 'triggered_by' => 'swamp:auto-deploy', 'caller_node_id' => $caller->id,
    ]);
    $version = $this->getJson($this->url)->assertOk()->json('data.auto_deploy.history_version');
    $body = [...policy_publication($this), 'last_deployment_id' => $row->id, 'history_version' => $version];

    $caller->update(['name' => 'renamed-services']);
    $this->getJson($this->url)->assertOk()->assertJsonPath('data.auto_deploy.history_version', $version);
    $this->putJson($this->url.'/auto-deploy/policy', $body)->assertOk();
    $caller->delete();
    $this->getJson($this->url)->assertOk()->assertJsonPath('data.auto_deploy.history_version', $version);
    $this->putJson($this->url.'/auto-deploy/policy', $body)->assertOk();

    app(InstanceDeploymentRecorder::class)->start($this->fixture->instance, 'swamp:auto-deploy', callerNodeId: $this->fixture->caller->id);
    $changed = $this->getJson($this->url)->assertOk()->json('data.auto_deploy.history_version');
    expect($changed)->not->toBe($version);
    $this->putJson($this->url.'/auto-deploy/policy', $body)->assertConflict()->assertJsonPath('error.code', 'instance.auto_deploy_observation_changed');
});

it('preserves a committed publication when a rollback records its terminal blocks', function (): void {
    InstanceDeployment::query()->create(['instance_id' => $this->fixture->instance->id, 'kind' => 'deploy', 'status' => 'succeeded', 'commit' => $this->sha, 'started_at' => now()]);
    $recorder = app(InstanceDeploymentRecorder::class);
    $row = $recorder->start($this->fixture->instance, 'operator', 'rollback');
    $body = [...policy_publication($this), 'last_deployment_id' => $row->id, 'result' => 'paused'];
    $this->putJson($this->url.'/auto-deploy/policy', $body)->assertOk();
    $recorder->finish($row, DeploymentResult::checkedOut($this->base), []);
    $this->getJson($this->url)->assertOk()->assertJsonPath('data.auto_deploy.result', 'paused')->assertJsonPath('data.auto_deploy.blocked', [$this->sha]);
});

it('skips policy history reads and hides blocks when automatic deployment is off', function (): void {
    $this->fixture->instance->update(['auto_deploy' => false, 'auto_deploy_state' => ['blocked' => [$this->sha]]]);
    $reads = 0;
    DB::listen(function (QueryExecuted $query) use (&$reads): void {
        if (str_starts_with(strtolower($query->sql), 'select') && str_contains($query->sql, 'instance_deployments')) {
            $reads++;
        }
    });
    $this->getJson($this->url)->assertOk()->assertJsonPath('data.auto_deploy.blocked', [])->assertJsonPath('data.auto_deploy.history_version', null);
    expect($reads)->toBe(0);
});

final class ReceiptAdmissionSettingsController
{
    #[StartsOperation('instance.deploy', bindCaller: false, maxJsonBytes: 1024)]
    public function automatic(): void {}
}

it('derives receipt fingerprints from the live admission route attribute', function (): void {
    policy_receipt_operation($this, 'settings', $this->sha);
    $route = Route::getRoutes()->getByName('instance:deploy:automatic');
    $route->setAction([...$route->getAction(), 'uses' => ReceiptAdmissionSettingsController::class.'@automatic', 'controller' => ReceiptAdmissionSettingsController::class.'@automatic']);
    $route->flushController();
    $start = StartsOperation::forRoute($route);
    $fingerprint = app(OperationRequestFingerprint::class)->make($this->fixture->caller, $route->getName(), ['instance' => $this->fixture->instance], json_encode(['commit' => $this->sha], JSON_THROW_ON_ERROR), $start);
    Operation::query()->sole()->update(['idempotency_fingerprint' => $fingerprint]);
    $this->getJson($this->url.'/deploy/automatic/receipt?request_key=settings&commit='.$this->sha)->assertOk()->assertJsonPath('data.found', true);
});

it('accepts full SHA256 identities in source and receipt reads', function (): void {
    $base = str_repeat('a', 64);
    $head = str_repeat('b', 64);
    $github = policy_source($this);
    $github->shouldReceive('repositoryReadToken')->once()->andReturn('contents-token');
    $github->shouldReceive('branchCommits')->once()->andReturn([new GitHubCommit($head, [$base])]);
    $github->shouldReceive('compareCommits')->once()->with('contents-token', Mockery::any(), $base, $head)->andReturn(new GitHubCommitComparison(GitHubComparisonStatus::Ahead, $base, $base));
    $github->shouldReceive('repositoryChecksToken')->once()->andReturn('checks-token');
    $github->shouldReceive('checkRuns')->once()->with('checks-token', Mockery::any(), $head, 'Required checks')->andReturn([]);
    $this->getJson($this->url.'/deployment-source/commits?base='.$base)->assertOk()->assertJsonPath('data.reached_base', true)->assertJsonPath('data.truncated', false);
    $this->getJson($this->url.'/deployment-source/checks?commit='.$head)->assertOk();
    $this->getJson($this->url.'/deploy/automatic/receipt?request_key=sha256&commit='.$head)->assertOk()->assertJsonPath('data.found', false);
});

it('distinguishes an already deployed head from a root that cannot reach the base', function (): void {
    $github = policy_source($this);
    $github->shouldReceive('repositoryReadToken')->twice()->andReturn('contents-token');
    $github->shouldReceive('branchCommits')->once()->andReturn([new GitHubCommit($this->base, [])])->ordered();
    $github->shouldReceive('branchCommits')->once()->andReturn([new GitHubCommit($this->sha, [])])->ordered();
    $github->shouldReceive('compareCommits')->once()->andReturn(new GitHubCommitComparison(GitHubComparisonStatus::Diverged, $this->base, $this->base));
    $this->getJson($this->url.'/deployment-source/commits?base='.$this->base)->assertOk()->assertJsonPath('data.reached_base', true)->assertJsonPath('data.truncated', false)->assertJsonCount(0, 'data.commits');
    $this->getJson($this->url.'/deployment-source/commits?base='.$this->base)->assertOk()->assertJsonPath('data.reached_base', false)->assertJsonPath('data.truncated', false)->assertJsonCount(1, 'data.commits');
});

it('exposes the shipped owner and accepts Swamp automatic admission', function (): void {
    $this->getJson($this->url)->assertOk()->assertJsonPath('data.auto_deploy.policy_owner', 'swamp');
    $this->withHeader('Idempotency-Key', 'swamp')->postJson($this->url.'/deploy/automatic', ['commit' => $this->sha])->assertAccepted();
    expect(Operation::query()->sole()->triggered_by)->toBe('swamp:auto-deploy');
});

it('returns 409 without automatic admission when the app-prod role is not active', function (?string $status): void {
    $roles = $this->fixture->owner->roles()->where('role', RoleName::AppProd);
    $status === null ? $roles->delete() : $roles->update(['status' => $status]);

    $this->withHeader('Idempotency-Key', 'inactive-role')->postJson($this->url.'/deploy/automatic', ['commit' => $this->sha])
        ->assertConflict()->assertJsonPath('error.code', 'instance.auto_deploy_role_inactive');

    $this->assertDatabaseCount('operations', 0);
    $this->assertDatabaseCount('instance_deployments', 0);
    $this->assertDatabaseCount('jobs', 0);
})->with(['provisioning', 'failed', 'removing', null]);

it('discovers only eligible automatic production Instances', function (?string $roleStatus, bool $enabled, string $state, string $role, int $count): void {
    $roles = $this->fixture->owner->roles()->where('role', RoleName::AppProd);
    $roleStatus === null ? $roles->delete() : $roles->update(['status' => $roleStatus, 'role' => $role]);
    $this->fixture->instance->update(['auto_deploy' => $enabled, 'status' => $state]);

    $response = $this->getJson('/api/v1/instances?auto_deploy=true')->assertOk()->assertJsonCount($count, 'data');
    if ($count === 1) {
        $response->assertJsonPath('data.0.id', $this->fixture->instance->id);
    }
    $this->getJson('/api/v1/instances')->assertOk()->assertJsonCount(1, 'data');
})->with([
    'eligible' => ['active', true, 'active', 'app-prod', 1],
    'provisioning role' => ['provisioning', true, 'active', 'app-prod', 0],
    'failed role' => ['failed', true, 'active', 'app-prod', 0],
    'removing role' => ['removing', true, 'active', 'app-prod', 0],
    'absent role' => [null, true, 'active', 'app-prod', 0],
    'disabled' => ['active', false, 'active', 'app-prod', 0],
    'inactive Instance' => ['active', true, 'reserved', 'app-prod', 0],
    'development Node' => ['active', true, 'active', 'app-dev', 0],
]);

it('keeps automatic discovery within the caller Node access', function (): void {
    $caller = Node::query()->create(['name' => 'discovery-peer', 'status' => 'active', 'platform' => 'linux', 'public_ssh_host' => '192.0.2.223', 'wireguard_ip' => '10.44.0.223', 'user' => 'orbit']);
    $this->withServerVariables(['REMOTE_ADDR' => $caller->wireguard_ip]);
    $this->getJson('/api/v1/instances?auto_deploy=true')->assertForbidden()->assertJsonPath('error.code', 'node_access.required');

    $caller->accessibleNodes()->attach($this->fixture->owner->id);
    $this->getJson('/api/v1/instances?auto_deploy=true')->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $this->fixture->instance->id);
});

it('fences a missing admission and replays the cancelled receipt instead of deploying late', function (): void {
    $body = ['request_key' => 'lost', 'commit' => $this->sha];
    $id = $this->postJson($this->url.'/deploy/automatic/resolve', $body)->assertOk()->assertJsonPath('data.found', true)
        ->assertJsonPath('data.operation.status', 'cancelled')->assertJsonPath('data.operation.error_code', 'operation.admission_fenced')->json('data.operation.id');
    $this->postJson($this->url.'/deploy/automatic/resolve', $body)->assertOk()->assertJsonPath('data.operation.id', $id);
    $this->withHeader('Idempotency-Key', 'lost')->postJson($this->url.'/deploy/automatic', ['commit' => $this->sha])->assertAccepted()->assertJsonPath('data.id', $id);
    $this->getJson($this->url.'/deploy/automatic/receipt?request_key=lost&commit='.$this->sha)->assertOk()->assertJsonPath('data.operation.id', $id);
    run_operation_jobs();
    $this->assertDatabaseCount('operations', 1);
    $this->assertDatabaseCount('instance_deployments', 0);
    expect(Operation::query()->sole()->lock_key)->toBeNull()->and(Operation::query()->sole()->attempts)->toBe(0);
    expect(Activity::query()->where('command', 'instance:deploy:automatic:resolve')->where('status', 'succeeded')->exists())->toBeTrue();
});

it('resolves an admitted request without changing its outcome or admitting another', function (): void {
    $id = $this->withHeader('Idempotency-Key', 'admitted')->postJson($this->url.'/deploy/automatic', ['commit' => $this->sha])->assertAccepted()->json('data.id');
    $before = Operation::query()->findOrFail($id)->getAttributes();
    $this->postJson($this->url.'/deploy/automatic/resolve', ['request_key' => 'admitted', 'commit' => $this->sha])->assertOk()->assertJsonPath('data.operation.id', $id)->assertJsonPath('data.operation.status', 'running');
    expect(Operation::query()->findOrFail($id)->getAttributes())->toBe($before);
    $this->postJson($this->url.'/deploy/automatic/resolve', ['request_key' => 'admitted', 'commit' => $this->base])->assertConflict()->assertJsonPath('error.code', 'operation.idempotency_conflict');
    $this->assertDatabaseCount('operations', 1);
});

it('validates resolution identity before fencing any admission', function (): void {
    $this->postJson($this->url.'/deploy/automatic/resolve', [])->assertUnprocessable();
    $this->postJson($this->url.'/deploy/automatic/resolve', ['request_key' => 'invalid key', 'commit' => 'main'])->assertUnprocessable();
    $this->assertDatabaseCount('operations', 0);
});

it('resolves the original caller identity explicitly and audits both Nodes', function (): void {
    $original = $this->fixture->caller;
    $actor = Node::query()->create(['name' => 'operator', 'status' => 'active', 'platform' => 'linux', 'public_ssh_host' => '192.0.2.223', 'wireguard_ip' => '10.44.0.223', 'user' => 'orbit']);
    $actor->accessibleNodes()->attach($original->id);
    $this->withServerVariables(['REMOTE_ADDR' => $actor->wireguard_ip]);
    $body = ['request_key' => 'operator-fenced', 'commit' => $this->sha, 'caller_node_id' => $original->id];
    $id = $this->postJson($this->url.'/deploy/automatic/resolve', $body)->assertOk()->assertJsonPath('data.operation.input.caller_node_id', $original->id)->json('data.operation.id');
    $activity = Activity::query()->where('command', 'instance:deploy:automatic:resolve')->sole();
    expect($activity->getAttribute('caller_node_id'))->toBe($actor->id)
        ->and($activity->properties->get('automatic_deploy_resolution'))->toBe(['caller_node_id' => $original->id]);
    $this->postJson($this->url.'/deploy/automatic/resolve', ['request_key' => 'operator-fenced', 'commit' => $this->sha])->assertConflict()->assertJsonPath('error.code', 'operation.idempotency_conflict');
    $this->withServerVariables(['REMOTE_ADDR' => $original->wireguard_ip]);
    $this->getJson($this->url.'/deploy/automatic/receipt?request_key=operator-fenced&commit='.$this->sha)->assertOk()->assertJsonPath('data.operation.id', $id);
    $this->withHeader('Idempotency-Key', 'operator-fenced')->postJson($this->url.'/deploy/automatic', ['commit' => $this->sha])->assertAccepted()->assertJsonPath('data.id', $id);
    $this->assertDatabaseCount('operations', 1);
    $this->assertDatabaseCount('instance_deployments', 0);
});

it('does not let another caller resolve an admitted request without naming its owner', function (): void {
    $id = $this->withHeader('Idempotency-Key', 'caller-bound')->postJson($this->url.'/deploy/automatic', ['commit' => $this->sha])->assertAccepted()->json('data.id');
    $actor = Node::query()->create(['name' => 'operator', 'status' => 'active', 'platform' => 'linux', 'public_ssh_host' => '192.0.2.223', 'wireguard_ip' => '10.44.0.223', 'user' => 'orbit']);
    $actor->accessibleNodes()->attach($this->fixture->caller->id);
    $this->withServerVariables(['REMOTE_ADDR' => $actor->wireguard_ip]);
    $body = ['request_key' => 'caller-bound', 'commit' => $this->sha];
    $this->postJson($this->url.'/deploy/automatic/resolve', $body)->assertConflict()->assertJsonPath('error.code', 'operation.idempotency_conflict');
    $this->postJson($this->url.'/deploy/automatic/resolve', [...$body, 'caller_node_id' => $this->fixture->caller->id])->assertOk()->assertJsonPath('data.operation.id', $id)->assertJsonPath('data.operation.status', 'running');
    $this->assertDatabaseCount('operations', 1);
});

it('records the automatic resolution response fixture', function (): void {
    $this->travelTo(Carbon::parse('2026-01-01T00:00:00Z'));
    $response = $this->withHeader('X-Orbit-Request-Id', fixture_request_id())->postJson($this->url.'/deploy/automatic/resolve', ['request_key' => 'lost.key', 'commit' => $this->sha])->assertOk();
    record_fixture($response, 'instances/instance-deploy-automatic-resolve/fenced', ResolveAutomaticDeployAdmissionRequest::class, 'POST /api/v1/instances/{instance}/deploy/automatic/resolve');
});

it('returns the fence when resolution wins after the admission preflight read', function (): void {
    $reads = 0;
    $fenced = null;
    DB::listen(function (QueryExecuted $query) use (&$reads, &$fenced): void {
        if (! str_starts_with($query->sql, 'select * from "operations" where "kind" =') || ++$reads !== 2) {
            return;
        }
        $fenced = app(ResolveAutomaticDeployAdmissionAction::class)->execute($this->fixture->instance, $this->fixture->caller, 'race', $this->sha, fixture_request_id());
    });

    $response = $this->withHeader('Idempotency-Key', 'race')->postJson($this->url.'/deploy/automatic', ['commit' => $this->sha])->assertAccepted();

    expect($fenced)->not->toBeNull();
    $response->assertJsonPath('data.id', $fenced->operation->id)->assertJsonPath('data.error_code', 'operation.admission_fenced');
    $this->assertDatabaseCount('operations', 1);
    $this->assertDatabaseCount('jobs', 0);
    run_operation_jobs();
    $this->assertDatabaseCount('instance_deployments', 0);
});
