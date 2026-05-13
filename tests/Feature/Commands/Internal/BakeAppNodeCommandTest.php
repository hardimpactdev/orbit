<?php

declare(strict_types=1);

use App\Models\Node;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

describe('orbit:internal:bake-app-node', function (): void {
    it('writes an app node row with the same shape as node:new produces', function (): void {
        $this->artisan('orbit:internal:bake-app-node', [
            'name' => 'app-dev-1',
            '--role' => 'app',
            '--environment' => 'development',
            '--host' => '10.6.0.4',
            '--wireguard-address' => '10.6.0.4',
            '--gateway-endpoint' => '10.6.0.2',
            '--user' => 'orbit',
            '--tld' => 'test',
        ])->assertSuccessful();

        $node = Node::query()->where('name', 'app-dev-1')->firstOrFail();

        expect($node->role)->toBe('app')
            ->and($node->environment)->toBe('development')
            ->and($node->host)->toBe('10.6.0.4')
            ->and($node->wireguard_address)->toBe('10.6.0.4')
            ->and($node->gateway_endpoint)->toBe('10.6.0.2')
            ->and($node->user)->toBe('orbit')
            ->and($node->orbit_path)->toBe('/home/orbit/orbit')
            ->and($node->tld)->toBe('test')
            ->and($node->status)->toBe('active');
    });

    it('is idempotent across repeated runs', function (): void {
        $args = [
            'name' => 'app-prod-1',
            '--role' => 'app',
            '--environment' => 'production',
            '--host' => '10.6.0.5',
            '--wireguard-address' => '10.6.0.5',
            '--gateway-endpoint' => '10.6.0.2',
            '--user' => 'orbit',
        ];

        $this->artisan('orbit:internal:bake-app-node', $args)->assertSuccessful();
        $this->artisan('orbit:internal:bake-app-node', $args)->assertSuccessful();

        $node = Node::query()->where('name', 'app-prod-1')->firstOrFail();

        expect(Node::query()->where('name', 'app-prod-1')->count())->toBe(1)
            ->and($node->environment)->toBe('production')
            ->and($node->tld)->toBeNull();
    });
});
