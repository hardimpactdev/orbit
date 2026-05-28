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

        expect($exitCode)->toBe(1)
            ->and($decoded['error']['code'])->toBe('gateway_unreachable_wireguard');
    });

    it('outputs gateway_unavailable for HTTP failures', function (): void {
        config()->set('orbit.gateway.url', 'https://gateway.test');
        app()->forgetInstance(GatewayApiClient::class);

        Http::fake([
            'https://gateway.test/api/status' => Http::response('offline', 503),
        ]);

        [$exitCode, $output] = runCommand($this, 'gateway:status', ['--json' => true]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($decoded['error']['code'])->toBe('gateway_unavailable');
    });
});
