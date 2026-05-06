<?php

declare(strict_types=1);

use App\Models\Node;
use App\Models\ScheduleRun;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

const SCHEDULE_RUN_CALLER_WG_IP = '10.6.0.96';

it('stores scheduler run history for the authenticated wireguard node', function (): void {
    $node = Node::factory()->create([
        'name' => 'app-1',
        'role' => 'app',
        'wireguard_address' => SCHEDULE_RUN_CALLER_WG_IP,
    ]);

    $response = $this->call('POST', '/api/schedules/runs', [
        'schedule_key' => 'app:docs:laravel-scheduler',
        'status' => 'completed',
        'exit_code' => 0,
        'stdout' => "No scheduled commands are ready to run.\n",
        'stderr' => '',
        'started_at' => '2026-05-06T12:34:00Z',
        'finished_at' => '2026-05-06T12:34:03Z',
    ], [], [], ['REMOTE_ADDR' => SCHEDULE_RUN_CALLER_WG_IP]);

    $response->assertCreated()
        ->assertJsonPath('success.data.run.schedule_key', 'app:docs:laravel-scheduler')
        ->assertJsonPath('success.data.run.node', 'app-1')
        ->assertJsonPath('success.data.run.status', 'completed')
        ->assertJsonPath('success.data.run.exit_code', 0)
        ->assertJsonPath('success.data.run.output.stdout', 'No scheduled commands are ready to run.');

    $run = ScheduleRun::query()->firstOrFail();

    expect($run->node->is($node))->toBeTrue()
        ->and($node->scheduleRuns()->first()->is($run))->toBeTrue()
        ->and($run->schedule_key)->toBe('app:docs:laravel-scheduler')
        ->and($run->started_at->toIso8601String())->toBe('2026-05-06T12:34:00+00:00')
        ->and($run->finished_at?->toIso8601String())->toBe('2026-05-06T12:34:03+00:00');
});

it('rejects invalid schedule run history payloads', function (): void {
    Node::factory()->create([
        'role' => 'app',
        'wireguard_address' => SCHEDULE_RUN_CALLER_WG_IP,
    ]);

    $response = $this->call('POST', '/api/schedules/runs', [
        'schedule_key' => 'app:docs:laravel-scheduler',
        'status' => 'running',
        'started_at' => '2026-05-06T12:34:00Z',
    ], [], [], ['REMOTE_ADDR' => SCHEDULE_RUN_CALLER_WG_IP]);

    $response->assertStatus(422)
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonPath('error.meta.field', 'status');
});

it('rejects run history intake from unknown wireguard peers', function (): void {
    $response = $this->call('POST', '/api/schedules/runs', [
        'schedule_key' => 'app:docs:laravel-scheduler',
        'status' => 'completed',
        'started_at' => '2026-05-06T12:34:00Z',
    ], [], [], ['REMOTE_ADDR' => '10.6.0.200']);

    $response->assertForbidden()
        ->assertJsonPath('error.code', 'authorization_failed');
});
