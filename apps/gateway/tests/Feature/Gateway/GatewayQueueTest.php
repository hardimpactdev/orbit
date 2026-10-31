<?php

declare(strict_types=1);

use App\Console\Scheduling\GatewaySchedule;
use App\Domain\DatabaseConnections\DatabaseDriver;
use App\Domain\Extensions\ExtensionStore;
use App\Domain\Gateway\GatewayQueue;
use App\Domain\Processes\DesiredProcessState;
use App\Domain\Processes\ProcessOperationException;
use App\Domain\Processes\ProcessRuntime;
use App\Domain\Processes\ProcessRuntimeManager;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tasks\TaskExtensionState;
use App\Infrastructure\Gateway\GatewayHorizon;
use App\Infrastructure\Gateway\GatewayQueueRedis;
use App\Jobs\RunScheduledCommand;
use App\Jobs\TaskVms\RefreshTaskVmBaseImages;
use App\Models\DatabaseConnection;
use App\Models\Node;
use App\Models\Process;
use Illuminate\Bus\UniqueLock;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Tests\Support\ProcessesApiFakeRuntimeManager;

/** @return list<Event> */
function gateway_queue_schedule(): array
{
    Artisan::call('schedule:list');

    return app(Schedule::class)->events();
}

function gateway_queue_event(string $name): Event
{
    return collect(gateway_queue_schedule())->first(static fn (Event $event): bool => $event->description === $name || str_ends_with((string) $event->command, $name))
        ?? throw new RuntimeException("No scheduled event [{$name}].");
}

beforeEach(function (): void {
    app(TaskExtensionState::class)->enable();
});

describe('the schedule', function (): void {

    it('runs the Vaultwarden sync only while the vault extension is on', function (): void {
        expect(gateway_queue_event('orbit:vault-sync')->filtersPass(app()))->toBeFalse();

        app(ExtensionStore::class)->set('vault', true);

        expect(gateway_queue_event('orbit:vault-sync')->filtersPass(app()))->toBeTrue();
    });

    it('frees the unique lock and throws when the queue cannot take the job, so the next run dispatches it again', function (): void {
        $job = new RunScheduledCommand('tasks:tick', 330);
        Queue::shouldReceive('connection')->once()->andThrow(new RuntimeException('Connection refused'));

        expect(fn () => GatewaySchedule::dispatch($job))->toThrow(RuntimeException::class, 'Connection refused')
            ->and(new UniqueLock(app(Repository::class))->acquire($job))->toBeTrue();
    });

    it('queues the task VM jobs on their own connection and queue', function (): void {
        config(['orbit.gateway_queue.connection' => 'services-valkey', 'task_vms.enabled' => true]);

        gateway_queue_event(RefreshTaskVmBaseImages::class)->run(app());

        expect(DB::table('jobs')->sole()->queue)->toBe(GatewayQueue::Builds->value);
    });

    it('fails loudly instead of skipping task VM work while task VMs are on and the Gateway queue is not set', function (): void {
        config(['orbit.gateway_queue.connection' => '', 'task_vms.enabled' => true]);
        $refresh = gateway_queue_event(RefreshTaskVmBaseImages::class);

        expect($refresh->filtersPass(app()))->toBeTrue()
            ->and(fn () => $refresh->run(app()))->toThrow(RuntimeException::class, 'ORBIT_GATEWAY_QUEUE_CONNECTION is empty')
            ->and(DB::table('jobs')->count())->toBe(0);
    });
});

describe(RunScheduledCommand::class, function (): void {
    it('runs the command in its own process and logs a failure', function (): void {
        Log::spy();

        new RunScheduledCommand('orbit:no-such-command', 60)->handle();

        Log::shouldHaveReceived('error')->once()->with('A scheduled command on the Gateway queue failed.', Mockery::on(
            static fn (array $context): bool => $context['command'] === 'orbit:no-such-command' && $context['exit_code'] !== 0,
        ));
    });
});

describe(GatewayQueueRedis::class, function (): void {
    it('reads host, port, and password from the registered Redis connection, with its own database index and prefix', function (): void {
        DatabaseConnection::query()->create(['slug' => 'services-valkey', 'driver' => DatabaseDriver::Redis, 'host' => '10.44.0.3', 'port' => 6380, 'password' => 'secret']);
        config(['orbit.gateway_queue.connection' => 'services-valkey', 'orbit.gateway_queue.redis_database' => 4]);

        GatewayQueueRedis::configure();

        expect(config('database.redis.gateway'))->toMatchArray(['host' => '10.44.0.3', 'port' => 6380, 'password' => 'secret', 'database' => 4, 'options' => ['prefix' => 'orbit-gateway:']])
            ->and(config('database.redis.horizon.host'))->toBe('10.44.0.3')
            ->and(config('database.redis.horizon.options.prefix'))->toBe('orbit-gateway-horizon:');
    });

    it('fails loudly without a Redis connection', function (string $slug): void {
        DatabaseConnection::query()->create(['slug' => 'main-db', 'driver' => DatabaseDriver::Mysql, 'host' => '10.44.0.3', 'port' => 3306]);
        config(['orbit.gateway_queue.connection' => $slug]);

        expect(GatewayQueueRedis::configure(...))->toThrow(RuntimeException::class);
    })->with(['empty' => '', 'missing' => 'nope', 'not redis' => 'main-db']);
});

