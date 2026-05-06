<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('registers a node through the hidden internal bootstrap command', function (): void {
    $this->artisan('orbit:internal:node-register', [
        'name' => 'gateway',
        '--role' => 'gateway',
        '--host' => 'gateway',
        '--ssh-user' => 'gateway',
        '--orbit-path' => '/home/gateway/orbit',
        '--local' => true,
    ])
        ->expectsOutputToContain('Registered node gateway.')
        ->assertSuccessful();

    $node = DB::table('nodes')->where('name', 'gateway')->first();

    expect($node)->not->toBeNull()
        ->and($node->role)->toBe('gateway')
        ->and($node->host)->toBe('gateway')
        ->and($node->ssh_user)->toBe('gateway')
        ->and($node->orbit_path)->toBe('/home/gateway/orbit')
        ->and((bool) $node->is_local)->toBeTrue();
});
