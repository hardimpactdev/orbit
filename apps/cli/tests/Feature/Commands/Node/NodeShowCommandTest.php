<?php

declare(strict_types=1);

use App\Services\OrbitConfigStore;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

describe('node:show', function (): void {
    it('returns a canonical success envelope in JSON mode and forwards the node path', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'node' => ['name' => 'app-1', 'role' => 'app-dev'],
        ]));

        [$exitCode, $output] = runCommand($this, 'node:show', [
            'name' => 'app-1',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
            && str_contains($request->url(), '/api/nodes/app-1'));

        expect($exitCode)->toBe(0)
            ->and($decoded['success']['data']['node']['name'])->toBe('app-1');
    });

    it('resolves a missing name from OrbitConfigStore before calling the gateway', function (): void {
        $store = new OrbitConfigStore(overridePath: base_path('tests/.tmp-node-show-config.json'));
        @unlink($store->path());
        $store->save(['defaults' => ['node' => 'default-app', 'profile' => null]]);
        app()->instance(OrbitConfigStore::class, $store);

        fakeGateway(fakeSuccessEnvelope([
            'node' => ['name' => 'default-app', 'role' => 'app-dev'],
        ]));

        [$exitCode, $output] = runCommand($this, 'node:show', ['--json' => true]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/api/nodes/default-app'));

        expect($exitCode)->toBe(0)
            ->and($decoded['success']['data']['node']['name'])->toBe('default-app');

        @unlink($store->path());
    });

    it('fails validation when no name or local default is available', function (): void {
        $store = new OrbitConfigStore(overridePath: base_path('tests/.tmp-node-show-empty-config.json'));
        @unlink($store->path());
        app()->instance(OrbitConfigStore::class, $store);

        [$exitCode, $output] = runCommand($this, 'node:show', ['--json' => true]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($decoded['error']['code'])->toBe('validation_failed')
            ->and($decoded['error']['meta']['field'])->toBe('name');

        @unlink($store->path());
    });

    it('renders human output containing node fields', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'node' => ['name' => 'gateway-1', 'role' => 'gateway'],
        ]));

        [$exitCode, $output] = runCommand($this, 'node:show', ['name' => 'gateway-1']);

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('node');
    });

    it('surfaces wireguard-specific gateway failures', function (): void {
        fakeGatewayDown('No route to host');

        [$exitCode, $output] = runCommand($this, 'node:show', [
            'name' => 'gateway-1',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($decoded['error']['code'])->toBe('gateway_unreachable_wireguard');
    });
});
