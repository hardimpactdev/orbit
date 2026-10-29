<?php

declare(strict_types=1);

use App\Data\GatewayProfile;
use App\Repositories\GatewayConfigRepository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Orbit\Sdk\Requests\Nodes\RemoveNodeRequest;
use Orbit\Sdk\Requests\Nodes\ShowNodeRequest;
use Saloon\Enums\Method;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

beforeEach(function (): void {
    MockClient::destroyGlobal();
    $this->orbitHome = sys_get_temp_dir().'/orbit-cli-'.(string) Str::uuid();
    config()->set('orbit.home', $this->orbitHome);

    app(GatewayConfigRepository::class)->add(new GatewayProfile(
        name: 'test',
        url: 'https://10.44.0.1',
        caPath: '/home/orbit/.orbit/ca/root.pem',
    ));
});

afterEach(function (): void {
    MockClient::destroyGlobal();
    new Filesystem()->deleteDirectory($this->orbitHome);
});

it('fails as not-found for a missing node without force', function (): void {
    $mockClient = MockClient::global([
        ShowNodeRequest::class => MockResponse::make(
            missing_node_error_payload(),
            404,
            ['X-Orbit-Request-Id' => remove_node_request_id()],
        ),
    ]);
    $expected = json_encode(missing_node_error_json(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

    $this
        ->artisan('node:remove', ['node' => '999999', '--json' => true])
        ->expectsOutput($expected)
        ->assertExitCode(1);

    expect($mockClient->getLastRequest())
        ->toBeInstanceOf(ShowNodeRequest::class)
        ->and($mockClient->getLastPendingRequest()?->getUrl())
        ->toBe('https://10.44.0.1/api/v1/nodes/999999')
        ->and($mockClient->getLastPendingRequest()?->getMethod())
        ->toBe(Method::GET)
        ->and($mockClient->getRecordedResponses())
        ->toHaveCount(1);
});

it('fails as not-found for a missing node with force', function (): void {
    $mockClient = MockClient::global([
        ShowNodeRequest::class => MockResponse::make(
            missing_node_error_payload(),
            404,
            ['X-Orbit-Request-Id' => remove_node_request_id()],
        ),
    ]);
    $expected = json_encode(missing_node_error_json(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

    $this
        ->artisan('node:remove', ['node' => '999999', '--force' => true, '--json' => true])
        ->expectsOutput($expected)
        ->assertExitCode(1);

    expect($mockClient->getLastRequest())
        ->toBeInstanceOf(ShowNodeRequest::class)
        ->and($mockClient->getLastPendingRequest()?->getMethod())
        ->toBe(Method::GET)
        ->and($mockClient->getRecordedResponses())
        ->toHaveCount(1);
});

it('fails as typed not-found when the gateway names the missing node', function (): void {
    $mockClient = MockClient::global([
        ShowNodeRequest::class => MockResponse::make(
            [
                'error' => [
                    'code' => 'node.not_found',
                    'message' => 'Node was not found.',
                    'details' => [],
                ],
            ],
            404,
            ['X-Orbit-Request-Id' => remove_node_request_id()],
        ),
    ]);
    $expected = json_encode([
        'error' => [
            'code' => 'node.not_found',
            'message' => 'Node was not found.',
            'request_id' => remove_node_request_id(),
        ],
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

    $this
        ->artisan('node:remove', ['node' => '999999', '--json' => true])
        ->expectsOutput($expected)
        ->assertExitCode(1);

    expect($mockClient->getLastRequest())
        ->toBeInstanceOf(ShowNodeRequest::class)
        ->and($mockClient->getRecordedResponses())
        ->toHaveCount(1);
});

it('requires force only after an existing node is resolved', function (): void {
    $mockClient = MockClient::global([
        ShowNodeRequest::class => MockResponse::make(existing_node_show_payload()),
    ]);
    $expected = json_encode([
        'error' => [
            'code' => 'node.confirmation_required',
            'message' => 'Use --force to confirm node removal.',
            'request_id' => null,
        ],
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

    $this
        ->artisan('node:remove', ['node' => '2', '--json' => true])
        ->expectsOutput($expected)
        ->assertExitCode(1);

    expect($mockClient->getLastRequest())
        ->toBeInstanceOf(ShowNodeRequest::class)
        ->and($mockClient->getRecordedResponses())
        ->toHaveCount(1);
});

it('rejects an invalid node id before making an API request', function (string $nodeId): void {
    $mockClient = MockClient::global();

    $this
        ->artisan('node:remove', ['node' => $nodeId, '--force' => true])
        ->expectsOutputToContain('Node ID must be a positive integer.')
        ->assertExitCode(1);

    expect($mockClient->getLastPendingRequest())->toBeNull();
})->with([
    'non-numeric' => 'app-dev',
    'zero' => '0',
    'negative' => '-1',
]);

it('prints the request id for node removal gateway api errors', function (): void {
    MockClient::global([
        ShowNodeRequest::class => MockResponse::make(existing_node_show_payload()),
        RemoveNodeRequest::class => MockResponse::make(
            [
                'error' => [
                    'code' => 'node.not_found',
                    'message' => 'Node was not found.',
                    'details' => [],
                ],
            ],
            404,
            [
                'X-Orbit-Request-Id' => remove_node_request_id(),
            ],
        ),
    ]);

    $this
        ->artisan('node:remove', ['node' => '2', '--force' => true])
        ->expectsOutputToContain('Node was not found.')
        ->expectsOutput('Request ID: '.remove_node_request_id())
        ->assertExitCode(1);
});

/** @return array<string, mixed> */
function existing_node_show_payload(int $id = 2, string $name = 'app-dev'): array
{
    return [
        'data' => [
            'id' => $id,
            'name' => $name,
            'status' => 'active',
            'public_ssh_host' => '10.0.0.3',
            'public_ssh_port' => 22,
            'user' => 'orbit',
            'roles' => [],
        ],
        'meta' => ['request_id' => remove_node_request_id()],
    ];
}

/** @return array{error: array{code: string, message: string, details: array<never, never>}} */
function missing_node_error_payload(): array
{
    return [
        'error' => [
            'code' => 'http.404',
            'message' => 'Resource not found.',
            'details' => [],
        ],
    ];
}

/** @return array{error: array{code: string, message: string, request_id: string}} */
function missing_node_error_json(): array
{
    return [
        'error' => [
            'code' => 'http.404',
            'message' => 'Resource not found.',
            'request_id' => remove_node_request_id(),
        ],
    ];
}

function remove_node_request_id(): string
{
    return '0198e15c-bf97-7c23-8f1f-61b8fe67a844';
}
