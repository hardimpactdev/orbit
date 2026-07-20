<?php

declare(strict_types=1);

namespace Orbit\Sdk\Laravel\Tests\Unit\Requests\Workspaces;

use Orbit\Sdk\Laravel\GatewayConnector;
use Orbit\Sdk\Laravel\Requests\Workspaces\ListWorkspacesRequest;
use Orbit\Sdk\Laravel\Responses\Workspaces\WorkspaceListResponse;
use Orbit\Sdk\Laravel\Tests\TestCase;
use Saloon\Enums\Method;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

uses(TestCase::class);
it('resolves to GET /api/workspaces', function (): void {
    $request = new ListWorkspacesRequest;

    expect($request->resolveEndpoint())->toBe('/api/workspaces');
    expect($request->getMethod())->toBe(Method::GET);
});

it('serializes app and node filters when provided', function (): void {
    $request = new ListWorkspacesRequest(instance: 'docs', node: 'app-1');

    expect($request->query()->all())->toBe([
        'instance' => 'docs',
        'node' => 'app-1',
    ]);
});

it('omits null filters from the query', function (): void {
    $request = new ListWorkspacesRequest(node: 'app-1');

    expect($request->query()->all())->toBe(['node' => 'app-1']);
});

it('returns a WorkspaceListResponse DTO with workspaces', function (): void {
    $mock = new MockClient([
        ListWorkspacesRequest::class => MockResponse::make([
            'success' => [
                'data' => [
                    'workspaces' => [
                        ['name' => 'feature-docs', 'instance' => 'docs'],
                    ],
                ],
            ],
        ], 200),
    ]);

    $connector = new GatewayConnector(baseUrl: 'https://10.6.0.2', caPemPath: '/path/to/ca.pem');
    $connector->withMockClient($mock);

    $dto = $connector->send(new ListWorkspacesRequest)->dto();

    expect($dto)->toBeInstanceOf(WorkspaceListResponse::class);
    expect($dto->workspaces)->toBe([
        ['name' => 'feature-docs', 'instance' => 'docs'],
    ]);
});
