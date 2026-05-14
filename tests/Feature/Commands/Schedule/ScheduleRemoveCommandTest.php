<?php

declare(strict_types=1);

use App\Http\Gateway\Requests\Schedules\RemoveScheduleRequest;
use App\Models\App;
use App\Models\LocalGatewaySettings;
use App\Models\Node;
use App\Models\Schedule;
use App\Models\SchedulerState;
use App\Models\ScheduleRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Laravel\Prompts\DataTablePrompt;
use Laravel\Prompts\Key;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

afterEach(function (): void {
    MockClient::destroyGlobal();
});

function createScheduleRemoveLocalNode(string $role = 'gateway'): Node
{
    return Node::factory()->create([
        'name' => "local-{$role}",
        'role' => $role,
        'host' => '10.6.0.1',
        'wireguard_address' => '10.6.0.1',
    ]);
}

it('removes an app scoped schedule on the gateway and retains run history', function (): void {
    createScheduleRemoveLocalNode('gateway');
    $node = Node::factory()->create(['name' => 'app-1', 'role' => 'app']);
    SchedulerState::factory()->create(['node_id' => $node->id, 'heartbeat_at' => now()]);
    $app = App::factory()->create(['name' => 'docs', 'node_id' => $node->id]);
    Schedule::factory()->forApp($app)->create([
        'name' => 'laravel-scheduler',
        'schedule_key' => 'app:docs:laravel-scheduler',
    ]);
    ScheduleRun::factory()->create([
        'node_id' => $node->id,
        'schedule_key' => 'app:docs:laravel-scheduler',
        'status' => 'completed',
    ]);

    $exitCode = Artisan::call('schedule:remove', [
        'name' => 'laravel-scheduler',
        '--app' => 'docs',
        '--force' => true,
        '--json' => true,
    ]);
    $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(0)
        ->and($payload['success']['data']['schedule']['status'])->toBe('removed')
        ->and($payload['success']['meta'])->toBe(['scheduler_pickup' => 'confirmed', 'history_retained' => true])
        ->and(Schedule::query()->where('schedule_key', 'app:docs:laravel-scheduler')->exists())->toBeFalse()
        ->and(ScheduleRun::query()->where('schedule_key', 'app:docs:laravel-scheduler')->exists())->toBeTrue();
});

it('requires destructive consent before removing schedules', function (): void {
    createScheduleRemoveLocalNode('gateway');

    $exitCode = Artisan::call('schedule:remove', [
        'name' => 'laravel-scheduler',
        '--app' => 'docs',
        '--json' => true,
    ]);
    $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(1)
        ->and($payload['error']['code'])->toBe('destructive_consent_required')
        ->and(Schedule::query()->count())->toBe(0);
});

it('prompts with a schedule data table when the name is missing', function (): void {
    createScheduleRemoveLocalNode('gateway');
    $node = Node::factory()->create(['name' => 'app-1', 'role' => 'app']);
    SchedulerState::factory()->create(['node_id' => $node->id, 'heartbeat_at' => now()]);
    $app = App::factory()->create(['name' => 'docs', 'node_id' => $node->id]);
    Schedule::factory()->forApp($app)->create([
        'name' => 'laravel-scheduler',
        'schedule_key' => 'app:docs:laravel-scheduler',
    ]);

    DataTablePrompt::fake([Key::ENTER]);

    $this->artisan('schedule:remove', ['--force' => true])
        ->assertSuccessful();

    expect(Schedule::query()->where('schedule_key', 'app:docs:laravel-scheduler')->exists())->toBeFalse();
});

it('returns not found before side effects', function (): void {
    createScheduleRemoveLocalNode('gateway');

    $exitCode = Artisan::call('schedule:remove', [
        'name' => 'missing',
        '--force' => true,
        '--json' => true,
    ]);
    $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(1)
        ->and($payload['error']['code'])->toBe('schedule.not_found')
        ->and($payload['error']['meta']['name'])->toBe('missing');
});

it('removes gateway intent and reports scheduler unreachable when pickup cannot be confirmed', function (): void {
    createScheduleRemoveLocalNode('gateway');
    $node = Node::factory()->create(['name' => 'app-1', 'role' => 'app']);
    $app = App::factory()->create(['name' => 'docs', 'node_id' => $node->id]);
    Schedule::factory()->forApp($app)->create([
        'name' => 'laravel-scheduler',
        'schedule_key' => 'app:docs:laravel-scheduler',
    ]);

    $exitCode = Artisan::call('schedule:remove', [
        'name' => 'laravel-scheduler',
        '--app' => 'docs',
        '--force' => true,
        '--json' => true,
    ]);
    $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(1)
        ->and($payload['error']['code'])->toBe('schedule.scheduler_unreachable')
        ->and($payload['error']['data']['schedule']['status'])->toBe('removed_pending_pickup')
        ->and(Schedule::query()->where('schedule_key', 'app:docs:laravel-scheduler')->exists())->toBeFalse();
});

