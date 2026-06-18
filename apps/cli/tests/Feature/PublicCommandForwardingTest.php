<?php

declare(strict_types=1);

use App\Services\GatewayApiClient;
use Illuminate\Console\OutputStyle;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Orbit\Core\Http\JsonEnvelope;

describe('gateway API-backed public commands', function (): void {
    it('calls the gateway API and renders the gateway status response', function (): void {
        configureGatewayStatusCommand();

        Http::fake([
            'https://gateway.test/api/status' => Http::response([
                'gateway' => [
                    'status' => 'healthy',
                    'version' => '0.1.0',
                ],
            ], 200),
        ]);

        [$exitCode, $output] = runPublicCommand($this, 'gateway:status');

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('status: healthy')
            ->and($output)->toContain('version: 0.1.0')
            ->and($output)->not->toContain('{');

        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
            && $request->url() === 'https://gateway.test/api/status');
    });

    it('outputs a success envelope as JSON', function (): void {
        configureGatewayStatusCommand();

        $response = [
            'gateway' => [
                'status' => 'healthy',
                'version' => '0.1.0',
            ],
            'observed_at' => '2026-05-24T12:00:00Z',
        ];

        Http::fake([
            'https://gateway.test/api/status' => Http::response($response, 200),
        ]);

        [$exitCode, $output] = runPublicCommand($this, 'gateway:status', ['--json' => true]);

        expect($exitCode)->toBe(0)
            ->and($output)->toBe(json_encode(
                JsonEnvelope::success($response, ['endpoint' => '/api/status']),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
            ));

        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
            && $request->url() === 'https://gateway.test/api/status');
    });

    it('renders only the gateway field for human success output', function (): void {
        configureGatewayStatusCommand();

        Http::fake([
            'https://gateway.test/api/status' => Http::response([
                'gateway' => [
                    'status' => 'healthy',
                ],
                'raw_response' => [
                    'debug' => 'hidden from human output',
                ],
            ], 200),
        ]);

        [$exitCode, $output] = runPublicCommand($this, 'gateway:status');

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('healthy')
            ->and($output)->not->toContain('raw_response')
            ->and($output)->not->toContain('hidden from human output');

        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
            && $request->url() === 'https://gateway.test/api/status');
    });

    it('exits non-zero and renders a failure for gateway HTTP errors', function (): void {
        configureGatewayStatusCommand();

        Http::fake([
            'https://gateway.test/api/status' => Http::response('gateway offline', 503),
        ]);

        [$exitCode, $output] = runPublicCommand($this, 'gateway:status');

        expect($exitCode)->toBe(1)
            ->and($output)->toContain('gateway_unavailable')
            ->and($output)->toContain('HTTP 503');

        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
            && $request->url() === 'https://gateway.test/api/status');
    });

    it('outputs gateway_unreachable_wireguard for WireGuard route failures in JSON mode', function (): void {
        configureGatewayStatusCommand();

        Http::fake(function () {
            throw new ConnectionException('Connection timed out after 30 seconds');
        });

        [$exitCode, $output] = runPublicCommand($this, 'gateway:status', ['--json' => true]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($decoded['error']['code'])->toBe('gateway_unreachable_wireguard')
            ->and($decoded['error']['message'])->toContain('WireGuard');
    });

    it('outputs a failure envelope as JSON for gateway HTTP errors', function (): void {
        configureGatewayStatusCommand();

        Http::fake([
            'https://gateway.test/api/status' => Http::response('gateway offline', 503),
        ]);

        [$exitCode, $output] = runPublicCommand($this, 'gateway:status', ['--json' => true]);

        expect($exitCode)->toBe(1)
            ->and($output)->toBe(json_encode(
                JsonEnvelope::failure(
                    'gateway_unavailable',
                    'Gateway request failed (HTTP 503) Body: gateway offline',
                    [
                        'endpoint' => '/api/status',
                        'status_code' => 503,
                        'body_excerpt' => 'gateway offline',
                    ],
                ),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
            ));

        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
            && $request->url() === 'https://gateway.test/api/status');
    });
});

function configureGatewayStatusCommand(): void
{
    config()->set('orbit.gateway.url', 'https://gateway.test');
    config()->set('orbit.gateway.identity', 'node-token');
    app()->forgetInstance(GatewayApiClient::class);
}

/**
 * @param  array<string, mixed>  $parameters
 * @return array{int, string}
 */
function runPublicCommand(object $test, string $command, array $parameters = []): array
{
    $test->mockConsoleOutput = false;
    app()->offsetUnset(OutputStyle::class);

    $exitCode = $test->artisan($command, $parameters);

    return [$exitCode, trim(app(Kernel::class)->output())];
}
