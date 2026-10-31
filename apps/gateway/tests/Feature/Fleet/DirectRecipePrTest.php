<?php

declare(strict_types=1);

use App\Actions\Fleet\AdmitRecipePrAction;
use App\Domain\Fleet\RecipePrProjection;
use App\Domain\Gateway\GatewayServingHost;
use App\Domain\GitHub\GitHubApi;
use App\Domain\GitHub\GitHubApiException;
use App\Domain\GitHub\GitHubCheckRun;
use App\Domain\GitHub\GitHubListedPullRequest;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\OperationExecutionContract;
use App\Domain\Operations\OperationKinds;
use App\Domain\Operations\OperationStatus;
use App\Domain\Operations\SwampOperationExecutor;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Models\Operation;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Feature\GitHub\GitHubTestSupport;
use Tests\Support\FakeRoutePublicationSocket;
use Tests\Support\Fleet\FleetFixtures;
use Tests\Support\TaskVmSettingsBuilder;

beforeEach(function (): void {
    FleetFixtures::bind();
    FleetFixtures::adopt(FleetFixtures::Commit);
    GitHubTestSupport::storeApp();
    $this->gateway = FleetFixtures::node('gateway-recipe', [RoleName::Gateway]);
    $worker = FleetFixtures::node('worker-recipe');
    $worker->accessibleNodes()->attach($this->gateway);
    app(GatewayServingHost::class)->remember($this->gateway);
    config(['dependency_detection.services_node_id' => $worker->id]);
    $this->withServerVariables(['REMOTE_ADDR' => $worker->wireguard_ip]);
    $this->run = 'a075eb23-7ac4-4487-80d1-57a038a92676';
    $this->input = ['action' => 'retire', 'variant' => 'snapshot', 'date' => now('Europe/Amsterdam')->format('Ymd'), 'pull_number' => 99,
        'head_sha' => str_repeat('a', 40), 'author' => 'orbit-acme[bot]', 'required_check' => 'Required checks', 'deployed_recipe' => file_get_contents(resource_path('machine-software/recipe.json'))];
    $this->operation = Operation::query()->create(['kind' => 'fleet.recipe_pr', 'target_type' => 'recipe_pr', 'target_id' => 99, 'node_id' => $this->gateway->id,
        'status' => OperationStatus::Queued, 'sequence' => 1, 'input' => [...$this->input, 'github' => ['$secret' => 'github.installation', 'id' => $this->gateway->id]], 'state' => ['recipe_pr_writer' => 'direct', 'swamp_run_id' => $this->run]]);
    $this->path = '/api/v1/direct-operations/'.$this->operation->id.'/result';
    $this->body = ['run_id' => $this->run, 'status' => 'succeeded', 'started_at' => '2026-10-30T19:00:00.000Z', 'finished_at' => '2026-10-30T19:00:01.000Z',
        'result' => ['completed' => true, 'outcome' => 'checked', 'url' => 'https://github.com/hardimpactdev/orbit/pull/99']];
});

it('admits through the owning API into one direct start without credential hydration', function (): void {
    $socket = new FakeRoutePublicationSocket;
    $socket->reply = fn (array $m): array => [['type' => 'event', 'id' => $m['id'], 'event' => [
        'kind' => 'started', 'workflowName' => 'operation-fleet-recipe-pr', 'runId' => $this->run]]];
    app()->instance(WebSocketClient::class, $socket);
    config(['swamp.run_token' => 'fake.token']);
    $response = $this->postJson('/api/v1/fleet/recipe-pr', array_intersect_key($this->input, array_flip(['action', 'variant', 'date', 'pull_number', 'head_sha'])))->assertAccepted();
    $operation = Operation::findOrFail($response->json('data.id'));
    expect($socket->sent)->toHaveCount(1)->and($socket->sent[0]['payload']['inputs'])->toBe(['operation_id' => $operation->id, 'kind' => 'fleet.recipe_pr', 'node_id' => $this->gateway->id])
        ->and($operation->state['recipe_pr_writer'])->toBe('direct')->and($operation->state['swamp_run_id'])->toBe($this->run)
        ->and($operation->input)->not->toHaveKey('token')->and($operation->input['author'])->toBe('orbit-acme[bot]')
        ->and($operation->input['deployed_recipe'])->toBe($this->input['deployed_recipe'])
        ->and($operation->input['github'])->toBe(['$secret' => 'github.installation', 'id' => $this->gateway->id])
        ->and($operation->attempts)->toBe(0)->and($operation->worker)->toBeNull()->and($operation->lock_key)->toBeNull();
    expect(app(OperationKinds::class)->directOnly('fleet.recipe_pr'))->toBeTrue();
});

