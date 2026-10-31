<?php

declare(strict_types=1);

use App\Data\GatewayProfile;
use App\Repositories\GatewayConfigRepository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Orbit\Sdk\Requests\Nodes\RemoveNodeRequest;
use Orbit\Sdk\Requests\Nodes\ShowNodeRequest;
use Orbit\Sdk\Requests\Operations\ShowOperationRequest;
use Saloon\Enums\Method;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

beforeEach(function (): void {
    MockClient::destroyGlobal();
    $this->orbitHome = sys_get_temp_dir().'/orbit-cli-'.Str::uuid();
    config()->set('orbit.home', $this->orbitHome);
    app(GatewayConfigRepository::class)->add(new GatewayProfile(name: 'test', url: 'https://10.44.0.1', caPath: '/home/orbit/.orbit/ca/root.pem'));
});

afterEach(function (): void {
    MockClient::destroyGlobal();
    new Filesystem()->deleteDirectory($this->orbitHome);
});

function removeNodeEnvelope(string $status = 'succeeded'): array
{
    return ['data' => ['id' => 70, 'kind' => 'node.remove', 'target_type' => 'node', 'target_id' => 2, 'status' => $status,
        'sequence' => 3, 'input' => [], 'error_code' => $status === 'failed' ? 'node.removal_failed' : null,
        'result' => ['schema_version' => 1, 'node_id' => 2, 'removed' => $status === 'succeeded']],
        'meta' => ['request_id' => '0198e15c-bf97-7c23-8f1f-61b8fe67a844']];
}

function removeNodeShow(): array
{
    return ['data' => ['id' => 2, 'name' => 'app-dev', 'status' => 'active', 'public_ssh_host' => '10.0.0.3', 'public_ssh_port' => 22,
        'user' => 'orbit', 'roles' => []], 'meta' => ['request_id' => '0198e15c-bf97-7c23-8f1f-61b8fe67a844']];
}

it('resolves a missing Node before asking for destructive consent', function (bool $force): void {
    $mock = MockClient::global([ShowNodeRequest::class => MockResponse::make(['error' => ['code' => 'node.not_found', 'message' => 'Node was not found.', 'details' => []]],
        404, ['X-Orbit-Request-Id' => '0198e15c-bf97-7c23-8f1f-61b8fe67a844'])]);
    $this->artisan('node:remove', ['node' => '99999', '--force' => $force, '--json' => true])
        ->expectsOutputToContain('"code":"node.not_found"')->assertExitCode(1);
    expect($mock->getRecordedResponses())->toHaveCount(1);
    $mock->assertNotSent(RemoveNodeRequest::class);
})->with([false, true]);

it('requires explicit destructive consent in JSON', function (): void {
    $mock = MockClient::global([ShowNodeRequest::class => MockResponse::make(removeNodeShow())]);
    $this->artisan('node:remove', ['node' => '2', '--json' => true])
        ->expectsOutputToContain('"code":"node.confirmation_required"')->assertExitCode(1);
    $mock->assertNotSent(RemoveNodeRequest::class);
});

it('starts and follows one native removal with the exact offline and idempotency inputs', function (bool $offline): void {
    $mock = MockClient::global([
        ShowNodeRequest::class => MockResponse::make(removeNodeShow()),
        RemoveNodeRequest::class => MockResponse::make(removeNodeEnvelope('running'), 202),
        ShowOperationRequest::class => MockResponse::make(removeNodeEnvelope()),
        '*/api/v1/realtime' => MockResponse::make(['error' => ['code' => 'http.404', 'message' => 'Unavailable']], 404),
    ]);
    $exit = Artisan::call('node:remove', ['node' => '2', '--force' => true, '--offline' => $offline, '--idempotency-key' => 'remove-once', '--json' => true]);
    $output = json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);
    expect($exit)->toBe(0)
        ->and($output['kind'])->toBe('node.remove')
        ->and($output['status'])->toBe('succeeded')
        ->and($output['result']['removed'])->toBeTrue();
    $mock->assertSent(ShowOperationRequest::class);
    $mutations = array_values(array_filter($mock->getRecordedResponses(), static fn ($response): bool => $response->getPendingRequest()->getRequest() instanceof RemoveNodeRequest));
    expect($mutations)->toHaveCount(1);
    $pending = $mutations[0]->getPendingRequest();
    expect($pending->getMethod())->toBe(Method::DELETE);
    expect($pending->getUrl())->toBe('https://10.44.0.1/api/v1/nodes/2');
    expect($pending->body()->all())->toBe(['force' => true, 'offline' => $offline]);
    expect($pending->headers()->get('Idempotency-Key'))->toBe('remove-once');
})->with([false, true]);

