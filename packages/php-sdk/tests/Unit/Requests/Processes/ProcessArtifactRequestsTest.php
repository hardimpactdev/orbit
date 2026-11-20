<?php

declare(strict_types=1);

use Orbit\Sdk\GatewayConnector;
use Orbit\Sdk\Requests\Processes\SetProcessArtifactRequest;
use Orbit\Sdk\Requests\Processes\UnsetProcessArtifactRequest;
use Orbit\Sdk\Requests\Processes\UpgradeProcessRequest;
use Orbit\Sdk\Responses\Operations\OperationResponse;
use Orbit\Sdk\Responses\Processes\ProcessArtifactRemovedResponse;
use Orbit\Sdk\Responses\Processes\ProcessArtifactResponse;
use Saloon\Enums\Method;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

function artifact_sdk_request_id(): string
{
    return '0198e15c-bf97-7c23-8f1f-61b8fe67a845';
}

function artifact_sdk_connector(MockClient $mock): GatewayConnector
{
    $connector = new GatewayConnector('https://10.44.0.1');
    $connector->withMockClient($mock);

    return $connector;
}

/** @return array<string, mixed> */
function artifact_sdk_data(): array
{
    return [
        'source' => 'github_release',
        'repository' => 'router-for-me/CLIProxyAPI',
        'asset' => 'CLIProxyAPI_{version}_linux_amd64.tar.gz',
        'binary' => 'cli-proxy-api',
        'checksums' => 'checksums.txt',
        'version_args' => ['--help'],
        'version' => '8.0.63',
        'previous_version' => null,
        'sha256' => null,
        'latest_version' => '8.0.80',
        'latest_checked_at' => '2026-11-20T03:00:00+00:00',
    ];
}

it('records an artifact with a PUT that omits absent fields', function (): void {
    $mock = new MockClient([SetProcessArtifactRequest::class => MockResponse::make([
        'data' => ['id' => 103, 'name' => 'ai-router', 'artifact' => artifact_sdk_data()],
        'meta' => ['request_id' => artifact_sdk_request_id()],
    ])]);
    $request = new SetProcessArtifactRequest(103, preset: 'cliproxyapi', versionArgs: ['--help'], version: '8.0.63');
    $response = artifact_sdk_connector($mock)->send($request)->dto();

    expect($request->getMethod())->toBe(Method::PUT)
        ->and($request->resolveEndpoint())->toBe('/api/v1/processes/103/artifact')
        ->and($mock->getLastRequest()?->body()->all())->toBe(['preset' => 'cliproxyapi', 'version_args' => ['--help'], 'version' => '8.0.63'])
        ->and($response)->toBeInstanceOf(ProcessArtifactResponse::class)
        ->and($response->toArray())->toBe(['id' => 103, 'name' => 'ai-router', 'artifact' => artifact_sdk_data(), 'request_id' => artifact_sdk_request_id()]);
});

it('removes an artifact with a bodyless DELETE', function (): void {
    $mock = new MockClient([UnsetProcessArtifactRequest::class => MockResponse::make([
        'data' => ['id' => 103, 'name' => 'ai-router', 'removed' => true],
        'meta' => ['request_id' => artifact_sdk_request_id()],
    ])]);
    $request = new UnsetProcessArtifactRequest(103);
    $response = artifact_sdk_connector($mock)->send($request)->dto();

    expect($request->getMethod())->toBe(Method::DELETE)
        ->and($request->resolveEndpoint())->toBe('/api/v1/processes/103/artifact')
        ->and($mock->getLastPendingRequest()?->body())->toBeNull()
        ->and($response)->toBeInstanceOf(ProcessArtifactRemovedResponse::class)
        ->and($response->toArray())->toBe(['id' => 103, 'name' => 'ai-router', 'removed' => true, 'request_id' => artifact_sdk_request_id()]);
});

it('starts an upgrade Operation with the version and the admission key', function (): void {
    $operation = ['id' => 91, 'kind' => 'process.upgrade', 'target_type' => 'process', 'target_id' => 103, 'input' => [], 'status' => 'queued', 'sequence' => 1, 'cancel_requested' => false, 'attempts' => 0, 'result' => [], 'steps' => []];
    $mock = new MockClient([UpgradeProcessRequest::class => MockResponse::make(['data' => $operation, 'meta' => ['request_id' => artifact_sdk_request_id()]], 202)]);
    $request = new UpgradeProcessRequest(103, 'v8.0.80', 'upgrade-proof');
    $response = artifact_sdk_connector($mock)->send($request)->dto();

    expect($request->getMethod())->toBe(Method::POST)
        ->and($request->resolveEndpoint())->toBe('/api/v1/processes/103/upgrade')
        ->and($mock->getLastRequest()?->body()->all())->toBe(['version' => 'v8.0.80'])
        ->and($mock->getLastPendingRequest()?->headers()->get('Idempotency-Key'))->toBe('upgrade-proof')
        ->and($response)->toBeInstanceOf(OperationResponse::class)
        ->and($response->kind)->toBe('process.upgrade');
});
