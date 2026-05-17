<?php

declare(strict_types=1);

use App\Http\Gateway\Requests\Nodes\UpdateNodeRoleRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

uses(RefreshDatabase::class);

require_once __DIR__.'/NodeRoleCommandTestHelpers.php';

describe('node role:update', function (): void {
    it('updates app-development tld and re-converges the role', function (): void {
        setupNodeRoleGatewayCaller();
        $node = createHostedNode([
            'name' => 'client-1',
            'role' => 'control',
            'environment' => null,
        ]);

        assignNodeRole($node, 'app-development', settings: ['tld' => 'old']);

        $exitCode = Artisan::call('node role:update', [
            'node' => 'client-1',
            'role' => 'app-development',
            '--tld' => 'test',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['assignment']['settings'])->toBe(['tld' => 'test'])
            ->and($payload['success']['data']['assignment']['status'])->toBe('active');
    });

    it('rejects gateway updates through the command surface', function (): void {
        setupNodeRoleGatewayCaller();
        $node = createHostedNode([
            'name' => 'gateway-client',
            'role' => 'control',
            'environment' => null,
        ]);

        assignNodeRole($node, 'gateway');

        $exitCode = Artisan::call('node role:update', [
            'node' => 'gateway-client',
            'role' => 'gateway',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('validation_failed');
    });

    it('updates database without role-local settings', function (): void {
        setupNodeRoleGatewayCaller();
        $node = createHostedNode([
            'name' => 'db-1',
        ]);

        assignNodeRole($node, 'database');

        $exitCode = Artisan::call('node role:update', [
            'node' => 'db-1',
            'role' => 'database',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['node'])->toBe('db-1')
            ->and($payload['success']['data']['assignment']['role'])->toBe('database')
            ->and($payload['success']['data']['assignment']['status'])->toBe('active')
            ->and($payload['success']['data']['assignment']['settings'])->toBe([]);
    });

    it('rejects an explicitly supplied empty tld for database', function (): void {
        setupNodeRoleGatewayCaller();
        $node = createHostedNode([
            'name' => 'db-1',
        ]);

        assignNodeRole($node, 'database');

        $exitCode = Artisan::call('node role:update', [
            'node' => 'db-1',
            'role' => 'database',
            '--tld' => '',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('validation_failed')
            ->and($payload['error']['meta']['field'])->toBe('tld');
    });

    it('forwards control callers to the gateway', function (): void {
        setupNodeRoleControlCaller();

        $convergedAt = now()->toJSON();
        $mock = fakeNodeRoleGateway(UpdateNodeRoleRequest::class, [
            'success' => [
                'data' => [
                    'node' => 'client-1',
                    'assignment' => nodeRoleAssignmentPayload('app-development', 'active', ['tld' => 'test'], null, $convergedAt),
                ],
            ],
        ]);

        $exitCode = Artisan::call('node role:update', [
            'node' => 'client-1',
            'role' => 'app-development',
            '--tld' => 'test',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data'])->toBe([
                'node' => 'client-1',
                'assignment' => nodeRoleAssignmentPayload('app-development', 'active', ['tld' => 'test'], null, $convergedAt),
            ]);

        $mock->assertSent(fn (UpdateNodeRoleRequest $request): bool => $request->node === 'client-1'
            && $request->role === 'app-development'
            && $request->resolveEndpoint() === '/api/nodes/client-1/roles/app-development'
            && $request->body()->all() === [
                'settings' => ['tld' => 'test'],
            ]);
    });
});
