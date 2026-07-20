<?php

declare(strict_types=1);

namespace Orbit\Sdk\Laravel\Tests\Unit\Requests\Workspaces;

use Orbit\Sdk\Laravel\GatewayConnector;
use Orbit\Sdk\Laravel\Requests\Workspaces\ShowWorkspaceHistoryRequest;
use Orbit\Sdk\Laravel\Responses\Workspaces\WorkspaceHistoryResponse;
use Orbit\Sdk\Laravel\Tests\TestCase;
use Saloon\Enums\Method;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

uses(TestCase::class);
it('resolves to GET /api/workspaces/{name}/history', function (): void {
    $request = new ShowWorkspaceHistoryRequest(name: 'feature-docs');

    expect($request->resolveEndpoint())->toBe('/api/workspaces/feature-docs/history');
    expect($request->getMethod())->toBe(Method::GET);
});

it('serializes filters when provided', function (): void {
    $request = new ShowWorkspaceHistoryRequest(
        name: 'feature-docs',
        instance: 'docs',
        limit: 25,
        since: '2026-05-01T00:00:00Z',
        until: '2026-05-02T00:00:00Z',
    );

    expect($request->query()->all())->toBe([
        'instance' => 'docs',
        'limit' => 25,
        'since' => '2026-05-01T00:00:00Z',
        'until' => '2026-05-02T00:00:00Z',
    ]);
});

it('resolves path lookups to the path endpoint', function (): void {
    $request = new ShowWorkspaceHistoryRequest(path: '/srv/docs/.worktrees/feature-docs');

    expect($request->resolveEndpoint())->toBe('/api/workspaces/history/resolve-by-path');
    expect($request->query()->all())->toBe(['path' => '/srv/docs/.worktrees/feature-docs']);
});

it('returns a WorkspaceHistoryResponse DTO with runs and pagination', function (): void {
    $mock = new MockClient([
        ShowWorkspaceHistoryRequest::class => MockResponse::make([
            'success' => [
                'data' => [
                    'runs' => [
                        ['id' => 12, 'workspace' => 'feature-docs'],
                    ],
                ],
                'meta' => [
                    'pagination' => [
                        'total' => 1,
                        'limit' => 50,
                    ],
                ],
            ],
        ], 200),
    ]);

    $connector = new GatewayConnector(baseUrl: 'https://10.6.0.2', caPemPath: '/path/to/ca.pem');
    $connector->withMockClient($mock);

    $dto = $connector->send(new ShowWorkspaceHistoryRequest(name: 'feature-docs'))->dto();

    expect($dto)->toBeInstanceOf(WorkspaceHistoryResponse::class);
    expect($dto->runs)->toBe([
        ['id' => 12, 'workspace' => 'feature-docs'],
    ]);
    expect($dto->pagination)->toBe([
        'total' => 1,
        'limit' => 50,
    ]);
});
