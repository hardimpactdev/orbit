<?php

declare(strict_types=1);

use App\Services\Vpn\WgEasyVpnBackend;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class);

it('mints client configs with the wireguard server dns address', function (): void {
    config()->set('services.wg_easy.username', 'orbit');
    config()->set('services.wg_easy.password', 'secret-password');

    Http::preventStrayRequests();

    $clientListCalls = 0;

    Http::fake(function (Request $request) use (&$clientListCalls) {
        if ($request->url() === 'http://127.0.0.1:51821/api/session') {
            return Http::response(['status' => 'success'], 200, [
                'Set-Cookie' => 'wg-easy=session-token; Path=/; HttpOnly',
            ]);
        }

        if ($request->method() === 'GET' && $request->url() === 'http://127.0.0.1:51821/api/client') {
            $clientListCalls++;

            return Http::response($clientListCalls === 1 ? [] : [
                [
                    'id' => 'client-7',
                    'name' => 'laptop',
                    'ipv4Address' => '10.6.0.7',
                    'enabled' => true,
                    'latestHandshakeAt' => null,
                ],
            ], 200);
        }

        if ($request->method() === 'POST' && $request->url() === 'http://127.0.0.1:51821/api/client') {
            return Http::response(['id' => 'client-7'], 200);
        }

        if ($request->url() === 'http://127.0.0.1:51821/api/client/client-7/configuration') {
            return Http::response(implode("\n", [
                '[Interface]',
                'PrivateKey = client-private',
                'Address = 10.6.0.7/32',
                'DNS = 10.6.0.2, 1.1.1.1, bear, gateway',
                '',
                '[Peer]',
                'PublicKey = server-public',
                'AllowedIPs = 0.0.0.0/0',
                'Endpoint = vpn.example.com:51820',
                '',
            ]), 200);
        }

        return Http::response("Unexpected request {$request->method()} {$request->url()}", 500);
    });

    $client = WgEasyVpnBackend::fromConfig()->createClient('laptop', includeConfig: true);

    expect($client->config)
        ->toContain('DNS = 10.6.0.1')
        ->not->toContain('10.6.0.2')
        ->not->toContain('1.1.1.1')
        ->not->toContain('bear')
        ->not->toContain('gateway');
});
