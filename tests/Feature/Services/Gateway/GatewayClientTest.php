<?php

declare(strict_types=1);

use App\Models\LocalGatewaySettings;
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
});
