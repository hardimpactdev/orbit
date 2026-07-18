<?php

declare(strict_types=1);

use App\Models\Node;
use App\Models\WireGuardPeer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('encrypts existing wireguard peer private material', function (): void {
    $node = Node::factory()->create();
    $peerId = DB::table('wireguard_peers')->insertGetId([
        'node_id' => $node->id,
        'public_key' => 'public-key',
        'private_key' => 'legacy-private-key',
        'pre_shared_key' => 'legacy-pre-shared-key',
        'allowed_ips' => '10.6.0.9/32',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $migrationPath = database_path(
        'migrations/2026_07_17_235919_encrypt_wireguard_peer_private_material.php',
    );

    expect($migrationPath)->toBeFile();

    $migration = require $migrationPath;
    $migration->up();

    $storedPeer = DB::table('wireguard_peers')->where('id', $peerId)->first();
    $peer = WireGuardPeer::query()->findOrFail($peerId);

    expect(Schema::getColumnType('wireguard_peers', 'pre_shared_key'))
        ->toBe('text')
        ->and($storedPeer)
        ->not->toBeNull()->and($storedPeer->private_key)
        ->not->toBe('legacy-private-key')->and($storedPeer->pre_shared_key)
        ->not->toBe('legacy-pre-shared-key')->and($peer->private_key)->toBe(
            'legacy-private-key',
        )->and($peer->pre_shared_key)->toBe('legacy-pre-shared-key');

    $migration->up();

    $reprocessedStoredPeer = DB::table('wireguard_peers')->where('id', $peerId)->first();

    expect($reprocessedStoredPeer)
        ->not
        ->toBeNull()
        ->and($reprocessedStoredPeer->private_key)
        ->toBe($storedPeer->private_key)
        ->and($reprocessedStoredPeer->pre_shared_key)
        ->toBe($storedPeer->pre_shared_key);
});
