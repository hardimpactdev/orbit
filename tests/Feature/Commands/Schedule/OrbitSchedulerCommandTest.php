<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Contracts\StartsRemoteShellProcesses;
use App\Data\RemoteShell\RemoteShellResult;
use App\Models\App;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Models\Schedule;
use App\Models\ScheduleLock;
use App\Models\SchedulerState;
use App\Models\ScheduleRun;
use App\Services\Schedules\OrbitScheduler;
use App\Services\Schedules\OrbitSchedulerProgramRenderer;
use App\Services\Schedules\ScheduleInterval;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Process\InvokedProcess;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\FakeInvokedProcess;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Process\Process as SymfonyProcess;

uses(RefreshDatabase::class);

it('runs one scheduler daemon tick on demand', function (): void {
    createOrbitSchedulerGatewayNode();

    $this->artisan('orbit-scheduler --once')
        ->expectsOutputToContain('Orbit Scheduler tick completed')
        ->assertSuccessful();
});

it('starts the scheduler as the gateway orbit-runtime default process alongside the gateway HTTP server', function (): void {
    $root = sys_get_temp_dir().'/orbit-scheduler-runtime-'.bin2hex(random_bytes(6));
    $source = "{$root}/source";
    $bin = "{$root}/bin";
    $capture = "{$root}/capture";
    $sleepCapture = "{$root}/sleep-capture";

    mkdir($source, recursive: true);
    mkdir($bin, recursive: true);

    file_put_contents("{$source}/artisan", "<?php\n");
    file_put_contents("{$bin}/php", <<<'BASH'
#!/usr/bin/env bash
printf 'argv=%s\n' "$*" >> "$PHP_CAPTURE"

for arg do
    case "$arg" in
        serve)
            exit 0
            ;;
    esac
done

exit 42
BASH);
    file_put_contents("{$bin}/sleep", <<<'BASH'
#!/usr/bin/env bash
printf 'sleep argv=%s\n' "$*" > "$SLEEP_CAPTURE"
exit 0
BASH);
    chmod("{$bin}/php", 0755);
    chmod("{$bin}/sleep", 0755);

    try {
        $process = new SymfonyProcess(
            ['bash', base_path('docker/orbit-runtime/entrypoint.sh')],
            null,
            [
                'ORBIT_IS_GATEWAY' => '1',
                'ORBIT_SOURCE_PATH' => $source,
                'ORBIT_SCHEDULER_SLEEP_SECONDS' => '7',
                'PATH' => $bin.':'.getenv('PATH'),
                'PHP_CAPTURE' => $capture,
                'SLEEP_CAPTURE' => $sleepCapture,
            ],
        );

        $process->run();

        expect($process->getExitCode())
            ->toBe(42, $process->getOutput().$process->getErrorOutput())
            ->and(file_get_contents($capture))
            ->toContain("argv={$source}/artisan orbit-scheduler --sleep-seconds=7")
            ->toContain('serve')
            ->toContain('--port=8080')
            ->and(file_exists($sleepCapture))->toBeFalse();
    } finally {
        (new SymfonyProcess(['rm', '-rf', $root]))->run();
    }
});

it('renders scheduler repair through orbit-runtime instead of host supervisor', function (): void {
    $node = new Node([
        'name' => 'gateway-1',
        'user' => 'orbit',
        'orbit_path' => '/home/orbit/orbit',
    ]);

    $script = (new OrbitSchedulerProgramRenderer)->installScript($node);

    expect($script)
        ->toContain('docker restart')
        ->toContain('orbit-runtime')
        ->not->toContain('supervisor')
        ->not->toContain('/etc/supervisor')
        ->not->toContain('php artisan orbit-scheduler');
});

