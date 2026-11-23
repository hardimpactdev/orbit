<?php

declare(strict_types=1);

use Orbit\Sdk\GatewayApiException;
use Orbit\Sdk\GatewayConnector;
use Orbit\Sdk\Requests\Conn\SetConnPushCredentialsRequest;
use Orbit\Sdk\Requests\Conn\ShowConnPushCredentialsRequest;
use Orbit\Sdk\Responses\Conn\ConnPushCredentialsResponse;
use Saloon\Enums\Method;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

beforeEach(fn () => MockClient::destroyGlobal());
afterEach(fn () => MockClient::destroyGlobal());

describe('conn push credentials requests', function (): void {
    it('reads whether Conn APNs push credentials are configured without returning a key', function (): void {
        $requestId = '0198e15c-bf97-7c23-8f1f-61b8fe67a844';
        $connector = new GatewayConnector('https://gateway.orbit', caPemPath: '/tmp/root.pem');
        $mockClient = new MockClient([
            ShowConnPushCredentialsRequest::class => MockResponse::make([
                'data' => [
                    'configured' => false,
                    'key_id' => null,
                    'team_id' => null,
                ],
                'meta' => ['request_id' => $requestId],
            ]),
        ]);
        $connector->withMockClient($mockClient);

        $request = new ShowConnPushCredentialsRequest;
        $response = $connector->send($request)->dto();

        expect($request->getMethod())->toBe(Method::GET)
            ->and($request->resolveEndpoint())->toBe('/api/v1/conn/push-credentials')
            ->and($response)->toBeInstanceOf(ConnPushCredentialsResponse::class)
            ->and($response->toArray())->toBe([
                'configured' => false,
                'key_id' => null,
                'team_id' => null,
                'request_id' => $requestId,
            ]);
    });

    it('sends key_id, team_id and private_key on PUT and hides private_key from debug output', function (): void {
        $requestId = '0198e15c-bf97-7c23-8f1f-61b8fe67a845';
        $secret = "-----BEGIN PRIVATE KEY-----\nSENTINEL-TEST-BODY\n-----END PRIVATE KEY-----";
        $connector = new GatewayConnector('https://gateway.orbit', caPemPath: '/tmp/root.pem');
        $mockClient = new MockClient([
            SetConnPushCredentialsRequest::class => MockResponse::make([
                'data' => [
                    'configured' => true,
                    'key_id' => 'AB12CD34EF',
                    'team_id' => 'TEAM987654',
                ],
                'meta' => ['request_id' => $requestId],
            ]),
        ]);
        $connector->withMockClient($mockClient);

        $request = new SetConnPushCredentialsRequest('AB12CD34EF', 'TEAM987654', $secret);
        $response = $connector->send($request)->dto();

        expect($request->getMethod())->toBe(Method::PUT)
            ->and($request->resolveEndpoint())->toBe('/api/v1/conn/push-credentials')
            ->and($mockClient->getLastPendingRequest()?->body()->all())->toBe([
                'key_id' => 'AB12CD34EF',
                'team_id' => 'TEAM987654',
                'private_key' => $secret,
            ])
            ->and($response->toArray())->toBe([
                'configured' => true,
                'key_id' => 'AB12CD34EF',
                'team_id' => 'TEAM987654',
                'request_id' => $requestId,
            ])
            ->and(print_r($request, true))->not->toContain('SENTINEL-TEST-BODY');
    });

    it('refuses an invalid Conn push credentials payload', function (): void {
        expect(fn () => ConnPushCredentialsResponse::fromGatewayData(['key_id' => 'AB12CD34EF'], 'req-1'))
            ->toThrow(GatewayApiException::class, 'invalid Conn push credentials data');
    });
});
