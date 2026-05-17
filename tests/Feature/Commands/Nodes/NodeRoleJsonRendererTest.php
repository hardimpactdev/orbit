<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

uses(RefreshDatabase::class);

require_once __DIR__.'/NodeRoleCommandTestHelpers.php';

describe('node role json renderer', function (): void {
    it('uses exactly one top-level success key on add success', function (): void {
        setupNodeRoleGatewayCaller();
        createHostedNode([
            'name' => 'client-1',
            'role' => 'control',
            'environment' => null,
        ]);

        Artisan::call('node role:add', [
            'node' => 'client-1',
            'role' => 'app-development',
            '--tld' => 'test',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        expect(array_keys($payload))->toBe(['success'])
            ->and($payload)->not->toHaveKey('error');
    });

    it('uses exactly one top-level error key on validation failure', function (): void {
        setupNodeRoleGatewayCaller();

        Artisan::call('node role:add', [
            'node' => 'client-1',
            'role' => 'app-development',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        expect(array_keys($payload))->toBe(['error'])
            ->and($payload)->not->toHaveKey('success');
    });
});
