<?php

declare(strict_types=1);

use App\Domain\Fleet\RecipePrProjection;
use App\Domain\Gateway\GatewayServingHost;
use App\Domain\GitHub\GitHubApi;
use App\Domain\GitHub\GitHubCheckRun;
use App\Domain\GitHub\GitHubListedPullRequest;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\OperationKinds;
use App\Domain\Operations\OperationStatus;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Models\Node;
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
    $this->worker = $worker;
    $worker->accessibleNodes()->attach($this->gateway);
    app(GatewayServingHost::class)->remember($this->gateway);
    Node::query()->forceCreate(['id' => 209, 'name' => 'swamp-executor', 'status' => 'active', 'platform' => 'linux', 'architecture' => 'x86_64', 'public_ssh_host' => '192.0.2.209', 'wireguard_ip' => '10.44.0.209', 'user' => 'orbit']);
    config(['dependency_detection.services_node_id' => $worker->id]);
    $this->withServerVariables(['REMOTE_ADDR' => $this->gateway->wireguard_ip]);
    $this->run = 'a075eb23-7ac4-4487-80d1-57a038a92676';
    $this->input = ['action' => 'retire', 'variant' => 'snapshot', 'date' => now('Europe/Amsterdam')->format('Ymd'), 'pull_number' => 99,
        'head_sha' => str_repeat('a', 40), 'author' => 'orbit-acme[bot]', 'required_check' => 'Required checks', 'deployed_recipe' => file_get_contents(resource_path('machine-software/recipe.json'))];
    $this->operation = Operation::query()->create(['kind' => 'fleet.recipe_pr', 'target_type' => 'recipe_pr', 'target_id' => 99, 'node_id' => $this->gateway->id,
        'status' => OperationStatus::Queued, 'sequence' => 1, 'input' => $this->input, 'state' => ['swamp_run_id' => $this->run]]);
    $this->path = '/api/v1/direct-operations/'.$this->operation->id.'/result';
    $this->body = ['run_id' => $this->run, 'status' => 'succeeded', 'started_at' => '2026-10-30T19:00:00.000Z', 'finished_at' => '2026-10-30T19:00:01.000Z',
        'result' => ['completed' => true, 'outcome' => 'checked', 'url' => 'https://github.com/hardimpactdev/orbit/pull/99']];
});

it('admits through the owning API and mints one scoped token into the vault at run start', function (): void {
    $this->withServerVariables(['REMOTE_ADDR' => $this->worker->wireguard_ip]);
    config(['dependency_detection.services_node_id' => null, 'operation_executor.worker_node_ids' => []]);
    $socket = recipe_pr_start($this->run);
    $response = $this->postJson('/api/v1/fleet/recipe-pr', array_intersect_key($this->input, array_flip(['action', 'variant', 'date', 'pull_number', 'head_sha'])))->assertAccepted();
    $operation = Operation::findOrFail($response->json('data.id'));
    $start = $socket->sent[1]['payload']['inputs'];
    expect($socket->sent)->toHaveCount(2)->and($socket->sent[0]['payload'])->toMatchArray(['vaultName' => 'orbit-control-inputs',
        'key' => 'fleet-recipe-pr-'.$operation->id, 'value' => 'private-recipe-sentinel'])
        ->and($start['intent'])->toEqual($this->input)
        ->and($start['execution_secrets'])->toBe(['github_token' => ['vault' => 'orbit-control-inputs', 'key' => 'fleet-recipe-pr-'.$operation->id]])
        ->and(json_encode($start))->not->toContain('private-recipe-sentinel')
        ->and($start['node_id'])->toBe(209)
        ->and($start['operation_id'])->toBe($operation->id)
        ->and($operation->state['swamp_run_id'])->toBe($this->run)
        ->and($operation->input)->not->toHaveKey('token')->and($operation->input['author'])->toBe('orbit-acme[bot]')
        ->and($operation->input['deployed_recipe'])->toBe($this->input['deployed_recipe'])
        ->and($operation->input)->not->toHaveKey('github')
        ->and($operation->attempts)->toBe(0)->and($operation->worker)->toBeNull()->and($operation->lock_key)->toBeNull();
    expect(app(OperationKinds::class)->definition('fleet.recipe_pr'))->not->toBeNull();
    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/access_tokens')
        && $request->data() === ['repositories' => ['orbit'], 'permissions' => RecipePrProjection::Grant['permissions']]);
});

