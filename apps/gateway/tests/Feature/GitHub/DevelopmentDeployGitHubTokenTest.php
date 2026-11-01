<?php

declare(strict_types=1);

use App\Actions\Instances\StartInstanceDeployAction;
use App\Domain\Operations\OperationStatus;
use App\Domain\Projects\ProjectSourceAccess;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Models\Activity;
use App\Models\Node;
use App\Models\Operation;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Feature\GitHub\GitHubTestSupport;
use Tests\Support\FakeRoutePublicationSocket;

beforeEach(function (): void {
    Http::preventStrayRequests();
    config(['swamp.run_token' => 'synthetic-run-token']);
    app()->instance(WebSocketClient::class, new FakeRoutePublicationSocket);
    $this->instance = dev935_instance('native-dev-mint');
    $this->instance->project->update(['repository_url' => 'https://github.com/example/owned.git']);
    $this->operation = app(StartInstanceDeployAction::class)->execute($this->instance->fresh(), 'operator', null);
    $this->run = 'c189a69f-4069-4ca7-a158-f090ac995d3f';
    $this->operation->update(['state' => [...$this->operation->state, 'swamp_run_id' => $this->run]]);
    $this->withServerVariables(['REMOTE_ADDR' => $this->instance->node->wireguard_ip]);
    $this->path = '/api/v1/direct-operations/'.$this->operation->id.'/github-token';
    GitHubTestSupport::storeApp();
    $this->reply = ['token' => 'development-ephemeral-sentinel', 'expires_at' => now()->addHour()->format('Y-m-d\TH:i:s\Z'),
        'permissions' => ['contents' => 'read', 'metadata' => 'read']];
});

afterEach(function (): void {
    expect(json_encode([Operation::query()->get()->toArray(), Activity::query()->get()->toArray()], JSON_THROW_ON_ERROR))
        ->not->toContain('development-ephemeral-sentinel');
});

it('mints exactly the owning admitted repository Contents read through the existing numeric POST', function (): void {
    $before = $this->operation->fresh()->toArray();
    Http::fake(['*/repos/example/owned/installation' => Http::response(['id' => 7]),
        '*/access_tokens' => Http::response($this->reply, 201),
        '*/installation/repositories*' => Http::response(['total_count' => 1, 'repositories' => [['full_name' => 'example/owned']]])]);
    $this->postJson($this->path, ['run_id' => $this->run])->assertOk()->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonPath('data.github.token', 'development-ephemeral-sentinel')
        ->assertJsonPath('data.github.repository', 'example/owned')->assertJsonPath('data.github.permissions', ['contents' => 'read']);
    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/access_tokens')
        && $request->data() === ['repositories' => ['owned'], 'permissions' => ['contents' => 'read']]);
    expect($this->operation->fresh()->toArray())->toBe($before);
});

it('refuses changed ownership or credential scope before contacting GitHub', function (string $change): void {
    match ($change) {
        'repository' => $this->instance->project->update(['repository_url' => 'https://github.com/foreign/owned.git']),
        'source' => $this->instance->project->update(['source_access' => ProjectSourceAccess::GhCli]),
        'user' => $this->instance->node->update(['user' => 'foreign']),
        'checkout' => $this->instance->update(['checkout_path' => '/foreign/checkout']),
        'cancel' => $this->operation->update(['cancel_requested_at' => now()]),
        'terminal' => $this->operation->update(['status' => OperationStatus::Succeeded]),
        'run' => $this->operation->update(['state' => [...$this->operation->state, 'swamp_run_id' => 'a6f19760-d4e3-4517-bbea-05799a1a810f']]),
        'peer' => $this->withServerVariables(['REMOTE_ADDR' => Node::query()->create(['name' => 'foreign-peer', 'status' => 'active', 'wireguard_ip' => '10.44.0.199', 'public_ssh_host' => '192.0.2.199'])->wireguard_ip]),
    };
    $response = $this->postJson($this->path, ['run_id' => $this->run]);
    expect($response->status())->toBeIn([403, 409]);
    Http::assertNothingSent();
})->with(['repository', 'source', 'user', 'checkout', 'cancel', 'terminal', 'run', 'peer']);
