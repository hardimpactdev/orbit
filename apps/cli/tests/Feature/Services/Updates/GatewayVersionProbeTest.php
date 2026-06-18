<?php

declare(strict_types=1);

use App\Services\GatewayApiClient;
use App\Services\Updates\GatewayVersionProbe;

describe('GatewayVersionProbe', function (): void {
    it('reads the gateway version from a nested gateway status payload', function (): void {
        fakeGateway(['gateway' => ['status' => 'healthy', 'version' => '0.1.130']]);

        $probe = new GatewayVersionProbe(app(GatewayApiClient::class));

        expect($probe->currentVersion())->toBe('0.1.130');
    });

    it('reads the gateway version from a success envelope payload', function (): void {
        fakeGateway(['success' => ['data' => ['gateway' => ['version' => '0.1.131']], 'meta' => []]]);

        $probe = new GatewayVersionProbe(app(GatewayApiClient::class));

        expect($probe->currentVersion())->toBe('0.1.131');
    });

    it('strips a leading v from the reported version', function (): void {
        fakeGateway(['gateway' => ['version' => 'v0.1.132']]);

        $probe = new GatewayVersionProbe(app(GatewayApiClient::class));

        expect($probe->currentVersion())->toBe('0.1.132');
    });

    it('returns null when the gateway is unreachable', function (): void {
        fakeGatewayDown();

        $probe = new GatewayVersionProbe(app(GatewayApiClient::class));

        expect($probe->currentVersion())->toBeNull();
    });

    it('returns null when no gateway url is configured', function (): void {
        fakeNoGatewayConfig(base_path('tests/.tmp-gateway-version-probe-empty-config.json'));

        $probe = new GatewayVersionProbe(app(GatewayApiClient::class));

        expect($probe->currentVersion())->toBeNull();
    });

    it('treats the placeholder 0.0.0 version as unknown', function (): void {
        fakeGateway(['gateway' => ['version' => '0.0.0']]);

        $probe = new GatewayVersionProbe(app(GatewayApiClient::class));

        expect($probe->currentVersion())->toBeNull();
    });

    it('returns null when the version field is missing', function (): void {
        fakeGateway(['gateway' => ['status' => 'healthy']]);

        $probe = new GatewayVersionProbe(app(GatewayApiClient::class));

        expect($probe->currentVersion())->toBeNull();
    });
});
