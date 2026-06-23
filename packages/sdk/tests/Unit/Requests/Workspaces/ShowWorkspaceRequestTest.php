<?php

declare(strict_types=1);

namespace Orbit\Sdk\Laravel\Tests\Unit\Requests\Workspaces;

use Orbit\Sdk\Laravel\GatewayConnector;
use Orbit\Sdk\Laravel\Requests\Workspaces\ShowWorkspaceRequest;
use Orbit\Sdk\Laravel\Responses\Workspaces\WorkspaceShowResponse;
use Orbit\Sdk\Laravel\Tests\TestCase;
use Saloon\Enums\Method;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

uses(TestCase::class);
it('resolves to GET /api/workspaces/{name}', function (): void {
    $request = new ShowWorkspaceRequest(name: 'feature-docs');

    expect($request->resolveEndpoint())->toBe('/api/workspaces/feature-docs');
    expect($request->getMethod())->toBe(Method::GET);
});

it('serializes app filter when provided', function (): void {
    $request = new ShowWorkspaceRequest(name: 'feature-docs', app: 'docs');

    expect($request->query()->all())->toBe(['app' => 'docs']);
});

it('resolves path lookups to the path endpoint', function (): void {
    $request = new ShowWorkspaceRequest(path: '/srv/docs/.worktrees/feature-docs');

    expect($request->resolveEndpoint())->toBe('/api/workspaces/resolve-by-path');
    expect($request->query()->all())->toBe(['path' => '/srv/docs/.worktrees/feature-docs']);
});

it('returns a WorkspaceShowResponse DTO with workspace details', function (): void {
    $mock = new MockClient([
        ShowWorkspaceRequest::class => MockResponse::make([
            'success' => [
                'data' => [
                    'workspace' => [
                        'name' => 'feature-docs',
                        'app' => 'docs',
                    ],
                ],
            ],
        ], 200),
    ]);

    $connector = new GatewayConnector(baseUrl: 'https://10.6.0.2', caPemPath: '/path/to/ca.pem');
    $connector->withMockClient($mock);

    $dto = $connector->send(new ShowWorkspaceRequest(name: 'feature-docs'))->dto();

    expect($dto)->toBeInstanceOf(WorkspaceShowResponse::class);
    expect($dto->workspace)->toBe([
        'name' => 'feature-docs',
        'app' => 'docs',
    ]);
});
