<?php

declare(strict_types=1);

use App\Console\Scheduling\GatewaySchedule;
use App\Domain\DatabaseConnections\DatabaseDriver;
use App\Domain\Extensions\ExtensionStore;
use App\Domain\Gateway\GatewayQueue;
use App\Domain\Operations\OperationStatus;
use App\Domain\Processes\DesiredProcessState;
use App\Domain\Processes\ProcessRuntime;
use App\Domain\Processes\ProcessRuntimeManager;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\TaskExtensionState;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\Gateway\GatewayHorizon;
use App\Infrastructure\Gateway\GatewayQueueRedis;
use App\Infrastructure\Swamp\SwampRunSubscriber;
use App\Jobs\RunScheduledCommand;
use App\Jobs\TaskVms\RefreshTaskVmBaseImages;
use App\Models\DatabaseConnection;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Process;
use Illuminate\Bus\UniqueLock;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Psr\Log\NullLogger;
use Tests\Support\FakeRoutePublicationSocket;
use Tests\Support\FakeSwampEventSocket;
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
    it('runs its commands itself, with their overlap locks, while the Gateway queue is not set', function (): void {
        config(['orbit.gateway_queue.connection' => '']);

        $vault = gateway_queue_event('orbit:vault-sync');

        expect($vault->command)->toEndWith("'artisan' orbit:vault-sync")
            ->and([$vault->withoutOverlapping, $vault->expiresAt])->toBe([true, 15]);
    });

    it('runs the Vaultwarden sync only while the vault extension is on', function (): void {
        expect(gateway_queue_event('orbit:vault-sync')->filtersPass(app()))->toBeFalse();

        app(ExtensionStore::class)->set('vault', true);

        expect(gateway_queue_event('orbit:vault-sync')->filtersPass(app()))->toBeTrue();
    });

    it('only dispatches while the Gateway queue is set, one unique job per command, and starts no queue worker', function (): void {
        config(['orbit.gateway_queue.connection' => 'services-valkey']);
        $vault = gateway_queue_event('orbit:vault-sync');

        expect($vault)->toBeInstanceOf(CallbackEvent::class)
            ->and(collect(gateway_queue_schedule())->filter(static fn (Event $event): bool => str_contains((string) $event->command, 'queue:work')))->toBeEmpty()
            ->and(collect(gateway_queue_schedule())->filter(static fn (Event $event): bool => is_string($event->command) && str_contains($event->command, 'artisan')
                && ! str_contains($event->command, 'project-documents:') && ! str_contains($event->command, 'problems:')))->toBeEmpty();

        $vault->run(app());
        $vault->run(app());

        $jobs = DB::table('jobs')->get();
        $job = unserialize(json_decode($jobs->sole()->payload, true)['data']['command']);
        expect($jobs->sole()->queue)->toBe(GatewayQueue::Tasks->value)
            ->and($job)->toBeInstanceOf(RunScheduledCommand::class)
            ->and([$job->command, $job->uniqueFor, $job->timeout, $job->connection])->toBe(['orbit:vault-sync', 900, 900, GatewayQueue::Connection]);
    });

    it('lists retained commands by name and keeps the retired PHP task tick absent', function (): void {
        config(['orbit.gateway_queue.connection' => 'services-valkey']);
        Artisan::call('schedule:list', ['--json' => true]);

        expect(collect(json_decode(Artisan::output(), true))->pluck('command'))->toContain('orbit:vault-sync')->not->toContain('tasks:tick');
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

    beforeEach(function (): void {
        app()->instance(WebSocketClient::class, $this->socket = new FakeRoutePublicationSocket);
        config(['swamp.run_token' => 'fixture-token', 'swamp.read_token' => 'fixture-read-token']);
    });

    it('records Horizon beside the scheduler and admits one native handoff without Gateway effects', function (): void {
        $result = app(GatewayHorizon::class)->converge($this->gateway, fn (): Process => $this->scheduler);
        $horizon = Process::query()->where('name', 'horizon')->sole();
        $operation = Operation::query()->findOrFail($result['operation_id']);
        expect($result['outcome'])->toBe('submitted')
            ->and($operation->kind)->toBe('process.horizon_handoff')
            ->and($operation->node_id)->toBe($this->gateway->id)
            ->and($operation->input['command'])->toBe(['/usr/bin/php8.5', 'artisan', 'horizon'])
            ->and($operation->input['content'])->not->toContain('EnvironmentFile=')
            ->and($this->socket->sent[0]['payload']['inputs'])->toEqual([
                'intent' => $operation->input, 'execution_secrets' => (object) [],
                'operation_id' => $operation->id, 'kind' => $operation->kind, 'node_id' => $operation->node_id,
            ])
            ->and($horizon->working_directory)->toBe('/home/orbit/orbit/apps/gateway')
            ->and($horizon->desired_state)->toBe(DesiredProcessState::Running)
            ->and($horizon->status)->toBe(LifecycleStatus::Provisioning)
            ->and($this->runtime->convergedProcessIds)->toBe([])
            ->and((int) Cache::get('gateway-horizon-terminated'))->toBe(0);
    });

    it('reuses an existing stopped Horizon definition without consulting the scheduler or runtime', function (): void {
        app(GatewayHorizon::class)->converge($this->gateway, fn (): Process => $this->scheduler);
        $horizon = Process::query()->where('name', 'horizon')->sole();
        $horizon->update(['desired_state' => DesiredProcessState::Stopped, 'status' => LifecycleStatus::Failed]);
        $result = app(GatewayHorizon::class)->converge($this->gateway, fn (): Process => throw new LogicException('The scheduler is not read.'));
        expect($result['outcome'])->toBe('submitted')
            ->and(Operation::query()->findOrFail($result['operation_id'])->input['created'])->toBeFalse()
            ->and(Process::query()->where('name', 'horizon')->count())->toBe(1)
            ->and($horizon->refresh()->desired_state)->toBe(DesiredProcessState::Running)
            ->and($this->runtime->convergedProcessIds)->toBe([]);
    });

    it('projects Horizon terminal results with no Horizon queue consumer', function (string $status): void {
        $admitted = app(GatewayHorizon::class)->converge($this->gateway, fn (): Process => $this->scheduler);
        $operation = Operation::query()->findOrFail($admitted['operation_id']);
        $result = $status === 'succeeded' ? ['ready' => true, 'state' => 'active', 'process_id' => $operation->target_id] : [];
        $runId = $operation->state['swamp_run_id'];
        $history = ['id' => $runId, 'workflowName' => 'operation-process-horizon-handoff', 'status' => $status,
            'inputs' => $this->socket->sent[0]['payload']['inputs'],
            'startedAt' => now()->toISOString(), 'completedAt' => now()->toISOString(),
            'jobs' => [['name' => 'horizon', 'steps' => [['name' => 'result',
                ...($status === 'succeeded' ? ['outputs' => ['stdout' => json_encode($result, JSON_THROW_ON_ERROR)]] : ['status' => 'skipped']),
            ]]]],
        ];
        $this->socket->reply = static fn (array $request): array => [['type' => 'workflow.history.get', 'id' => $request['id'], 'payload' => ['data' => $history]]];
        config(['queue.default' => 'gateway']);
        $native = new FakeSwampEventSocket;
        $subscriber = new SwampRunSubscriber($native, new NullLogger);
        $subscriber->pass();
        $requestId = $native->sent[0]['id'];
        $native->push(
            ['type' => 'run.attached', 'id' => $requestId, 'payload' => ['runId' => $runId, 'kind' => 'workflow-run', 'lastSeq' => 0]],
            ['type' => 'event', 'id' => $requestId, 'event' => ['kind' => 'completed', 'runId' => $runId, 'seq' => 1,
                'run' => ['id' => $runId, 'workflowName' => 'operation-process-horizon-handoff', 'status' => $status]]],
        );

        $subscriber->pass();
        $subscriber->close();

        expect(DB::table('jobs')->count())->toBe(0);
        expect($this->socket->sent)->toHaveCount(2)
            ->and($this->socket->sent[1]['type'])->toBe('workflow.history.get');
        $horizon = Process::query()->findOrFail($operation->target_id);
        expect($horizon->status)->toBe($status === 'succeeded' ? LifecycleStatus::Active : LifecycleStatus::Failed)
            ->and($horizon->error_code)->toBe($status === 'failed' ? 'operation.native_failed' : null)
            ->and($operation->refresh()->result)->toBe($result)
            ->and($this->runtime->convergedProcessIds)->toBe([]);
        if ($status === 'succeeded') {
            expect($horizon->systemd_activation_hash)->toBe($operation->input['content_hash'])
                ->and($horizon->systemd_activation_pending)->toBeFalse();
        }
    })->with(['succeeded', 'failed']);

    it('retains a failed Horizon intent when native submission cannot be confirmed', function (): void {
        app()->instance(WebSocketClient::class, new FakeRoutePublicationSocket(false));

        expect(fn () => app(GatewayHorizon::class)->converge($this->gateway, fn (): Process => $this->scheduler))
            ->toThrow(ResourceOperationException::class);

        $operation = Operation::query()->sole();
        $horizon = Process::query()->where('name', 'horizon')->sole();
        expect($operation->status)->toBe(OperationStatus::Failed)
            ->and($horizon->status)->toBe(LifecycleStatus::Failed)
            ->and($horizon->error_code)->toBe($operation->error_code)
            ->and($horizon->desired_state)->toBe(DesiredProcessState::Running)
            ->and($this->runtime->convergedProcessIds)->toBe([]);
    });

    it('does nothing without the Gateway queue', function (): void {
        config(['orbit.gateway_queue.connection' => '']);
        expect(app(GatewayHorizon::class)->converge($this->gateway, fn (): Process => $this->scheduler))->toBe(['outcome' => 'none'])
            ->and(Process::query()->where('name', 'horizon')->exists())->toBeFalse();
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
