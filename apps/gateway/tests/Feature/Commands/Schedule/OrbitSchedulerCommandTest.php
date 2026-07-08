<?php

declare(strict_types=1);

use App\Data\Doctor\DriftEntry;
use App\Data\RemoteShell\RemoteShellResult;
use App\Enums\DriftKind;
use App\Models\App;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Models\Schedule;
use App\Models\ScheduleLock;
use App\Models\SchedulerState;
use App\Models\ScheduleRun;
use App\Services\RemoteShell\RunsInternalCommands;
use App\Services\Schedules\OrbitScheduler;
use App\Services\Schedules\ScheduleInterval;
use App\Services\Schedules\SchedulesFixer;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Orbit\Core\Enums\InternalCommand;
use Orbit\Core\Http\JsonEnvelope;

uses(RefreshDatabase::class);

it('runs one scheduler daemon tick on demand', function (): void {
    createOrbitSchedulerGatewayNode();

    $this
        ->artisan('orbit-scheduler --once')
        ->expectsOutputToContain('Orbit Scheduler tick completed')
        ->assertSuccessful();
});

it('starts the scheduler through the gateway image scheduler entrypoint', function (): void {
    $entrypoint = file_get_contents(repo_path('docker/orbit-gateway/entrypoint.sh'));

    expect($entrypoint)
        ->toContain('scheduler)')
        ->toContain('run_artisan orbit-'.'scheduler "$@"')
        ->not->toContain('php "$artisan" serve')
        ->not->toContain('PHP_CLI_SERVER_WORKERS');
});

it('renders scheduler repair through the gateway Swarm scheduler service instead of host supervisor', function (): void {
    $gateway = createOrbitSchedulerGatewayNode();
    Process::fake([
        "docker service inspect --format '{{.Spec.TaskTemplate.ContainerSpec.Image}}' 'orbit_orbit-scheduler'" => Process::result(
            output: "ghcr.io/hardimpactdev/orbit-gateway:1.2.3@sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa\n",
        ),
        "docker service scale --detach=true 'orbit_orbit-scheduler=1'" => Process::result(),
    ]);

    new SchedulesFixer()->fixGateway($gateway, new DriftEntry(
        family: 'schedule',
        key: 'schedule.scheduler_stopped',
        kind: DriftKind::Divergent,
        summary: 'Orbit Scheduler service is stopped.',
    ));

    Process::assertRan(
        "docker service inspect --format '{{.Spec.TaskTemplate.ContainerSpec.Image}}' 'orbit_orbit-scheduler'",
    );
    Process::assertRan("docker service scale --detach=true 'orbit_orbit-scheduler=1'");
    Process::assertNotRan(fn ($process): bool => str_contains((string) $process->command, 'orbit'.'-runtime'));
    Process::assertNotRan(fn ($process): bool => str_contains((string) $process->command, 'supervisor'));
    Process::assertNotRan(fn ($process): bool => str_contains((string) $process->command, 'docker restart'));
});

