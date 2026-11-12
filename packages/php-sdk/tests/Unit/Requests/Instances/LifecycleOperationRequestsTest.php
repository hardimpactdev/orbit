<?php

declare(strict_types=1);

use Orbit\Sdk\GatewayConnector;
use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Requests\Instances\CloneInstanceRequest;
use Orbit\Sdk\Requests\Instances\CreateInstanceRequest;
use Orbit\Sdk\Requests\Instances\DestroyInstanceRequest;
use Orbit\Sdk\Requests\Instances\MigrateInstanceRequest;
use Orbit\Sdk\Requests\Instances\RegisterInstanceRequest;
use Orbit\Sdk\Requests\Instances\TransferInstanceRequest;
use Orbit\Sdk\Requests\Instances\UpdateInstanceMailRequest;
use Orbit\Sdk\Responses\Instances\InstanceRegistrationResult;
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
    // A registration that needs a run answers 202 with its Operation inside the registration result.
    if ($result instanceof InstanceRegistrationResult) {
        expect($result->instance)->toBeNull();
        $result = $result->operation;
    }
    expect($result)->toBeInstanceOf(OperationResponse::class)
        ->and($result->id)->toBe(901)->and($result->kind)->toBe($kind)
        ->and($result->requestId)->toBe($requestId)
        ->and($mock->getLastPendingRequest()?->headers()->get('Idempotency-Key'))->toBe('lifecycle-proof')
        ->and($request->resolveEndpoint())->toBe($endpoint)->and($request->body()->all())->toBe($body);
})->with([
    'create' => [new CreateInstanceRequest(50, 9, 'proof', root: 'server/web/public', idempotencyKey: 'lifecycle-proof'),
        'instance.create', '/api/v1/instances', ['project_id' => 50, 'node_id' => 9, 'name' => 'proof', 'root' => 'server/web/public']],
    'clone' => [new CloneInstanceRequest(501, 10, 'prod', 'preview', idempotencyKey: 'lifecycle-proof'),
        'instance.clone', '/api/v1/instances/501/clone', ['node_id' => 10, 'name' => 'prod', 'preview_name' => 'preview']],
    'register' => [new RegisterInstanceRequest('/work/project', setup: true, idempotencyKey: 'lifecycle-proof'),
        'instance.register', '/api/v1/instances/register', ['source_path' => '/work/project', 'setup' => true]],
    'transfer' => [new TransferInstanceRequest(501, 10, name: 'proof', idempotencyKey: 'lifecycle-proof'),
        'instance.transfer', '/api/v1/instances/501/transfer', ['node_id' => 10, 'name' => 'proof']],
    'mail update' => [new UpdateInstanceMailRequest(501, idempotencyKey: 'lifecycle-proof'),
        'instance.mail_update', '/api/v1/instances/501/mail', []],
    'migrate' => [new MigrateInstanceRequest(501, idempotencyKey: 'lifecycle-proof'),
        'instance.migrate', '/api/v1/instances/501/migrate', []],
    'destroy' => [new DestroyInstanceRequest(501, force: true, idempotencyKey: 'lifecycle-proof'),
        'instance.destroy', '/api/v1/instances/501', ['force' => true]],
]);
