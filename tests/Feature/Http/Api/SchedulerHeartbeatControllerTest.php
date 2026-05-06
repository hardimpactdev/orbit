<?php

declare(strict_types=1);

use App\Models\Node;
use App\Models\SchedulerState;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

const SCHEDULER_HEARTBEAT_CALLER_WG_IP = '10.6.0.97';

it('stores scheduler heartbeat state for the authenticated wireguard node', function (): void {
    $node = Node::factory()->create([
        'name' => 'app-1',
        'role' => 'app',
        'wireguard_address' => SCHEDULER_HEARTBEAT_CALLER_WG_IP,
    ]);

    $response = $this->call('POST', '/api/schedules/heartbeat', [
        'heartbeat_at' => '2026-05-06T12:34:00Z',
        'registry_synced_at' => '2026-05-06T12:33:55Z',
    ], [], [], ['REMOTE_ADDR' => SCHEDULER_HEARTBEAT_CALLER_WG_IP]);

    $response->assertSuccessful()
        ->assertJsonPath('success.data.state.node', 'app-1')
        ->assertJsonPath('success.data.state.heartbeat_at', '2026-05-06T12:34:00+00:00')
        ->assertJsonPath('success.data.state.registry_synced_at', '2026-05-06T12:33:55+00:00');

    $state = SchedulerState::query()->firstOrFail();

    expect($state->node->is($node))->toBeTrue()
        ->and($state->heartbeat_at?->toIso8601String())->toBe('2026-05-06T12:34:00+00:00');
});

it('updates existing scheduler heartbeat state for the node', function (): void {
    $node = Node::factory()->create([
        'role' => 'app',
        'wireguard_address' => SCHEDULER_HEARTBEAT_CALLER_WG_IP,
    ]);
    SchedulerState::factory()->create([
        'node_id' => $node->id,
        'heartbeat_at' => '2026-05-06 12:00:00',
    ]);

    $response = $this->call('POST', '/api/schedules/heartbeat', [
        'heartbeat_at' => '2026-05-06T12:35:00Z',
    ], [], [], ['REMOTE_ADDR' => SCHEDULER_HEARTBEAT_CALLER_WG_IP]);

    $response->assertSuccessful()
        ->assertJsonPath('success.data.state.heartbeat_at', '2026-05-06T12:35:00+00:00');

    expect(SchedulerState::query()->count())->toBe(1);
});

it('rejects invalid heartbeat payloads', function (): void {
    Node::factory()->create([
        'role' => 'app',
        'wireguard_address' => SCHEDULER_HEARTBEAT_CALLER_WG_IP,
    ]);

    $response = $this->call('POST', '/api/schedules/heartbeat', [
        'heartbeat_at' => 'not-a-date',
    ], [], [], ['REMOTE_ADDR' => SCHEDULER_HEARTBEAT_CALLER_WG_IP]);

    $response->assertStatus(422)
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonPath('error.meta.field', 'heartbeat_at');
});

it('rejects scheduler heartbeat from unknown wireguard peers', function (): void {
    $response = $this->call('POST', '/api/schedules/heartbeat', [
        'heartbeat_at' => '2026-05-06T12:34:00Z',
    ], [], [], ['REMOTE_ADDR' => '10.6.0.200']);

    $response->assertForbidden()
        ->assertJsonPath('error.code', 'authorization_failed');
});
