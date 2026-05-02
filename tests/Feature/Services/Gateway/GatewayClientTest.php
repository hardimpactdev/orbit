<?php

declare(strict_types=1);

use App\Models\LocalGatewaySettings;
use App\Services\ActivityLogCorrelation;
use App\Services\Gateway\GatewayClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

describe('GatewayClient', function (): void {
    beforeEach(function (): void {
        Http::preventStrayRequests();
    });

    it('configures verify with the ca pem path from local gateway settings', function (): void {
        $settings = LocalGatewaySettings::current();
        $settings->gateway_url = 'https://10.6.0.2';
        $settings->ca_pem_path = '/path/to/ca.pem';
        $settings->save();

        $pendingRequest = GatewayClient::make();

        $options = (fn () => $this->options)->call($pendingRequest);

        expect($options)->toHaveKey('verify', '/path/to/ca.pem');
    });

    it('sets allow_redirects to false', function (): void {
        $settings = LocalGatewaySettings::current();
        $settings->gateway_url = 'https://10.6.0.2';
        $settings->ca_pem_path = '/path/to/ca.pem';
        $settings->save();

        $pendingRequest = GatewayClient::make();

        $options = (fn () => $this->options)->call($pendingRequest);

        expect($options)->toHaveKey('allow_redirects', false);
    });

    it('sets accept json header', function (): void {
        $settings = LocalGatewaySettings::current();
        $settings->gateway_url = 'https://10.6.0.2';
        $settings->ca_pem_path = '/path/to/ca.pem';
        $settings->save();

        Http::fake([
            'https://10.6.0.2/api/test' => Http::response(['ok' => true], 200),
        ]);

        GatewayClient::make()->get('/api/test');

        Http::assertSent(function ($request): bool {
            return $request->hasHeader('Accept', 'application/json');
        });
    });

    it('appends x-orbit-request-id header', function (): void {
        $settings = LocalGatewaySettings::current();
        $settings->gateway_url = 'https://10.6.0.2';
        $settings->ca_pem_path = '/path/to/ca.pem';
        $settings->save();

        Http::fake([
            'https://10.6.0.2/api/test' => Http::response(['ok' => true], 200),
        ]);

        GatewayClient::make()->get('/api/test');

        Http::assertSent(function ($request): bool {
            $header = $request->header('X-Orbit-Request-Id');

            if (is_array($header)) {
                $header = $header[0] ?? null;
            }

            return is_string($header) && $header !== '' && Str::isUuid($header);
        });
    });

    it('reuses the same correlation id for multiple calls in the same process', function (): void {
        $settings = LocalGatewaySettings::current();
        $settings->gateway_url = 'https://10.6.0.2';
        $settings->ca_pem_path = '/path/to/ca.pem';
        $settings->save();

        Http::fake([
            'https://10.6.0.2/api/test' => Http::response(['ok' => true], 200),
        ]);

        GatewayClient::make()->get('/api/test');
        GatewayClient::make()->get('/api/test');

        $uuids = [];
        Http::assertSent(function ($request) use (&$uuids): bool {
            $header = $request->header('X-Orbit-Request-Id');

            if (is_array($header)) {
                $header = $header[0] ?? null;
            }

            if (is_string($header) && $header !== '') {
                $uuids[] = $header;
            }

            return true;
        });

        expect($uuids)->toHaveCount(2)
            ->and($uuids[0])->toBe($uuids[1]);
    });

    it('uses pre-set correlation uuid when context is active', function (): void {
        $settings = LocalGatewaySettings::current();
        $settings->gateway_url = 'https://10.6.0.2';
        $settings->ca_pem_path = '/path/to/ca.pem';
        $settings->save();

        $expectedUuid = (string) Str::uuid();
        app(ActivityLogCorrelation::class)->start($expectedUuid);

        Http::fake([
            'https://10.6.0.2/api/test' => Http::response(['ok' => true], 200),
        ]);

        GatewayClient::make()->get('/api/test');

        Http::assertSent(function ($request) use ($expectedUuid): bool {
            $header = $request->header('X-Orbit-Request-Id');

            if (is_array($header)) {
                $header = $header[0] ?? null;
            }

            return $header === $expectedUuid;
        });

        app(ActivityLogCorrelation::class)->end();
    });

    it('sets x-orbit-client to cli when running in console', function (): void {
        $settings = LocalGatewaySettings::current();
        $settings->gateway_url = 'https://10.6.0.2';
        $settings->ca_pem_path = '/path/to/ca.pem';
        $settings->save();

        Http::fake([
            'https://10.6.0.2/api/test' => Http::response(['ok' => true], 200),
        ]);

        GatewayClient::make()->get('/api/test');

        Http::assertSent(function ($request): bool {
            $header = $request->header('X-Orbit-Client');

            if (is_array($header)) {
                $header = $header[0] ?? null;
            }

            return $header === 'cli';
        });
    });
});
