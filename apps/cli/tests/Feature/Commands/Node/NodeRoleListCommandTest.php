<?php

declare(strict_types=1);

use App\Services\OrbitConfigStore;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

describe('node role:list', function (): void {
    it('returns a canonical success envelope in JSON mode and forwards the node roles path', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'node' => 'gateway-1',
            'roles' => [
                ['role' => 'gateway', 'status' => 'active'],
            ],
        ]));

        [$exitCode, $output] = runCommand($this, 'node role:list', [
            'node' => 'gateway-1',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
            && str_contains($request->url(), '/api/nodes/gateway-1/roles'));

        expect($exitCode)->toBe(0)
            ->and($decoded['success']['data']['node'])->toBe('gateway-1')
            ->and($decoded['success']['data']['roles'][0]['role'])->toBe('gateway');
    });

    it('resolves a missing node from OrbitConfigStore before calling the gateway', function (): void {
        $store = new OrbitConfigStore(overridePath: base_path('tests/.tmp-node-role-list-config.json'));
        @unlink($store->path());
        $store->save(['defaults' => ['node' => 'default-app', 'profile' => null]]);
        app()->instance(OrbitConfigStore::class, $store);

        fakeGateway(fakeSuccessEnvelope([
            'node' => 'default-app',
            'roles' => [],
        ]));

        [$exitCode, $output] = runCommand($this, 'node role:list', ['--json' => true]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/api/nodes/default-app/roles'));

        expect($exitCode)->toBe(0)
            ->and($decoded['success']['data']['node'])->toBe('default-app');

        @unlink($store->path());
    });

    it('fails validation when no node or local default is available', function (): void {
        $store = new OrbitConfigStore(overridePath: base_path('tests/.tmp-node-role-list-empty-config.json'));
        @unlink($store->path());
        app()->instance(OrbitConfigStore::class, $store);

        [$exitCode, $output] = runCommand($this, 'node role:list', ['--json' => true]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($decoded['error']['code'])->toBe('validation_failed')
            ->and($decoded['error']['meta']['field'])->toBe('node');

        @unlink($store->path());
    });

    it('renders human output as a table with uppercase headers and role cells', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'node' => 'gateway-1',
            'roles' => [
                [
                    'role' => 'gateway',
                    'status' => 'active',
                    'settings' => ['port' => 8443],
                    'last_error' => null,
                    'converged_at' => '2026-05-02T08:31:12.000000Z',
                ],
                [
                    'role' => 'router',
                    'status' => 'failed',
                    'settings' => [],
                    'last_error' => 'boom',
                    'converged_at' => null,
                ],
            ],
        ]));

        [$exitCode, $output] = runCommand($this, 'node role:list', ['node' => 'gateway-1']);

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('ROLE')
            ->and($output)->toContain('STATUS')
            ->and($output)->toContain('SETTINGS')
            ->and($output)->toContain('LAST ERROR')
            ->and($output)->toContain('CONVERGED AT')
            ->and($output)->toContain('gateway')
            ->and($output)->toContain('active')
            ->and($output)->toContain('router')
            ->and($output)->toContain('failed')
            ->and($output)->toContain('boom')
            ->and($output)->toContain('—')
            ->and($output)->not->toContain('roles: [')
            ->and($output)->not->toContain('"converged_at"');
    });

    it('renders empty human output naming the node when it has no role assignments', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'node' => 'gateway-1',
            'roles' => [],
        ]));

        [$exitCode, $output] = runCommand($this, 'node role:list', ['node' => 'gateway-1']);

        expect($exitCode)->toBe(0)
            ->and($output)->toBe('No role assignments found for node gateway-1.');
    });

    it('surfaces wireguard-specific gateway failures', function (): void {
        fakeGatewayDown('Network is unreachable');

        [$exitCode, $output] = runCommand($this, 'node role:list', [
            'node' => 'gateway-1',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($decoded['error']['code'])->toBe('gateway_unreachable_wireguard');
    });
});
