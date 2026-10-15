<?php

declare(strict_types=1);

use Orbit\Sdk\GatewayConnector;
use Orbit\Sdk\Requests\Nodes\UpdateNodeSettingsRequest;
use Orbit\Sdk\Responses\Nodes\AppsSettings;
use Orbit\Sdk\Responses\Nodes\NodeResponse;
use Orbit\Sdk\Responses\Nodes\NodeSettings;
use Orbit\Sdk\Responses\Nodes\TaskVmsSettings;
use Saloon\Enums\Method;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

it('sends a partial settings patch and preserves omitted members', function (): void {
    $mockClient = new MockClient([
        UpdateNodeSettingsRequest::class => MockResponse::make([
            'data' => [
                'id' => 2,
                'name' => 'app-dev',
                'status' => 'active',
                'public_ssh_host' => '94.237.40.75',
                'public_ssh_port' => 22,
                'user' => 'orbit',
                'roles' => ['app-dev'],
                'settings' => [
                    'apps' => ['path' => '/srv/orbit/apps'],
                ],
            ],
            'meta' => ['request_id' => '0198e15c-bf97-7c23-8f1f-61b8fe67a844'],
        ]),
    ]);
    $connector = new GatewayConnector('https://10.44.0.1');
    $connector->withMockClient($mockClient);
    $request = new UpdateNodeSettingsRequest(
        nodeId: 2,
        hasApps: true,
        apps: new AppsSettings('/srv/orbit/apps'),
    );

    $response = $connector->send($request)->dto();

    expect($request->getMethod())
        ->toBe(Method::PATCH)
        ->and($request->resolveEndpoint())
        ->toBe('/api/v1/nodes/2/settings')
        ->and($request->body()->all())
        ->toBe([
            'apps' => ['path' => '/srv/orbit/apps'],
        ])
        ->and($response)
        ->toBeInstanceOf(NodeResponse::class)
        ->and($response->settings?->apps?->path)
        ->toBe('/srv/orbit/apps');
});

it('keeps apps-only fromSettings callers from removing host intent', function (): void {
    $request = UpdateNodeSettingsRequest::fromSettings(9, new NodeSettings(new AppsSettings('/srv/apps')));
    expect($request->body()->all())->toBe(['apps' => ['path' => '/srv/apps']]);
});

it('sends task host intent and explicit clearing without touching apps', function (): void {
    $host = new TaskVmsSettings(cidr: '10.251.77.0/24', max_vms: 4, operator_owned: []);
    $request = new UpdateNodeSettingsRequest(nodeId: 9, hasTaskVms: true, taskVms: $host);
    expect($request->body()->all())->toBe(['task_vms' => [
        'cidr' => '10.251.77.0/24', 'max_vms' => 4, 'operator_owned' => [],
    ]]);
    $clear = new UpdateNodeSettingsRequest(nodeId: 9, hasTaskVms: true);
    expect($clear->body()->all())->toBe(['task_vms' => null]);
});

it('sends an explicit null nested member to unset a setting', function (): void {
    $request = new UpdateNodeSettingsRequest(
        nodeId: 2,
        hasApps: true,
        apps: null,
    );

    expect($request->body()->all())->toBe([
        'apps' => null,
    ]);
});
