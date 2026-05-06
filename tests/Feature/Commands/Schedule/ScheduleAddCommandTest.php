<?php

declare(strict_types=1);

use App\Http\Gateway\Requests\Schedules\AddScheduleRequest;
use App\Models\App;
use App\Models\LocalGatewaySettings;
use App\Models\Node;
use App\Models\Schedule;
use App\Models\SchedulerState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

uses(RefreshDatabase::class);

afterEach(function (): void {
    MockClient::destroyGlobal();
});

function createScheduleAddLocalNode(string $role = 'gateway'): Node
{
    return Node::factory()->create([
        'name' => "local-{$role}",
        'role' => $role,
        'host' => '10.6.0.1',
        'wireguard_address' => '10.6.0.1',
        'is_local' => true,
    ]);
}

it('creates an app scoped schedule on the gateway', function (): void {
    createScheduleAddLocalNode('gateway');
    $node = Node::factory()->create(['name' => 'app-1', 'role' => 'app']);
    SchedulerState::factory()->create(['node_id' => $node->id, 'heartbeat_at' => now()]);
    App::factory()->create(['name' => 'docs', 'node_id' => $node->id]);

    $exitCode = Artisan::call('schedule:add', [
        'name' => 'laravel-scheduler',
        '--app' => 'docs',
        '--command' => 'php artisan schedule:run',
        '--interval' => 'every minute',
        '--timezone' => 'Europe/Amsterdam',
        '--json' => true,
    ]);
    $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(0)
        ->and($payload['success']['data']['result'])->toBe(['action' => 'created', 'scheduler_pickup' => 'confirmed'])
        ->and($payload['success']['data']['schedule']['name'])->toBe('laravel-scheduler')
        ->and(Schedule::query()->where('schedule_key', 'app:docs:laravel-scheduler')->exists())->toBeTrue();
});

it('records intent and returns scheduler unreachable when pickup cannot be confirmed', function (): void {
    createScheduleAddLocalNode('gateway');
    $node = Node::factory()->create(['name' => 'app-1', 'role' => 'app']);
    App::factory()->create(['name' => 'docs', 'node_id' => $node->id]);

    $exitCode = Artisan::call('schedule:add', [
        'name' => 'laravel-scheduler',
        '--app' => 'docs',
        '--command' => 'php artisan schedule:run',
        '--interval' => 'every minute',
        '--json' => true,
    ]);
    $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(1)
        ->and($payload['error']['code'])->toBe('schedule.scheduler_unreachable')
        ->and($payload['error']['data']['schedule']['status'])->toBe('scheduler_unreachable')
        ->and(Schedule::query()->where('schedule_key', 'app:docs:laravel-scheduler')->exists())->toBeTrue();
});

it('validates target and execution source exclusivity', function (): void {
    createScheduleAddLocalNode('gateway');

    $exitCode = Artisan::call('schedule:add', [
        'name' => 'nightly',
        '--app' => 'docs',
        '--node' => 'app-1',
        '--command' => 'date',
        '--interval' => 'daily at 09:00',
        '--json' => true,
    ]);
    $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(1)
        ->and($payload['error']['code'])->toBe('validation_failed')
        ->and($payload['error']['meta']['fields'])->toBe(['app', 'node']);

    $exitCode = Artisan::call('schedule:add', [
        'name' => 'nightly',
        '--app' => 'docs',
        '--command' => 'date',
        '--script' => '/opt/orbit/nightly',
        '--interval' => 'daily at 09:00',
        '--json' => true,
    ]);
    $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(1)
        ->and($payload['error']['code'])->toBe('validation_failed')
        ->and($payload['error']['meta']['fields'])->toBe(['command', 'script']);
});

it('forwards non-gateway schedule adds through the typed gateway request', function (): void {
    createScheduleAddLocalNode('control');

    LocalGatewaySettings::current()->fill([
        'gateway_url' => 'https://10.6.0.1',
        'ca_pem_path' => '/dev/null',
    ])->save();

    MockClient::global([
        AddScheduleRequest::class => MockResponse::make([
            'success' => [
                'data' => [
                    'result' => ['action' => 'created', 'scheduler_pickup' => 'confirmed'],
                    'schedule' => ['name' => 'laravel-scheduler'],
                ],
            ],
        ], 201),
    ]);

    $exitCode = Artisan::call('schedule:add', [
        'name' => 'laravel-scheduler',
        '--app' => 'docs',
        '--command' => 'php artisan schedule:run',
        '--interval' => 'every minute',
        '--json' => true,
    ]);
    $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(0)
        ->and($payload['success']['data']['schedule']['name'])->toBe('laravel-scheduler');
});

it('exposes schedule add over the authenticated gateway API', function (): void {
    $caller = Node::factory()->create([
        'name' => 'control-1',
        'role' => 'control',
        'wireguard_address' => '10.6.0.50',
    ]);
    $node = Node::factory()->create(['name' => 'app-1', 'role' => 'app']);
    SchedulerState::factory()->create(['node_id' => $node->id, 'heartbeat_at' => now()]);
    DB::table('node_access')->insert([
        'consumer_node_id' => $caller->id,
        'serving_node_id' => $node->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    App::factory()->create(['name' => 'docs', 'node_id' => $node->id]);

    $response = $this->call('POST', '/api/schedules', [
        'name' => 'laravel-scheduler',
        'app' => 'docs',
        'command' => 'php artisan schedule:run',
        'interval' => 'every minute',
        'timezone' => 'UTC',
    ], [], [], ['REMOTE_ADDR' => '10.6.0.50']);

    $response->assertCreated()
        ->assertJsonPath('success.data.schedule.name', 'laravel-scheduler');
});
