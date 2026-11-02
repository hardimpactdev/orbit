<?php

declare(strict_types=1);

use App\Data\GatewayProfile;
use App\Repositories\GatewayConfigRepository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Orbit\Sdk\Requests\Operations\ShowOperationRequest;
use Orbit\Sdk\Requests\Realtime\ShowRealtimeRequest;
use Orbit\Sdk\Requests\Tools\AdoptToolRequest;
use Orbit\Sdk\Requests\Tools\InstallToolRequest;
use Orbit\Sdk\Requests\Tools\RemoveToolRequest;
use Orbit\Sdk\Requests\Tools\UpdateToolRequest;
use Orbit\Sdk\Responses\Operations\OperationResponse;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

beforeEach(function (): void {
    MockClient::destroyGlobal();
    $this->orbitHome = sys_get_temp_dir().'/orbit-tools-operation-'.Str::uuid();
    config()->set('orbit.home', $this->orbitHome);
    app(GatewayConfigRepository::class)->add(new GatewayProfile(name: 'test', url: 'https://10.44.0.1', caPath: '/unused/root.pem'));
});

afterEach(function (): void {
    MockClient::destroyGlobal();
    new Filesystem()->deleteDirectory($this->orbitHome);
});

it('follows each Tool write and forwards its stable start key', function (string $verb, string $request, array $arguments): void {
    $id = '11111111-1111-4111-8111-111111111111';
    $queued = ['id' => 11, 'kind' => 'tool.'.$verb, 'target_type' => 'node_tools', 'target_id' => 2, 'status' => 'queued', 'sequence' => 1];
    $finished = [...$queued, 'status' => 'succeeded', 'sequence' => 6, 'result' => ['installed_version' => $verb === 'remove' ? null : '7.0.0', 'block' => null]];
    $mock = MockClient::global([
        $request => MockResponse::make(['data' => $queued, 'meta' => ['request_id' => $id]], 202),
        ShowRealtimeRequest::class => MockResponse::make([], 503),
        ShowOperationRequest::class => MockResponse::make(['data' => $finished, 'meta' => ['request_id' => $id]]),
    ]);
    $this->artisan('tool:'.$verb, [...$arguments, '--json' => true, '--idempotency-key' => 'tools-stable-key'])
        ->expectsOutput(json_encode(OperationResponse::fromGatewayData($finished, $id)->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES))->assertExitCode(0);
    $mock->assertSent(static fn ($pending): bool => $pending instanceof $request && $pending->headers()->get('Idempotency-Key') === 'tools-stable-key');
    $mock->assertSent(ShowOperationRequest::class);
})->with([
    ['install', InstallToolRequest::class, ['package' => 'is-number', '--node' => '2', '--manager' => 'vp']],
    ['adopt', AdoptToolRequest::class, ['package' => 'is-number', '--node' => '2', '--manager' => 'vp', '--yes' => true]],
    ['update', UpdateToolRequest::class, ['tool' => '8']],
    ['remove', RemoveToolRequest::class, ['tool' => '8', '--yes' => true]],
]);

it('returns failure for a failed Tool Operation and keeps its receipt in JSON', function (): void {
    $id = '11111111-1111-4111-8111-111111111111';
    $operation = ['id' => 11, 'kind' => 'tool.install', 'target_type' => 'node_tools', 'target_id' => 2, 'status' => 'failed', 'sequence' => 6,
        'error_code' => 'tool.version_constraint_blocked', 'result' => ['outcome' => 'blocked_by_constraint']];
    MockClient::global([
        InstallToolRequest::class => MockResponse::make(['data' => $operation, 'meta' => ['request_id' => $id]], 202),
        ShowRealtimeRequest::class => MockResponse::make([], 503),
        ShowOperationRequest::class => MockResponse::make(['data' => $operation, 'meta' => ['request_id' => $id]]),
    ]);
    $this->artisan('tool:install', ['package' => 'is-number', '--node' => 2, '--manager' => 'vp', '--json' => true])
        ->expectsOutput(json_encode(OperationResponse::fromGatewayData($operation, $id)->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES))->assertExitCode(1);
});

it('transports a repository definition on install adopt and update', function (string $verb, string $requestClass, array $arguments): void {
    $repository = ['name' => 'vendor', 'uris' => ['https://packages.example.test/apt'], 'suites' => ['preview'], 'components' => ['main'], 'signing_key' => ['url' => 'https://packages.example.test/key.gpg', 'fingerprint' => str_repeat('A', 40)]];
    $operation = ['id' => 11, 'kind' => 'tool.'.$verb, 'target_type' => 'node_tools', 'target_id' => 2, 'status' => 'succeeded', 'sequence' => 3, 'result' => ['installed_version' => '1.0.0']];
    $mock = MockClient::global([$requestClass => MockResponse::make(['data' => $operation, 'meta' => ['request_id' => '11111111-1111-4111-8111-111111111111']], 202), ShowRealtimeRequest::class => MockResponse::make([], 503), ShowOperationRequest::class => MockResponse::make(['data' => $operation, 'meta' => ['request_id' => '11111111-1111-4111-8111-111111111111']])]);
    $this->artisan('tool:'.$verb, [...$arguments, '--repository' => json_encode($repository, JSON_THROW_ON_ERROR), '--json' => true])->assertSuccessful();
    $mock->assertSent(static fn ($pending): bool => $pending instanceof $requestClass && $pending->body()->all()['repository'] === $repository);
})->with([
    ['install', InstallToolRequest::class, ['package' => 'hello', '--node' => 2, '--manager' => 'apt']],
    ['adopt', AdoptToolRequest::class, ['package' => 'hello', '--node' => 2, '--manager' => 'apt', '--yes' => true]],
    ['update', UpdateToolRequest::class, ['tool' => 8]],
]);

it('rejects malformed repository JSON before HTTP', function (string $value): void {
    $mock = MockClient::global();
    $this->artisan('tool:update', ['tool' => 8, '--repository' => $value, '--json' => true])->assertFailed();
    expect($mock->getLastPendingRequest())->toBeNull();
})->with(['[]', 'false', '{broken']);
