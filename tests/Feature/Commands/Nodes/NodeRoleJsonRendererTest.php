<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

uses(RefreshDatabase::class);

require_once __DIR__.'/NodeRoleCommandTestHelpers.php';

describe('node role json renderer', function (): void {
    it('uses exactly one top-level success key on list success', function (): void {
        setupNodeRoleGatewayCaller();
        $node = createHostedNode([
            'name' => 'client-1',
            'role' => 'control',
            'environment' => null,
        ]);

        assignNodeRole($node, 'database');

        Artisan::call('node role:list', [
            'node' => 'client-1',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        expect(array_keys($payload))->toBe(['success'])
            ->and($payload)->not->toHaveKey('error')
            ->and($payload['success']['data']['node'])->toBe('client-1')
            ->and($payload['success']['data']['roles'][0]['role'])->toBe('database')
            ->and($payload['success']['data']['roles'][0]['status'])->toBe('active')
            ->and($payload['success']['data']['roles'][0]['settings'])->toBe([]);
    });

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
            ->and($payload)->not->toHaveKey('error')
            ->and($payload['success']['data']['assignment']['role'])->toBe('app-development')
            ->and($payload['success']['data']['assignment']['settings'])->toBe(['tld' => 'test']);
    });

    it('uses exactly one top-level success key on update success', function (): void {
        setupNodeRoleGatewayCaller();
        $node = createHostedNode([
            'name' => 'client-1',
            'role' => 'control',
            'environment' => null,
        ]);

        assignNodeRole($node, 'app-development', settings: ['tld' => 'old']);

        Artisan::call('node role:update', [
            'node' => 'client-1',
            'role' => 'app-development',
            '--tld' => 'test',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        expect(array_keys($payload))->toBe(['success'])
            ->and($payload)->not->toHaveKey('error')
            ->and($payload['success']['data']['assignment']['role'])->toBe('app-development')
            ->and($payload['success']['data']['assignment']['settings'])->toBe(['tld' => 'test']);
    });

    it('uses exactly one top-level success key on remove success', function (): void {
        setupNodeRoleGatewayCaller();
        $node = createHostedNode([
            'name' => 'client-1',
            'role' => 'control',
            'environment' => null,
        ]);

        assignNodeRole($node, 'database');

        Artisan::call('node role:remove', [
            'node' => 'client-1',
            'role' => 'database',
            '--force' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        expect(array_keys($payload))->toBe(['success'])
            ->and($payload)->not->toHaveKey('error')
            ->and($payload['success']['data']['role'])->toBe('database')
            ->and($payload['success']['data']['purged_data'])->toBeFalse();
    });

    it('uses exactly one top-level error key on remove failure', function (): void {
        setupNodeRoleGatewayCaller();
        $node = createHostedNode([
            'name' => 'client-1',
            'role' => 'control',
            'environment' => null,
        ]);

        assignNodeRole($node, 'database');

        Artisan::call('node role:remove', [
            'node' => 'client-1',
            'role' => 'database',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        expect(array_keys($payload))->toBe(['error'])
            ->and($payload)->not->toHaveKey('success')
            ->and($payload['error']['code'])->toBe('validation_failed')
            ->and($payload['error']['meta']['field'])->toBe('force');
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
