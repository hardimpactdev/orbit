<?php

declare(strict_types=1);

namespace Orbit\Sdk\Laravel\Tests\Unit\Requests\Nodes;

use Orbit\Sdk\Laravel\GatewayConnector;
use Orbit\Sdk\Laravel\Requests\Nodes\RevokeNodeRequest;
use Orbit\Sdk\Laravel\Responses\Nodes\NodeRevokeResponse;
use Orbit\Sdk\Laravel\Tests\TestCase;
use Saloon\Enums\Method;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

uses(TestCase::class);
it('resolves to POST /api/nodes/revoke with body', function (): void {
    $request = new RevokeNodeRequest('app-1', 'gw-1');

    expect($request->resolveEndpoint())->toBe('/api/nodes/revoke');
    expect($request->getMethod())->toBe(Method::POST);
    expect($request->body()->all())->toBe([
        'consuming_node' => 'app-1',
        'serving_node' => 'gw-1',
        'force' => true,
    ]);
});

it('returns a NodeRevokeResponse DTO', function (): void {
    $mock = new MockClient([
        RevokeNodeRequest::class => MockResponse::make([
            'success' => [
                'data' => [
                    'consuming_node' => 'app-1',
                    'serving_node' => 'gw-1',
                    'already_absent' => false,
                    'self_lockout' => false,
                ],
            ],
        ], 200),
    ]);

    $connector = new GatewayConnector(baseUrl: 'https://10.6.0.2', caPemPath: '/path/to/ca.pem');
    $connector->withMockClient($mock);

    $dto = $connector->send(new RevokeNodeRequest('app-1', 'gw-1'))->dto();

    expect($dto)->toBeInstanceOf(NodeRevokeResponse::class);
    expect($dto->consumingNode)->toBe('app-1');
    expect($dto->servingNode)->toBe('gw-1');
    expect($dto->alreadyAbsent)->toBeFalse();
    expect($dto->selfLockout)->toBeFalse();
});
