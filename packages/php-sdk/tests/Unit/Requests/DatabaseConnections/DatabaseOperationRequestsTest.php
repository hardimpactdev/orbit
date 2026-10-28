<?php

declare(strict_types=1);

use Orbit\Sdk\GatewayConnector;
use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Requests\DatabaseConnections\CreateDatabaseConnectionRequest;
use Orbit\Sdk\Requests\DatabaseConnections\CreateDatabaseUserRequest;
use Orbit\Sdk\Requests\DatabaseConnections\DestroyDatabaseConnectionRequest;
use Orbit\Sdk\Requests\DatabaseConnections\QueryDatabaseConnectionRequest;
use Orbit\Sdk\Requests\DatabaseServers\CloseDatabaseServerRequest;
use Orbit\Sdk\Requests\DatabaseServers\CreateDatabaseServerRequest;
use Orbit\Sdk\Requests\DatabaseServers\DestroyDatabaseServerRequest;
use Orbit\Sdk\Responses\DatabaseConnections\DestroyedDatabaseConnectionResponse;
use Orbit\Sdk\Responses\Operations\OperationResponse;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

it('preserves the start key and decodes the accepted database Operation', function (GatewayRequest $request): void {
    $connector = new GatewayConnector('https://gateway.example.test', '/tmp/unused-ca.pem');
    $connector->withMockClient(new MockClient([$request::class => MockResponse::make([
        'data' => ['id' => 7, 'kind' => 'database.create', 'target_type' => 'database_server', 'target_id' => 2, 'status' => 'queued', 'sequence' => 1, 'input' => ['intent_id' => 9]],
        'meta' => ['request_id' => '11111111-1111-4111-8111-111111111111'],
    ], 202)]));

    $response = $connector->send($request)->dto();
    $operation = $response instanceof DestroyedDatabaseConnectionResponse ? $response->result : $response;

    expect($request->headers()->get('Idempotency-Key'))->toBe('same-start-key');
    expect($operation)->toBeInstanceOf(OperationResponse::class);
    expect($operation->id)->toBe(7);
})->with([
    'server-create' => [new CreateDatabaseServerRequest('dev-db', 2, idempotencyKey: 'same-start-key')],
    'server-destroy' => [new DestroyDatabaseServerRequest('dev-db', 'same-start-key')],
    'server-close' => [new CloseDatabaseServerRequest('dev-db', 'same-start-key')],
    'database-create' => [new CreateDatabaseConnectionRequest('throwaway', server: 'dev-db', idempotencyKey: 'same-start-key')],
    'database-user-create' => [new CreateDatabaseUserRequest('throwaway', 'reader', 'synthetic-password', idempotencyKey: 'same-start-key')],
    'database-destroy' => [new DestroyDatabaseConnectionRequest('throwaway', 'same-start-key')],
    'database-write' => [new QueryDatabaseConnectionRequest('throwaway', 'CREATE TABLE sample (id integer)', true, 'same-start-key')],
]);
