<?php

declare(strict_types=1);

use App\Services\GatewayApiClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

describe('GatewayStatus command', function (): void {
    it('outputs gateway_unreachable_wireguard when the WireGuard route is unreachable', function (): void {
        config()->set('orbit.gateway.url', 'https://gateway.test');
        app()->forgetInstance(GatewayApiClient::class);

        Http::fake(function () {
            throw new ConnectionException('no route to host');
        });

        [$exitCode, $output] = runCommand($this, 'gateway:status', ['--json' => true]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)->and($decoded['error']['code'])->toBe('gateway_unreachable_wireguard');
    });

    it('outputs gateway_unavailable for HTTP failures', function (): void {
        config()->set('orbit.gateway.url', 'https://gateway.test');
        app()->forgetInstance(GatewayApiClient::class);

        Http::fake([
            'https://gateway.test/api/status' => Http::response('offline', 503),
        ]);

        [$exitCode, $output] = runCommand($this, 'gateway:status', ['--json' => true]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)->and($decoded['error']['code'])->toBe('gateway_unavailable');
    });

    it('renders the gateway status fields as flat key-value lines in human mode', function (): void {
        config()->set('orbit.gateway.url', 'https://gateway.test');
        app()->forgetInstance(GatewayApiClient::class);

        Http::fake([
            'https://gateway.test/api/status' => Http::response([
                'gateway' => [
                    'status' => 'healthy',
                    'version' => '0.1.124',
                    'time' => '2026-06-18T00:00:00+00:00',
                ],
                'extra_top_level' => 'should-not-appear',
            ], 200),
        ]);

        [$exitCode, $output] = runCommand($this, 'gateway:status');

        expect($exitCode)
            ->toBe(0)
            ->and($output)
            ->toContain('status: healthy')
            ->and($output)
            ->toContain('version: 0.1.124')
            ->and($output)
            ->toContain('time: 2026-06-18T00:00:00+00:00')
            ->and($output)
            ->not->toContain('extra_top_level')->and($output)
            ->not->toContain('should-not-appear')->and($output)
            ->not->toContain('gateway: {')->and($output)
            ->not->toContain('{');
    });

    it('renders the full response in human mode when the gateway field is absent', function (): void {
        config()->set('orbit.gateway.url', 'https://gateway.test');
        app()->forgetInstance(GatewayApiClient::class);

        Http::fake([
            'https://gateway.test/api/status' => Http::response([
                'status' => 'healthy',
                'version' => '0.1.124',
            ], 200),
        ]);

        [$exitCode, $output] = runCommand($this, 'gateway:status');

        expect($exitCode)
            ->toBe(0)
            ->and($output)
            ->toContain('status: healthy')
            ->and($output)
            ->toContain('version: 0.1.124')
            ->and($output)
            ->not->toContain('{');
    });

    it('unwraps a success envelope and renders flat status fields in human mode', function (): void {
        config()->set('orbit.gateway.url', 'https://gateway.test');
        app()->forgetInstance(GatewayApiClient::class);

        Http::fake([
            'https://gateway.test/api/status' => Http::response([
                'success' => [
                    'data' => [
                        'gateway' => [
                            'status' => 'healthy',
                            'version' => '0.1.124',
                        ],
                    ],
                    'meta' => [],
                ],
            ], 200),
        ]);

        [$exitCode, $output] = runCommand($this, 'gateway:status');

        expect($exitCode)
            ->toBe(0)
            ->and($output)
            ->toContain('status: healthy')
            ->and($output)
            ->toContain('version: 0.1.124')
            ->and($output)
            ->not->toContain('success:')->and($output)
            ->not->toContain('{');
    });

    it('renders flat status fields from an enveloped response without a nested gateway field', function (): void {
        config()->set('orbit.gateway.url', 'https://gateway.test');
        app()->forgetInstance(GatewayApiClient::class);

        Http::fake([
            'https://gateway.test/api/status' => Http::response([
                'success' => [
                    'data' => [
                        'version' => '0.0.0',
                        'time' => '2026-06-18T00:00:00Z',
                    ],
                    'meta' => [],
                ],
            ], 200),
        ]);

        [$exitCode, $output] = runCommand($this, 'gateway:status');

        expect($exitCode)
            ->toBe(0)
            ->and($output)
            ->toContain('version: 0.0.0')
            ->and($output)
            ->toContain('time: 2026-06-18T00:00:00Z')
            ->and($output)
            ->not->toContain('success:')->and($output)
            ->not->toContain('{');
    });

    it('renders gateway:status unavailable failures as prose in human mode', function (): void {
        config()->set('orbit.gateway.url', 'https://gateway.test');
        app()->forgetInstance(GatewayApiClient::class);

        Http::fake([
            'https://gateway.test/api/status' => Http::response('gateway offline', 503),
        ]);

        [$exitCode, $output] = runCommand($this, 'gateway:status');

        expect($exitCode)
            ->toBe(1)
            ->and($output)
            ->toContain('gateway_unavailable:')
            ->and($output)
            ->not->toContain('"error"')->and($output)
            ->not->toContain('{');
    });
});
