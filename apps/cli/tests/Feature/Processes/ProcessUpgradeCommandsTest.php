<?php

declare(strict_types=1);

use App\Data\GatewayProfile;
use App\Repositories\GatewayConfigRepository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Orbit\Sdk\Requests\Operations\ShowOperationRequest;
use Orbit\Sdk\Requests\Processes\SetProcessArtifactRequest;
use Orbit\Sdk\Requests\Processes\UnsetProcessArtifactRequest;
use Orbit\Sdk\Requests\Processes\UpgradeProcessRequest;
use Orbit\Sdk\Requests\Realtime\ShowRealtimeRequest;
use Orbit\Sdk\Responses\Operations\OperationResponse;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

beforeEach(function (): void {
    MockClient::destroyGlobal();
    $this->upgradeHome = sys_get_temp_dir().'/orbit-cli-process-upgrade-'.Str::uuid();
    config()->set('orbit.home', $this->upgradeHome);
    app(GatewayConfigRepository::class)->add(new GatewayProfile(name: 'test', url: 'https://10.44.0.1', caPath: '/unused/root.pem'));
});

afterEach(function (): void {
    MockClient::destroyGlobal();
    new Filesystem()->deleteDirectory($this->upgradeHome);
});

function process_upgrade_cli_request_id(): string
{
    return '0198e15c-bf97-7c23-8f1f-61b8fe67a846';
}

/** @return array<string, mixed> */
function process_upgrade_cli_artifact(): array
{
    return [
        'source' => 'github_release', 'repository' => 'router-for-me/CLIProxyAPI', 'asset' => 'CLIProxyAPI_{version}_linux_amd64.tar.gz',
        'binary' => 'cli-proxy-api', 'checksums' => 'checksums.txt', 'version_args' => ['--help'], 'version' => null,
        'previous_version' => null, 'sha256' => null, 'latest_version' => null, 'latest_checked_at' => null,
    ];
}

it('starts an upgrade Operation and follows it to its result', function (): void {
    $data = ['id' => 91, 'kind' => 'process.upgrade', 'target_type' => 'process', 'target_id' => 103, 'input' => [], 'status' => 'succeeded', 'sequence' => 3, 'cancel_requested' => false, 'attempts' => 1,
        'result' => ['before' => '8.0.63', 'after' => '8.0.80', 'outcome' => 'upgraded', 'pruned' => ['8.0.17']], 'steps' => []];
    $mock = MockClient::global([
        UpgradeProcessRequest::class => MockResponse::make(['data' => [...$data, 'status' => 'queued'], 'meta' => ['request_id' => process_upgrade_cli_request_id()]], 202),
        ShowRealtimeRequest::class => MockResponse::make([], 503),
        ShowOperationRequest::class => MockResponse::make(['data' => $data, 'meta' => ['request_id' => process_upgrade_cli_request_id()]]),
    ]);
    $expected = json_encode(OperationResponse::fromGatewayData($data, process_upgrade_cli_request_id())->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

    $this->artisan('process:upgrade', ['process' => '103', 'version' => 'v8.0.80', '--idempotency-key' => 'upgrade-proof', '--json' => true])
        ->expectsOutput($expected)->assertExitCode(0);

    $mock->assertSent(fn ($request): bool => $request instanceof UpgradeProcessRequest
        && $request->body()->all() === ['version' => 'v8.0.80']
        && $request->resolveEndpoint() === '/api/v1/processes/103/upgrade'
        && $request->headers()->get('Idempotency-Key') === 'upgrade-proof');
});

it('renders a Gateway refusal of an upgrade with its code', function (): void {
    MockClient::global([UpgradeProcessRequest::class => MockResponse::make([
        'error' => ['code' => 'process.version_unknown', 'message' => 'No release 9.9.9 with one matching asset.'],
    ], 422)]);

    $this->artisan('process:upgrade', ['process' => '103', 'version' => '9.9.9', '--json' => true])
        ->expectsOutputToContain('"code":"process.version_unknown"')
        ->doesntExpectOutputToContain('"request_id":null')
        ->assertExitCode(1);
});

it('refuses invalid upgrade input without a request', function (array $arguments, string $code): void {
    $mock = MockClient::global();

    $this->artisan('process:upgrade', [...$arguments, '--json' => true])
        ->expectsOutputToContain("\"code\":\"{$code}\"")
        ->assertExitCode(1);

    expect($mock->getLastPendingRequest())->toBeNull();
})->with([
    'process name' => [['process' => 'router', 'version' => '8.0.80'], 'process.id_invalid'],
    'version with a space' => [['process' => '103', 'version' => '8.0 80'], 'process.version_unknown'],
    'version with a slash' => [['process' => '103', 'version' => '../8.0.80'], 'process.version_unknown'],
]);

it('records an artifact from a preset and explicit fields', function (): void {
    $mock = MockClient::global([SetProcessArtifactRequest::class => MockResponse::make([
        'data' => ['id' => 103, 'name' => 'ai-router', 'artifact' => [...process_upgrade_cli_artifact(), 'version' => '8.0.63']],
        'meta' => ['request_id' => process_upgrade_cli_request_id()],
    ])]);

    $this->artisan('process:artifact:set', ['process' => '103', '--preset' => 'cliproxyapi', '--version' => '8.0.63', '--version-args' => ['--help'], '--json' => true])
        ->expectsOutput(json_encode(['id' => 103, 'name' => 'ai-router', 'artifact' => [...process_upgrade_cli_artifact(), 'version' => '8.0.63'], 'request_id' => process_upgrade_cli_request_id()], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES))
        ->assertExitCode(0);

    expect($mock->getLastRequest())->toBeInstanceOf(SetProcessArtifactRequest::class)
        ->and($mock->getLastRequest()?->body()->all())->toBe(['preset' => 'cliproxyapi', 'version_args' => ['--help'], 'version' => '8.0.63']);
});

it('renders a recorded artifact for a person', function (): void {
    MockClient::global([SetProcessArtifactRequest::class => MockResponse::make([
        'data' => ['id' => 103, 'name' => 'ai-router', 'artifact' => process_upgrade_cli_artifact()],
        'meta' => ['request_id' => process_upgrade_cli_request_id()],
    ])]);

    $this->artisan('process:artifact:set', ['process' => '103', '--repository' => 'router-for-me/CLIProxyAPI', '--asset' => 'CLIProxyAPI_{version}_linux_amd64.tar.gz', '--binary' => 'cli-proxy-api'])
        ->expectsOutputToContain('Process [ai-router] artifact recorded.')
        ->expectsOutputToContain('router-for-me/CLIProxyAPI')
        ->expectsOutputToContain('unknown')
        ->expectsOutputToContain(process_upgrade_cli_request_id())
        ->assertExitCode(0);
});

it('removes an artifact record', function (): void {
    $mock = MockClient::global([UnsetProcessArtifactRequest::class => MockResponse::make([
        'data' => ['id' => 103, 'name' => 'ai-router', 'removed' => true],
        'meta' => ['request_id' => process_upgrade_cli_request_id()],
    ])]);

    $this->artisan('process:artifact:unset', ['process' => '103', '--json' => true])
        ->expectsOutput(json_encode(['id' => 103, 'name' => 'ai-router', 'removed' => true, 'request_id' => process_upgrade_cli_request_id()], JSON_THROW_ON_ERROR))
        ->assertExitCode(0);

    expect($mock->getLastRequest())->toBeInstanceOf(UnsetProcessArtifactRequest::class)
        ->and($mock->getLastPendingRequest()?->getUrl())->toBe('https://10.44.0.1/api/v1/processes/103/artifact');
});
