<?php

declare(strict_types=1);

use App\Http\Gateway\Requests\Schedules\ListSchedulesRequest;
use App\Http\Gateway\Requests\Schedules\ShowScheduleRequest;
use App\Models\App;
use App\Models\LocalGatewaySettings;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Models\Schedule;
use App\Models\ScheduleRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process as ProcessFacade;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

afterEach(function (): void {
    MockClient::destroyGlobal();
});

function createScheduleReadLocalNode(string $role = 'gateway'): Node
{
    return Node::factory()->create([
        'name' => "local-{$role}",

        'host' => '10.6.0.1',
        'wireguard_address' => '10.6.0.1',
    ]);
}

function assignScheduleReadAppHostRole(Node $node, string $role = 'app-dev', array $settings = ['tld' => 'test']): void
{
    NodeRoleAssignment::factory()->create([
        'node_id' => $node->id,
        'role' => $role,
        'status' => 'active',
        'settings' => $settings,
    ]);
}

it('lists gateway-local schedules as JSON with latest run history', function (): void {
    createScheduleReadLocalNode('gateway');
    $node = Node::factory()->create(['name' => 'app-1']);
    $app = App::factory()->create(['name' => 'docs', 'node_id' => $node->id]);
    Schedule::factory()->forApp($app)->create([
        'name' => 'laravel-scheduler',
        'schedule_key' => 'app:docs:laravel-scheduler',
    ]);
    ScheduleRun::factory()->create([
        'node_id' => $node->id,
        'schedule_key' => 'app:docs:laravel-scheduler',
        'status' => 'completed',
        'started_at' => '2026-05-06 12:34:00',
        'finished_at' => '2026-05-06 12:34:03',
    ]);

    $exitCode = Artisan::call('schedule:list', ['--json' => true, '--app' => 'docs']);
    $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(0)
        ->and($payload['success']['meta'])->toMatchArray(['app' => 'docs', 'node' => null, 'count' => 1])
        ->and($payload['success']['data']['schedules'][0]['name'])->toBe('laravel-scheduler')
        ->and($payload['success']['data']['schedules'][0]['target']['node'])->toBe('app-1')
        ->and($payload['success']['data']['schedules'][0]['last_run']['status'])->toBe('completed');
});

it('shows a gateway-local schedule as JSON', function (): void {
    createScheduleReadLocalNode('gateway');
    $node = Node::factory()->create(['name' => 'app-1']);
    $app = App::factory()->create(['name' => 'docs', 'node_id' => $node->id]);
    Schedule::factory()->forApp($app)->create([
        'name' => 'laravel-scheduler',
        'schedule_key' => 'app:docs:laravel-scheduler',
        'timezone' => 'Europe/Amsterdam',
    ]);

    $exitCode = Artisan::call('schedule:show', [
        'name' => 'laravel-scheduler',
        '--json' => true,
        '--app' => 'docs',
    ]);
    $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(0)
        ->and($payload['success']['meta'])->toMatchArray(['app' => 'docs', 'node' => null])
        ->and($payload['success']['data']['schedule']['name'])->toBe('laravel-scheduler')
        ->and($payload['success']['data']['schedule']['timezone'])->toBe('Europe/Amsterdam');
});

it('renders human schedule list and show output', function (): void {
    createScheduleReadLocalNode('gateway');
    $node = Node::factory()->create(['name' => 'app-1']);
    $app = App::factory()->create(['name' => 'docs', 'node_id' => $node->id]);
    Schedule::factory()->forApp($app)->create([
        'name' => 'laravel-scheduler',
        'schedule_key' => 'app:docs:laravel-scheduler',
    ]);

    $this->artisan('schedule:list', ['--app' => 'docs'])
        ->expectsOutputToContain('laravel-scheduler')
        ->assertSuccessful();

    $showExitCode = Artisan::call('schedule:show', ['name' => 'laravel-scheduler', '--app' => 'docs']);
    $showOutput = Artisan::output();

    expect($showExitCode)->toBe(0)
        ->and($showOutput)->toContain('┌  Schedule: laravel-scheduler')
        ->and($showOutput)->toContain('├  Execution')
        ->and($showOutput)->toContain('php artisan schedule:run');
});

it('renders an empty state for schedule filters', function (): void {
    createScheduleReadLocalNode('gateway');

    $this->artisan('schedule:list', ['--app' => 'docs'])
        ->expectsOutput('No schedules found for app docs.')
        ->assertSuccessful();
});

it('rejects mutually exclusive schedule filters', function (): void {
    createScheduleReadLocalNode('gateway');

    $exitCode = Artisan::call('schedule:list', ['--json' => true, '--app' => 'docs', '--node' => 'app-1']);
    $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(1)
        ->and($payload['error']['code'])->toBe('validation_failed')
        ->and($payload['error']['meta']['fields'])->toBe(['app', 'node']);
});

it('returns a stable schedule not found error', function (): void {
    createScheduleReadLocalNode('gateway');

    $exitCode = Artisan::call('schedule:show', ['name' => 'missing', '--json' => true]);
    $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(1)
        ->and($payload['error']['code'])->toBe('schedule.not_found')
        ->and($payload['error']['meta']['name'])->toBe('missing');
});