it('accepts a stable producer key within the HTTP contract and replays its original direct intent', function (): void {
    $this->withServerVariables(['REMOTE_ADDR' => $this->worker->wireguard_ip]);
    $socket = recipe_pr_start($this->run);
    $key = 'fleet:recipe-pr:'.$this->input['date'].':retire:99:'.hash('sha256', json_encode([$this->input['head_sha'], ['observation']], JSON_THROW_ON_ERROR));
    $body = array_intersect_key($this->input, array_flip(['action', 'variant', 'date', 'pull_number', 'head_sha']));
    $first = $this->withHeader('Idempotency-Key', $key)->postJson('/api/v1/fleet/recipe-pr', $body)->assertAccepted()->json('data.id');
    $this->withHeader('Idempotency-Key', $key)->postJson('/api/v1/fleet/recipe-pr', $body)->assertAccepted()->assertJsonPath('data.id', $first);
    // One run start: one vault copy and one workflow start; the replay starts nothing.
    expect($socket->sent)->toHaveCount(2);
});

it('reports the serving recipe revision without execution state', function (): void {
    $this->withServerVariables(['REMOTE_ADDR' => $this->worker->wireguard_ip]);
    TaskVmSettingsBuilder::bind();
    $github = Mockery::mock(GitHubApi::class);
    $github->shouldReceive('repositoryInstallation')->once()->andReturn(9);
    $github->shouldReceive('repositoryReadToken')->once()->andReturn('private-read-token');
    $github->shouldReceive('openPullRequests')->once()->andReturn([]);
    app()->instance(GitHubApi::class, $github);
    Http::preventStrayRequests();
    Http::fake(['*/git/ref/heads/main' => Http::response(['object' => ['sha' => str_repeat('b', 40)]]),
        '*/contents/apps/gateway/resources/machine-software/recipe.json*' => Http::response(['sha' => str_repeat('c', 40)])]);
    $response = $this->getJson('/api/v1/fleet/recipe-pr/plan')->assertOk();
    expect($response->json('data.serving_recipe_revision'))->toBe(hash_file('sha256', resource_path('machine-software/recipe.json')))
        ->and($response->json('data.recipe_revision'))->toBe(hash('sha256', str_repeat('c', 40)))
        ->and($response->json('data.pulls'))->toBe([])
        ->and($response->getContent())->not->toContain('private-read-token');
});

