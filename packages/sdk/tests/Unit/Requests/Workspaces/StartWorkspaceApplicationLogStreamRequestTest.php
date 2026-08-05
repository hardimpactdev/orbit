<?php

declare(strict_types=1);

use Orbit\Sdk\Laravel\GatewayConnector;
use Orbit\Sdk\Laravel\Requests\Workspaces\StartWorkspaceApplicationLogStreamRequest;
use Orbit\Sdk\Laravel\Responses\ApplicationLogs\ApplicationLogStreamResponse;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

it('targets the workspace application log stream-start endpoint with body fields', function (): void {
    $request = new StartWorkspaceApplicationLogStreamRequest(
        workspace: 'feature-docs',
        instance: 'docs.development',
        lines: 40,
        node: 'app-dev-1',
    );

    expect($request->resolveEndpoint())
        ->toBe('/api/workspaces/feature-docs/log-stream')
        ->and($request->body()->all())
        ->toMatchArray([
            'instance' => 'docs.development',
            'lines' => 40,
            'node' => 'app-dev-1',
        ]);
});

it('returns an ApplicationLogStreamResponse DTO', function (): void {
    $mockClient = new MockClient([
        StartWorkspaceApplicationLogStreamRequest::class => MockResponse::make([
            'success' => [
                'data' => [
                    'operation' => [
                        'uuid' => 'op-workspace-log',
                        'stream_descriptor_url' => '/api/operations/op-workspace-log/stream',
                        'events_url' => '/api/operations/op-workspace-log/events',
                    ],
                ],
                'meta' => [],
            ],
        ]),
    ]);

    $connector = new GatewayConnector(baseUrl: 'https://gateway.test');
    $connector->withMockClient($mockClient);

    $dto = $connector->send(new StartWorkspaceApplicationLogStreamRequest(
        workspace: 'feature-docs',
        instance: 'docs.development',
    ))->dto();

    expect($dto)
        ->toBeInstanceOf(ApplicationLogStreamResponse::class)
        ->and($dto->data['operation']['uuid'] ?? null)
        ->toBe('op-workspace-log');
});
