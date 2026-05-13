<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Http\Gateway\Requests\Schedules\StoreSchedulerHeartbeatRequest;
use App\Http\Gateway\Requests\Schedules\StoreScheduleRunRequest;
use App\Http\Gateway\Requests\Schedules\SyncSchedulesRequest;
use App\Models\App;
use App\Models\LocalGatewaySettings;
use App\Models\Node;
use App\Models\Schedule;
use App\Models\ScheduleLock;
use App\Models\SchedulerState;
use App\Models\ScheduleRun;
use App\Services\Schedules\OrbitScheduler;
use App\Services\Schedules\ScheduleInterval;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

uses(RefreshDatabase::class);

afterEach(function (): void {
    MockClient::destroyGlobal();
});

it('runs one scheduler daemon tick on demand', function (): void {
    $this->artisan('orbit-scheduler --once')
        ->expectsOutputToContain('Orbit Scheduler tick completed')
        ->assertSuccessful();
});

it('runs due local schedules through rendered hook material and records history', function (): void {
    $localNode = createOrbitSchedulerLocalNode('gateway');
    $schedule = Schedule::factory()->forNode($localNode)->create([
        'name' => 'gateway-maintenance',
        'schedule_key' => 'node:local-gateway:gateway-maintenance',
        'interval' => 'every minute',
    ]);
    $remoteShell = new OrbitSchedulerRecordingRemoteShell([
        new RemoteShellResult(exitCode: 0, stdout: "ran\n", stderr: '', durationMs: 25),
    ]);
    app()->instance(RemoteShell::class, $remoteShell);

    $result = app(OrbitScheduler::class)->tick(CarbonImmutable::parse('2026-05-06T12:34:00Z'));

    $run = ScheduleRun::query()->firstOrFail();
    $state = SchedulerState::query()->firstOrFail();

    expect($result->dueSchedules)->toBe(1)
        ->and($result->executedSchedules)->toBe(1)
        ->and($remoteShell->scripts)->toBe(['/opt/orbit/schedules/hooks/'.hash('sha256', $schedule->schedule_key).'.sh'])
        ->and($remoteShell->options[0]['timeout'])->toBe(900)
        ->and($run->schedule_key)->toBe('node:local-gateway:gateway-maintenance')
        ->and($run->status)->toBe('completed')
        ->and($run->stdout)->toBe("ran\n")
        ->and($state->node_id)->toBe($localNode->id)
        ->and($state->heartbeat_at?->toIso8601String())->toBe('2026-05-06T12:34:00+00:00')
        ->and(ScheduleLock::query()->count())->toBe(0);
});

it('skips schedules that are not due or do not target the local node', function (): void {
    $localNode = createOrbitSchedulerLocalNode('gateway');
    $remoteNode = Node::factory()->create(['name' => 'app-2', 'role' => 'app']);
    $remoteApp = App::factory()->create(['name' => 'remote', 'node_id' => $remoteNode->id]);

    Schedule::factory()->forNode($localNode)->create([
        'name' => 'daily-report',
        'schedule_key' => 'node:local-gateway:daily-report',
        'interval' => 'daily at 09:00',
    ]);
    Schedule::factory()->forApp($remoteApp)->create([
        'name' => 'remote-report',
        'schedule_key' => 'app:remote:remote-report',
        'interval' => 'every minute',
    ]);

    $remoteShell = new OrbitSchedulerRecordingRemoteShell;
    app()->instance(RemoteShell::class, $remoteShell);

    $result = app(OrbitScheduler::class)->tick(CarbonImmutable::parse('2026-05-06T12:34:00Z'));

    expect($result->dueSchedules)->toBe(0)
        ->and($result->executedSchedules)->toBe(0)
        ->and($remoteShell->scripts)->toBe([])
        ->and(ScheduleRun::query()->count())->toBe(0);
});

