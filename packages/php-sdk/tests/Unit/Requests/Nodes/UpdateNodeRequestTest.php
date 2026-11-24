<?php

declare(strict_types=1);

use Orbit\Sdk\GatewayApiException;
use Orbit\Sdk\GatewayConnector;
use Orbit\Sdk\Requests\Nodes\CiPlacesInput;
use Orbit\Sdk\Requests\Nodes\UpdateNodeRequest;
use Orbit\Sdk\Responses\Nodes\AppsSettings;
use Orbit\Sdk\Responses\Nodes\NodeResponse;
use Saloon\Enums\Method;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

it('sends a partial settings patch and preserves omitted members', function (): void {
    $mockClient = new MockClient([
        UpdateNodeRequest::class => MockResponse::make([
            'data' => [
                'id' => 2,
                'name' => 'app-dev',
                'status' => 'active',
                'public_ssh_host' => '94.237.40.75',
                'public_ssh_port' => 22,
                'user' => 'orbit',
                'roles' => ['app-dev'],
                'assignments' => [['id' => 1, 'role' => 'app-dev', 'status' => 'active', 'failed_step' => null, 'error_code' => null]],
                'settings' => [
                    'apps' => ['path' => '/srv/orbit/apps'],
                ],
            ],
            'meta' => ['request_id' => '0198e15c-bf97-7c23-8f1f-61b8fe67a844'],
        ]),
    ]);
    $connector = new GatewayConnector('https://10.44.0.1');
    $connector->withMockClient($mockClient);
    $request = new UpdateNodeRequest(
        nodeId: 2,
        hasApps: true,
        apps: new AppsSettings('/srv/orbit/apps'),
    );

    $response = $connector->send($request)->dto()->result;

    expect($request->getMethod())
        ->toBe(Method::PATCH)
        ->and($request->resolveEndpoint())
        ->toBe('/api/v1/nodes/2')
        ->and($request->body()->all())
        ->toBe([
            'apps' => ['path' => '/srv/orbit/apps'],
        ])
        ->and($response)
        ->toBeInstanceOf(NodeResponse::class)
        ->and($response->settings?->apps?->path)
        ->toBe('/srv/orbit/apps');
});

it('sends only the CI members it names, and an explicit null to clear them', function (?CiPlacesInput $ci, ?array $body): void {
    $request = new UpdateNodeRequest(nodeId: 14, hasCi: true, ci: $ci);

    expect($request->body()->all())->toBe(['ci' => $body]);
})->with([
    'places' => [new CiPlacesInput(places: 4), ['places' => 4]],
    'proof only' => [new CiPlacesInput(proofOnly: true), ['proof_only' => true]],
    'both, with zero places' => [new CiPlacesInput(places: 0, proofOnly: false), ['places' => 0, 'proof_only' => false]],
    'cleared' => [null, null],
]);

it('reads the CI places of the updated Node', function (): void {
    $connector = new GatewayConnector('https://10.44.0.1');
    $connector->withMockClient(new MockClient([UpdateNodeRequest::class => MockResponse::make(['data' => [
        'id' => 14, 'name' => 'ci-node', 'status' => 'active', 'public_ssh_host' => '10.44.0.20', 'public_ssh_port' => 22, 'user' => 'orbit',
        'roles' => ['ci-runner'], 'settings' => ['ci' => ['places' => 2, 'proof_only' => true]],
    ], 'meta' => ['request_id' => '0198e15c-bf97-7c23-8f1f-61b8fe67a844']])]));

    $node = $connector->send(new UpdateNodeRequest(nodeId: 14, hasCi: true, ci: new CiPlacesInput(places: 2)))->dto()->result;

    expect($node)->toBeInstanceOf(NodeResponse::class)
        ->and($node->settings?->ci?->places)->toBe(2)
        ->and($node->settings?->ci?->proof_only)->toBeTrue();
});

it('sends an explicit null nested member to unset a setting', function (): void {
    $request = new UpdateNodeRequest(
        nodeId: 2,
        hasApps: true,
        apps: null,
    );

    expect($request->body()->all())->toBe([
        'apps' => null,
    ]);
});

