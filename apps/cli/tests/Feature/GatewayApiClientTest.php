<?php

declare(strict_types=1);

use App\Exceptions\GatewayApiException;
use App\Services\GatewayApiClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

describe('GatewayApiClient', function (): void {
    it('returns decoded arrays from get requests', function (): void {
        Http::fake([
            'https://gateway.test/api/me*' => Http::response(['self' => ['name' => 'operator']], 200),
        ]);

        $result = new GatewayApiClient('https://gateway.test', 'node-token', 30)
            ->get('/api/me', ['include' => 'permissions']);

        expect($result)->toBe(['self' => ['name' => 'operator']]);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
            && str_starts_with($request->url(), 'https://gateway.test/api/me')
            && str_contains($request->url(), 'include=permissions'));
    });

    it('returns decoded arrays from post requests and sends JSON payloads', function (): void {
        Http::fake([
            'https://gateway.test/api/nodes' => Http::response(['created' => true], 200),
        ]);

        $result = new GatewayApiClient('https://gateway.test', 'node-token', 30)
            ->post('/api/nodes', ['name' => 'app-dev']);

        expect($result)->toBe(['created' => true]);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === 'https://gateway.test/api/nodes'
            && $request->isJson()
            && $request['name'] === 'app-dev');
    });

    it('throws gateway api exceptions for client errors with the status code', function (): void {
        Http::fake([
            'https://gateway.test/api/missing' => Http::response(['error' => ['message' => 'Missing']], 404),
        ]);

        $exception = captureGatewayException(fn () => new GatewayApiClient('https://gateway.test', 'node-token', 30)
            ->get('/api/missing'));

        expect($exception)->toBeInstanceOf(GatewayApiException::class)
            ->and($exception?->statusCode())->toBe(404)
            ->and($exception?->getMessage())->toContain('HTTP 404')
            ->and($exception?->getMessage())->toContain('Missing');
    });

    it('throws gateway api exceptions for server errors', function (): void {
        Http::fake([
            'https://gateway.test/api/status' => Http::response('gateway unavailable', 503),
        ]);

        $exception = captureGatewayException(fn () => new GatewayApiClient('https://gateway.test', 'node-token', 30)
            ->get('/api/status'));

        expect($exception)->toBeInstanceOf(GatewayApiException::class)
            ->and($exception?->statusCode())->toBe(503)
            ->and($exception?->getMessage())->toContain('HTTP 503');
    });

    it('throws gateway api exceptions for network errors', function (): void {
        Http::fake([
            'https://gateway.test/*' => Http::failedConnection('connection refused'),
        ]);

        $exception = captureGatewayException(fn () => new GatewayApiClient('https://gateway.test', 'node-token', 30)
            ->get('/api/me'));

        expect($exception)->toBeInstanceOf(GatewayApiException::class)
            ->and($exception?->statusCode())->toBeNull()
            ->and($exception?->getMessage())->toContain('Gateway request failed');
    });

    it('sends the configured identity as a bearer authorization header', function (): void {
        Http::fake([
            'https://gateway.test/api/me' => Http::response(['ok' => true], 200),
        ]);

        new GatewayApiClient('https://gateway.test', 'node-secret', 30)
            ->get('/api/me');

        Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer node-secret'));
    });

    it('resolves from config and applies the configured timeout', function (): void {
        config()->set('orbit.gateway.url', 'https://gateway.test');
        config()->set('orbit.gateway.identity', 'node-token');
        config()->set('orbit.gateway.timeout', 12);
        app()->forgetInstance(GatewayApiClient::class);

        $timeout = null;

        Http::fake(function (Request $request, array $options) use (&$timeout) {
            $timeout = $options['timeout'] ?? null;

            return Http::response(['ok' => true], 200);
        });

        $result = app(GatewayApiClient::class)->get('/api/me');

        expect($result)->toBe(['ok' => true])
            ->and($timeout)->toBe(12);
    });
});

function captureGatewayException(callable $callback): ?GatewayApiException
{
    try {
        $callback();
    } catch (GatewayApiException $exception) {
        return $exception;
    }

    return null;
}