it('refuses an explicit deleted writer before recording intent', function (): void {
    config(['swamp.direct_writers' => ['fleet.recipe_pr' => 'polled']]);
    expect(fn () => app(AdmitRecipePrAction::class)->execute('retire', 'snapshot', 99, str_repeat('a', 40), $this->input['date']))->toThrow(ResourceOperationException::class);
    expect(Operation::query()->count())->toBe(1);
});

it('accepts a stable producer key within the HTTP contract and replays its original direct intent', function (): void {
    $socket = new FakeRoutePublicationSocket;
    $socket->reply = fn (array $m): array => [['type' => 'event', 'id' => $m['id'], 'event' => [
        'kind' => 'started', 'workflowName' => 'operation-fleet-recipe-pr', 'runId' => $this->run]]];
    app()->instance(WebSocketClient::class, $socket);
    config(['swamp.run_token' => 'fake.token']);
    $key = 'fleet:recipe-pr:'.$this->input['date'].':retire:99:'.hash('sha256', json_encode([$this->input['head_sha'], ['observation']], JSON_THROW_ON_ERROR));
    $body = array_intersect_key($this->input, array_flip(['action', 'variant', 'date', 'pull_number', 'head_sha']));
    $first = $this->withHeader('Idempotency-Key', $key)->postJson('/api/v1/fleet/recipe-pr', $body)->assertAccepted()->json('data.id');
    $this->withHeader('Idempotency-Key', $key)->postJson('/api/v1/fleet/recipe-pr', $body)->assertAccepted()->assertJsonPath('data.id', $first);
    expect($socket->sent)->toHaveCount(1);
});

it('reports the admitted writer and serving recipe revision without execution state', function (): void {
    TaskVmSettingsBuilder::bind();
    $github = Mockery::mock(GitHubApi::class);
    $github->shouldReceive('repositoryInstallation')->once()->andReturn(9);
    $github->shouldReceive('repositoryReadToken')->once()->andReturn('private-read-token');
    $github->shouldReceive('openPullRequests')->once()->andReturn([]);
    $github->shouldReceive('branchHead')->once()->andReturn(str_repeat('b', 40));
    $github->shouldReceive('fileBlobSha')->once()->andReturn(str_repeat('c', 40));
    app()->instance(GitHubApi::class, $github);
    Http::preventStrayRequests();
    $response = $this->getJson('/api/v1/fleet/recipe-pr/plan')->assertOk();
    expect($response->json('data.writer'))->toBe('direct')
        ->and($response->json('data.serving_recipe_revision'))->toBe(hash_file('sha256', resource_path('machine-software/recipe.json')))
        ->and($response->json('data.recipe_revision'))->toBe(hash('sha256', str_repeat('c', 40)))
        ->and($response->json('data.pulls'))->toBe([])
        ->and($response->getContent())->not->toContain('private-read-token');
});