it('sends the record fields beside a settings member', function (): void {
    $request = new UpdateNodeRequest(nodeId: 2, name: 'tablet', platform: 'ipados', hasApps: true, apps: new AppsSettings('/srv/apps'));

    expect($request->body()->all())->toBe(['name' => 'tablet', 'platform' => 'ipados', 'apps' => ['path' => '/srv/apps']]);
});

it('sends only the platform and returns the recorded Node', function (): void {
    $recorded = node_update_fixture('nodes/node-update/platform-updated');
    $request = new UpdateNodeRequest(2, platform: 'ipados');
    $node = node_update_fixture_connector($request, $recorded)->send($request)->dto()->result;

    expect($request->body()->all())->toBe(['platform' => 'ipados'])
        ->and($node)->toBeInstanceOf(NodeResponse::class)
        ->and($node->platform)->toBe('ipados')
        ->and($node->architecture)->toBe('arm64')
        ->and($node->id)->toBe($recorded['body']['data']['id'])
        ->and($node->name)->toBe($recorded['body']['data']['name']);
});

it('preserves the recorded refusal of a platform on a managed Node', function (): void {
    $recorded = node_update_fixture('nodes/node-update/platform-managed-refused');
    $request = new UpdateNodeRequest(2, platform: 'ipados');

    expect(fn () => node_update_fixture_connector($request, $recorded)->send($request)->dto())
        ->toThrow(function (GatewayApiException $exception) use ($recorded): void {
            expect($exception->errorCode())->toBe('node.platform_managed')
                ->and($exception->getMessage())->toBe($recorded['body']['error']['message']);
        });
});

/** @return array<string, mixed> */
function node_update_fixture(string $name): array
{
    $decoded = json_decode((string) file_get_contents(dirname(__DIR__, levels: 4)."/fixtures/{$name}.json"), true, flags: JSON_THROW_ON_ERROR);
    assert(is_array($decoded));

    return $decoded;
}

/** @param array<string, mixed> $recorded */
function node_update_fixture_connector(UpdateNodeRequest $request, array $recorded): GatewayConnector
{
    $connector = new GatewayConnector('https://10.44.0.1');
    $connector->withMockClient(new MockClient([$request::class => MockResponse::make($recorded['body'], $recorded['status'])]));

    return $connector;
}

it('transports explicit reboot holds and clearing while omitting an unspecified hold', function (): void {
    expect(new UpdateNodeRequest(nodeId: 2)->body()->all())->toBe([]);
    foreach ([true, false] as $hold) {
        $request = new UpdateNodeRequest(nodeId: 2, rebootHold: $hold);
        expect($request->body()->all())->toBe(['reboot_hold' => $hold]);
        $node = NodeResponse::fromGatewayData(['id' => 2, 'reboot_hold' => $hold], '0198e15c-bf97-7c23-8f1f-61b8fe67a844');
        expect($node->rebootHold)->toBe($hold)->and($node->toArray()['reboot_hold'])->toBe($hold);
    }
});

it('transports primary_agent designation while omitting it when unspecified', function (): void {
    expect(new UpdateNodeRequest(nodeId: 2)->body()->all())->toBe([]);
    foreach ([true, false] as $primary) {
        $request = new UpdateNodeRequest(nodeId: 2, primaryAgent: $primary);
        expect($request->body()->all())->toBe(['primary_agent' => $primary]);
        $node = NodeResponse::fromGatewayData(['id' => 2, 'primary_agent' => $primary], '0198e15c-bf97-7c23-8f1f-61b8fe67a844');
        expect($node->primaryAgent)->toBe($primary)->and($node->toArray()['primary_agent'])->toBe($primary);
    }
});

it('reads a Node body without image_refresh_hold and returns none', function (): void {
    expect(NodeResponse::fromGatewayData(['id' => 12], '0198e15c-bf97-7c23-8f1f-61b8fe67a844')->toArray())->not->toHaveKey('image_refresh_hold')
        ->and(NodeResponse::fromGatewayData(['id' => 12, 'image_refresh_hold' => true], '0198e15c-bf97-7c23-8f1f-61b8fe67a844')->toArray())->not->toHaveKey('image_refresh_hold');
});