it('dispatches due app schedules from the gateway and records run history centrally', function (): void {
    $gateway = createOrbitSchedulerGatewayNode();
    $appNode = createOrbitSchedulerAppHostNode(['name' => 'app-1']);
    $app = App::factory()->create(['name' => 'docs', 'node_id' => $appNode->id, 'path' => '/srv/docs']);
    Schedule::factory()->forApp($app)->create([
        'name' => 'laravel-scheduler',
        'schedule_key' => 'app:docs:laravel-scheduler',
        'execution_value' => 'php artisan schedule:run',
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
        ->and($remoteShell->nodes)->toBe(['app-1'])
        ->and($remoteShell->scripts)->toBe(['php artisan schedule:run'])
        ->and($remoteShell->options[0]['timeout'])->toBe(900)
        ->and($remoteShell->options[0]['cwd'])->toBe('/srv/docs')
        ->and($run->node_id)->toBe($appNode->id)
        ->and($run->schedule_key)->toBe('app:docs:laravel-scheduler')
        ->and($run->status)->toBe('completed')
        ->and($run->stdout)->toBe("ran\n")
        ->and($state->node_id)->toBe($gateway->id)
        ->and($state->heartbeat_at?->toIso8601String())->toBe('2026-05-06T12:34:00+00:00')
        ->and(ScheduleLock::query()->count())->toBe(0);
});

it('runs gateway-target schedules locally without remote shell dispatch', function (): void {
    $gateway = createOrbitSchedulerGatewayNode();
    Schedule::factory()->orbit()->create([
        'name' => 'gateway-maintenance',
        'schedule_key' => 'orbit:gateway:gateway-maintenance',
        'execution_value' => 'php artisan orbit:cleanup',
        'interval' => 'every minute',
    ]);
    $remoteShell = new OrbitSchedulerRecordingRemoteShell;
    app()->instance(RemoteShell::class, $remoteShell);
    Process::fake([
        'php artisan orbit:cleanup' => Process::result(output: "local\n"),
    ]);
    Process::preventStrayProcesses();

    $result = app(OrbitScheduler::class)->tick(CarbonImmutable::parse('2026-05-06T12:34:00Z'));

    $run = ScheduleRun::query()->firstOrFail();

    expect($result->dueSchedules)->toBe(1)
        ->and($result->executedSchedules)->toBe(1)
        ->and($remoteShell->scripts)->toBe([])
        ->and($run->node_id)->toBe($gateway->id)
        ->and($run->status)->toBe('completed')
        ->and($run->stdout)->toBe("local\n");

    Process::assertRan('php artisan orbit:cleanup');
});

it('dispatches multiple remote schedules through the remote shell pool', function (): void {
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

        Schedule::factory()->forApp($app)->create([
            'name' => 'scheduler',
            'schedule_key' => "app:{$name}:scheduler",
            'execution_value' => "echo {$name}",
            'interval' => 'every minute',
        ]);
    }

    $remoteShell = new OrbitSchedulerAsyncRemoteShell;
    app()->instance(RemoteShell::class, $remoteShell);

    $result = app(OrbitScheduler::class)->tick(CarbonImmutable::parse('2026-05-06T12:34:00Z'));

    expect($result->dueSchedules)->toBe(3)
        ->and($result->executedSchedules)->toBe(3)
        ->and($remoteShell->runCalls)->toBe(0)
        ->and($remoteShell->started)->toBe([
            ['node' => 'app-1', 'script' => 'echo one', 'cwd' => '/srv/one'],
            ['node' => 'app-2', 'script' => 'echo two', 'cwd' => '/srv/two'],
            ['node' => 'app-3', 'script' => 'echo three', 'cwd' => '/srv/three'],
        ])
        ->and($remoteShell->maxActiveProcesses)->toBe(3)
        ->and(ScheduleRun::query()->where('status', 'completed')->count())->toBe(3)
        ->and(ScheduleLock::query()->count())->toBe(0);
});

it('skips schedules that are not due', function (): void {
    createOrbitSchedulerGatewayNode();

    Schedule::factory()->orbit()->create([
        'name' => 'daily-report',
        'schedule_key' => 'orbit:gateway:daily-report',
        'interval' => 'daily at 09:00',
    ]);

    $remoteShell = new OrbitSchedulerRecordingRemoteShell;
    app()->instance(RemoteShell::class, $remoteShell);

    $result = app(OrbitScheduler::class)->tick(CarbonImmutable::parse('2026-05-06T12:34:00Z'));

    expect($result->dueSchedules)->toBe(0)
        ->and($result->executedSchedules)->toBe(0)
        ->and($remoteShell->scripts)->toBe([])
        ->and(ScheduleRun::query()->count())->toBe(0);
});

it('refuses to run the scheduler daemon away from the gateway', function (): void {
    config(['orbit.is_gateway' => false]);
    createOrbitSchedulerAppHostNode(['name' => 'app-1']);

    $this->artisan('orbit-scheduler --once')
        ->expectsOutputToContain('Orbit Scheduler can only run on the gateway.')
        ->assertFailed();
});

it('records remote dispatch failures as failed gateway history', function (): void {
    createOrbitSchedulerGatewayNode();
    $appNode = createOrbitSchedulerAppHostNode(['name' => 'app-1']);
    $app = App::factory()->create(['name' => 'docs', 'node_id' => $appNode->id]);
    Schedule::factory()->forApp($app)->create([
        'name' => 'laravel-scheduler',
        'schedule_key' => 'app:docs:laravel-scheduler',
        'interval' => 'every minute',
    ]);
    app()->instance(RemoteShell::class, new OrbitSchedulerRecordingRemoteShell(throwable: new RuntimeException('ssh timeout')));

    $result = app(OrbitScheduler::class)->tick(CarbonImmutable::parse('2026-05-06T12:34:00Z'));
    $run = ScheduleRun::query()->firstOrFail();

    expect($result->dueSchedules)->toBe(1)
        ->and($result->executedSchedules)->toBe(1)
        ->and($run->node_id)->toBe($appNode->id)
        ->and($run->status)->toBe('failed')
        ->and($run->exit_code)->toBe(1)
        ->and($run->stderr)->toBe('ssh timeout')
        ->and(ScheduleLock::query()->count())->toBe(0);
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

function createOrbitSchedulerGatewayNode(array $attributes = []): Node
{
    $node = Node::factory()->create([
        'name' => 'gateway-1',
        'role' => 'gateway',
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
        'role' => 'app',
        'status' => 'active',
        ...$attributes,
    ]);

    NodeRoleAssignment::factory()->create([
        'node_id' => $node->id,
        'role' => 'app-development',
        'status' => 'active',
        'settings' => ['tld' => 'test'],
    ]);

    return $node;
}

final class OrbitSchedulerRecordingRemoteShell implements RemoteShell
{
    /**
     * @var list<string>
     */
    public array $nodes = [];

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
        private ?RuntimeException $throwable = null,
    ) {}

    /**
     * @param  array<string, mixed>  $options
     */
    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        if ($this->throwable instanceof RuntimeException) {
            throw $this->throwable;
        }

        $this->nodes[] = $node->name;
        $this->scripts[] = $script;
        $this->options[] = $options;

        return array_shift($this->results) ?? new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1);
    }
}

