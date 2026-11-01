<?php

declare(strict_types=1);

use App\Domain\Instances\InstanceGitHubReadTokens;
use App\Domain\Operations\DirectOperation;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Project;
use Illuminate\Support\Facades\Http;
use Tests\Feature\GitHub\GitHubTestSupport;
use Tests\Support\FakeRoutePublicationSocket;

beforeEach(function (): void {
    Http::preventStrayRequests();
    GitHubTestSupport::storeApp();
    $this->project = Project::query()->create(['name' => 'Native read', 'slug' => 'native-read', 'type' => 'node-package',
        'repository_url' => 'https://github.com/hardimpactdev/orbit.git', 'root' => '.', 'default_branch' => 'main']);
    $this->node = Node::query()->create(['name' => 'native-read', 'user' => 'orbit', 'status' => 'active', 'platform' => 'linux', 'public_ssh_host' => '192.0.2.9', 'wireguard_ip' => '10.44.0.9']);
    orbit_test_set_app_placement_role($this->node, false);
    $this->instance = Instance::query()->create(['project_id' => $this->project->id, 'node_id' => $this->node->id,
        'name' => 'proof', 'checkout_path' => '/srv/apps/native-read/proof', 'source_layout' => 'checkout', 'status' => 'reserved']);
    $this->run = 'a075eb23-7ac4-4487-80d1-57a038a92676';
    $this->operation = Operation::query()->create(['kind' => 'instance.create', 'target_type' => 'instance', 'target_id' => $this->instance->id,
        'node_id' => $this->node->id, 'status' => 'queued', 'state' => ['swamp_run_id' => $this->run],
        'input' => ['instance_id' => $this->instance->id, 'project_id' => $this->project->id, 'repository' => $this->project->repository_url, 'repository_identity' => $this->project->repository_identity]]);
    config(['swamp.run_token' => 'fake.token']);
    $socket = $this->socket = new FakeRoutePublicationSocket;
    $socket->reply = static fn (array $request): array => [['type' => 'vault.put', 'id' => $request['id'], 'payload' => ['data' => ['vaultName' => $request['payload']['vaultName'], 'secretKey' => $request['payload']['key'], 'vaultType' => 'local_encryption']]]];
    app()->instance(WebSocketClient::class, $socket);
});

it('mints repository read scope into the exact native descriptor without returning values', function (): void {
    Http::fake(['*/installation' => Http::response(['id' => 7]), '*/access_tokens' => Http::response(['token' => 'private-instance-sentinel', 'expires_at' => now()->addHour()->format('Y-m-d\TH:i:s\Z'), 'permissions' => ['contents' => 'read', 'metadata' => 'read']], 201), '*/installation/repositories*' => Http::response(['total_count' => 1, 'repositories' => [['full_name' => 'hardimpactdev/orbit']]])]);
    $before = $this->operation->fresh()->toArray();
    $result = app(InstanceGitHubReadTokens::class)->issue($this->operation, 209, $this->run);
    expect($result)->toBe(['git_read' => InstanceGitHubReadTokens::reference($this->operation)])
        ->and($this->socket->sent[0]['payload']['value'])->toBe('{"github_token":"private-instance-sentinel"}')
        ->and(json_encode($this->operation->fresh()->toArray()))->not->toContain('private-instance-sentinel')
        ->and($this->operation->fresh()->toArray())->toBe($before);
    Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/access_tokens') && $request->data() === ['repositories' => ['orbit'], 'permissions' => ['contents' => 'read']]);
});

it('refuses changed authority before reading or copying any credential', function (string $change): void {
    match ($change) {
        'terminal' => $this->operation->update(['status' => 'succeeded']),
        'cancel' => $this->operation->update(['cancel_requested_at' => now()]),
        'run' => $this->operation->update(['state' => ['swamp_run_id' => 'fabd4059-fb6c-49d8-940e-01ae58aa3f38']]),
        'repository' => $this->project->update(['repository_url' => 'https://github.com/other/repository.git']),
        'instance' => $this->instance->update(['project_id' => Project::query()->create(['name' => 'Other', 'slug' => 'other', 'type' => 'node-package', 'repository_url' => 'https://github.com/other/project.git'])->id]),
        'node' => $this->operation->update(['node_id' => null]),
        'input' => $this->operation->update(['input' => [...$this->operation->input, 'repository' => 'https://github.com/foreign/repository.git']]),
        'caller' => null,
    };
    expect(fn () => app(InstanceGitHubReadTokens::class)->issue($this->operation, $change === 'caller' ? $this->node->id : 209, $this->run))->toThrow(ResourceOperationException::class);
    Http::assertNothingSent();
    expect($this->socket->sent)->toBe([]);
})->with(['terminal', 'cancel', 'run', 'repository', 'instance', 'node', 'input', 'caller']);