it('dispatches due app schedules from the gateway and records run history centrally', function (): void {
    $gateway = createOrbitSchedulerGatewayNode();
    $appNode = createOrbitSchedulerAppHostNode(['name' => 'app-1']);
    $app = App::factory()->create(['name' => 'docs', 'node_id' => $appNode->id, 'path' => '/srv/docs']);
    Schedule::factory()
        ->forApp($app)
        ->create([
            'name' => 'laravel-scheduler',
            'schedule_key' => 'app:docs:laravel-scheduler',
            'execution_value' => 'php artisan schedule:run',
            'interval' => 'every minute',
        ]);
    $localExecutor = new OrbitSchedulerRecordingInternalExecutor([
        OrbitSchedulerRecordingInternalExecutor::result(stdout: "ran\n", durationMs: 25),
    ]);
    app()->instance(RunsInternalCommands::class, $localExecutor);

    $result = app(OrbitScheduler::class)->tick(CarbonImmutable::parse('2026-05-06T12:34:00Z'));

    $run = ScheduleRun::query()->firstOrFail();
    $state = SchedulerState::query()->firstOrFail();
    $payload = $localExecutor->payloads()[0];

    expect($result->dueSchedules)
        ->toBe(1)
        ->and($result->executedSchedules)
        ->toBe(1)
        ->and($localExecutor->nodes)
        ->toBe(['app-1'])
        ->and($localExecutor->commands)
        ->toBe([InternalCommand::ScheduleRun->value])
        ->and($localExecutor->transportOptions[0]['timeout'])
        ->toBe(915)
        ->and($localExecutor->transportOptions[0]['strict'])
        ->toBeFalse()
        ->and($localExecutor->transportOptions[0]['metadata']['ORBIT_OPERATION_ID'] ?? null)
        ->toBe('schedule.dispatch')
        ->and($payload['execution_type'] ?? null)
        ->toBe('command')
        ->and($payload['execution_value'] ?? null)
        ->toBe('php artisan schedule:run')
        ->and($payload['cwd'] ?? null)
        ->toBe('/srv/docs')
        ->and($payload['timeout'] ?? null)
        ->toBe(900)
        ->and($run->node_id)
        ->toBe($appNode->id)
        ->and($run->schedule_key)
        ->toBe('app:docs:laravel-scheduler')
        ->and($run->status)
        ->toBe('completed')
        ->and($run->stdout)
        ->toBe("ran\n")
        ->and($state->node_id)
        ->toBe($gateway->id)
        ->and($state->heartbeat_at?->toIso8601String())
        ->toBe('2026-05-06T12:34:00+00:00')
        ->and(ScheduleLock::query()->count())
        ->toBe(0);
});

it('dispatches remote schedules through the internal schedule command without transitional fallback opt in', function (): void {
    $gateway = createOrbitSchedulerGatewayNode();
    $appNode = createOrbitSchedulerAppHostNode(['name' => 'app-1']);
    $app = App::factory()->create(['name' => 'docs', 'node_id' => $appNode->id, 'path' => '/srv/docs']);
    Schedule::factory()
        ->forApp($app)
        ->create([
            'name' => 'laravel-scheduler',
            'schedule_key' => 'app:docs:laravel-scheduler',
            'execution_value' => 'php artisan schedule:run',
            'interval' => 'every minute',
        ]);
    $localExecutor = new OrbitSchedulerRecordingInternalExecutor([
        OrbitSchedulerRecordingInternalExecutor::result(stdout: "ran\n"),
    ]);
    app()->instance(RunsInternalCommands::class, $localExecutor);

    $result = app(OrbitScheduler::class)->tick(CarbonImmutable::parse('2026-05-06T12:34:00Z'));

    $run = ScheduleRun::query()->firstOrFail();
    $state = SchedulerState::query()->firstOrFail();

    expect($result->dueSchedules)
        ->toBe(1)
        ->and($result->executedSchedules)
        ->toBe(1)
        ->and($localExecutor->commands)
        ->toBe([InternalCommand::ScheduleRun->value])
        ->and($run->node_id)
        ->toBe($appNode->id)
        ->and($run->status)
        ->toBe('completed')
        ->and($run->exit_code)
        ->toBe(0)
        ->and($run->stdout)
        ->toBe("ran\n")
        ->and($state->node_id)
        ->toBe($gateway->id)
        ->and(ScheduleLock::query()->count())
        ->toBe(0);
});

it('runs gateway-target schedules locally without remote shell dispatch', function (): void {
    $gateway = createOrbitSchedulerGatewayNode();
    Schedule::factory()
        ->orbit()
        ->create([
            'name' => 'gateway-maintenance',
            'schedule_key' => 'orbit:gateway:gateway-maintenance',
            'execution_value' => 'php apps/gateway/artisan orbit:cleanup',
            'interval' => 'every minute',
        ]);
    $localExecutor = new OrbitSchedulerRecordingInternalExecutor;
    app()->instance(RunsInternalCommands::class, $localExecutor);
    Process::fake([
        'php apps/gateway/artisan orbit:cleanup' => Process::result(output: "local\n"),
    ]);
    Process::preventStrayProcesses();

    $result = app(OrbitScheduler::class)->tick(CarbonImmutable::parse('2026-05-06T12:34:00Z'));

    $run = ScheduleRun::query()->firstOrFail();

    expect($result->dueSchedules)
        ->toBe(1)
        ->and($result->executedSchedules)
        ->toBe(1)
        ->and($localExecutor->commands)
        ->toBe([])
        ->and($run->node_id)
        ->toBe($gateway->id)
        ->and($run->status)
        ->toBe('completed')
        ->and($run->stdout)
        ->toBe("local\n");

    Process::assertRan('php apps/gateway/artisan orbit:cleanup');
});

