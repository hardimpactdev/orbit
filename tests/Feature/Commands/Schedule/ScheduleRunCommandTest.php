<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Http\Gateway\Requests\Schedules\RunScheduleRequest;
use App\Models\App;
use App\Models\LocalGatewaySettings;
use App\Models\Node;
use App\Models\Schedule;
use App\Models\ScheduleRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

afterEach(function (): void {
    MockClient::destroyGlobal();
});

function createScheduleRunLocalNode(string $role = 'gateway'): Node
{
    return Node::factory()->create([
        'name' => "local-{$role}",
        'role' => $role,
        'host' => '10.6.0.1',
        'wireguard_address' => '10.6.0.1',
        'is_local' => true,
    ]);
}

it('runs an app scoped schedule on the target node and records history', function (): void {
    createScheduleRunLocalNode('gateway');
    $node = Node::factory()->create(['name' => 'app-1', 'role' => 'app']);
    $app = App::factory()->create(['name' => 'docs', 'node_id' => $node->id, 'path' => '/srv/docs']);
    Schedule::factory()->forApp($app)->create([
        'name' => 'laravel-scheduler',
        'schedule_key' => 'app:docs:laravel-scheduler',
        'execution_value' => 'php artisan schedule:run',
    ]);
    $remoteShell = new ScheduleRunRecordingRemoteShell([
        new RemoteShellResult(exitCode: 0, stdout: "ok\n", stderr: '', durationMs: 123),
    ]);
    app()->instance(RemoteShell::class, $remoteShell);

    $exitCode = Artisan::call('schedule:run', [
        'name' => 'laravel-scheduler',
        '--app' => 'docs',
        '--json' => true,
    ]);
    $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

    $run = ScheduleRun::query()->firstOrFail();

    expect($exitCode)->toBe(0)
        ->and($payload['success']['data']['run']['id'])->toBe($run->id)
        ->and($payload['success']['data']['run']['status'])->toBe('completed')
        ->and($payload['success']['data']['output']['stdout'])->toBe("ok\n")
        ->and($payload['success']['meta']['duration_ms'])->toBe(123)
        ->and($run->schedule_key)->toBe('app:docs:laravel-scheduler')
        ->and($remoteShell->scripts)->toBe(['php artisan schedule:run'])
        ->and($remoteShell->options[0]['cwd'])->toBe('/srv/docs');
});

it('returns schedule run failed with captured history for non-zero exits', function (): void {
    createScheduleRunLocalNode('gateway');
    $node = Node::factory()->create(['name' => 'app-1', 'role' => 'app']);
    $app = App::factory()->create(['name' => 'docs', 'node_id' => $node->id]);
    Schedule::factory()->forApp($app)->create([
        'name' => 'backups',
        'schedule_key' => 'app:docs:backups',
        'execution_value' => 'php artisan backup:run',
    ]);
    app()->instance(RemoteShell::class, new ScheduleRunRecordingRemoteShell([
        new RemoteShellResult(exitCode: 1, stdout: '', stderr: "Backup target is not mounted.\n", durationMs: 456),
    ]));

    $exitCode = Artisan::call('schedule:run', [
        'name' => 'backups',
        '--app' => 'docs',
        '--json' => true,
    ]);
    $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(1)
        ->and($payload['error']['code'])->toBe('schedule.run_failed')
        ->and($payload['error']['data']['run']['status'])->toBe('failed')
        ->and($payload['error']['data']['run']['exit_code'])->toBe(1)
        ->and($payload['error']['data']['output']['stderr'])->toBe("Backup target is not mounted.\n")
        ->and(ScheduleRun::query()->where('status', 'failed')->exists())->toBeTrue();
});

