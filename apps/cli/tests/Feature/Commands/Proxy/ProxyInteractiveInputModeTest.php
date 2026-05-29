<?php

declare(strict_types=1);

use App\Services\OrbitConfigStore;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

describe('proxy interactive input mode', function (): void {
    beforeEach(function (): void {
        $store = new OrbitConfigStore(overridePath: base_path('tests/.tmp-proxy-interactive-config.json'));
        @unlink($store->path());
        app()->instance(OrbitConfigStore::class, $store);
    });

    afterEach(function (): void {
        @unlink(base_path('tests/.tmp-proxy-interactive-config.json'));
    });

    it('prompts for a custom upstream route before contacting the gateway', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'route' => [
                'domain' => 'vite.docs.test',
                'node' => 'app-1',
                'upstream' => 'http://127.0.0.1:5173',
                'status' => 'expected',
            ],
        ]));

        $this->artisan('proxy:add')
            ->expectsQuestion('Domain', 'vite.docs.test')
            ->expectsQuestion('Serving node', 'app-1')
            ->expectsChoice('Route type', 'upstream', ['Upstream', 'Redirect', 'upstream', 'redirect'])
            ->expectsQuestion('Upstream URL', 'http://127.0.0.1:5173')
            ->expectsOutputToContain('route')
            ->assertSuccessful();

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === 'https://gateway.test/api/proxy-routes'
            && $request->data() === [
                'domain' => 'vite.docs.test',
                'node' => 'app-1',
                'upstream' => 'http://127.0.0.1:5173',
                'force' => false,
            ]);
    });
});
