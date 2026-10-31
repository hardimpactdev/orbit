<?php

declare(strict_types=1);

use Orbit\Sdk\GatewayApiException;
use Orbit\Sdk\GatewayConnector;
use Orbit\Sdk\Requests\Nodes\RelocateNodeRoleRequest;
use Orbit\Sdk\Responses\Operations\OperationResponse;
use Saloon\Enums\Method;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

describe(RelocateNodeRoleRequest::class, function (): void {
    it('transports one native Operation with exact selectors and retry identity', function (): void {
        $client = new MockClient([RelocateNodeRoleRequest::class => MockResponse::make(['data' => node_role_operation_data('node.role_relocate'), 'meta' => ['request_id' => node_role_request_id()]], 202)]);
        $request = new RelocateNodeRoleRequest(nodeId: 7, role: 'gateway', force: true, idempotencyKey: 'role-native-retry');
        $response = node_role_gateway_connector($client)->send($request)->dto();
        expect($request->getMethod())->toBe(Method::POST)->and($request->resolveEndpoint())->toBe('/api/v1/nodes/7/roles/gateway/relocate')
            ->and($client->getLastPendingRequest()?->headers()->get('Idempotency-Key'))->toBe('role-native-retry')
            ->and($response)->toBeInstanceOf(OperationResponse::class)->and($response->kind)->toBe('node.role_relocate')
            ->and($response->id)->toBe(91)->and($response->status)->toBe('queued')->and($response->requestId)->toBe(node_role_request_id());
    });

    it('maps gateway validation failures with safe details and header request id', function (): void {
        $mockClient = new MockClient([
            RelocateNodeRoleRequest::class => MockResponse::make(
                [
                    'error' => [
                        'code' => 'validation.failed',
                        'message' => 'Use --force to relocate this node role.',
                        'details' => [
                            'field' => 'force',
                            'reason' => 'destructive_consent_required',
                            'role' => 'gateway',
                            'dependents' => [],
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
            $connector->send(new RelocateNodeRoleRequest(nodeId: 7, role: 'gateway', force: false))->dto();
            test()->fail('Expected GatewayApiException.');
        } catch (GatewayApiException $exception) {
            expect($exception->getMessage())
                ->toBe('Use --force to relocate this node role.')
                ->and($exception->errorCode())
                ->toBe('validation.failed')
                ->and($exception->details())
                ->toBe([
                    'field' => 'force',
                    'reason' => 'destructive_consent_required',
                    'role' => 'gateway',
                    'dependents' => [],
                ])
                ->and($exception->requestId())
                ->toBe('0198e15d-16c4-7855-8eb2-182b53ad28ba');
        }
    });

    it('includes optional from in the body when set', function (): void {
        $mockClient = new MockClient([
            RelocateNodeRoleRequest::class => MockResponse::make([
                'data' => [
                    ...node_role_operation_data('node.role_relocate'),
                    'node_name' => 'services',
                    'role' => 'websocket',
                    'assignment' => [
                        'id' => 2,
                        'role' => 'websocket',
                        'status' => 'active',
                        'failed_step' => null,
                        'error_code' => null,
                    ],
                ],
                'meta' => ['request_id' => node_role_request_id()],
            ]),
        ]);
        $connector = node_role_gateway_connector($mockClient);
        $request = new RelocateNodeRoleRequest(nodeId: 7, role: 'websocket', force: true, from: 3);

        $connector->send($request)->dto();

        expect($request->resolveEndpoint())
            ->toBe('/api/v1/nodes/7/roles/websocket/relocate')
            ->and($mockClient->getLastPendingRequest()?->body()->all())
            ->toBe(['force' => true, 'from' => 3]);
    });
});