it('reports app-node scheduler heartbeat and run history to the gateway', function (): void {
    config(['orbit.is_gateway' => false]);

    $localNode = createOrbitSchedulerLocalNode('app');
    LocalGatewaySettings::current()->fill([
        'gateway_url' => 'https://10.6.0.1',
        'ca_pem_path' => '/dev/null',
    ])->save();
    MockClient::global([
        SyncSchedulesRequest::class => MockResponse::make([
            'success' => [
                'data' => [
                    'schedules' => [[
                        'schedule_key' => 'app:docs:laravel-scheduler',
                        'name' => 'laravel-scheduler',
                        'scope' => 'app',
                        'target' => ['type' => 'app', 'name' => 'docs', 'node' => 'local-app'],
                        'interval' => 'every minute',
                        'timezone' => 'UTC',
                        'execution' => ['type' => 'command', 'value' => 'php artisan schedule:run'],
                        'enabled' => true,
                        'status' => 'expected',
                    ]],
                ],
                'meta' => ['node' => 'local-app', 'count' => 1],
            ],
        ], 200),
        StoreSchedulerHeartbeatRequest::class => MockResponse::make(['success' => ['data' => ['state' => []]]], 201),
        StoreScheduleRunRequest::class => MockResponse::make(['success' => ['data' => ['run' => ['id' => 9]]]], 201),
    ]);
    app()->instance(RemoteShell::class, new OrbitSchedulerRecordingRemoteShell([
        new RemoteShellResult(exitCode: 1, stdout: '', stderr: "failed\n", durationMs: 25),
    ]));

    $result = app(OrbitScheduler::class)->tick(CarbonImmutable::parse('2026-05-06T12:34:00Z'));

    expect($result->dueSchedules)->toBe(1)
        ->and($result->executedSchedules)->toBe(1)
        ->and(Schedule::query()->where('schedule_key', 'app:docs:laravel-scheduler')->exists())->toBeTrue()
        ->and(SchedulerState::query()->count())->toBe(0)
        ->and(ScheduleRun::query()->count())->toBe(0);
});

it('exposes scheduler sync intent for schedules targeting the authenticated node', function (): void {
    $caller = Node::factory()->create([
        'name' => 'app-1',
        'role' => 'app',
        'wireguard_address' => '10.6.0.41',
    ]);
    $otherNode = Node::factory()->create(['name' => 'app-2', 'role' => 'app']);
    $app = App::factory()->create(['name' => 'docs', 'node_id' => $caller->id]);
    $otherApp = App::factory()->create(['name' => 'other', 'node_id' => $otherNode->id]);
    Schedule::factory()->forApp($app)->create([
        'name' => 'laravel-scheduler',
        'schedule_key' => 'app:docs:laravel-scheduler',
    ]);
    Schedule::factory()->forApp($otherApp)->create([
        'name' => 'other-scheduler',
        'schedule_key' => 'app:other:other-scheduler',
    ]);

    $response = $this->call('GET', '/api/schedules/sync', [], [], [], ['REMOTE_ADDR' => '10.6.0.41']);

    $response->assertSuccessful()
        ->assertJsonPath('success.meta.node', 'app-1')
        ->assertJsonPath('success.meta.count', 1)
        ->assertJsonPath('success.data.schedules.0.schedule_key', 'app:docs:laravel-scheduler')
        ->assertJsonPath('success.data.schedules.0.name', 'laravel-scheduler');
});

it('evaluates portable schedule interval expressions', function (): void {
    $interval = new ScheduleInterval;
    $now = CarbonImmutable::parse('2026-05-06T12:35:00Z');

    expect($interval->isDue('every minute', 'UTC', $now))->toBeTrue()
        ->and($interval->isDue('every 5 minutes', 'UTC', $now))->toBeTrue()
        ->and($interval->isDue('every 10 minutes', 'UTC', $now))->toBeFalse()
        ->and($interval->isDue('daily at 14:35', 'Europe/Amsterdam', $now))->toBeTrue()
        ->and($interval->isDue('weekdays at 14:35', 'Europe/Amsterdam', $now))->toBeTrue()
        ->and($interval->isDue('weekly on wednesday at 14:35', 'Europe/Amsterdam', $now))->toBeTrue()
        ->and($interval->isDue('weekly on monday at 14:35', 'Europe/Amsterdam', $now))->toBeFalse();
});

function createOrbitSchedulerLocalNode(string $role): Node
{
    return Node::factory()->create([
        'name' => "local-{$role}",
        'role' => $role,
        'host' => '10.6.0.10',
        'wireguard_address' => '10.6.0.10',
        'status' => 'active',
    ]);
}

final class OrbitSchedulerRecordingRemoteShell implements RemoteShell
{
    /**
     * @var list<string>
     */
    public array $scripts = [];

    /**
     * @var list<array<string, mixed>>
     */
    public array $options = [];

    /**
     * @param  list<RemoteShellResult>  $results
     */
    public function __construct(
        private array $results = [],
    ) {}

    /**
     * @param  array<string, mixed>  $options
     */
    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        $this->scripts[] = $script;
        $this->options[] = $options;

        return array_shift($this->results) ?? new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1);
    }
}