final class OrbitSchedulerAsyncRemoteShell implements RemoteShell, StartsRemoteShellProcesses
{
    public int $runCalls = 0;

    public int $activeProcesses = 0;

    public int $maxActiveProcesses = 0;

    /**
     * @var list<array{node: string, script: string, cwd: string|null}>
     */
    public array $started = [];

    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        $this->runCalls++;

        throw new RuntimeException('Synchronous remote shell should not be used for pooled scheduler dispatch.');
    }

    public function start(Node $node, string $script, array $options = []): InvokedProcess
    {
        $this->started[] = [
            'node' => $node->name,
            'script' => $script,
            'cwd' => is_string($options['cwd'] ?? null) ? $options['cwd'] : null,
        ];
        $this->activeProcesses++;
        $this->maxActiveProcesses = max($this->maxActiveProcesses, $this->activeProcesses);

        return new OrbitSchedulerTrackingInvokedProcess(
            new FakeInvokedProcess(
                command: $script,
                process: Process::describe()
                    ->output("ran {$node->name}")
                    ->exitCode(0),
            ),
            function (): void {
                $this->activeProcesses--;
            },
        );
    }
}

final class OrbitSchedulerTrackingInvokedProcess implements InvokedProcess
{
    private bool $finished = false;

    public function __construct(
        private readonly InvokedProcess $process,
        private readonly Closure $onFinished,
    ) {}

    public function id(): ?int
    {
        return $this->process->id();
    }

    public function command(): string
    {
        return $this->process->command();
    }

    public function signal(int $signal): static
    {
        $this->process->signal($signal);

        return $this;
    }

    public function running(): bool
    {
        return $this->process->running();
    }

    public function output(): string
    {
        return $this->process->output();
    }

    public function errorOutput(): string
    {
        return $this->process->errorOutput();
    }

    public function latestOutput(): string
    {
        return $this->process->latestOutput();
    }

    public function latestErrorOutput(): string
    {
        return $this->process->latestErrorOutput();
    }

    public function wait(?callable $output = null): ProcessResult
    {
        $result = $this->process->wait($output);

        $this->markFinished();

        return $result;
    }

    public function waitUntil(?callable $output = null): ProcessResult
    {
        $result = $this->process->waitUntil($output);

        $this->markFinished();

        return $result;
    }

    private function markFinished(): void
    {
        if ($this->finished) {
            return;
        }

        ($this->onFinished)();
        $this->finished = true;
    }
}