it('reports the terminal native failure without claiming Node deletion', function (): void {
    MockClient::global([
        ShowNodeRequest::class => MockResponse::make(removeNodeShow()),
        RemoveNodeRequest::class => MockResponse::make(removeNodeEnvelope('running'), 202),
        ShowOperationRequest::class => MockResponse::make(removeNodeEnvelope('failed')),
        '*/api/v1/realtime' => MockResponse::make(['error' => ['code' => 'http.404', 'message' => 'Unavailable']], 404),
    ]);
    $exit = Artisan::call('node:remove', ['node' => '2', '--force' => true, '--json' => true]);
    $output = json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);
    expect($exit)->toBe(1)
        ->and($output['status'])->toBe('failed')
        ->and($output['result']['removed'])->toBeFalse();
});

it('uses the shared native human completion', function (): void {
    MockClient::global([
        ShowNodeRequest::class => MockResponse::make(removeNodeShow()),
        RemoveNodeRequest::class => MockResponse::make(removeNodeEnvelope(), 202),
        '*/api/v1/realtime' => MockResponse::make(['error' => ['code' => 'http.404', 'message' => 'Unavailable']], 404),
    ]);
    $this->artisan('node:remove', ['node' => '2', '--force' => true])
        ->expectsOutputToContain('Node removal succeeded.')->doesntExpectOutputToContain('Node [app-dev] removed.')->assertExitCode(0);
});

it('reports retained macOS host state from the native result', function (): void {
    $envelope = removeNodeEnvelope();
    $envelope['data']['result'] += ['name' => 'mini', 'offline' => false,
        'retained_on_node' => ['user', 'package-managers', 'host-wireguard']];
    MockClient::global([ShowNodeRequest::class => MockResponse::make(removeNodeShow()),
        RemoveNodeRequest::class => MockResponse::make($envelope, 202),
        '*/api/v1/realtime' => MockResponse::make(['error' => ['code' => 'http.404', 'message' => 'Unavailable']], 404)]);
    $this->artisan('node:remove', ['node' => '2', '--force' => true])->expectsOutputToContain('Node removal succeeded.')
        ->expectsOutputToContain('Left on the node:')->expectsOutputToContain('package-managers')->assertExitCode(0);
});

it('rejects invalid IDs before an API call', function (string $node): void {
    $mock = MockClient::global();
    $this->artisan('node:remove', ['node' => $node, '--force' => true])
        ->expectsOutputToContain('Node ID must be a positive integer.')->assertExitCode(1);
    expect($mock->getLastPendingRequest())->toBeNull();
})->with(['invalid', '0', '-1']);

it('preserves a Gateway refusal and request ID', function (): void {
    MockClient::global([
        ShowNodeRequest::class => MockResponse::make(removeNodeShow()),
        RemoveNodeRequest::class => MockResponse::make(['error' => ['code' => 'node.has_routes', 'message' => 'The Node has Routes.', 'details' => []]], 409,
            ['X-Orbit-Request-Id' => '0198e15c-bf97-7c23-8f1f-61b8fe67a844']),
    ]);
    $this->artisan('node:remove', ['node' => '2', '--force' => true])
        ->expectsOutputToContain('The Node has Routes.')->expectsOutput('Request ID: 0198e15c-bf97-7c23-8f1f-61b8fe67a844')->assertExitCode(1);
});
