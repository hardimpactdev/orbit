<?php

declare(strict_types=1);

use App\Models\Node;
use App\Models\ScheduleLock;
use App\Models\SchedulerState;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('stores one scheduler heartbeat and registry sync state per node', function (): void {
    $node = Node::factory()->create(['name' => 'app-1']);

    $state = SchedulerState::factory()->create([
        'node_id' => $node->id,
        'heartbeat_at' => '2026-05-06 12:34:00',
        'registry_synced_at' => '2026-05-06 12:33:55',
    ]);

    expect($node->schedulerState->is($state))->toBeTrue()
        ->and($state->node->is($node))->toBeTrue()
        ->and($state->heartbeat_at?->toIso8601String())->toBe('2026-05-06T12:34:00+00:00')
        ->and($state->registry_synced_at?->toIso8601String())->toBe('2026-05-06T12:33:55+00:00');
});

it('keeps scheduler state unique per node', function (): void {
    $node = Node::factory()->create();

    SchedulerState::factory()->create(['node_id' => $node->id]);

    expect(fn () => SchedulerState::factory()->create(['node_id' => $node->id]))
        ->toThrow(QueryException::class);
});

it('stores schedule locks by stable schedule key per node', function (): void {
    $firstNode = Node::factory()->create(['name' => 'app-1']);
    $secondNode = Node::factory()->create(['name' => 'app-2']);

    $firstLock = ScheduleLock::factory()->create([
        'node_id' => $firstNode->id,
        'schedule_key' => 'app:docs:laravel-scheduler',
        'owner_token' => 'tick-1',
        'locked_at' => '2026-05-06 12:34:00',
        'expires_at' => '2026-05-06 12:39:00',
    ]);
    $secondLock = ScheduleLock::factory()->create([
        'node_id' => $secondNode->id,
        'schedule_key' => 'app:docs:laravel-scheduler',
    ]);

    expect($firstNode->scheduleLocks()->first()->is($firstLock))->toBeTrue()
        ->and($firstLock->node->is($firstNode))->toBeTrue()
        ->and($secondLock->node->is($secondNode))->toBeTrue()
        ->and($firstLock->locked_at->toIso8601String())->toBe('2026-05-06T12:34:00+00:00')
        ->and($firstLock->expires_at?->toIso8601String())->toBe('2026-05-06T12:39:00+00:00');
});

it('keeps schedule lock keys unique within a node only', function (): void {
    $node = Node::factory()->create();

    ScheduleLock::factory()->create([
        'node_id' => $node->id,
        'schedule_key' => 'node:app-1:backups',
    ]);

    expect(fn () => ScheduleLock::factory()->create([
        'node_id' => $node->id,
        'schedule_key' => 'node:app-1:backups',
    ]))->toThrow(QueryException::class);
});
