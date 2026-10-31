<?php

declare(strict_types=1);

use Orbit\Sdk\GatewayApiException;
use Orbit\Sdk\GatewayConnector;
use Orbit\Sdk\Requests\Nodes\AddNodeRoleRequest;
use Orbit\Sdk\Responses\Operations\OperationResponse;
use Saloon\Enums\Method;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

describe(AddNodeRoleRequest::class, function (): void {
    it('transports Ingress assignment and explicit retry through the same typed request', function (): void {
        $assignment = new AddNodeRoleRequest(nodeId: 17, role: 'ingress');
        $retry = new AddNodeRoleRequest(nodeId: 17, role: 'ingress', convergeExisting: true);

        expect($assignment->resolveEndpoint())
            ->toBe('/api/v1/nodes/17/roles')
            ->and($assignment->body()->all())
            ->toBe(['role' => 'ingress', 'converge_existing' => false])
            ->and($retry->body()->all())
            ->toBe(['role' => 'ingress', 'converge_existing' => true]);
    });

    it('sends the two storage Process IDs for the analytics role and leaves them out otherwise', function (): void {
        $analytics = new AddNodeRoleRequest(17, 'analytics', false, 41, 42);
        $other = new AddNodeRoleRequest(17, 'database');

        expect($analytics->body()->all())
            ->toBe(['role' => 'analytics', 'converge_existing' => false, 'postgres_process_id' => 41, 'clickhouse_process_id' => 42])
            ->and($other->body()->all())
            ->toBe(['role' => 'database', 'converge_existing' => false]);
    });

    it('transports the exact role intent and parses the queued Operation', function (): void {
        $data = ['id' => 91, 'kind' => 'node.role_add', 'target_type' => 'node', 'target_id' => 13, 'status' => 'queued',
            'sequence' => 1, 'input' => ['role' => 'ci-runner'], 'poll_after_seconds' => 2];
        $client = new MockClient([AddNodeRoleRequest::class => MockResponse::make([
            'data' => $data, 'meta' => ['request_id' => node_role_request_id()],
        ], 202)]);
        $connector = node_role_gateway_connector($client);
        $request = new AddNodeRoleRequest(13, 'ci-runner', true, idempotencyKey: 'native-role-once');
        $response = $connector->send($request)->dto();
        expect($request->getMethod())->toBe(Method::POST)->and($request->resolveEndpoint())->toBe('/api/v1/nodes/13/roles')
            ->and($request->body()->all())->toBe(['role' => 'ci-runner', 'converge_existing' => true])
            ->and($client->getLastPendingRequest()?->headers()->get('Idempotency-Key'))->toBe('native-role-once')
            ->and($response)->toBeInstanceOf(OperationResponse::class)->and($response->id)->toBe(91)
            ->and($response->status)->toBe('queued')->and($response->requestId)->toBe(node_role_request_id());
    });

    it('maps gateway validation failures with safe details and header request id', function (): void {
        $mockClient = new MockClient([
            AddNodeRoleRequest::class => MockResponse::make(
                [
                    'error' => [
                        'code' => 'validation.failed',
                        'message' => 'Role [gateway] is protected from generic mutation.',
                        'details' => [
                            'field' => 'role',
                            'role' => 'gateway',
                        ],
                    ],
                ],
                422,
                ['X-Orbit-Request-Id' => '0198e15d-16c4-7855-8eb2-182b53ad28ba'],
            ),
        ]);
        $connector = new GatewayConnector('https://10.44.0.1');
        $connector->withMockClient($mockClient);

        try {
            $connector->send(new AddNodeRoleRequest(nodeId: 7, role: 'gateway'))->dto();
            test()->fail('Expected GatewayApiException.');
        } catch (GatewayApiException $exception) {
            expect($exception->getMessage())
                ->toBe('Role [gateway] is protected from generic mutation.')
                ->and($exception->errorCode())
                ->toBe('validation.failed')
                ->and($exception->details())
                ->toBe([
                    'field' => 'role',
                    'role' => 'gateway',
                ])
                ->and($exception->requestId())
                ->toBe('0198e15d-16c4-7855-8eb2-182b53ad28ba');
        }
    });
});
