<?php

declare(strict_types=1);

use Orbit\Sdk\GatewayConnector;
use Orbit\Sdk\Requests\Conn\RestartConnEnvironmentRequest;
use Orbit\Sdk\Requests\Conn\RestartNodeConnEnvironmentsRequest;
use Orbit\Sdk\Responses\Conn\ConnEnvironmentRestartsResponse;
use Saloon\Enums\Method;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

beforeEach(fn () => MockClient::destroyGlobal());
afterEach(fn () => MockClient::destroyGlobal());

it('restarts one Instance or each container of a Node', function (string $request, string $path): void {
    $request = new $request(42);

    expect($request->resolveEndpoint())->toBe($path)->and($request->getMethod())->toBe(Method::POST);
})->with([
    'one Instance' => [RestartConnEnvironmentRequest::class, '/api/v1/conn/instances/42/t3-restart'],
    'a Node' => [RestartNodeConnEnvironmentsRequest::class, '/api/v1/conn/nodes/42/t3-restart'],
]);

it('reads each answer, with the Operation of a restart', function (): void {
    $connector = new GatewayConnector('https://gateway.orbit', caPemPath: '/tmp/root.pem');
    MockClient::global([RestartNodeConnEnvironmentsRequest::class => MockResponse::make(['data' => ['restarts' => [
        ['instance_id' => 3, 'instance' => 'default', 'answer' => 'current', 'reason' => null, 'operation_id' => null],
        ['instance_id' => 4, 'instance' => 'clone', 'answer' => 'restarting', 'reason' => null, 'operation_id' => 9],
        ['instance_id' => 5, 'instance' => 'busy', 'answer' => 'busy', 'reason' => 't3_working', 'operation_id' => null],
        ['instance_id' => 6, 'instance' => 'odd', 'answer' => 'failed', 'reason' => 'conn.restart_admission_failed', 'operation_id' => null],
    ]], 'meta' => ['request_id' => 'restart-id']])]);

    $response = $connector->send(new RestartNodeConnEnvironmentsRequest(7))->dto();

    expect(array_map(static fn ($restart): array => [$restart->instanceId, $restart->answer, $restart->reason, $restart->operationId], $response->restarts))
        ->toBe([[3, 'current', null, null], [4, 'restarting', null, 9], [5, 'busy', 't3_working', null], [6, 'failed', 'conn.restart_admission_failed', null]]);
});

it('refuses a restarting answer without its Operation', function (): void {
    expect(fn () => ConnEnvironmentRestartsResponse::fromGatewayData(['restarts' => [
        ['instance_id' => 4, 'instance' => 'clone', 'answer' => 'restarting', 'reason' => null, 'operation_id' => null],
    ]], 'restart-id'))->toThrow(InvalidArgumentException::class, 'invalid T3 restart answer');
});
