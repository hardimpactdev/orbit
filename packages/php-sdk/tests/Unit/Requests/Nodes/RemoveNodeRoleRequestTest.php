<?php

declare(strict_types=1);

use Orbit\Sdk\GatewayApiException;
use Orbit\Sdk\GatewayConnector;
use Orbit\Sdk\Requests\Nodes\AddNodeRoleRequest;
use Orbit\Sdk\Requests\Nodes\RemoveNodeRoleRequest;
use Orbit\Sdk\Responses\Operations\OperationResponse;
use Saloon\Enums\Method;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

describe(RemoveNodeRoleRequest::class, function (): void {
    it('transports repeated Ingress removal and remove-then-add replacement', function (): void {
        $remove = new RemoveNodeRoleRequest(nodeId: 17, role: 'ingress', force: true);
        $repeat = new RemoveNodeRoleRequest(nodeId: 17, role: 'ingress', force: true);
        $replacement = new AddNodeRoleRequest(nodeId: 18, role: 'ingress');

        expect($remove->resolveEndpoint())
            ->toBe('/api/v1/nodes/17/roles/ingress')
            ->and($remove->body()->all())
            ->toBe(['force' => true, 'purge_data' => false, 'offline' => false])
            ->and($repeat->resolveEndpoint())
            ->toBe('/api/v1/nodes/17/roles/ingress')
            ->and($replacement->resolveEndpoint())
            ->toBe('/api/v1/nodes/18/roles')
            ->and($replacement->body()->all())
            ->toBe(['role' => 'ingress', 'converge_existing' => false]);
    });

    it('transports one native Operation with exact selectors and retry identity', function (): void {
        $client = new MockClient([RemoveNodeRoleRequest::class => MockResponse::make(['data' => node_role_operation_data('node.role_remove'), 'meta' => ['request_id' => node_role_request_id()]], 202)]);
        $request = new RemoveNodeRoleRequest(nodeId: 7, role: 'app-dev', force: true, purgeData: false, idempotencyKey: 'role-native-retry');
        $response = node_role_gateway_connector($client)->send($request)->dto();
        expect($request->getMethod())->toBe(Method::DELETE)->and($request->resolveEndpoint())->toBe('/api/v1/nodes/7/roles/app-dev')
            ->and($client->getLastPendingRequest()?->headers()->get('Idempotency-Key'))->toBe('role-native-retry')
            ->and($response)->toBeInstanceOf(OperationResponse::class)->and($response->kind)->toBe('node.role_remove')
            ->and($response->id)->toBe(91)->and($response->status)->toBe('queued')->and($response->requestId)->toBe(node_role_request_id());
    });

    it('sends the offline claim in the request body', function (): void {
        $mockClient = new MockClient([
            RemoveNodeRoleRequest::class => MockResponse::make([
                'data' => node_role_operation_data('node.role_remove'),
                'meta' => ['request_id' => node_role_request_id()],
            ]),
        ]);
        $connector = node_role_gateway_connector($mockClient);
        $request = new RemoveNodeRoleRequest(nodeId: 7, role: 'app-dev', force: true, offline: true);

        $connector->send($request)->dto();

        expect($mockClient->getLastPendingRequest()?->body()->all())
            ->toBe([
                'force' => true,
                'purge_data' => false,
                'offline' => true,
            ]);
    });

    it('preserves preview dependents while redacting nested secret-shaped details', function (): void {
        $secret = node_role_secret('preview');
        $secretKey = 'access_token';
        $mockClient = new MockClient([
            RemoveNodeRoleRequest::class => MockResponse::make(
                [
                    'error' => [
                        'code' => 'validation.failed',
                        'message' => 'Use --force to remove this node role.',
                        'details' => [
                            'field' => 'force',
                            'reason' => 'destructive_consent_required',
                            'role' => 'app-dev',
                            'dependents' => [
                                '1 development instance record',
                                '1 workspace record',
                                '1 process record',
                            ],
                            'nested' => [
                                $secretKey => $secret,
                            ],
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
            $connector->send(new RemoveNodeRoleRequest(nodeId: 7, role: 'app-dev', force: false))->dto();
            test()->fail('Expected GatewayApiException.');
        } catch (GatewayApiException $exception) {
            expect($exception->details())
                ->toBe([
                    'field' => 'force',
                    'reason' => 'destructive_consent_required',
                    'role' => 'app-dev',
                    'dependents' => [
                        '1 development instance record',
                        '1 workspace record',
                        '1 process record',
                    ],
                    'nested' => [
                        $secretKey => '[REDACTED]',
                    ],
                ])
                ->and($exception->requestId())
                ->toBe('0198e15d-16c4-7855-8eb2-182b53ad28ba');
        }
    });

    it('rawurlencodes the role into one endpoint segment', function (): void {
        $request = new RemoveNodeRoleRequest(
            nodeId: 7,
            role: '../app-dev/slash role',
            force: true,
        );

        expect($request->resolveEndpoint())
            ->toBe('/api/v1/nodes/7/roles/..%2Fapp-dev%2Fslash%20role');
    });
});