it('keeps recipe and check observations stable across unrelated main commits', function (): void {
    TaskVmSettingsBuilder::bind();
    $main = str_repeat('b', 40);
    $blob = str_repeat('c', 40);
    $head = str_repeat('a', 40);
    $runs = [new GitHubCheckRun('Required checks', 'success', null, id: 1, status: 'completed', headSha: $head, appSlug: 'github-actions')];
    $github = Mockery::mock(GitHubApi::class);
    $github->shouldReceive('repositoryInstallation')->andReturn(9);
    $github->shouldReceive('repositoryReadToken')->andReturn('private-read-token');
    $github->shouldReceive('repositoryChecksToken')->andReturn('private-checks-token');
    $draft = true;
    $base = 'main';
    $source = 'hardimpactdev/orbit';
    $github->shouldReceive('openPullRequests')->andReturnUsing(function () use ($head, &$draft, &$base, &$source): array {
        return [new GitHubListedPullRequest(99, 'https://github.com/hardimpactdev/orbit/pull/99', 'recipe', null, 1,
            'orbit-acme[bot]', 'nightly/recipe-20261030', $head, $source, $base, $draft)];
    });
    $github->shouldReceive('checkRuns')->andReturnUsing(function () use (&$runs): array {
        return $runs;
    });
    app()->instance(GitHubApi::class, $github);
    Http::preventStrayRequests();
    $github->shouldReceive('branchHead')->andReturnUsing(function () use (&$main): string {
        return $main;
    });
    $github->shouldReceive('fileBlobSha')->andReturnUsing(function (string $token, $repository, string $path, string $commit) use (&$main, &$blob): string {
        expect($commit)->toBe($main);

        return $blob;
    });
    $read = fn (): array => $this->getJson('/api/v1/fleet/recipe-pr/plan')->assertOk()->json('data');
    $first = $read();
    expect($first['pulls'][0]['checks'])->toBe('passed');
    $main = str_repeat('d', 40);
    $next = $read();
    expect($next['base_revision'])->not->toBe($first['base_revision'])
        ->and($next['recipe_revision'])->toBe($first['recipe_revision'])
        ->and($next['pulls'][0]['checks_revision'])->toBe($first['pulls'][0]['checks_revision']);
    $draft = false;
    $ready = $read();
    expect($ready['pulls'][0]['policy_revision'])->not->toBe($first['pulls'][0]['policy_revision'])
        ->and($ready['pulls'][0]['head_sha'])->toBe($head)
        ->and($ready['pulls'][0]['checks_revision'])->toBe($first['pulls'][0]['checks_revision']);
    $base = 'release';
    $retargeted = $read();
    expect($retargeted['pulls'][0]['policy_revision'])->not->toBe($ready['pulls'][0]['policy_revision']);
    $source = 'other/orbit';
    expect($read()['pulls'][0]['policy_revision'])->not->toBe($retargeted['pulls'][0]['policy_revision']);
    $runs = [new GitHubCheckRun('Required checks', 'failure', null, id: 2, status: 'completed', headSha: $head, appSlug: 'github-actions')];
    $changed = $read();
    expect($changed['pulls'][0]['checks'])->toBe('failed')
        ->and($changed['pulls'][0]['checks_revision'])->not->toBe($first['pulls'][0]['checks_revision']);
    $blob = str_repeat('e', 40);
    $recipe = $read();
    expect($recipe['recipe_revision'])->not->toBe($first['recipe_revision'])
        ->and($recipe['serving_recipe_revision'])->toBe($first['serving_recipe_revision'])
        ->and(json_encode($recipe))->not->toContain('private-read-token', 'private-checks-token');
});

it('requests checks authority once for all eligible recipe candidates', function (bool $readable): void {
    TaskVmSettingsBuilder::bind();
    $github = Mockery::mock(GitHubApi::class);
    $github->shouldReceive('repositoryInstallation')->twice()->andReturn(9);
    $github->shouldReceive('repositoryReadToken')->once()->andReturn('private-read-token');
    $github->shouldReceive('branchHead')->once()->andReturn(str_repeat('b', 40));
    $github->shouldReceive('fileBlobSha')->once()->andReturn(str_repeat('c', 40));
    $checks = $github->shouldReceive('repositoryChecksToken')->once();
    if ($readable) {
        $checks->andReturn('private-checks-token');
        $github->shouldReceive('checkRuns')->twice()->andReturn([]);
    } else {
        $checks->andThrow(GitHubApiException::unavailable());
    }
    $github->shouldReceive('openPullRequests')->once()->andReturn([
        new GitHubListedPullRequest(99, 'https://github.com/hardimpactdev/orbit/pull/99', 'recipe', null, 1,
            'orbit-acme[bot]', 'nightly/recipe-20261031', str_repeat('a', 40), 'hardimpactdev/orbit', 'main', false),
        new GitHubListedPullRequest(100, 'https://github.com/hardimpactdev/orbit/pull/100', 'artifacts', null, 1,
            'orbit-acme[bot]', 'nightly/artifacts-123456abcdef', str_repeat('d', 40), 'hardimpactdev/orbit', 'main', false),
    ]);
    app()->instance(GitHubApi::class, $github);
    Http::preventStrayRequests();

    $response = $this->getJson('/api/v1/fleet/recipe-pr/plan')->assertOk();
    expect($response->json('data.pulls'))->toHaveCount(2);
    if (! $readable) {
        expect(array_column($response->json('data.pulls'), 'checks'))->toBe(['unreadable', 'unreadable']);
    }
    expect($response->getContent())->not->toContain('private-read-token', 'private-checks-token');
})->with([true, false]);

it('rejects worker-selected identities credentials and stale actions at admission', function (): void {
    $body = array_intersect_key($this->input, array_flip(['action', 'variant', 'date', 'pull_number', 'head_sha']));
    $this->postJson('/api/v1/fleet/recipe-pr', [...$body, 'date' => '20000101'])->assertUnprocessable();
    $this->postJson('/api/v1/fleet/recipe-pr', [...$body, 'action' => 'exec'])->assertUnprocessable();
    expect(Operation::query()->count())->toBe(1);
});

