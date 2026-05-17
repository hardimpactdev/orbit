<?php

declare(strict_types=1);

use App\Models\App;
use App\Models\NodeTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

uses(RefreshDatabase::class);

require_once __DIR__.'/NodeRoleCommandTestHelpers.php';

describe('node role:remove', function (): void {
    it('blocks removal when dependents exist', function (): void {
        setupNodeRoleGatewayCaller();
        $node = createHostedNode([
            'name' => 'client-1',
            'role' => 'control',
            'environment' => null,
        ]);

        assignNodeRole($node, 'app-development', settings: ['tld' => 'test']);
        App::factory()->create([
            'node_id' => $node->id,
            'environment' => 'development',
        ]);

        $exitCode = Artisan::call('node role:remove', [
            'node' => 'client-1',
            'role' => 'app-development',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('node_role.remove_blocked');
    });

    it('requires force when purge-data is requested', function (): void {
        setupNodeRoleGatewayCaller();
        $node = createHostedNode([
            'name' => 'db-1',
        ]);

        assignNodeRole($node, 'database');

        $exitCode = Artisan::call('node role:remove', [
            'node' => 'db-1',
            'role' => 'database',
            '--purge-data' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('validation_failed')
            ->and($payload['error']['meta']['field'])->toBe('purge-data');
    });

    it('force preserves data while removing orbit-owned dependents', function (): void {
        setupNodeRoleGatewayCaller();
        $node = createHostedNode([
            'name' => 'db-1',
        ]);

        assignNodeRole($node, 'database');

        NodeTool::query()->create([
            'node_id' => $node->id,
            'name' => 'postgres',
            'expected_state' => 'running',
            'installed_version' => null,
            'settings' => [],
            'status' => 'running',
        ]);

        $exitCode = Artisan::call('node role:remove', [
            'node' => 'db-1',
            'role' => 'database',
            '--force' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['purged_data'])->toBeFalse();
    });

    it('force with purge-data performs purge cleanup', function (): void {
        setupNodeRoleGatewayCaller();
        $node = createHostedNode([
            'name' => 'db-1',
        ]);

        assignNodeRole($node, 'database');

        $exitCode = Artisan::call('node role:remove', [
            'node' => 'db-1',
            'role' => 'database',
            '--force' => true,
            '--purge-data' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['purged_data'])->toBeTrue();
    });
});
