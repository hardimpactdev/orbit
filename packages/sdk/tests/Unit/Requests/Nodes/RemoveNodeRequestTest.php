<?php

declare(strict_types=1);

namespace Orbit\Sdk\Laravel\Tests\Unit\Requests\Nodes;

use Orbit\Sdk\Laravel\GatewayConnector;
use Orbit\Sdk\Laravel\Requests\Nodes\RemoveNodeRequest;
use Orbit\Sdk\Laravel\Responses\Nodes\NodeRemoveResponse;
use Orbit\Sdk\Laravel\Tests\TestCase;
use Saloon\Enums\Method;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

uses(TestCase::class);
it('resolves to DELETE /api/nodes/{name} with destructive consent body', function (): void {
    $request = new RemoveNodeRequest('app-1', destructiveConsentSource: 'flag');

    expect($request->resolveEndpoint())->toBe('/api/nodes/app-1');
    expect($request->getMethod())->toBe(Method::DELETE);
    expect($request->body()->all())->toBe([
        'destructive_consent' => true,
        'destructive_consent_source' => 'flag',
    ]);
});

it('defaults destructive_consent_source to "force"', function (): void {
    $request = new RemoveNodeRequest('app-1');

    expect($request->body()->all())->toBe([
        'destructive_consent' => true,
        'destructive_consent_source' => 'force',
    ]);
});

it('returns a NodeRemoveResponse DTO', function (): void {
    $mock = new MockClient([
        RemoveNodeRequest::class => MockResponse::make([
            'success' => [
                'data' => [
                    'name' => 'app-1',
                    'removed' => true,
                    'removed_self' => false,
                    'wireguard_peer_removed' => false,
                    'grants_removed' => 2,
                ],
            ],
        ], 200),
    ]);

    $connector = new GatewayConnector(baseUrl: 'https://10.6.0.2', caPemPath: '/path/to/ca.pem');
    $connector->withMockClient($mock);

    $dto = $connector->send(new RemoveNodeRequest('app-1'))->dto();

    expect($dto)->toBeInstanceOf(NodeRemoveResponse::class);
    expect($dto->name)->toBe('app-1');
    expect($dto->removed)->toBeTrue();
    expect($dto->removedSelf)->toBeFalse();
    expect($dto->wireguardPeerRemoved)->toBeFalse();
    expect($dto->grantsRemoved)->toBe(2);
});