it('keeps recipe and check observations stable across unrelated main commits', function (): void {
    $this->withServerVariables(['REMOTE_ADDR' => $this->worker->wireguard_ip]);
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
    Http::fake(function (Request $request) use (&$main, &$blob) {
        if (str_ends_with($request->url(), '/git/ref/heads/main')) {
            return Http::response(['object' => ['sha' => $main]]);
        }
        expect($request['ref'])->toBe($main);

        return Http::response(['sha' => $blob]);
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

it('rejects worker-selected identities credentials and stale actions at admission', function (): void {
    $this->withServerVariables(['REMOTE_ADDR' => $this->worker->wireguard_ip]);
    $body = array_intersect_key($this->input, array_flip(['action', 'variant', 'date', 'pull_number', 'head_sha']));
    $this->postJson('/api/v1/fleet/recipe-pr', [...$body, 'date' => '20000101'])->assertUnprocessable();
    $this->postJson('/api/v1/fleet/recipe-pr', [...$body, 'action' => 'exec'])->assertUnprocessable();
    expect(Operation::query()->count())->toBe(1);
});

it('exposes only safe admitted input and refuses any family execution primitive', function (): void {
    $this->operation->update(['input' => [...$this->input, 'token' => 'PRIVATE']]);
    $response = $this->getJson('/api/v1/direct-operations/'.$this->operation->id.'?run_id='.$this->run)->assertOk();
    expect($response->json('data.input'))->toBe(['intent' => $this->input]);
    $this->postJson('/api/v1/direct-operations/'.$this->operation->id.'/primitive', ['run_id' => $this->run])->assertUnprocessable();
    expect(array_keys(app(RecipePrProjection::class)->input($this->operation)))->not->toContain('token');
});

it('refuses a per-step mint because the run reads its run-start token from the vault', function (): void {
    Http::preventStrayRequests();
    $this->withServerVariables(['REMOTE_ADDR' => $this->gateway->wireguard_ip]);
    $this->postJson('/api/v1/direct-operations/'.$this->operation->id.'/github-token', ['run_id' => $this->run])
        ->assertForbidden()->assertJsonPath('error.code', 'operation.github_token_forbidden');
    Http::assertNothingSent();
});

it('projects an idempotent bounded result and discards undeclared diagnostics', function (): void {
    $body = [...$this->body, 'result' => [...$this->body['result'], 'diagnostic' => 'PRIVATE']];
    $this->postJson($this->path, $body)->assertOk();
    $this->postJson($this->path, $body)->assertOk();
    expect($this->operation->fresh()->result)->toBe($this->body['result'])->and($this->operation->fresh()->status)->toBe(OperationStatus::Succeeded)
        ->and($this->operation->fresh()->state)->not->toHaveKeys(['executor_runs', 'executor_receipts']);
});

it('refuses a wrong run without revalidating native recipe policy', function (): void {
    $this->postJson($this->path, [...$this->body, 'run_id' => 'fabd4059-fb6c-49d8-940e-01ae58aa3f38'])->assertConflict();
    expect($this->operation->fresh()->status)->toBe(OperationStatus::Queued);
});

it('refuses an unbound first read before any token or result request', function (): void {
    $this->operation->update(['state' => []]);
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
    ['failed', 'fleet.recipe_pr_credential_unavailable'],
    ['cancelled', null],
]);

it('preserves a failed outcome and admits a fresh run with its own token', function (): void {
    $this->operation->update(['status' => OperationStatus::Failed, 'error_code' => 'operation.native_failed', 'finished_at' => now()]);
    $this->withServerVariables(['REMOTE_ADDR' => $this->worker->wireguard_ip]);
    recipe_pr_start('f55044a2-bde6-482d-84c9-87fae6fe3269');
    $body = array_intersect_key($this->input, array_flip(['action', 'variant', 'date', 'pull_number', 'head_sha']));
    $response = $this->withHeader('Idempotency-Key', 'recipe-explicit-fresh-admission')->postJson('/api/v1/fleet/recipe-pr', $body)->assertAccepted();
    expect($response->json('data.id'))->not->toBe($this->operation->id);
    expect($this->operation->fresh()->status)->toBe(OperationStatus::Failed);
});

/** A Swamp socket that accepts the run-start vault copy and the start, with GitHub minting one scoped token. */
function recipe_pr_start(string $run): FakeRoutePublicationSocket
{
    $socket = new FakeRoutePublicationSocket;
    $socket->reply = fn (array $m): array => $m['type'] === 'vault.put'
        ? [['type' => 'vault.put', 'id' => $m['id'], 'payload' => ['data' => ['vaultName' => $m['payload']['vaultName'], 'secretKey' => $m['payload']['key'], 'vaultType' => 'local_encryption']]]]
        : [['type' => 'event', 'id' => $m['id'], 'event' => ['kind' => 'started', 'workflowName' => 'operation-fleet-recipe-pr', 'runId' => $run]]];
    app()->instance(WebSocketClient::class, $socket);
    config(['swamp.run_token' => 'fake.token']);
    Http::fake(['*/installation' => Http::response(['id' => 7]), '*/access_tokens' => Http::response(['token' => 'private-recipe-sentinel', 'expires_at' => now()->addHour()->format('Y-m-d\TH:i:s\Z'),
        'permissions' => [...RecipePrProjection::Grant['permissions'], 'metadata' => 'read']], 201),
        '*/installation/repositories*' => Http::response(['total_count' => 1, 'repositories' => [['full_name' => 'hardimpactdev/orbit']]])]);

    return $socket;
}
