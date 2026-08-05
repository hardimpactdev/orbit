<?php

declare(strict_types=1);

use Orbit\Sdk\Laravel\GatewayConnector;
use Orbit\Sdk\Laravel\Requests\Workspaces\ShowWorkspaceApplicationLogRequest;
use Orbit\Sdk\Laravel\Responses\ApplicationLogs\ApplicationLogResponse;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

it('requires instance and targets the workspace application log endpoint', function (): void {
    $request = new ShowWorkspaceApplicationLogRequest(
        workspace: 'feature-docs',
        instance: 'docs.development',
        lines: 100,
    );

    expect($request->resolveEndpoint())
        ->toBe('/api/workspaces/feature-docs/log')
        ->and($request->query()->all())
        ->toMatchArray([
            'instance' => 'docs.development',
            'lines' => 100,
        ]);
});

it('returns an ApplicationLogResponse DTO', function (): void {
    $mockClient = new MockClient([
        ShowWorkspaceApplicationLogRequest::class => MockResponse::make([
            'success' => [
                'data' => [
                    'path' => 'storage/logs/laravel.log',
                    'target' => ['type' => 'workspace', 'selector' => 'feature-docs'],
                    'lines' => ['a'],
                ],
                'meta' => [],
            ],
        ]),
    ]);

    $connector = new GatewayConnector(baseUrl: 'https://gateway.test');
    $connector->withMockClient($mockClient);

    $dto = $connector->send(new ShowWorkspaceApplicationLogRequest(
        workspace: 'feature-docs',
        instance: 'docs.development',
    ))->dto();

    expect($dto)
        ->toBeInstanceOf(ApplicationLogResponse::class)
        ->and($dto->data['target']['selector'])
        ->toBe('feature-docs');
});
