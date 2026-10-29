<?php

declare(strict_types=1);

use Orbit\Sdk\GatewayConnector;
use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Requests\Instances\CreateInstanceRequest;
use Orbit\Sdk\Requests\Instances\DestroyInstanceRequest;
use Orbit\Sdk\Requests\Instances\RegisterInstanceRequest;
use Orbit\Sdk\Requests\Instances\RenameInstanceRequest;
use Orbit\Sdk\Responses\Operations\OperationResponse;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

it('preserves lifecycle admission payloads, keys and typed Operation receipts', function (GatewayRequest $request, string $kind, string $endpoint, array $body): void {
    $requestId = '0198e15c-bf97-7c23-8f1f-61b8fe67a846';
    $mock = new MockClient([$request::class => MockResponse::make([
        'data' => ['id' => 901, 'kind' => $kind, 'target_type' => 'instance', 'target_id' => 501,
            'status' => 'queued', 'sequence' => 1, 'steps' => []],
        'meta' => ['request_id' => $requestId],
    ], 202)]);
    $connector = new GatewayConnector('https://gateway.example.test');
    $connector->withMockClient($mock);
    $result = $connector->send($request)->dto();
    expect($result)->toBeInstanceOf(OperationResponse::class)
        ->and($result->id)->toBe(901)->and($result->kind)->toBe($kind)
        ->and($result->requestId)->toBe($requestId)
        ->and($mock->getLastPendingRequest()?->headers()->get('Idempotency-Key'))->toBe('lifecycle-proof')
        ->and($request->resolveEndpoint())->toBe($endpoint)->and($request->body()->all())->toBe($body);
})->with([
    'create' => [new CreateInstanceRequest(50, 9, 'proof', root: 'server/web/public', idempotencyKey: 'lifecycle-proof'),
        'instance.create', '/api/v1/instances', ['project_id' => 50, 'node_id' => 9, 'name' => 'proof', 'root' => 'server/web/public']],
    'register' => [new RegisterInstanceRequest('/work/project', setup: true, idempotencyKey: 'lifecycle-proof'),
        'instance.register', '/api/v1/instances/register', ['source_path' => '/work/project', 'setup' => true]],
    'rename' => [new RenameInstanceRequest(501, 'feature', idempotencyKey: 'lifecycle-proof'),
        'instance.rename', '/api/v1/instances/501/rename', ['branch' => 'feature']],
    'destroy' => [new DestroyInstanceRequest(501, force: true, idempotencyKey: 'lifecycle-proof'),
        'instance.destroy', '/api/v1/instances/501', ['force' => true]],
]);
