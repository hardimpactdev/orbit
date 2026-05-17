<?php

declare(strict_types=1);

use App\Http\Gateway\Requests\Nodes\AddNodeRoleRequest;
use App\Models\NodeTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

uses(RefreshDatabase::class);

require_once __DIR__.'/NodeRoleCommandTestHelpers.php';

describe('node role:add', function (): void {
    it('adds database to an app-production node on gateway', function (): void {
        setupNodeRoleGatewayCaller();
        $node = createHostedNode([
            'name' => 'prod-1',
            'environment' => 'production',
        ]);

        assignNodeRole($node, 'app-production');

        $exitCode = Artisan::call('node role:add', [
            'node' => 'prod-1',
            'role' => 'database',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['node'])->toBe('prod-1')
            ->and($payload['success']['data']['assignment']['role'])->toBe('database')
            ->and($payload['success']['data']['assignment']['status'])->toBe('active')
            ->and(NodeTool::query()->where('node_id', $node->id)->where('name', 'docker')->exists())->toBeTrue();
    });

    it('adds app-development with a required tld to a joined client node', function (): void {
        setupNodeRoleGatewayCaller();
        createHostedNode([
            'name' => 'client-1',
            'role' => 'control',
            'environment' => null,
        ]);

        $exitCode = Artisan::call('node role:add', [
            'node' => 'client-1',
            'role' => 'app-development',
            '--tld' => 'test',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['assignment']['settings'])->toBe(['tld' => 'test']);
    });

    it('rejects adding gateway through the command surface', function (): void {
        setupNodeRoleGatewayCaller();
        createHostedNode([
            'name' => 'client-1',
            'role' => 'control',
            'environment' => null,
        ]);

        $exitCode = Artisan::call('node role:add', [
            'node' => 'client-1',
            'role' => 'gateway',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('validation_failed');
    });

    it('rejects app-development without tld', function (): void {
        setupNodeRoleGatewayCaller();
        createHostedNode([
            'name' => 'client-1',
            'role' => 'control',
            'environment' => null,
        ]);

        $exitCode = Artisan::call('node role:add', [
            'node' => 'client-1',
            'role' => 'app-development',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('validation_failed')
            ->and($payload['error']['meta']['field'])->toBe('tld');
    });

    it('rejects unsupported role options for app-production', function (): void {
        setupNodeRoleGatewayCaller();
        createHostedNode([
            'name' => 'prod-1',
            'environment' => 'production',
        ]);

        $exitCode = Artisan::call('node role:add', [
            'node' => 'prod-1',
            'role' => 'app-production',
            '--tld' => 'test',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('validation_failed');
    });

    it('rejects an explicitly supplied empty tld for app-production', function (): void {
        setupNodeRoleGatewayCaller();
        createHostedNode([
            'name' => 'prod-1',
            'environment' => 'production',
        ]);

        $exitCode = Artisan::call('node role:add', [
            'node' => 'prod-1',
            'role' => 'app-production',
            '--tld' => '',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('validation_failed')
            ->and($payload['error']['meta']['field'])->toBe('tld');
    });

    it('rejects an explicitly supplied empty tld for database', function (): void {
        setupNodeRoleGatewayCaller();
        createHostedNode([
            'name' => 'db-1',
        ]);

        $exitCode = Artisan::call('node role:add', [
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

    it('rejects app-production when app-development is active', function (): void {
        setupNodeRoleGatewayCaller();
        $node = createHostedNode([
            'name' => 'client-1',
            'role' => 'control',
            'environment' => null,
        ]);

        assignNodeRole($node, 'app-development', settings: ['tld' => 'test']);

        $exitCode = Artisan::call('node role:add', [
            'node' => 'client-1',
            'role' => 'app-production',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('validation_failed');
    });

    it('forwards control callers to the gateway', function (): void {
        setupNodeRoleControlCaller();

        $mock = fakeNodeRoleGateway(AddNodeRoleRequest::class, [
            'success' => [
                'data' => [
                    'node' => 'client-1',
                    'assignment' => nodeRoleAssignmentPayload('app-development', 'active', ['tld' => 'test'], null, now()->toJSON()),
                ],
            ],
        ]);

        $exitCode = Artisan::call('node role:add', [
            'node' => 'client-1',
            'role' => 'app-development',
            '--tld' => 'test',
            '--json' => true,
        ]);

        expect($exitCode)->toBe(0);

        $mock->assertSent(fn (AddNodeRoleRequest $request): bool => $request->body()->all() === [
            'role' => 'app-development',
            'settings' => ['tld' => 'test'],
        ]);
    });
});
