<?php

declare(strict_types=1);

use Orbit\Sdk\GatewayConnector;
use Orbit\Sdk\Requests\Nodes\AddNodeRoleRequest;
use Orbit\Sdk\Requests\Nodes\RelocateNodeRoleRequest;
use Orbit\Sdk\Requests\Nodes\RemoveNodeRoleRequest;
use Orbit\Sdk\Responses\Nodes\NodeRoleRemovalResponse;
use Orbit\Sdk\Responses\Operations\OperationResponse;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

it('transports role intent and returns the admitted Operation with correlation', function (string $verb): void {
    [$request, $method, $endpoint, $body] = match ($verb) {
        'add' => [new AddNodeRoleRequest(7, 'analytics', false, 41, 42, 'role-proof'), 'POST', '/api/v1/nodes/7/roles',
            ['role' => 'analytics', 'converge_existing' => false, 'postgres_process_id' => 41, 'clickhouse_process_id' => 42]],
        'remove' => [new RemoveNodeRoleRequest(7, 'websocket', true, false, true, 'role-proof'), 'DELETE', '/api/v1/nodes/7/roles/websocket',
            ['force' => true, 'purge_data' => false, 'offline' => true]],
        'relocate' => [new RelocateNodeRoleRequest(7, 'websocket', true, 8, 'role-proof'), 'POST', '/api/v1/nodes/7/roles/websocket/relocate',
            ['force' => true, 'from' => 8]],
    };
    $mock = new MockClient([$request::class => MockResponse::make([
        'data' => ['id' => 123, 'kind' => 'node.role_'.$verb, 'target_type' => 'node', 'target_id' => 7,
            'status' => 'queued', 'sequence' => 1, 'input' => [], 'steps' => []],
        'meta' => ['request_id' => '0198e15c-bf97-7c23-8f1f-61b8fe67a844'],
    ], 202)]);
    $connector = new GatewayConnector('https://gateway.example.test');
    $connector->withMockClient($mock);

    $response = $connector->send($request)->dto();
    $operation = $response instanceof NodeRoleRemovalResponse ? $response->result : $response;

    expect($operation)->toBeInstanceOf(OperationResponse::class);
    expect($operation->id)->toBe(123);
    expect($operation->requestId)->toBe('0198e15c-bf97-7c23-8f1f-61b8fe67a844');
    $mock->assertSent(fn ($pending): bool => $pending->headers()->get('Idempotency-Key') === 'role-proof'
        && $pending->getMethod()->value === $method && $pending->resolveEndpoint() === $endpoint
        && $pending->body()->all() === $body);
})->with(['add', 'remove', 'relocate']);

it('keeps the immediate receipt for an already absent Ingress assignment', function (): void {
    $mock = new MockClient([RemoveNodeRoleRequest::class => MockResponse::make([
        'data' => ['node_id' => 7, 'node_name' => 'roleless', 'role' => 'ingress', 'assignment' => null, 'removed' => true],
        'meta' => ['request_id' => '0198e15c-bf97-7c23-8f1f-61b8fe67a844'],
    ])]);
    $connector = new GatewayConnector('https://gateway.example.test');
    $connector->withMockClient($mock);

    $response = $connector->send(new RemoveNodeRoleRequest(7, 'ingress', true))->dto();

    expect($response)->toBeInstanceOf(NodeRoleRemovalResponse::class);
    expect($response->result->removed)->toBeTrue();
});
