<?php

declare(strict_types=1);

use App\Http\Gateway\Requests\Schedules\ShowScheduleLogsRequest;
use App\Models\App;
use App\Models\LocalGatewaySettings;
use App\Models\Node;
use App\Models\Schedule;
use App\Models\ScheduleRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process as ProcessFacade;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

uses(RefreshDatabase::class);

afterEach(function (): void {
    MockClient::destroyGlobal();
});

function createScheduleLogsLocalNode(string $role = 'gateway'): Node
{
    return Node::factory()->create([
        'name' => "local-{$role}",
        'role' => $role,
        'host' => '10.6.0.1',
        'wireguard_address' => '10.6.0.1',
        'is_local' => true,
    ]);
}

it('reads the latest schedule run logs with independent line limiting', function (): void {
    createScheduleLogsLocalNode('gateway');
    $node = Node::factory()->create(['name' => 'app-1', 'role' => 'app']);
    $app = App::factory()->create(['name' => 'docs', 'node_id' => $node->id]);
    Schedule::factory()->forApp($app)->create([
        'name' => 'laravel-scheduler',
        'schedule_key' => 'app:docs:laravel-scheduler',
    ]);
    ScheduleRun::factory()->create([
        'node_id' => $node->id,
        'schedule_key' => 'app:docs:laravel-scheduler',
        'started_at' => '2026-05-06 12:00:00',
        'stdout' => "old\n",
    ]);
    $latestRun = ScheduleRun::factory()->create([
        'node_id' => $node->id,
        'schedule_key' => 'app:docs:laravel-scheduler',
        'started_at' => '2026-05-06 12:01:00',
        'stdout' => "one\ntwo\nthree\n",
        'stderr' => "alpha\nbeta\ngamma\n",
    ]);

    $exitCode = Artisan::call('schedule:logs', [
        'name' => 'laravel-scheduler',
        '--app' => 'docs',
        '--lines' => 2,
        '--json' => true,
    ]);
    $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(0)
        ->and($payload['success']['data']['run']['id'])->toBe($latestRun->id)
        ->and($payload['success']['data']['output']['stdout'])->toBe("two\nthree\n")
        ->and($payload['success']['data']['output']['stderr'])->toBe("beta\ngamma\n")
        ->and($payload['success']['meta'])->toBe(['lines' => 2, 'truncated' => true]);
});

it('reads a selected schedule run id', function (): void {
    createScheduleLogsLocalNode('gateway');
    $node = Node::factory()->create(['role' => 'app']);
    $app = App::factory()->create(['name' => 'docs', 'node_id' => $node->id]);
    Schedule::factory()->forApp($app)->create([
        'name' => 'laravel-scheduler',
        'schedule_key' => 'app:docs:laravel-scheduler',
    ]);
    $selectedRun = ScheduleRun::factory()->create([
        'node_id' => $node->id,
        'schedule_key' => 'app:docs:laravel-scheduler',
        'stdout' => "selected\n",
    ]);
    ScheduleRun::factory()->create([
        'node_id' => $node->id,
        'schedule_key' => 'app:docs:laravel-scheduler',
        'started_at' => now()->addMinute(),
        'stdout' => "latest\n",
    ]);

    $exitCode = Artisan::call('schedule:logs', [
        'name' => 'laravel-scheduler',
        '--app' => 'docs',
        '--run' => $selectedRun->id,
        '--json' => true,
    ]);
    $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(0)
        ->and($payload['success']['data']['run']['id'])->toBe($selectedRun->id)
        ->and($payload['success']['data']['output']['stdout'])->toBe("selected\n");
});

it('returns run not found for missing run history', function (): void {
    createScheduleLogsLocalNode('gateway');
    $node = Node::factory()->create(['role' => 'app']);
    $app = App::factory()->create(['name' => 'docs', 'node_id' => $node->id]);
    Schedule::factory()->forApp($app)->create([
        'name' => 'laravel-scheduler',
        'schedule_key' => 'app:docs:laravel-scheduler',
    ]);

    $exitCode = Artisan::call('schedule:logs', [
        'name' => 'laravel-scheduler',
        '--app' => 'docs',
        '--json' => true,
    ]);
    $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(1)
        ->and($payload['error']['code'])->toBe('schedule.run_not_found');
});

it('rejects invalid line limits', function (): void {
    createScheduleLogsLocalNode('gateway');

    $exitCode = Artisan::call('schedule:logs', [
        'name' => 'laravel-scheduler',
        '--lines' => 0,
        '--json' => true,
    ]);
    $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(1)
        ->and($payload['error']['code'])->toBe('validation_failed')
        ->and($payload['error']['meta']['field'])->toBe('lines');
});

it('forwards non-gateway schedule logs through the typed gateway request', function (): void {
    createScheduleLogsLocalNode('control');

    LocalGatewaySettings::current()->fill([
        'gateway_url' => 'https://10.6.0.1',
        'ca_pem_path' => '/dev/null',
    ])->save();

    MockClient::global([
        ShowScheduleLogsRequest::class => MockResponse::make([
            'success' => [
                'data' => [
                    'run' => ['id' => 18, 'schedule' => 'laravel-scheduler', 'status' => 'completed'],
                    'output' => ['stdout' => "ok\n", 'stderr' => ''],
                ],
                'meta' => ['lines' => 100, 'truncated' => false],
            ],
        ], 200),
    ]);

    $exitCode = Artisan::call('schedule:logs', [
        'name' => 'laravel-scheduler',
        '--app' => 'docs',
        '--json' => true,
    ]);
    $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(0)
        ->and($payload['success']['data']['run']['id'])->toBe(18);
});

it('exposes schedule logs over the authenticated gateway API without external processes', function (): void {
    ProcessFacade::fake();
    ProcessFacade::preventStrayProcesses();

    $caller = Node::factory()->create([
        'name' => 'control-1',
        'role' => 'control',
        'wireguard_address' => '10.6.0.80',
    ]);
    $node = Node::factory()->create(['role' => 'app']);
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
    ScheduleRun::factory()->create([
        'node_id' => $node->id,
        'schedule_key' => 'app:docs:laravel-scheduler',
        'stdout' => "ok\n",
    ]);

    $response = $this->call('GET', '/api/schedules/laravel-scheduler/logs', [
        'app' => 'docs',
    ], [], [], ['REMOTE_ADDR' => '10.6.0.80']);

    $response->assertSuccessful()
        ->assertJsonPath('success.data.output.stdout', "ok\n");
    ProcessFacade::assertNothingRan();
});