describe('Horizon', function (): void {
    it('works tasks with three processes, builds with two and task-vms with one, below the connection retry_after', function (): void {
        expect(config('horizon.use'))->toBe(GatewayQueue::Connection)
            ->and(config('horizon.fast_termination'))->toBeFalse()
            ->and([config('horizon.defaults.tasks.queue'), config('horizon.defaults.tasks.minProcesses'), config('horizon.defaults.tasks.maxProcesses')])->toBe([['tasks', 'problems'], 3, 3])
            ->and(config('horizon.defaults.tasks.balance'))->toBeFalse()
            ->and([config('horizon.defaults.builds.queue'), config('horizon.defaults.builds.minProcesses'), config('horizon.defaults.builds.maxProcesses')])->toBe([['builds', 'builds-optional'], 2, 2])
            ->and(config('horizon.waits.gateway:builds-optional'))->toBe(3600)
            ->and([config('horizon.defaults.task-vms.queue'), config('horizon.defaults.task-vms.minProcesses'), config('horizon.defaults.task-vms.maxProcesses')])->toBe([['task-vms'], 1, 1])
            ->and(config('horizon.defaults.builds.timeout'))->toBeLessThan(config('queue.connections.gateway.retry_after'))
            ->and(array_keys(config('horizon.environments')))->toBe(['*']);
    });

    it('shows the New Dawn dashboard only to a WireGuard peer with Gateway access', function (): void {
        $gateway = Node::query()->create(['name' => 'gateway', 'status' => LifecycleStatus::Active, 'platform' => 'linux', 'public_ssh_host' => '192.0.2.2', 'wireguard_ip' => '10.44.0.2']);
        $this->markAsGateway($gateway);
        Node::query()->create(['name' => 'stranger', 'status' => LifecycleStatus::Active, 'platform' => 'linux', 'public_ssh_host' => '192.0.2.9', 'wireguard_ip' => '10.44.0.9']);

        $this->withServerVariables(['REMOTE_ADDR' => '10.44.0.2'])->get('/horizon')->assertOk()->assertSee('horizon-new-dawn', false);
        $this->withServerVariables(['REMOTE_ADDR' => '10.44.0.9'])->get('/horizon')->assertForbidden();
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.50'])->get('/horizon')->assertForbidden();
    });

    it('admits a dashboard change only from the Gateway origin, because the dashboard has no CSRF token', function (): void {
        $gateway = Node::query()->create(['name' => 'gateway', 'status' => LifecycleStatus::Active, 'platform' => 'linux', 'public_ssh_host' => '192.0.2.2', 'wireguard_ip' => '10.44.0.2']);
        $this->markAsGateway($gateway);
        // Laravel skips its request forgery check while unit tests run.
        app()->detectEnvironment(static fn (): string => 'production');
        $peer = $this->withServerVariables(['REMOTE_ADDR' => '10.44.0.2']);

        $peer->withHeaders(['Sec-Fetch-Site' => 'cross-site'])->post('/horizon/api/jobs/retry/1')->assertForbidden();
        $peer->withHeaders(['Sec-Fetch-Site' => 'same-site'])->post('/horizon/api/jobs/retry/1')->assertForbidden();
        $peer->withHeaders([])->post('/horizon/api/jobs/retry/1')->assertForbidden();
        expect($peer->withHeaders(['Sec-Fetch-Site' => 'same-origin'])->post('/horizon/api/jobs/retry/1')->status())->not->toBe(403);
    });
});

