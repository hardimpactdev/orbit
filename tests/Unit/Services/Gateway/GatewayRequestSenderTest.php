<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Gateway;

use App\Models\LocalGatewaySettings;
use App\Services\Gateway\GatewayRequest;
use App\Services\Gateway\GatewayRequestSender;
use App\Services\Gateway\GatewayResponseParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

beforeEach(function (): void {
    Http::preventStrayRequests();
});

describe('GatewayRequestSender', function (): void {
    it('can be created via static factory', function (): void {
        $sender = GatewayRequestSender::make();

        expect($sender)->toBeInstanceOf(GatewayRequestSender::class);
    });

    it('sends get requests and returns parsed response', function (): void {
        $settings = LocalGatewaySettings::current();
        $settings->gateway_url = 'https://10.6.0.2';
        $settings->ca_pem_path = '/path/to/ca.pem';
        $settings->save();

        Http::fake([
            'https://10.6.0.2/api/test' => Http::response(['success' => ['data' => ['foo' => 'bar']]], 200),
        ]);

        $sender = new GatewayRequestSender(new GatewayResponseParser);
        $response = $sender->get('/api/test');

        expect($response->isSuccess())->toBeTrue();
        expect($response->data())->toBe(['foo' => 'bar']);

        Http::assertSent(function ($request): bool {
            return $request->method() === 'GET'
                && $request->url() === 'https://10.6.0.2/api/test';
        });
    });

    it('passes query parameters on get requests', function (): void {
        $settings = LocalGatewaySettings::current();
        $settings->gateway_url = 'https://10.6.0.2';
        $settings->ca_pem_path = '/path/to/ca.pem';
        $settings->save();

        Http::fake([
            'https://10.6.0.2/api/test*' => Http::response(['success' => ['data' => []]], 200),
        ]);

        $sender = new GatewayRequestSender(new GatewayResponseParser);
        $sender->get('/api/test', ['page' => 2, 'limit' => 10]);

        Http::assertSent(function ($request): bool {
            return $request->method() === 'GET'
                && $request->url() === 'https://10.6.0.2/api/test?page=2&limit=10';
        });
    });

    it('sends post requests and returns parsed response', function (): void {
        $settings = LocalGatewaySettings::current();
        $settings->gateway_url = 'https://10.6.0.2';
        $settings->ca_pem_path = '/path/to/ca.pem';
        $settings->save();

        Http::fake([
            'https://10.6.0.2/api/test' => Http::response(['success' => ['data' => ['id' => 1]]], 200),
        ]);

        $sender = new GatewayRequestSender(new GatewayResponseParser);
        $response = $sender->post('/api/test', ['name' => 'test']);

        expect($response->isSuccess())->toBeTrue();
        expect($response->data())->toBe(['id' => 1]);

        Http::assertSent(function ($request): bool {
            return $request->method() === 'POST'
                && $request->url() === 'https://10.6.0.2/api/test'
                && $request->data() === ['name' => 'test'];
        });
    });

    it('sends put requests and returns parsed response', function (): void {
        $settings = LocalGatewaySettings::current();
        $settings->gateway_url = 'https://10.6.0.2';
        $settings->ca_pem_path = '/path/to/ca.pem';
        $settings->save();

        Http::fake([
            'https://10.6.0.2/api/test' => Http::response(['success' => ['data' => ['updated' => true]]], 200),
        ]);

        $sender = new GatewayRequestSender(new GatewayResponseParser);
        $response = $sender->put('/api/test', ['name' => 'updated']);

        expect($response->isSuccess())->toBeTrue();
        expect($response->data())->toBe(['updated' => true]);

        Http::assertSent(function ($request): bool {
            return $request->method() === 'PUT'
                && $request->url() === 'https://10.6.0.2/api/test'
                && $request->data() === ['name' => 'updated'];
        });
    });

    it('sends delete requests and returns parsed response', function (): void {
        $settings = LocalGatewaySettings::current();
        $settings->gateway_url = 'https://10.6.0.2';
        $settings->ca_pem_path = '/path/to/ca.pem';
        $settings->save();

        Http::fake([
            'https://10.6.0.2/api/test' => Http::response(['success' => true], 200),
        ]);

        $sender = new GatewayRequestSender(new GatewayResponseParser);
        $response = $sender->delete('/api/test');

        expect($response->isSuccess())->toBeTrue();

        Http::assertSent(function ($request): bool {
            return $request->method() === 'DELETE'
                && $request->url() === 'https://10.6.0.2/api/test';
        });
    });

    it('propagates runtime exceptions from the parser', function (): void {
        $settings = LocalGatewaySettings::current();
        $settings->gateway_url = 'https://10.6.0.2';
        $settings->ca_pem_path = '/path/to/ca.pem';
        $settings->save();

        Http::fake([
            'https://10.6.0.2/api/test' => Http::response('', 500),
        ]);

        $sender = new GatewayRequestSender(new GatewayResponseParser);

        expect(fn () => $sender->get('/api/test'))
            ->toThrow(RuntimeException::class, 'Gateway request failed with HTTP status 500');
    });

    it('propagates connection failures', function (): void {
        $settings = LocalGatewaySettings::current();
        $settings->gateway_url = 'https://10.6.0.2';
        $settings->ca_pem_path = '/path/to/ca.pem';
        $settings->save();

        Http::fake(function () {
            throw new ConnectionException('Connection refused');
        });

        $sender = new GatewayRequestSender(new GatewayResponseParser);

        expect(fn () => $sender->get('/api/test'))
            ->toThrow(ConnectionException::class, 'Connection refused');
    });

    it('propagates timeout failures', function (): void {
        $settings = LocalGatewaySettings::current();
        $settings->gateway_url = 'https://10.6.0.2';
        $settings->ca_pem_path = '/path/to/ca.pem';
        $settings->save();

        Http::fake(function () {
            throw new ConnectionException('cURL error 28: Operation timed out');
        });

        $sender = new GatewayRequestSender(new GatewayResponseParser);

        expect(fn () => $sender->get('/api/test'))
            ->toThrow(ConnectionException::class, 'cURL error 28: Operation timed out');
    });

    it('handles HTTP 5xx with non-JSON body', function (): void {
        $settings = LocalGatewaySettings::current();
        $settings->gateway_url = 'https://10.6.0.2';
        $settings->ca_pem_path = '/path/to/ca.pem';
        $settings->save();

        Http::fake([
            'https://10.6.0.2/api/test' => Http::response('<html>Service Unavailable</html>', 503),
        ]);

        $sender = new GatewayRequestSender(new GatewayResponseParser);

        expect(fn () => $sender->get('/api/test'))
            ->toThrow(RuntimeException::class, 'Gateway request failed with HTTP status 503');
    });

    it('handles HTTP 4xx with valid error envelope', function (): void {
        $settings = LocalGatewaySettings::current();
        $settings->gateway_url = 'https://10.6.0.2';
        $settings->ca_pem_path = '/path/to/ca.pem';
        $settings->save();

        Http::fake([
            'https://10.6.0.2/api/test' => Http::response([
                'error' => [
                    'code' => 'validation_failed',
                    'message' => 'The given data was invalid.',
                    'meta' => ['field' => 'name'],
                ],
            ], 422),
        ]);

        $sender = new GatewayRequestSender(new GatewayResponseParser);
        $response = $sender->get('/api/test');

        expect($response->isSuccess())->toBeFalse();
        expect($response->errorCode())->toBe('validation_failed');
        expect($response->errorMessage())->toBe('The given data was invalid.');
        expect($response->errorMeta())->toBe(['field' => 'name']);
    });

    it('handles HTTP 200 with error envelope', function (): void {
        $settings = LocalGatewaySettings::current();
        $settings->gateway_url = 'https://10.6.0.2';
        $settings->ca_pem_path = '/path/to/ca.pem';
        $settings->save();

        Http::fake([
            'https://10.6.0.2/api/test' => Http::response([
                'error' => [
                    'code' => 'status_mismatch',
                    'message' => 'HTTP 200 but envelope says error',
                ],
            ], 200),
        ]);

        $sender = new GatewayRequestSender(new GatewayResponseParser);
        $response = $sender->get('/api/test');

        expect($response->isSuccess())->toBeFalse();
        expect($response->errorCode())->toBe('status_mismatch');
        expect($response->errorMessage())->toBe('HTTP 200 but envelope says error');
    });

    it('throws on unsupported HTTP method via send', function (): void {
        $request = new class implements GatewayRequest
        {
            public function method(): string
            {
                return 'PATCH';
            }

            public function path(): string
            {
                return '/api/test';
            }

            public function query(): array
            {
                return [];
            }

            public function data(): array
            {
                return [];
            }
        };

        $sender = new GatewayRequestSender(new GatewayResponseParser);

        expect(fn () => $sender->send($request))
            ->toThrow(\InvalidArgumentException::class, 'Unsupported HTTP method: PATCH');
    });
});