it('dispatches multiple remote schedules through the internal schedule command', function (): void {
    createOrbitSchedulerGatewayNode();
    $firstNode = createOrbitSchedulerAppHostNode(['name' => 'app-1']);
    $secondNode = createOrbitSchedulerAppHostNode(['name' => 'app-2']);
    $thirdNode = createOrbitSchedulerAppHostNode(['name' => 'app-3']);

    foreach ([[$firstNode, 'one'], [$secondNode, 'two'], [$thirdNode, 'three']] as [$node, $name]) {
        $app = App::factory()->create([
            'name' => $name,
            'node_id' => $node->id,
            'path' => "/srv/{$name}",
        ]);

        Schedule::factory()
            ->forApp($app)
            ->create([
                'name' => 'scheduler',
                'schedule_key' => "app:{$name}:scheduler",
                'execution_value' => "echo {$name}",
                'interval' => 'every minute',
            ]);
    }

    $localExecutor = new OrbitSchedulerRecordingInternalExecutor;
    app()->instance(RunsInternalCommands::class, $localExecutor);

    $result = app(OrbitScheduler::class)->tick(CarbonImmutable::parse('2026-05-06T12:34:00Z'));

    expect($result->dueSchedules)
        ->toBe(3)
        ->and($result->executedSchedules)
        ->toBe(3)
        ->and($localExecutor->commands)
        ->toBe([
            InternalCommand::ScheduleRun->value,
            InternalCommand::ScheduleRun->value,
            InternalCommand::ScheduleRun->value,
        ])
        ->and(array_map(
            static fn (array $payload): array => [
                'command' => $payload['execution_value'],
                'cwd' => $payload['cwd'],
            ],
            $localExecutor->payloads(),
        ))
        ->toBe([
            ['command' => 'echo one', 'cwd' => '/srv/one'],
            ['command' => 'echo two', 'cwd' => '/srv/two'],
            ['command' => 'echo three', 'cwd' => '/srv/three'],
        ])
        ->and(ScheduleRun::query()->where('status', 'completed')->count())
        ->toBe(3)
        ->and(ScheduleLock::query()->count())
        ->toBe(0);
});

it('skips schedules that are not due', function (): void {
    createOrbitSchedulerGatewayNode();

    Schedule::factory()
        ->orbit()
        ->create([
            'name' => 'daily-report',
            'schedule_key' => 'orbit:gateway:daily-report',
            'interval' => 'daily at 09:00',
        ]);

    $localExecutor = new OrbitSchedulerRecordingInternalExecutor;
    app()->instance(RunsInternalCommands::class, $localExecutor);

    $result = app(OrbitScheduler::class)->tick(CarbonImmutable::parse('2026-05-06T12:34:00Z'));

    expect($result->dueSchedules)
        ->toBe(0)
        ->and($result->executedSchedules)
        ->toBe(0)
        ->and($localExecutor->commands)
        ->toBe([])
        ->and(ScheduleRun::query()->count())
        ->toBe(0);
});

it('records remote dispatch failures as failed gateway history', function (): void {
    createOrbitSchedulerGatewayNode();
    $appNode = createOrbitSchedulerAppHostNode(['name' => 'app-1']);
    $app = App::factory()->create(['name' => 'docs', 'node_id' => $appNode->id]);
    Schedule::factory()
        ->forApp($app)
        ->create([
            'name' => 'laravel-scheduler',
            'schedule_key' => 'app:docs:laravel-scheduler',
            'interval' => 'every minute',
        ]);
    app()->instance(
        RunsInternalCommands::class,
        new OrbitSchedulerRecordingInternalExecutor(throwable: new RuntimeException('agent timeout')),
    );

    $result = app(OrbitScheduler::class)->tick(CarbonImmutable::parse('2026-05-06T12:34:00Z'));
    $run = ScheduleRun::query()->firstOrFail();

    expect($result->dueSchedules)
        ->toBe(1)
        ->and($result->executedSchedules)
        ->toBe(1)
        ->and($run->node_id)
        ->toBe($appNode->id)
        ->and($run->status)
        ->toBe('failed')
        ->and($run->exit_code)
        ->toBe(1)
        ->and($run->stderr)
        ->toBe('agent timeout')
        ->and(ScheduleLock::query()->count())
        ->toBe(0);
});