it('forwards non-gateway schedule removes through the typed gateway request', function (): void {
    config(['orbit.is_gateway' => false]);

    createScheduleRemoveLocalNode('control');

    LocalGatewaySettings::current()->fill([
        'gateway_url' => 'https://10.6.0.1',
        'ca_pem_path' => '/dev/null',
    ])->save();

    MockClient::global([
        RemoveScheduleRequest::class => MockResponse::make([
            'success' => [
                'data' => [
                    'schedule' => ['name' => 'laravel-scheduler', 'status' => 'removed'],
                ],
                'meta' => ['scheduler_pickup' => 'confirmed', 'history_retained' => true],
            ],
        ], 200),
    ]);

    $exitCode = Artisan::call('schedule:remove', [
        'name' => 'laravel-scheduler',
        '--app' => 'docs',
        '--force' => true,
        '--json' => true,
    ]);
    $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(0)
        ->and($payload['success']['data']['schedule']['name'])->toBe('laravel-scheduler');
});

it('renders human progress and success prose', function (): void {
    createScheduleRemoveLocalNode('gateway');
    $node = Node::factory()->create(['name' => 'app-1', 'role' => 'app']);
    SchedulerState::factory()->create(['node_id' => $node->id, 'heartbeat_at' => now()]);
    $app = App::factory()->create(['name' => 'docs', 'node_id' => $node->id]);
    Schedule::factory()->forApp($app)->create([
        'name' => 'laravel-scheduler',
        'schedule_key' => 'app:docs:laravel-scheduler',
    ]);

    $this->artisan('schedule:remove', [
        'name' => 'laravel-scheduler',
        '--app' => 'docs',
        '--force' => true,
    ])
        ->expectsOutputToContain('  ┌  Removing Schedule')
        ->expectsOutputToContain('  │')
        ->expectsOutputToContain('  ○  Resolve schedule')
        ->expectsOutputToContain('  ○  Apply and verify removal')
        ->expectsOutputToContain('  ○  Notify Orbit Scheduler')
        ->expectsOutputToContain('  ●  Resolved schedule')
        ->expectsOutputToContain('  └  Schedule removed')
        ->expectsOutput("Schedule 'laravel-scheduler' removed.")
        ->assertSuccessful();
});

it('exposes schedule remove over the authenticated gateway API', function (): void {
    $caller = Node::factory()->create([
        'name' => 'control-1',
        'role' => 'control',
        'wireguard_address' => '10.6.0.60',
    ]);
    $node = Node::factory()->create(['name' => 'app-1', 'role' => 'app']);
    SchedulerState::factory()->create(['node_id' => $node->id, 'heartbeat_at' => now()]);
    DB::table('node_access')->insert([
        'consumer_node_id' => $caller->id,
        'serving_node_id' => $node->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $app = App::factory()->create(['name' => 'docs', 'node_id' => $node->id]);
    Schedule::factory()->forApp($app)->create([
        'name' => 'laravel-scheduler',
        'schedule_key' => 'app:docs:laravel-scheduler',
    ]);

    $response = $this->call('DELETE', '/api/schedules/laravel-scheduler', [
        'app' => 'docs',
        'destructive_consent' => true,
    ], [], [], ['REMOTE_ADDR' => '10.6.0.60']);

    $response->assertSuccessful()
        ->assertJsonPath('success.data.schedule.status', 'removed');

    $entry = Activity::query()->first();

    expect($entry)->not->toBeNull();
    expect($entry->event)->toBe('api:DELETE /schedules/{name}');
    expect($entry->subject_type)->toBe(Schedule::class);
    expect($entry->properties->get('type'))->toBe('destructive');
    expect($entry->properties->get('name'))->toBe('laravel-scheduler');
});

it('requires destructive consent on the authenticated schedule remove API', function (): void {
    $caller = Node::factory()->create([
        'name' => 'control-1',
        'role' => 'control',
        'wireguard_address' => '10.6.0.60',
    ]);
    $node = Node::factory()->create(['name' => 'app-1', 'role' => 'app']);
    DB::table('node_access')->insert([
        'consumer_node_id' => $caller->id,
        'serving_node_id' => $node->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $app = App::factory()->create(['name' => 'docs', 'node_id' => $node->id]);
    $schedule = Schedule::factory()->forApp($app)->create([
        'name' => 'laravel-scheduler',
        'schedule_key' => 'app:docs:laravel-scheduler',
    ]);

    $response = $this->call('DELETE', '/api/schedules/laravel-scheduler', [
        'app' => 'docs',
    ], [], [], ['REMOTE_ADDR' => '10.6.0.60']);

    $response->assertStatus(422)
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonPath('error.meta.field', 'force');

    expect(Schedule::query()->whereKey($schedule->id)->exists())->toBeTrue();
});
