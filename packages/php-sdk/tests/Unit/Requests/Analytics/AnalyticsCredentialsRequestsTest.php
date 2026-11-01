<?php

declare(strict_types=1);

use Orbit\Sdk\GatewayApiException;
use Orbit\Sdk\Requests\Analytics\SetAnalyticsCredentialsRequest;
use Orbit\Sdk\Requests\Analytics\ShowAnalyticsCredentialsRequest;
use Orbit\Sdk\Requests\Analytics\UnsetAnalyticsCredentialsRequest;
use Orbit\Sdk\Responses\Analytics\AnalyticsCredentialsResponse;
use Saloon\Enums\Method;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

function analytics_credentials_payload(bool $configured = true): array
{
    return [
        'data' => ['configured' => $configured, 'driver' => 'plausible_ce', 'management_email_configured' => false, 'management_password_configured' => false],
        'meta' => ['request_id' => node_role_request_id()],
    ];
}

describe('analytics credentials requests', function (): void {
    it('transports optional management credentials only in the protected body', function (): void {
        $email = 'private-owner@example.test';
        $password = 'private-management-sentinel';
        $mockClient = new MockClient([SetAnalyticsCredentialsRequest::class => MockResponse::make(analytics_credentials_payload())]);
        $request = new SetAnalyticsCredentialsRequest('private-api-sentinel', $email, $password);
        node_role_gateway_connector($mockClient)->send($request);
        expect($mockClient->getLastPendingRequest()?->body()->all())->toBe([
            'api_key' => 'private-api-sentinel', 'management_email' => $email, 'management_password' => $password,
        ])->and(print_r($request, true))->not->toContain($email, $password, 'private-api-sentinel');
    });

    it('requires boolean management presence metadata', function (): void {
        $payload = analytics_credentials_payload();
        $payload['data']['management_password_configured'] = 'private-value';
        $mockClient = new MockClient([ShowAnalyticsCredentialsRequest::class => MockResponse::make($payload)]);
        expect(fn () => node_role_gateway_connector($mockClient)->send(new ShowAnalyticsCredentialsRequest)->dto())
            ->toThrow(GatewayApiException::class, 'invalid analytics credentials data');
    });
    it('reads whether a Stats API key is stored and never expects the key', function (): void {
        $mockClient = new MockClient([
            ShowAnalyticsCredentialsRequest::class => MockResponse::make(analytics_credentials_payload(false)),
        ]);

        $response = node_role_gateway_connector($mockClient)->send(new ShowAnalyticsCredentialsRequest)->dto();

        expect((new ShowAnalyticsCredentialsRequest)->getMethod())->toBe(Method::GET)
            ->and((new ShowAnalyticsCredentialsRequest)->resolveEndpoint())->toBe('/api/v1/analytics/credentials')
            ->and($response)->toBeInstanceOf(AnalyticsCredentialsResponse::class)
            ->and($response->toArray())->toBe([
                'configured' => false,
                'driver' => 'plausible_ce',
                'request_id' => node_role_request_id(),
                'management_email_configured' => false,
                'management_password_configured' => false,
            ]);
    });

    it('sends the key on set and hides it from debug output', function (): void {
        $secret = 'plausible-stats-sentinel-key';
        $mockClient = new MockClient([
            SetAnalyticsCredentialsRequest::class => MockResponse::make(analytics_credentials_payload()),
        ]);
        $request = new SetAnalyticsCredentialsRequest($secret);

        $response = node_role_gateway_connector($mockClient)->send($request)->dto();

        expect($request->getMethod())->toBe(Method::PUT)
            ->and($request->resolveEndpoint())->toBe('/api/v1/analytics/credentials')
            ->and($mockClient->getLastPendingRequest()?->body()->all())->toBe(['api_key' => $secret])
            ->and($response->configured)->toBeTrue()
            ->and(print_r($request, true))->not->toContain($secret);
    });

    it('clears the stored key', function (): void {
        $mockClient = new MockClient([
            UnsetAnalyticsCredentialsRequest::class => MockResponse::make(analytics_credentials_payload(false)),
        ]);

        $response = node_role_gateway_connector($mockClient)->send(new UnsetAnalyticsCredentialsRequest)->dto();

        expect((new UnsetAnalyticsCredentialsRequest)->getMethod())->toBe(Method::DELETE)
            ->and($response->configured)->toBeFalse();
    });

    it('refuses an answer that lacks configured', function (): void {
        $mockClient = new MockClient([
            ShowAnalyticsCredentialsRequest::class => MockResponse::make([
                'data' => ['driver' => 'plausible_ce', 'management_email_configured' => false, 'management_password_configured' => false],
                'meta' => ['request_id' => node_role_request_id()],
            ]),
        ]);

        expect(fn () => node_role_gateway_connector($mockClient)->send(new ShowAnalyticsCredentialsRequest)->dto())
            ->toThrow(GatewayApiException::class, 'invalid analytics credentials data');
    });
});