it('revokes fresh credentials without a vault write when authority changes during the mint', function (string $change): void {
    Http::fake(function ($request) use ($change) {
        if ($request->method() === 'DELETE') {
            return Http::response(null, 204);
        }
        if (str_contains($request->url(), '/installation/repositories')) {
            return Http::response(['total_count' => 1, 'repositories' => [['full_name' => 'hardimpactdev/orbit']]]);
        }
        if ($request->method() === 'GET') {
            return Http::response(['id' => 7]);
        }
        match ($change) {
            'cancel' => $this->operation->update(['cancel_requested_at' => now()]),
            'run' => $this->operation->update(['state' => ['swamp_run_id' => 'fabd4059-fb6c-49d8-940e-01ae58aa3f38']]),
            'repository' => $this->project->update(['repository_url' => 'https://github.com/other/repository.git']),
            'terminal' => $this->operation->update(['status' => 'succeeded']),
        };

        return Http::response(['token' => 'private-instance-sentinel', 'expires_at' => now()->addHour()->format('Y-m-d\TH:i:s\Z'), 'permissions' => ['contents' => 'read', 'metadata' => 'read']], 201);
    });
    expect(fn () => app(InstanceGitHubReadTokens::class)->issue($this->operation, 209, $this->run))->toThrow(ResourceOperationException::class);
    expect($this->socket->sent)->toBe([]);
    Http::assertSent(fn ($request): bool => $request->method() === 'DELETE' && $request->header('Authorization') === ['Bearer private-instance-sentinel']);
})->with(['cancel', 'run', 'repository', 'terminal']);

it('dispatches a registered issuer only at the bound numeric mint endpoint for control209', function (): void {
    $gateway = $this->markAsGateway(Node::query()->create(['name' => 'gateway', 'public_ssh_host' => '192.0.2.1', 'wireguard_ip' => '10.44.0.1', 'status' => 'active']));
    $control = Node::query()->forceCreate(['id' => 209, 'name' => 'control', 'public_ssh_host' => '192.0.2.209', 'wireguard_ip' => '10.44.0.209', 'status' => 'active']);
    $control->accessibleNodes()->attach($gateway);
    $direct = app(DirectOperation::class);
    $reflection = new ReflectionClass($direct);
    $definitions = $reflection->getProperty('definitions')->getValue($direct);
    $definitions['instance.create']['github_token_issuer'] = InstanceGitHubReadTokens::class;
    $registered = $reflection->newInstanceWithoutConstructor();
    $reflection->getProperty('definitions')->setValue($registered, $definitions);
    app()->instance(DirectOperation::class, $registered);
    Http::fake(['*/installation' => Http::response(['id' => 7]), '*/access_tokens' => Http::response(['token' => 'private-instance-sentinel', 'expires_at' => now()->addHour()->format('Y-m-d\TH:i:s\Z'), 'permissions' => ['contents' => 'read', 'metadata' => 'read']], 201), '*/installation/repositories*' => Http::response(['total_count' => 1, 'repositories' => [['full_name' => 'hardimpactdev/orbit']]])]);
    $path = '/api/v1/direct-operations/'.$this->operation->id.'/github-token';
    $this->withServerVariables(['REMOTE_ADDR' => $control->wireguard_ip])->postJson($path, ['run_id' => $this->run])
        ->assertOk()->assertJsonPath('data.execution_secrets.git_read', InstanceGitHubReadTokens::reference($this->operation))->assertDontSee('private-instance-sentinel');
    $this->withServerVariables(['REMOTE_ADDR' => $this->node->wireguard_ip])->postJson($path, ['run_id' => $this->run])->assertForbidden();
});
