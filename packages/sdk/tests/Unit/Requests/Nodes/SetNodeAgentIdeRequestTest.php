<?php

declare(strict_types=1);

namespace Orbit\Sdk\Laravel\Tests\Unit\Requests\Nodes;

use Orbit\Sdk\Laravel\GatewayConnector;
use Orbit\Sdk\Laravel\Requests\Nodes\SetNodeAgentIdeRequest;
use Orbit\Sdk\Laravel\Responses\Nodes\NodeAgentIdeResponse;
use Orbit\Sdk\Laravel\Tests\TestCase;
use Saloon\Enums\Method;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

uses(TestCase::class);
it('resolves to POST /api/nodes/{name}/agent-ide with the adapter body', function (): void {
    $request = new SetNodeAgentIdeRequest('app-1', 'opencode');

    expect($request->resolveEndpoint())->toBe('/api/nodes/app-1/agent-ide');
    expect($request->getMethod())->toBe(Method::POST);
    expect($request->body()->all())->toBe(['agent_ide' => 'opencode']);
});

it('returns a NodeAgentIdeResponse DTO', function (): void {
    $mock = new MockClient([
        SetNodeAgentIdeRequest::class => MockResponse::make([
            'success' => [
                'data' => [
                    'name' => 'app-1',
                    'agent_ide' => [
                        'adapter' => 'opencode',
                        'source' => 'node',
                    ],
                    'action' => 'set',
                ],
            ],
        ], 200),
    ]);

    $connector = new GatewayConnector(baseUrl: 'https://10.6.0.2', caPemPath: '/path/to/ca.pem');
    $connector->withMockClient($mock);

    $dto = $connector->send(new SetNodeAgentIdeRequest('app-1', 'opencode'))->dto();

    expect($dto)->toBeInstanceOf(NodeAgentIdeResponse::class);
    expect($dto->name)->toBe('app-1');
    expect($dto->agentIde)->toBe([
        'adapter' => 'opencode',
        'source' => 'node',
    ]);
    expect($dto->action)->toBe('set');
});
