<?php

declare(strict_types=1);

use App\Data\GatewayProfile;
use App\Repositories\GatewayConfigRepository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Orbit\Sdk\Requests\Operations\ShowOperationRequest;
use Orbit\Sdk\Requests\Realtime\ShowRealtimeRequest;
use Orbit\Sdk\Requests\Tools\AddAptRepositoryRequest;
use Orbit\Sdk\Requests\Tools\ListAptRepositoriesRequest;
use Orbit\Sdk\Requests\Tools\RemoveAptRepositoryRequest;
use Orbit\Sdk\Requests\Tools\ShowAptRepositoryRequest;
use Orbit\Sdk\Responses\Operations\OperationResponse;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

beforeEach(function (): void {
    MockClient::destroyGlobal();
    $this->orbitHome = sys_get_temp_dir().'/orbit-tools-apt-repository-'.Str::uuid();
    config()->set('orbit.home', $this->orbitHome);
    app(GatewayConfigRepository::class)->add(new GatewayProfile(name: 'test', url: 'https://10.44.0.1', caPath: '/unused/root.pem'));
    $this->definition = ['uris' => ['https://packages.example.test/apt'], 'suites' => ['stable'], 'components' => ['main'],
        'signing_key' => ['url' => 'https://packages.example.test/key.asc', 'fingerprint' => str_repeat('A', 40)]];
    $this->requestId = '11111111-1111-4111-8111-111111111111';
});

afterEach(function (): void {
    MockClient::destroyGlobal();
    new Filesystem()->deleteDirectory($this->orbitHome);
});

function apt_repository_data(array $overrides = []): array
{
    return array_replace(['id' => 5, 'node_id' => 2, 'name' => 'vendor', 'definition' => test()->definition, 'status' => 'ready', 'error_code' => null, 'tool_count' => 1], $overrides);
}

it('adds an apt repository by name and definition, follows its Operation and forwards its start key', function (): void {
    $queued = ['id' => 11, 'kind' => 'tool.apt_repository_add', 'target_type' => 'node_tools', 'target_id' => 2, 'status' => 'queued', 'sequence' => 1];
    $finished = [...$queued, 'status' => 'succeeded', 'sequence' => 4, 'result' => ['repository' => 'vendor', 'status' => 'ready', 'error_code' => null]];
    $mock = MockClient::global([
        AddAptRepositoryRequest::class => MockResponse::make(['data' => $queued, 'meta' => ['request_id' => $this->requestId]], 202),
        ShowRealtimeRequest::class => MockResponse::make([], 503),
        ShowOperationRequest::class => MockResponse::make(['data' => $finished, 'meta' => ['request_id' => $this->requestId]]),
    ]);

    $this->artisan('tool:apt-repository-add', ['--node' => '2', '--name' => 'vendor', '--definition' => json_encode($this->definition, JSON_THROW_ON_ERROR),
        '--idempotency-key' => 'repo-key', '--json' => true])
        ->expectsOutput(json_encode(OperationResponse::fromGatewayData($finished, $this->requestId)->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES))->assertExitCode(0);
    $mock->assertSent(fn ($pending): bool => $pending instanceof AddAptRepositoryRequest && $pending->headers()->get('Idempotency-Key') === 'repo-key'
        && $pending->body()->all() === ['node_id' => 2, 'name' => 'vendor', 'definition' => $this->definition]);
});

it('rejects a missing name or a definition that is not a JSON object before HTTP', function (array $options): void {
    $mock = MockClient::global();
    $this->artisan('tool:apt-repository-add', [...$options, '--json' => true])->assertFailed();
    expect($mock->getLastPendingRequest())->toBeNull();
})->with([
    'no name' => [['--node' => '2', '--definition' => '{}']],
    'a list' => [['--node' => '2', '--name' => 'vendor', '--definition' => '[]']],
    'false' => [['--node' => '2', '--name' => 'vendor', '--definition' => 'false']],
    'broken JSON' => [['--node' => '2', '--name' => 'vendor', '--definition' => '{broken']],
    'no definition' => [['--node' => '2', '--name' => 'vendor']],
    'a bad node' => [['--node' => 'x', '--name' => 'vendor', '--definition' => '{}']],
]);

it('lists and shows apt repositories with their status and Tool count', function (): void {
    $mock = MockClient::global([
        ListAptRepositoriesRequest::class => MockResponse::make(['data' => [apt_repository_data()], 'meta' => ['request_id' => $this->requestId]]),
        ShowAptRepositoryRequest::class => MockResponse::make(['data' => apt_repository_data(['status' => 'failed', 'error_code' => 'tool.repository_unconfirmed']), 'meta' => ['request_id' => $this->requestId]]),
    ]);

    $this->artisan('tool:apt-repository-list', ['--node' => '2', '--json' => true])
        ->expectsOutput(json_encode(['repositories' => [apt_repository_data()], 'request_id' => $this->requestId], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES))->assertSuccessful();
    expect(Artisan::call('tool:apt-repository-list', ['--node' => '2']))->toBe(0)
        ->and(Artisan::output())->toContain('vendor')->toContain('stable')->toContain('ready');
    $this->artisan('tool:apt-repository-show', ['repository' => '5'])->expectsOutputToContain('Apt repository [vendor].')
        ->expectsOutputToContain('tool.repository_unconfirmed')->assertSuccessful();
    $mock->assertSent(static fn ($pending): bool => $pending instanceof ListAptRepositoriesRequest && $pending->query()->all() === ['node_id' => 2]);
    $mock->assertSent(static fn ($pending): bool => $pending instanceof ShowAptRepositoryRequest && $pending->resolveEndpoint() === '/api/v1/apt-repositories/5');
});

it('removes an apt repository only with confirmation, and rejects a nonnumeric ID before HTTP', function (): void {
    $operation = ['id' => 12, 'kind' => 'tool.apt_repository_remove', 'target_type' => 'node_tools', 'target_id' => 2, 'status' => 'succeeded', 'sequence' => 3,
        'result' => ['repository' => 'vendor', 'status' => 'removed', 'error_code' => null]];
    $mock = MockClient::global([
        ShowAptRepositoryRequest::class => MockResponse::make(['data' => apt_repository_data(), 'meta' => ['request_id' => $this->requestId]]),
        RemoveAptRepositoryRequest::class => MockResponse::make(['data' => $operation, 'meta' => ['request_id' => $this->requestId]], 202),
        ShowRealtimeRequest::class => MockResponse::make([], 503),
        ShowOperationRequest::class => MockResponse::make(['data' => $operation, 'meta' => ['request_id' => $this->requestId]]),
    ]);

    $this->artisan('tool:apt-repository-remove', ['repository' => '5'])->assertFailed();
    $mock->assertSent(ShowAptRepositoryRequest::class);
    $mock->assertNotSent(RemoveAptRepositoryRequest::class);
    $this->artisan('tool:apt-repository-remove', ['repository' => 'abc'])->assertFailed();
    $this->artisan('tool:apt-repository-remove', ['repository' => '5', '--yes' => true, '--json' => true])->assertSuccessful();
    $mock->assertSent(static fn ($pending): bool => $pending instanceof RemoveAptRepositoryRequest && $pending->resolveEndpoint() === '/api/v1/apt-repositories/5');
});
