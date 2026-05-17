<?php

declare(strict_types=1);

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
});