it('forwards non-gateway schedule runs through the typed gateway request', function (): void {
    createScheduleRunLocalNode('control');

    LocalGatewaySettings::current()->fill([
        'gateway_url' => 'https://10.6.0.1',
        'ca_pem_path' => '/dev/null',
    ])->save();

    MockClient::global([
        RunScheduleRequest::class => MockResponse::make([
            'success' => [
                'data' => [
                    'run' => ['id' => 18, 'schedule' => 'laravel-scheduler', 'status' => 'completed'],
                    'output' => ['stdout' => "ok\n", 'stderr' => ''],
                ],
                'meta' => ['duration_ms' => 10],
            ],
        ], 200),
    ]);

    $exitCode = Artisan::call('schedule:run', [
        'name' => 'laravel-scheduler',
        '--app' => 'docs',
        '--json' => true,
    ]);
    $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(0)
        ->and($payload['success']['data']['run']['id'])->toBe(18)
        ->and($payload['success']['meta']['duration_ms'])->toBe(10);
});

it('renders human progress and success prose', function (): void {
    createScheduleRunLocalNode('gateway');
    $node = Node::factory()->create(['name' => 'app-1', 'role' => 'app']);
    $app = App::factory()->create(['name' => 'docs', 'node_id' => $node->id, 'path' => '/srv/docs']);
    Schedule::factory()->forApp($app)->create([
        'name' => 'laravel-scheduler',
        'schedule_key' => 'app:docs:laravel-scheduler',
        'execution_value' => 'php artisan schedule:run',
    ]);
    app()->instance(RemoteShell::class, new ScheduleRunRecordingRemoteShell([
        new RemoteShellResult(exitCode: 0, stdout: "ok\n", stderr: '', durationMs: 123),
    ]));

    $this->artisan('schedule:run', [
        'name' => 'laravel-scheduler',
        '--app' => 'docs',
    ])
        ->expectsOutputToContain('  ┌  Running Schedule')
        ->expectsOutputToContain('  │')
        ->expectsOutputToContain('  ○  Resolve schedule')
        ->expectsOutputToContain('  ○  Open gateway execution')
        ->expectsOutputToContain('  ○  Run scheduled command')
        ->expectsOutputToContain('  ○  Record run history')
        ->expectsOutputToContain('  ●  Resolved schedule')
        ->expectsOutputToContain('  └  Schedule run completed')
        ->expectsOutputToContain("Schedule 'laravel-scheduler' completed with exit 0.")
        ->assertSuccessful();
});

it('exposes schedule run over the authenticated gateway API', function (): void {
    $caller = Node::factory()->create([
        'name' => 'control-1',
        'role' => 'control',
        'wireguard_address' => '10.6.0.70',
    ]);
    $node = Node::factory()->create(['name' => 'app-1', 'role' => 'app']);
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
    app()->instance(RemoteShell::class, new ScheduleRunRecordingRemoteShell([
        new RemoteShellResult(exitCode: 0, stdout: "ok\n", stderr: '', durationMs: 10),
    ]));

    $response = $this->call('POST', '/api/schedules/laravel-scheduler/run', [
        'app' => 'docs',
    ], [], [], ['REMOTE_ADDR' => '10.6.0.70']);

    $response->assertSuccessful()
        ->assertJsonPath('success.data.run.status', 'completed')
        ->assertJsonPath('success.data.output.stdout', "ok\n");

    $entry = Activity::query()->first();

    expect($entry)->not->toBeNull();
    expect($entry->event)->toBe('api:POST /schedules/{name}/run');
    expect($entry->subject_type)->toBe(Schedule::class);
    expect($entry->properties->get('type'))->toBe('write');
    expect($entry->properties->get('name'))->toBe('laravel-scheduler');
});

it('returns not found before running external commands', function (): void {
    createScheduleRunLocalNode('gateway');
    $remoteShell = new ScheduleRunRecordingRemoteShell;
    app()->instance(RemoteShell::class, $remoteShell);

    $exitCode = Artisan::call('schedule:run', [
        'name' => 'missing',
        '--json' => true,
    ]);
    $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(1)
        ->and($payload['error']['code'])->toBe('schedule.not_found')
        ->and($remoteShell->scripts)->toBe([]);
});

final class ScheduleRunRecordingRemoteShell implements RemoteShell
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
