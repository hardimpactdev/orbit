<?php

declare(strict_types=1);

use App\Exceptions\GatewayApiException;
use App\Exceptions\GatewayApiFailureKind;
use App\Services\GatewayApiClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

describe('GatewayApiClient', function (): void {
    it('returns decoded arrays from get requests', function (): void {
        Http::fake([
            'https://gateway.test/api/me*' => Http::response(['self' => ['name' => 'operator']], 200),
        ]);

        $result = new GatewayApiClient('https://gateway.test', 30)
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

        $result = new GatewayApiClient('https://gateway.test', 30)
            ->post('/api/nodes', ['name' => 'app-dev']);

        expect($result)->toBe(['created' => true]);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === 'https://gateway.test/api/nodes'
            && $request->isJson()
            && $request['name'] === 'app-dev');
    });

    it('returns decoded arrays from patch requests and sends JSON payloads', function (): void {
        Http::fake([
            'https://gateway.test/api/processes/vite' => Http::response(['updated' => true], 200),
        ]);

        $result = new GatewayApiClient('https://gateway.test', 30)
            ->patch('/api/processes/vite', ['command' => 'npm run dev']);

        expect($result)->toBe(['updated' => true]);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'PATCH'
            && $request->url() === 'https://gateway.test/api/processes/vite'
            && $request->isJson()
            && $request['command'] === 'npm run dev');
    });

    it('throws gateway api exceptions for client errors with the status code', function (): void {
        Http::fake([
            'https://gateway.test/api/missing' => Http::response(['error' => ['message' => 'Missing']], 404),
        ]);

        $exception = captureGatewayException(fn () => new GatewayApiClient('https://gateway.test', 30)
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

        $exception = captureGatewayException(fn () => new GatewayApiClient('https://gateway.test', 30)
            ->get('/api/status'));

        expect($exception)->toBeInstanceOf(GatewayApiException::class)
            ->and($exception?->statusCode())->toBe(503)
            ->and($exception?->getMessage())->toContain('HTTP 503');
    });

    it('throws gateway api exceptions for generic network errors', function (): void {
        Http::fake(function () {
            throw new ConnectionException('connection refused');
        });

        $exception = captureGatewayException(fn () => new GatewayApiClient('https://gateway.test', 30)
            ->get('/api/me'));

        expect($exception)->toBeInstanceOf(GatewayApiException::class)
            ->and($exception?->statusCode())->toBeNull()
            ->and($exception?->failureKind())->toBe(GatewayApiFailureKind::Network)
            ->and($exception?->cliFailureCode())->toBe('gateway_unavailable')
            ->and($exception?->getMessage())->toContain('Gateway request failed');
    });

    it('surfaces wireguard_unreachable failure when the gateway is not reachable on the WireGuard route', function (): void {
        Http::fake(function () {
            throw new ConnectionException('Connection timed out after 30 seconds');
        });

        $exception = captureGatewayException(fn () => new GatewayApiClient('https://10.6.0.1', 30)
            ->get('/api/me'));

        expect($exception)->toBeInstanceOf(GatewayApiException::class)
            ->and($exception?->failureKind())->toBe(GatewayApiFailureKind::WireguardUnreachable)
            ->and($exception?->cliFailureCode())->toBe('gateway_unreachable_wireguard')
            ->and($exception?->getMessage())->toContain('Gateway unreachable over WireGuard');
    });

    it('classifies no route to host failures as wireguard unreachable', function (): void {
        Http::fake(function () {
            throw new ConnectionException('Failed to connect: no route to host');
        });

        $exception = captureGatewayException(fn () => new GatewayApiClient('https://10.6.0.1', 30)
            ->get('/api/me'));

        expect($exception)->toBeInstanceOf(GatewayApiException::class)
            ->and($exception?->failureKind())->toBe(GatewayApiFailureKind::WireguardUnreachable)
            ->and($exception?->cliFailureCode())->toBe('gateway_unreachable_wireguard');
    });

    it('never sends an Authorization header (D2: no bearer identity)', function (): void {
        Http::fake([
            'https://gateway.test/api/me' => Http::response(['success' => ['data' => [], 'meta' => []]], 200),
        ]);

        new GatewayApiClient('https://gateway.test', 30)
            ->get('/api/me');

        Http::assertSent(fn (Request $request): bool => ! $request->hasHeader('Authorization'));
    });

    it('resolves baseUrl + timeout from the provider binding (env-only config + JSON fallback in provider closure)', function (): void {
        config()->set('orbit.gateway.url', 'https://gateway.test');
        config()->set('orbit.gateway.timeout', 12);
        app()->forgetInstance(GatewayApiClient::class);

        $timeout = null;

        Http::fake(function (Request $request, array $options) use (&$timeout) {
            $timeout = $options['timeout'] ?? null;

            return Http::response(['success' => ['data' => [], 'meta' => []]], 200);
        });

        $result = app(GatewayApiClient::class)->get('/api/me');

        expect($result)->toBe(['success' => ['data' => [], 'meta' => []]])
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
