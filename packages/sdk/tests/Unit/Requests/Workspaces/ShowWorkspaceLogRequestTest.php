<?php

declare(strict_types=1);

namespace Orbit\Sdk\Laravel\Tests\Unit\Requests\Workspaces;

use Orbit\Sdk\Laravel\GatewayConnector;
use Orbit\Sdk\Laravel\Requests\Workspaces\ShowWorkspaceLogRequest;
use Orbit\Sdk\Laravel\Responses\Workspaces\WorkspaceLogResponse;
use Orbit\Sdk\Laravel\Tests\TestCase;
use Saloon\Enums\Method;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

uses(TestCase::class);
it('resolves to GET /api/workspaces/runs/{run}/log', function (): void {
    $request = new ShowWorkspaceLogRequest(run: 12);

    expect($request->resolveEndpoint())->toBe('/api/workspaces/runs/12/log');
    expect($request->getMethod())->toBe(Method::GET);
});

it('returns a WorkspaceLogResponse DTO with run and meta payloads', function (): void {
    $mock = new MockClient([
        ShowWorkspaceLogRequest::class => MockResponse::make([
            'success' => [
                'data' => [
                    'run' => [
                        'id' => 12,
                        'workspace' => 'feature-docs',
                        'steps' => [],
                    ],
                ],
                'meta' => [
                    'registry_only' => true,
                ],
            ],
        ], 200),
    ]);

    $connector = new GatewayConnector(baseUrl: 'https://10.6.0.2', caPemPath: '/path/to/ca.pem');
    $connector->withMockClient($mock);

    $dto = $connector->send(new ShowWorkspaceLogRequest(run: 12))->dto();

    expect($dto)->toBeInstanceOf(WorkspaceLogResponse::class);
    expect($dto->run)->toBe([
        'id' => 12,
        'workspace' => 'feature-docs',
        'steps' => [],
    ]);
    expect($dto->meta)->toBe(['registry_only' => true]);
});
