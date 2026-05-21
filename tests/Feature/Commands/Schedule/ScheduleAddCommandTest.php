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
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;

uses(RefreshDatabase::class);

afterEach(function (): void {
    MockClient::destroyGlobal();
});

function createScheduleAddLocalNode(string $role = 'gateway', bool $withSchedulerHeartbeat = true): Node
{
    $node = Node::factory()->create([
        'name' => "local-{$role}",
        'role' => $role,
        'host' => '10.6.0.1',
        'wireguard_address' => '10.6.0.1',
    ]);

    if ($role === 'gateway' && $withSchedulerHeartbeat) {
        SchedulerState::factory()->create(['node_id' => $node->id, 'heartbeat_at' => now()]);
    }

    return $node;
}

it('creates an app scoped schedule on the gateway', function (): void {
    createScheduleAddLocalNode('gateway');
    $node = Node::factory()->create(['name' => 'app-1', 'role' => 'app']);
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

it('records intent and returns target unreachable when pickup cannot be confirmed', function (): void {
    createScheduleAddLocalNode('gateway', withSchedulerHeartbeat: false);
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
        ->and($payload['error']['code'])->toBe('schedule.target_unreachable')
        ->and($payload['error']['message'])->toBe("Schedule 'laravel-scheduler' was recorded, but schedule dispatch through gateway node 'local-gateway' could not be confirmed reachable.")
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
    config(['orbit.is_gateway' => false]);

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

it('renders human progress and success prose', function (): void {
    createScheduleAddLocalNode('gateway');
    $node = Node::factory()->create(['name' => 'app-1', 'role' => 'app']);
    App::factory()->create(['name' => 'docs', 'node_id' => $node->id]);

    $this->artisan('schedule:add', [
        'name' => 'laravel-scheduler',
        '--app' => 'docs',
        '--command' => 'php artisan schedule:run',
        '--interval' => 'every minute',
    ])
        ->expectsOutputToContain('  ┌  Adding Schedule')
        ->expectsOutputToContain('  │')
        ->expectsOutputToContain('  ○  Validate schedule')
        ->expectsOutputToContain('  ○  Create schedule intent')
        ->expectsOutputToContain('  ○  Confirm scheduler pickup')
        ->expectsOutputToContain('  ●  Validated schedule')
        ->expectsOutputToContain('  └  Schedule added')
        ->expectsOutput("Schedule 'laravel-scheduler' added.")
        ->assertSuccessful();
});

it('renders a decorated schedule add progress tree', function (): void {
    createScheduleAddLocalNode('gateway');
    $node = Node::factory()->create(['name' => 'app-1', 'role' => 'app']);
    App::factory()->create(['name' => 'docs', 'node_id' => $node->id]);

    $output = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, true);

    Artisan::call('schedule:add', [
        'name' => 'laravel-scheduler',
        '--app' => 'docs',
        '--command' => 'php artisan schedule:run',
        '--interval' => 'every minute',
    ], $output);

    $text = $output->fetch();

    expect($text)
        ->toContain('┌')
        ->toContain('│')
        ->toContain('└')
        ->toContain("\e[38;5;242m○  Validate schedule\e[39m")
        ->toContain("\e[32m●\e[39m")
        ->toContain('Schedule added');
});

it('exposes schedule add over the authenticated gateway API', function (): void {
    createScheduleAddLocalNode('gateway');
    $caller = Node::factory()->create([
        'name' => 'control-1',
        'role' => 'control',
        'wireguard_address' => '10.6.0.50',
    ]);
    $node = Node::factory()->create(['name' => 'app-1', 'role' => 'app']);
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

    $entry = Activity::query()->first();

    expect($entry)->not->toBeNull();
    expect($entry->event)->toBe('api:POST /schedules');
    expect($entry->subject_type)->toBe(Schedule::class);
    expect($entry->properties->get('type'))->toBe('write');
    expect($entry->properties->get('name'))->toBe('laravel-scheduler');
});