it('evaluates portable schedule interval expressions', function (): void {
    $interval = new ScheduleInterval;
    $now = CarbonImmutable::parse('2026-05-06T12:35:00Z');

    expect($interval->isDue('every minute', 'UTC', $now))
        ->toBeTrue()
        ->and($interval->isDue('every 5 minutes', 'UTC', $now))
        ->toBeTrue()
        ->and($interval->isDue('every 10 minutes', 'UTC', $now))
        ->toBeFalse()
        ->and($interval->isDue('daily at 14:35', 'Europe/Amsterdam', $now))
        ->toBeTrue()
        ->and($interval->isDue('weekdays at 14:35', 'Europe/Amsterdam', $now))
        ->toBeTrue()
        ->and($interval->isDue('weekly on wednesday at 14:35', 'Europe/Amsterdam', $now))
        ->toBeTrue()
        ->and($interval->isDue('weekly on monday at 14:35', 'Europe/Amsterdam', $now))
        ->toBeFalse();
});

function createOrbitSchedulerGatewayNode(array $attributes = []): Node
{
    $node = Node::factory()->create([
        'name' => 'gateway-1',
        'host' => '10.6.0.10',
        'wireguard_address' => '10.6.0.10',
        'status' => 'active',
        ...$attributes,
    ]);

    NodeRoleAssignment::factory()->create([
        'node_id' => $node->id,
        'role' => 'gateway',
        'status' => 'active',
    ]);

    return $node;
}

function createOrbitSchedulerAppHostNode(array $attributes = []): Node
{
    $node = Node::factory()->create([
        'status' => 'active',
        ...$attributes,
    ]);

    NodeRoleAssignment::factory()->create([
        'node_id' => $node->id,
        'role' => 'app-dev',
        'status' => 'active',
        'settings' => ['tld' => 'test'],
    ]);

    return $node;
}

final class OrbitSchedulerRecordingInternalExecutor implements RunsInternalCommands
{
    /**
     * @var list<string>
     */
    public array $nodes = [];

    /**
     * @var list<string>
     */
    public array $commands = [];

    /**
     * @var list<array<string, mixed>>
     */
    public array $transportOptions = [];

    /**
     * @param  list<RemoteShellResult>  $results
     */
    public function __construct(
        private array $results = [],
        private ?RuntimeException $throwable = null,
    ) {}

    /**
     * @param  array<int|string, mixed>  $arguments
     * @param  array<int|string, mixed>  $commandOptions
     * @param  array<string, mixed>  $transportOptions
     */
    public function runInternal(
        Node $node,
        string $commandName,
        array $arguments = [],
        array $commandOptions = [],
        array $transportOptions = [],
    ): RemoteShellResult {
        $this->nodes[] = $node->name;
        $this->commands[] = $commandName;
        $this->transportOptions[] = $transportOptions;

        if ($this->throwable instanceof RuntimeException) {
            throw $this->throwable;
        }

        return array_shift($this->results) ?? self::result();
    }

    public static function result(
        int $exitCode = 0,
        string $stdout = '',
        string $stderr = '',
        int $durationMs = 1,
    ): RemoteShellResult {
        return new RemoteShellResult(
            exitCode: 0,
            stdout: json_encode(JsonEnvelope::success([
                'exit_code' => $exitCode,
                'stdout' => $stdout,
                'stderr' => $stderr,
                'duration_ms' => $durationMs,
            ]), JSON_THROW_ON_ERROR),
            stderr: '',
            durationMs: $durationMs,
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function payloads(): array
    {
        return array_map(
            static function (array $options): array {
                /** @var mixed $payload */
                $payload = json_decode(
                    (string) ($options['input'] ?? ''),
                    associative: true,
                    flags: JSON_THROW_ON_ERROR,
                );

                if (! is_array($payload)) {
                    return [];
                }

                /** @var array<string, mixed> $payload */
                return $payload;
            },
            $this->transportOptions,
        );
    }
}