it('exposes schedule reads over the authenticated gateway API', function (): void {
    $caller = Node::factory()->create([
        'name' => 'control-1',

        'wireguard_address' => '10.6.0.40',
    ]);
    $node = Node::factory()->create(['name' => 'app-1']);
    assignScheduleReadAppHostRole($node);
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

    $list = $this->call('GET', '/api/schedules', ['app' => 'docs'], [], [], ['REMOTE_ADDR' => '10.6.0.40']);
    $show = $this->call('GET', '/api/schedules/laravel-scheduler', ['app' => 'docs'], [], [], ['REMOTE_ADDR' => '10.6.0.40']);

    $list->assertSuccessful()
        ->assertJsonPath('success.meta.count', 1)
        ->assertJsonPath('success.data.schedules.0.name', 'laravel-scheduler');
    $show->assertSuccessful()
        ->assertJsonPath('success.data.schedule.name', 'laravel-scheduler');

    $entries = Activity::query()->orderBy('id')->get();

    expect($entries)->toHaveCount(2);
    expect($entries[0]->event)->toBe('api:GET /schedules');
    expect($entries[0]->properties->get('type'))->toBe('read');
    expect($entries[1]->event)->toBe('api:GET /schedules/{name}');
    expect($entries[1]->subject_type)->toBe(Schedule::class);
    expect($entries[1]->properties->get('name'))->toBe('laravel-scheduler');
});

it('hides legacy app-only schedule nodes from non-gateway schedule reads', function (): void {
    $caller = Node::factory()->create([
        'name' => 'control-1',

        'wireguard_address' => '10.6.0.42',
    ]);
    $node = Node::factory()->create(['name' => 'legacy-app-only']);
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

    $response = $this->call('GET', '/api/schedules', ['app' => 'docs'], [], [], ['REMOTE_ADDR' => '10.6.0.42']);

    $response->assertForbidden()
        ->assertJsonPath('error.code', 'authorization_failed');
});

it('rejects schedule API reads from unauthorized callers', function (): void {
    Node::factory()->create([

        'wireguard_address' => '10.6.0.41',
    ]);

    $response = $this->call('GET', '/api/schedules', [], [], [], ['REMOTE_ADDR' => '10.6.0.41']);

    $response->assertForbidden()
        ->assertJsonPath('error.code', 'authorization_failed');
});

it('forwards non-gateway schedule reads through typed gateway requests', function (): void {
    config(['orbit.is_gateway' => false]);

    createScheduleReadLocalNode('control');

    LocalGatewaySettings::current()->fill([
        'gateway_url' => 'https://10.6.0.1',
        'ca_pem_path' => '/dev/null',
    ])->save();

    MockClient::global([
        ListSchedulesRequest::class => MockResponse::make([
            'success' => [
                'data' => [
                    'schedules' => [[
                        'name' => 'laravel-scheduler',
                        'scope' => 'app',
                        'target' => ['type' => 'app', 'name' => 'docs', 'node' => 'app-1'],
                        'interval' => 'every minute',
                        'timezone' => 'UTC',
                        'execution' => ['type' => 'command', 'value' => 'php artisan schedule:run'],
                        'enabled' => true,
                        'status' => 'expected',
                        'last_run' => null,
                    ]],
                ],
                'meta' => ['app' => 'docs', 'node' => null, 'count' => 1],
            ],
        ], 200),
        ShowScheduleRequest::class => MockResponse::make([
            'success' => [
                'data' => [
                    'schedule' => [
                        'name' => 'laravel-scheduler',
                        'scope' => 'app',
                        'target' => ['type' => 'app', 'name' => 'docs', 'node' => 'app-1'],
                        'interval' => 'every minute',
                        'timezone' => 'UTC',
                        'execution' => ['type' => 'command', 'value' => 'php artisan schedule:run'],
                        'enabled' => true,
                        'status' => 'expected',
                        'last_run' => null,
                    ],
                ],
                'meta' => ['app' => 'docs', 'node' => null],
            ],
        ], 200),
    ]);

    $listExitCode = Artisan::call('schedule:list', ['--json' => true, '--app' => 'docs']);
    $listPayload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

    $showExitCode = Artisan::call('schedule:show', ['name' => 'laravel-scheduler', '--json' => true, '--app' => 'docs']);
    $showPayload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

    expect($listExitCode)->toBe(0)
        ->and($listPayload['success']['data']['schedules'][0]['name'])->toBe('laravel-scheduler')
        ->and($showExitCode)->toBe(0)
        ->and($showPayload['success']['data']['schedule']['name'])->toBe('laravel-scheduler');
});

it('does not mutate schedules or run external processes', function (): void {
    ProcessFacade::fake();
    ProcessFacade::preventStrayProcesses();

    createScheduleReadLocalNode('gateway');
    $node = Node::factory()->create([]);
    $app = App::factory()->create(['node_id' => $node->id]);
    Schedule::factory()->count(2)->forApp($app)->create();

    $scheduleCount = DB::table('schedules')->count();

    $this->artisan('schedule:list')->assertSuccessful();

    expect(DB::table('schedules')->count())->toBe($scheduleCount);
    ProcessFacade::assertNothingRan();
});