describe(GatewayHorizon::class, function (): void {
    beforeEach(function (): void {
        config(['orbit.gateway_queue.connection' => 'services-valkey']);
        $this->gateway = $this->markAsGateway(Node::query()->create(['name' => 'gateway', 'status' => LifecycleStatus::Active, 'platform' => 'linux', 'public_ssh_host' => '192.0.2.2', 'wireguard_ip' => '10.44.0.2', 'user' => 'orbit']));
        $this->scheduler = Process::query()->create([
            'owner_type' => Node::class,
            'owner_id' => $this->gateway->id,
            'name' => 'schedule-work',
            'runtime' => ProcessRuntime::Systemd,
            'working_directory' => '/home/orbit/orbit/apps/gateway',
            'runtime_config' => ['command' => ['/usr/bin/php8.5', 'artisan', 'schedule:work'], 'environment_file' => ''],
            'restart_policy' => 'always',
            'desired_state' => DesiredProcessState::Running,
            'status' => LifecycleStatus::Active,
        ]);
        $this->runtime = new ProcessesApiFakeRuntimeManager;
        app()->instance(ProcessRuntimeManager::class, $this->runtime);
        // The command's closure is bound to the command, so it counts in the cache.
        Artisan::command('horizon:terminate', function (): void {
            Cache::increment('gateway-horizon-terminated');
        });
    });

    it('creates and starts a horizon Process beside the scheduler when the Gateway has none', function (): void {
        expect(app(GatewayHorizon::class)->converge($this->gateway, fn (): Process => $this->scheduler))->toBe('created');

        $horizon = Process::query()->where('name', 'horizon')->sole();
        expect($horizon->runtime)->toBe(ProcessRuntime::Systemd)
            ->and($horizon->runtime_config['command'])->toBe(['/usr/bin/php8.5', 'artisan', 'horizon'])
            ->and($horizon->working_directory)->toBe('/home/orbit/orbit/apps/gateway')
            ->and($horizon->restart_policy)->toBe('always')
            ->and($horizon->desired_state)->toBe(DesiredProcessState::Running)
            ->and($this->runtime->convergedProcessIds)->toBe([$horizon->id])
            ->and((int) Cache::get('gateway-horizon-terminated'))->toBe(0);
    });

    it('terminates the master of an active unit and leaves the unit alone, so no restart kills a running job', function (): void {
        app(GatewayHorizon::class)->converge($this->gateway, fn (): Process => $this->scheduler);
        $this->runtime->convergedProcessIds = [];
        $this->runtime->statusOverride = 'active';

        expect(app(GatewayHorizon::class)->converge($this->gateway, fn (): Process => $this->scheduler))->toBe('terminated')
            ->and((int) Cache::get('gateway-horizon-terminated'))->toBe(1)
            ->and($this->runtime->convergedProcessIds)->toBe([])
            ->and(Process::query()->where('name', 'horizon')->count())->toBe(1);
    });

    it('starts a Horizon whose unit is not active, where horizon:terminate would reach no master', function (string $unit, DesiredProcessState $desired, LifecycleStatus $status): void {
        $horizon = Process::query()->create([
            'owner_type' => Node::class,
            'owner_id' => $this->gateway->id,
            'name' => 'horizon',
            'runtime' => ProcessRuntime::Systemd,
            'working_directory' => '/home/orbit/orbit/apps/gateway',
            'runtime_config' => ['command' => ['/usr/bin/php8.5', 'artisan', 'horizon'], 'environment_file' => ''],
            'restart_policy' => 'always',
            'desired_state' => $desired,
            'status' => $status,
            'failed_step' => $status === LifecycleStatus::Failed ? 'start' : null,
            'error_code' => $status === LifecycleStatus::Failed ? 'process.start_failed' : null,
        ]);
        $this->runtime->statusOverride = $unit;

        expect(app(GatewayHorizon::class)->converge($this->gateway, fn (): Process => throw new LogicException('The scheduler is not read.')))->toBe('started')
            ->and($this->runtime->convergedProcessIds)->toBe([$horizon->id])
            ->and((int) Cache::get('gateway-horizon-terminated'))->toBe(0)
            ->and($horizon->refresh()->only(['desired_state', 'status', 'failed_step', 'error_code']))
            ->toBe(['desired_state' => DesiredProcessState::Running, 'status' => LifecycleStatus::Active, 'failed_step' => null, 'error_code' => null]);
    })->with([
        'a failed first creation, whose unit was removed' => ['absent', DesiredProcessState::Running, LifecycleStatus::Failed],
        'a crash loop that systemd gave up on' => ['failed', DesiredProcessState::Running, LifecycleStatus::Active],
        'a crash loop between restarts' => ['activating', DesiredProcessState::Running, LifecycleStatus::Active],
        'a stop after a switch back' => ['inactive', DesiredProcessState::Stopped, LifecycleStatus::Active],
    ]);

    it('marks the Process failed and fails loudly when Horizon cannot start', function (): void {
        app(GatewayHorizon::class)->converge($this->gateway, fn (): Process => $this->scheduler);
        $this->runtime->statusOverride = 'failed';
        $this->runtime->failConverge = true;

        expect(fn () => app(GatewayHorizon::class)->converge($this->gateway, fn (): Process => $this->scheduler))
            ->toThrow(ProcessOperationException::class)
            ->and(Process::query()->where('name', 'horizon')->sole()->status)->toBe(LifecycleStatus::Failed);
    });

    it('does nothing without the Gateway queue', function (): void {
        config(['orbit.gateway_queue.connection' => '']);

        expect(app(GatewayHorizon::class)->converge($this->gateway, fn (): Process => $this->scheduler))->toBe('none')
            ->and(Process::query()->where('name', 'horizon')->exists())->toBeFalse()
            ->and((int) Cache::get('gateway-horizon-terminated'))->toBe(0);
    });
});

it('restores queued commands written before arguments were added', function (): void {
    $original = new RunScheduledCommand('tasks:tick', 330);
    unset($original->arguments);

    $restored = unserialize(serialize($original));

    expect($restored)->toBeInstanceOf(RunScheduledCommand::class)
        ->and($restored->arguments)->toBe([])
        ->and($restored->command)->toBe('tasks:tick');
});
