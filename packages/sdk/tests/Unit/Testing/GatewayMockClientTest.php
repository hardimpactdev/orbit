<?php

declare(strict_types=1);

namespace Orbit\Sdk\Laravel\Tests\Unit\Testing;

use Orbit\Sdk\Laravel\GatewayConnector;
use Orbit\Sdk\Laravel\GatewayRequest;
use Orbit\Sdk\Laravel\Testing\GatewayMockClient;
use Orbit\Sdk\Laravel\Testing\GatewayMockResponse;
use Orbit\Sdk\Laravel\Testing\GatewayPendingRequest;
use Orbit\Sdk\Laravel\Tests\TestCase;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use Saloon\Traits\Body\HasJsonBody;

uses(TestCase::class);

afterEach(function (): void {
    GatewayMockClient::destroyGlobal();
});

it('wraps Saloon mocks behind the SDK testing API', function (): void {
    $seenRequest = null;

    GatewayMockClient::global([
        '*' => function (GatewayPendingRequest $pendingRequest) use (&$seenRequest): GatewayMockResponse {
            $seenRequest = $pendingRequest;

            return GatewayMockResponse::make([
                'success' => ['data' => ['ok' => true]],
            ], 200);
        },
    ]);

    $request = new class extends GatewayRequest implements HasBody {
        use HasJsonBody;

        protected Method $method = Method::POST;

        public function resolveEndpoint(): string
        {
            return '/api/probe';
        }

        /**
         * @return array<string, mixed>
         */
        protected function defaultBody(): array
        {
            return ['name' => 'orbit'];
        }

        /**
         * @return array<string, mixed>
         */
        public function createDtoFromResponse(Response $response): array
        {
            return $this->unwrapData($response);
        }
    };

    $connector = new GatewayConnector(
        baseUrl: 'https://10.6.0.2',
        caPemPath: '/tmp/orbit-ca.pem',
        timeout: 15,
    );

    $dto = $connector->send($request)->dto();

    expect(GatewayMockClient::hasGlobal())
        ->toBeTrue()
        ->and($dto)
        ->toBe(['ok' => true])
        ->and($seenRequest)
        ->toBeInstanceOf(GatewayPendingRequest::class)
        ->and($seenRequest?->method())
        ->toBe('POST')
        ->and($seenRequest?->url())
        ->toBe('https://10.6.0.2/api/probe')
        ->and($seenRequest?->header('Accept'))
        ->toBe('application/json')
        ->and($seenRequest?->body())
        ->toBe(['name' => 'orbit'])
        ->and($seenRequest?->configValue('verify'))
        ->toBe('/tmp/orbit-ca.pem')
        ->and($seenRequest?->configValue('timeout'))
        ->toBe(15)
        ->and(GatewayMockClient::lastPendingRequest())
        ->toBeInstanceOf(GatewayPendingRequest::class);
});