it('exposes only safe admitted input and refuses any family execution primitive', function (): void {
    $this->operation->update(['input' => [...$this->input, 'token' => 'PRIVATE']]);
    $response = $this->getJson('/api/v1/direct-operations/'.$this->operation->id.'?run_id='.$this->run)->assertOk();
    expect($response->json('data.input'))->toBe($this->input);
    $this->postJson('/api/v1/direct-operations/'.$this->operation->id.'/primitive', ['run_id' => $this->run])->assertUnprocessable();
    expect(array_keys(app(RecipePrProjection::class)->input($this->operation)))->not->toContain('token');
});

it('mints only the fixed recipe repository grant for its bound own-Node run', function (): void {
    Http::preventStrayRequests();
    $permissions = ['contents' => 'write', 'pull_requests' => 'write', 'checks' => 'read'];
    Http::fake(['*/repos/hardimpactdev/orbit/installation' => Http::response(['id' => 7]),
        '*/access_tokens' => Http::response(['token' => 'recipe-ephemeral-sentinel', 'expires_at' => now()->addHour()->format('Y-m-d\TH:i:s\Z'),
            'permissions' => [...$permissions, 'metadata' => 'read'], 'repositories' => [['full_name' => 'hardimpactdev/orbit']]], 201)]);
    $this->withServerVariables(['REMOTE_ADDR' => $this->gateway->wireguard_ip]);
    $this->getJson('/api/v1/direct-operations/'.$this->operation->id.'/private-material?run_id='.$this->run)
        ->assertOk()->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonPath('data.github.repository', 'hardimpactdev/orbit')->assertJsonPath('data.github.permissions', $permissions);
    Http::assertSentCount(2);
    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/access_tokens')
        && $request->data() === ['repositories' => ['orbit'], 'permissions' => $permissions]);
    expect(json_encode($this->operation->fresh()->toArray(), JSON_THROW_ON_ERROR))->not->toContain('recipe-ephemeral-sentinel');
});

it('projects an idempotent bounded result and discards undeclared diagnostics', function (): void {
    $body = [...$this->body, 'result' => [...$this->body['result'], 'diagnostic' => 'PRIVATE']];
    $this->postJson($this->path, $body)->assertOk();
    $this->postJson($this->path, $body)->assertOk();
    expect($this->operation->fresh()->result)->toBe($this->body['result'])->and($this->operation->fresh()->status)->toBe(OperationStatus::Succeeded)
        ->and($this->operation->fresh()->state)->not->toHaveKeys(['executor_runs', 'executor_receipts']);
});

it('rejects foreign PR URLs incomplete success and wrong runs', function (): void {
    foreach ([['completed' => false, 'outcome' => 'checked'], ['completed' => true, 'outcome' => 'unknown'], [...$this->body['result'], 'url' => 'https://foreign.test/PRIVATE']] as $result) {
        $this->postJson($this->path, [...$this->body, 'result' => $result])->assertUnprocessable();
    }
    $this->postJson($this->path, [...$this->body, 'run_id' => 'fabd4059-fb6c-49d8-940e-01ae58aa3f38'])->assertConflict();
    expect($this->operation->fresh()->status)->toBe(OperationStatus::Queued);
});

it('refuses an unbound first read before any token or result request', function (): void {
    $this->operation->update(['state' => ['recipe_pr_writer' => 'direct']]);
    $this->getJson('/api/v1/direct-operations/'.$this->operation->id.'?run_id='.$this->run)->assertConflict();
    $this->postJson($this->path, $this->body)->assertConflict();
});

it('projects only matching bounded refusal codes and cancellation', function (string $status, ?string $code): void {
    $result = $code === null ? [] : ['error_code' => $code];
    $this->postJson($this->path, [...$this->body, 'status' => $status, 'error_code' => $code, 'result' => $result])->assertOk();
    expect($this->operation->fresh()->status->value)->toBe($status);
})->with([
    ['failed', 'fleet.recipe_pr_stale'],
    ['failed', 'fleet.recipe_pending_deployment'],
    ['failed', 'fleet.recipe_pr_policy_refused'],
    ['cancelled', null],
]);

it('refuses old claim execution and receipt completion after deleting the executor', function (): void {
    $this->operation->update(['state' => []]);
    expect(fn () => app(SwampOperationExecutor::class)->claim('fleet.recipe_pr', 'old', $this->gateway->id, $this->operation->id))->toThrow(ResourceOperationException::class);
    expect(fn () => app(OperationExecutionContract::class)->context($this->operation))->toThrow(ResourceOperationException::class)
        ->and(fn () => app(OperationExecutionContract::class)->assertCompletion($this->operation, []))->toThrow(ResourceOperationException::class);
});
