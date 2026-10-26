<?php

declare(strict_types=1);

use App\Domain\AgentView\AgentViewConverger;
use App\Domain\Fleet\FleetConvergeUnits;
use App\Domain\Gateway\GatewayServiceStartHook;
use App\Domain\GatewayReleases\GatewayReleaseCleanup;
use App\Domain\GatewayReleases\GatewayReleaseException;
use App\Domain\GatewayReleases\GatewayReleaseUnitConverger;
use App\Domain\Hibernation\RuntimeHibernatorConverger;
use App\Domain\Nodes\RoleName;
use App\Domain\Processes\DesiredProcessState;
use App\Domain\Processes\ProcessRuntime;
use App\Domain\Processes\ProcessRuntimeManager;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\AgentCommands\AgentCommandRefused;
use App\Infrastructure\AgentCommands\AgentDelivery;
use App\Infrastructure\Gateway\GatewayFpmConfigRenderer;
use App\Infrastructure\Gateway\GatewayHorizon;
use App\Infrastructure\Gateway\NativeGatewayFpmConverger;
use App\Infrastructure\GatewayReleases\GatewayCleanupHandoff;
use App\Infrastructure\GatewayReleases\GatewayNodeAgentUpdate;
use App\Infrastructure\GatewayReleases\GatewayRuntimeHandoff;
use App\Infrastructure\GatewayReleases\GatewaySchedulerHandoff;
use App\Models\Node;
use App\Models\Process;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\Support\FakeAgentCommandRunner;
use Tests\Support\FakeNodeCaddyBuilds;
use Tests\Support\Fleet\FakeFleetConvergeUnits;
use Tests\Support\MonotonicTime;
use Tests\Support\ProcessesApiFakeRuntimeManager;

const HANDOFF_APP = '/home/orbit/orbit/apps/gateway';

final class FakeReleaseCleanup implements GatewayReleaseCleanup
{
    public bool $isConfigured = true;

    /** @var list<array{cleanup_state: string, cleanup_generation: string|null}> */
    public array $statuses = [];

    /** @var array{report_id?: string, report_state?: string, difference_count?: int, error_code?: string} */
    public array $report = ['report_id' => 'r1', 'report_state' => 'complete', 'difference_count' => 0];

    /** @var array{cleanup_state?: string, error_code?: string} */
    public array $resumed = ['cleanup_state' => 'running'];

    /** @var list<string> */
    public array $calls = [];

    public function configured(): bool
    {
        return $this->isConfigured;
    }

    public function status(): array
    {
        $this->calls[] = 'status';

        return count($this->statuses) > 1 ? array_shift($this->statuses) : ($this->statuses[0] ?? ['cleanup_state' => 'running', 'cleanup_generation' => 'g1']);
    }

    public function reconcile(): array
    {
        $this->calls[] = 'reconcile';

        return $this->report;
    }

    public function resume(string $reportId): array
    {
        $this->calls[] = 'resume:'.$reportId;

        return $this->resumed;
    }
}

final class RecordingHandoffUnits implements AgentViewConverger, GatewayReleaseUnitConverger, RuntimeHibernatorConverger
{
    /** @var list<string> */
    public array $converged = [];

    public function __construct(private readonly string $name) {}

    public function converge(): void
    {
        $this->converged[] = $this->name;
    }
}

function handoff_gateway(): Node
{
    $node = Node::query()->create([
        'name' => 'gateway',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.2',
        'user' => 'orbit',
        'wireguard_ip' => '10.44.0.2',
    ]);
    $node->roles()->create(['role' => RoleName::Gateway, 'status' => LifecycleStatus::Active]);

    return $node;
}

function handoff_scheduler(Node $node, string $directory = HANDOFF_APP, string $name = 'schedule-work'): Process
{
    return Process::query()->create([
        'owner_type' => Node::class,
        'owner_id' => $node->id,
        'name' => $name,
        'runtime' => ProcessRuntime::Systemd,
        'working_directory' => $directory,
        'runtime_config' => ['command' => ['/usr/bin/php8.5', 'artisan', 'schedule:work'], 'environment_file' => ''],
        'restart_policy' => 'always',
        'desired_state' => DesiredProcessState::Running,
        'status' => LifecycleStatus::Active,
    ]);
}

final class SchedulerDrainProbe
{
    public MonotonicTime $time;

    public int $cleared = 0;

    /** @var list<string> */
    public array $opcacheCalls = [];

    public bool $pending = false;

    public bool $observationFails = false;

    /** @var list<int> open pool connections, one entry per look; the last one repeats */
    public array $connections = [0];

    /** @var list<array{string, bool}> */
    public array $tickLockFree = [];

    /** @var array<string, mixed> */
    public array $drain = ['outcome' => 'drained', 'waited_ms' => 1000, 'running' => ['php artisan tasks:tick']];

    public function observeTickLock(string $step): void
    {
        $lock = Cache::lock(GatewaySchedulerHandoff::TickLock, 1);
        $free = $lock->get();
        if ($free) {
            $lock->release();
        }
        $this->tickLockFree[] = [$step, $free];
    }
}

/**
 * @return array{GatewayRuntimeHandoff, FakeAgentCommandRunner, FakeReleaseCleanup, FakeNodeCaddyBuilds, RecordingHandoffUnits, SchedulerDrainProbe}
 */
function runtime_handoff(?string $livePool = null, int $drainSeconds = 5, ?FleetConvergeUnits $fleet = null, ?FakeAgentCommandRunner $agent = null, ?GatewayHorizon $horizon = null, ?Closure $recipeImages = null, ?FakeAgentCommandRunner $hooks = null): array
{
    $probe = new SchedulerDrainProbe;
    $probe->time = new MonotonicTime;
    $schedulerAgents = new FakeAgentCommandRunner()->answer('gateway.drain-scheduler', static function () use ($probe): array {
        $probe->observeTickLock('drain');

        return $probe->drain;
    });
    foreach (['gateway.start-scheduler', 'gateway.stop-scheduler', 'gateway.restart-release-process'] as $command) {
        $schedulerAgents->answer($command, static function () use ($probe, $command): array {
            $probe->observeTickLock($command);

            return [];
        });
    }
    $cleanup = new FakeReleaseCleanup;
    $builds = new FakeNodeCaddyBuilds;
    $units = new RecordingHandoffUnits('units');
    $orbitHome = sys_get_temp_dir().'/orbit-handoff-'.bin2hex(random_bytes(4));
    $rendered = new GatewayFpmConfigRenderer()->renderPool(HANDOFF_APP, $orbitHome);

    return [
        new GatewayRuntimeHandoff(
            recipeImages: $recipeImages,
            builds: $builds,
            fpmRenderer: new GatewayFpmConfigRenderer,
            fpm: new NativeGatewayFpmConverger($hooks ?? new FakeAgentCommandRunner()->answer('gateway.converge-fpm', ['changed' => true, 'activated' => $livePool !== null]), [...app()->tagged(GatewayServiceStartHook::class)]),
            hibernator: $units,
            agentView: $units,
            releaseUnits: $units,
            scheduler: new GatewaySchedulerHandoff(
                $schedulerAgents,
                HANDOFF_APP,
                tickWaitSeconds: 1,
                drainSeconds: $drainSeconds,
                clearMutexes: static function () use ($probe): void {
                    $probe->observeTickLock('clear');
                    $probe->cleared++;
                },
            ),
            cleanup: new GatewayCleanupHandoff([$cleanup], generationWaitSeconds: 1, sleep: $probe->time->sleep(...), clock: $probe->time->now(...)),
            applicationPath: HANDOFF_APP,
            orbitHome: $orbitHome,
            resetOpcache: static function (string $script, string $query) use ($probe): string {
                $probe->opcacheCalls[] = $script.'?'.$query;

                return json_encode(['reset' => $query === '', 'before' => ['restart_pending' => $probe->pending, 'cache_full' => false]], JSON_THROW_ON_ERROR);
            },
            fpmConnections: static function () use ($probe): int {
                if ($probe->observationFails) {
                    throw new RuntimeException('Native observation refused.');
                }

                return count($probe->connections) > 1 ? array_shift($probe->connections) : $probe->connections[0];
            },
            sleep: $probe->time->sleep(...),
            clock: $probe->time->now(...),
            idleWaitSeconds: 1,
            fleet: $fleet,
            agent: $agent === null ? null : new GatewayNodeAgentUpdate($agent, app(AgentDelivery::class)),
            horizon: $horizon,
        ),
        $schedulerAgents,
        $cleanup,
        $builds,
        $units,
        $probe,
    ];
}

describe('gateway:release:handoff', function (): void {
    it('refuses malformed drain observations before forcing a stop or clearing overlap locks', function (array $drain): void {
        handoff_scheduler(handoff_gateway());
        [$handoff, $agents, , , , $probe] = runtime_handoff();
        $agents->answerMalformed('gateway.drain-scheduler', static fn (): array => $drain);

        $error = release_failure(fn () => $handoff->schedule());

        expect($error->errorCode)->toBe('gateway.release_scheduler_failed')
            ->and($agents->commands())->toBe(['gateway.drain-scheduler', 'gateway.start-scheduler'])
            ->and($probe->cleared)->toBe(0);
    })->with([
        'no outcome' => [[]],
        'negative time' => [['outcome' => 'drained', 'waited_ms' => -1, 'running' => []]],
        'invalid member' => [['outcome' => 'drained', 'waited_ms' => 0, 'running' => [4]]],
        'forced without stopped commands' => [['outcome' => 'forced', 'waited_ms' => 0, 'running' => []]],
    ]);

    it('lets the old scheduler finish its running commands, starts it on the new release, and resumes cleanup', function (): void {
        $gateway = handoff_gateway();
        $scheduler = handoff_scheduler($gateway);
        [$handoff, $schedulerAgents, $cleanup, $builds, $units, $probe] = runtime_handoff();
        $cleanup->statuses = [
            ['cleanup_state' => 'running', 'cleanup_generation' => 'g1'],
            ['cleanup_state' => 'running', 'cleanup_generation' => 'g1'],
            ['cleanup_state' => 'paused', 'cleanup_generation' => 'g2'],
        ];

        $result = $handoff->run();
        $unit = "orbit-process-{$scheduler->id}-schedule-work.service";

        expect($result)->toMatchArray([
            'caddy' => 'reloaded',
            'fpm' => 'unchanged',
            'scheduler' => 'restarted',
            'scheduler_unit' => $unit,
            'cleanup' => 'resumed',
            'agent_view' => 'restarted',
            'cleanup_paused' => false,
        ])
            ->and($result['scheduler_drain']['outcome'])->toBe('drained')
            ->and($result['scheduler_drain'])->toBe($probe->drain)
            ->and($builds->built)->toBe(['gateway'])
            ->and($units->converged)->toBe(['units', 'units', 'units'])
            ->and($schedulerAgents->calls)->toBe([
                ['node' => 'gateway', 'command' => 'gateway.drain-scheduler', 'input' => ['process_id' => $scheduler->id, 'name' => $unit, 'drain_seconds' => 5]],
                ['node' => 'gateway', 'command' => 'gateway.start-scheduler', 'input' => ['process_id' => $scheduler->id, 'name' => $unit]],
            ])
            ->and($probe->cleared)->toBe(0)
            ->and($cleanup->calls)->toContain('reconcile', 'resume:r1');
    });

    it('holds the tick lock across native stop, overlap cleanup and native start after a forced drain', function (): void {
        handoff_scheduler(handoff_gateway());
        [$handoff, $schedulerAgents, , , , $probe] = runtime_handoff(drainSeconds: 0);
        $probe->drain = ['outcome' => 'forced', 'waited_ms' => 0, 'running' => ['php artisan tasks:tick'], 'stopped' => ['php artisan tasks:tick']];

        $result = $handoff->run();

        expect($result['scheduler_drain'])->toBe($probe->drain)
            ->and($schedulerAgents->commands())->toBe(['gateway.drain-scheduler', 'gateway.stop-scheduler', 'gateway.start-scheduler'])
            ->and($probe->tickLockFree)->toBe([['drain', true], ['gateway.stop-scheduler', false], ['clear', false], ['gateway.start-scheduler', false]])
            ->and($probe->cleared)->toBe(1)
            ->and(Cache::lock(GatewaySchedulerHandoff::TickLock, 1)->get())->toBeTrue();
    });

    it('preserves the native no-pcntl reason and takes the forced path', function (): void {
        handoff_scheduler(handoff_gateway());
        [$handoff, $schedulerAgents, , , , $probe] = runtime_handoff();
        $probe->drain = ['outcome' => 'forced', 'waited_ms' => 0, 'running' => [], 'stopped' => [], 'reason' => 'no_pcntl'];

        $result = $handoff->run();

        expect($result['scheduler_drain'])->toBe($probe->drain)
            ->and($schedulerAgents->commands())->toContain('gateway.stop-scheduler')
            ->and($probe->cleared)->toBe(1);
    });

    it('starts a scheduler reported as not running without clearing overlap locks', function (): void {
        handoff_scheduler(handoff_gateway());
        [$handoff, $schedulerAgents, , , , $probe] = runtime_handoff();
        $probe->drain = ['outcome' => 'not_running', 'waited_ms' => 0, 'running' => []];

        $result = $handoff->run();

        expect($result['scheduler_drain'])->toBe($probe->drain)
            ->and($schedulerAgents->commands())->toBe(['gateway.drain-scheduler', 'gateway.start-scheduler'])
            ->and($probe->cleared)->toBe(0);
    });

    it('resets OPcache once no pool serves a request, confirms the restart is done, and defers it otherwise', function (): void {
        handoff_scheduler(handoff_gateway());
        [$handoff, , , , , $probe] = runtime_handoff();
        $probe->connections = [2, 1, 0];

        $reset = $handoff->run();
        [$busyPool, , , , , $busyProbe] = runtime_handoff();
        $busyProbe->connections = [1];
        $busy = $busyPool->run();

        expect($reset['opcache'])->toMatchArray(['outcome' => 'reset', 'cache_full' => false])
            ->and($probe->opcacheCalls)->toHaveCount(2)
            ->and($probe->opcacheCalls[0])->toEndWith('/resources/fpm/opcache-reset.php?')
            ->and($probe->opcacheCalls[1])->toEndWith('/resources/fpm/opcache-reset.php?status=1')
            ->and($busy['opcache'])->toBe(['outcome' => 'deferred'])
            ->and($busyProbe->opcacheCalls)->toBe([])
            ->and($busyProbe->time->now())->toBeGreaterThanOrEqual(1_000_000_000);
    });

    it('leaves the OPcache reset, which may wait for idle pools, out of the serving phase that verify waits for', function (): void {
        handoff_scheduler(handoff_gateway());
        [$handoff, , , , , $probe] = runtime_handoff();

        $serve = $handoff->serve();
        $calledBeforeVerify = $probe->opcacheCalls;
        $schedule = $handoff->schedule();

        expect($serve)->not->toHaveKey('opcache')
            ->and($calledBeforeVerify)->toBe([])
            ->and($schedule['opcache']['outcome'])->toBe('reset');
    });

    it('reports a reset whose restart stays pending', function (): void {
        handoff_scheduler(handoff_gateway());
        [$handoff, , , , , $probe] = runtime_handoff();
        $probe->pending = true;

        expect($handoff->run()['opcache']['outcome'])->toBe('pending');
    });

    it('installs the fleet rollout units while it hands over what serves, and only warns when that fails', function (): void {
        handoff_scheduler(handoff_gateway());
        $fleet = new FakeFleetConvergeUnits;
        [$handoff] = runtime_handoff(fleet: $fleet);

        $handoff->serve();

        expect($fleet->converged)->toBe(1);

        $failing = new class implements FleetConvergeUnits
        {
            public function converge(): void
            {
                throw new RuntimeException('sudo refused');
            }

            public function start(): bool
            {
                return false;
            }

            public function startLater(): bool
            {
                return false;
            }
        };
        Log::spy();
        [$handoff] = runtime_handoff(fleet: $failing);

        expect($handoff->serve()['agent_view'])->toBe('restarted');
        Log::shouldHaveReceived('warning')->withArgs(static fn (string $message): bool => str_contains($message, 'fleet rollout units'))->once();
    });

    it('moves the Gateway Node agent to the pin after verify, in the scheduler phase, through the agent installer', function (): void {
        $gateway = handoff_gateway();
        $gateway->forceFill(['architecture' => 'x86_64'])->save();
        handoff_scheduler($gateway);
        $runner = (new FakeAgentCommandRunner)->installReplaces();
        [$handoff] = runtime_handoff(agent: $runner);

        $serve = $handoff->serve();
        $beforeVerify = $runner->installs;
        $schedule = $handoff->schedule();

        expect($serve)->not->toHaveKey('gateway_agent')
            ->and($beforeVerify)->toBe([])
            ->and($runner->installs)->toBe([$gateway->name])
            ->and($schedule['gateway_agent'])->toBe(['outcome' => 'updated', 'version' => app(AgentDelivery::class)->reportedVersion($gateway->fresh() ?? $gateway)])
            ->and($schedule['scheduler'])->toBe('restarted');
    });

    it('records a failed Gateway Node agent update and still completes the scheduler phase', function (): void {
        $gateway = handoff_gateway();
        $gateway->forceFill(['architecture' => 'x86_64'])->save();
        handoff_scheduler($gateway);
        $runner = (new FakeAgentCommandRunner)->failInstall(new AgentCommandRefused('agent.install', 'agent.install_failed', 'The observer did not stay active.', ['cause' => 'agent.health_check_failed']));
        [$handoff] = runtime_handoff(agent: $runner);

        $schedule = $handoff->schedule();

        expect($schedule['gateway_agent'])->toMatchArray(['outcome' => 'failed', 'error_code' => 'agent.health_check_failed'])
            ->and($schedule['scheduler'])->toBe('restarted')
            ->and($schedule['opcache']['outcome'])->toBe('reset');
    });

    it('moves Horizon in the serving phase, right after the switch', function (): void {
        config(['orbit.gateway_queue.connection' => 'services-valkey']);
        $gateway = handoff_gateway();
        handoff_scheduler($gateway);
        $horizon = handoff_scheduler($gateway, name: 'horizon');
        $horizon->update(['runtime_config' => ['command' => ['/usr/bin/php8.5', 'artisan', 'horizon'], 'environment_file' => '']]);
        $runtime = new ProcessesApiFakeRuntimeManager;
        $runtime->statusOverride = 'active';
        app()->instance(ProcessRuntimeManager::class, $runtime);
        Artisan::command('horizon:terminate', function (): void {
            Cache::increment('handoff-horizon-terminated');
        });
        [$handoff, $schedulerAgents] = runtime_handoff(horizon: app(GatewayHorizon::class));

        $serve = $handoff->serve();
        $schedule = $handoff->schedule();

        expect($serve['horizon'])->toBe('terminated')
            ->and(Cache::get('handoff-horizon-terminated'))->toBe(1)
            ->and($schedule)->not->toHaveKey('horizon')
            ->and($schedule)->toMatchArray(['scheduler' => 'restarted'])
            ->and($schedule['processes_restarted'])->toBe([])
            ->and($runtime->convergedProcessIds)->toBe([])
            ->and(array_column(array_column($schedulerAgents->calls, 'input'), 'process_id'))->not->toContain($horizon->id)
            ->and(DB::table('jobs')->pluck('queue')->all())->toBe([]);
    });

    it('fails the serving phase when Horizon cannot move, so the release switches back', function (): void {
        config(['orbit.gateway_queue.connection' => 'services-valkey']);
        $gateway = handoff_gateway();
        handoff_scheduler($gateway);
        handoff_scheduler($gateway, name: 'horizon')->update(['runtime_config' => ['command' => ['/usr/bin/php8.5', 'artisan', 'horizon'], 'environment_file' => '']]);
        $runtime = new ProcessesApiFakeRuntimeManager;
        $runtime->statusOverride = 'active';
        app()->instance(ProcessRuntimeManager::class, $runtime);
        Artisan::command('horizon:terminate', function (): never {
            throw new RuntimeException('Redis is down.');
        });
        [$handoff] = runtime_handoff(horizon: app(GatewayHorizon::class));

        expect(fn () => $handoff->serve())
            ->toThrow(fn (GatewayReleaseException $exception) => expect([$exception->errorCode, $exception->getMessage()])->toBe(['gateway.release_units_failed', 'Runtime handoff step [horizon] failed: Redis is down.']));
    });

    it('does not reset OPcache or fail the release when native connection observation fails', function (): void {
        handoff_scheduler(handoff_gateway());
        [$handoff, , , , , $probe] = runtime_handoff();
        $probe->observationFails = true;

        expect($handoff->schedule()['opcache'])->toBe(['outcome' => 'failed'])
            ->and($probe->opcacheCalls)->toBe([]);
    });

    it('sends rendered pool and hooks and reports native activation', function (bool $activated): void {
        handoff_gateway();
        $agents = new FakeAgentCommandRunner()->answer('gateway.converge-fpm', ['changed' => $activated, 'activated' => $activated]);
        [$handoff] = runtime_handoff(hooks: $agents);

        $result = $handoff->serve();

        expect($result['fpm'])->toBe($activated ? 'reloaded' : 'unchanged')
            ->and($agents->calls[0]['input']['pool'])->toContain('[orbit-gateway]')
            ->and($agents->calls[0]['input']['hooks'][0]['command'])->toBe('documents.prepare-cleanup');
    })->with([false, true]);

    it('fails when the Gateway scheduler Process is missing, or runs outside the Gateway application path', function (): void {
        $gateway = handoff_gateway();
        [$handoff, $schedulerAgents] = runtime_handoff();

        $missing = release_failure(fn () => $handoff->run());
        handoff_scheduler($gateway, directory: '/srv/other');
        $mismatch = release_failure(fn () => $handoff->run());

        expect($missing->errorCode)->toBe('gateway.release_scheduler_missing')
            ->and($mismatch->errorCode)->toBe('gateway.release_scheduler_mismatch')
            ->and($mismatch->getMessage())->toContain('/srv/other')
            ->and($schedulerAgents->calls)->toBe([]);
    });

    it('restarts other Gateway Node Processes that run from the Gateway application', function (): void {
        $gateway = handoff_gateway();
        $scheduler = handoff_scheduler($gateway);
        $worker = handoff_scheduler($gateway, directory: '/home/orbit/orbit/apps/cli', name: 'cli-worker');
        $worker->update(['runtime_config' => ['command' => ['/usr/bin/php8.5', 'orbit', 'watch'], 'environment_file' => '']]);
        handoff_scheduler($gateway, directory: '/srv/elsewhere', name: 'unrelated')->update(['runtime_config' => ['command' => ['sleep', 'infinity'], 'environment_file' => '']]);
        [$handoff, $schedulerAgents] = runtime_handoff();

        $result = $handoff->run();

        expect($result['processes_restarted'])->toBe(["orbit-process-{$worker->id}-cli-worker.service"])
            ->and($schedulerAgents->calls[2])->toBe(['node' => 'gateway', 'command' => 'gateway.restart-release-process', 'input' => ['process_id' => $worker->id, 'name' => "orbit-process-{$worker->id}-cli-worker.service"]])
            ->and($result['scheduler_unit'])->toBe("orbit-process-{$scheduler->id}-schedule-work.service");
    });

    it('starts the scheduler again when the forced stop fails', function (): void {
        $scheduler = handoff_scheduler(handoff_gateway());
        [$handoff, $schedulerAgents, , , , $probe] = runtime_handoff(drainSeconds: 0);
        $probe->drain = ['outcome' => 'forced', 'waited_ms' => 0, 'running' => [], 'stopped' => []];
        $unit = "orbit-process-{$scheduler->id}-schedule-work.service";
        $schedulerAgents->refuse('gateway.stop-scheduler', 'gateway.release_scheduler_failed', ['stage' => 'stop']);

        expect(release_failure(fn () => $handoff->run())->errorCode)->toBe('gateway.release_scheduler_failed')
            ->and(array_slice($schedulerAgents->commands(), -1))->toBe(['gateway.start-scheduler']);
    });

    it('refuses to stop the scheduler after the drain limit while a tasks tick holds its lock', function (): void {
        $scheduler = handoff_scheduler(handoff_gateway());
        [$handoff, $schedulerAgents, , , , $probe] = runtime_handoff(drainSeconds: 0);
        $probe->drain = ['outcome' => 'forced', 'waited_ms' => 0, 'running' => [], 'stopped' => []];
        $tick = Cache::lock(GatewaySchedulerHandoff::TickLock, 300);
        expect($tick->get())->toBeTrue();

        $exception = release_failure(fn () => $handoff->run());
        $tick->release();

        expect($exception->errorCode)->toBe('gateway.release_scheduler_busy')
            ->and($exception->step)->toBe('handoff')
            ->and($schedulerAgents->commands())->not->toContain('gateway.stop-scheduler');
    });

    it('fails the handoff when the scheduler unit does not come back', function (): void {
        $scheduler = handoff_scheduler(handoff_gateway());
        [$handoff, $schedulerAgents] = runtime_handoff();
        $unit = "orbit-process-{$scheduler->id}-schedule-work.service";
        $schedulerAgents->refuse('gateway.start-scheduler', 'gateway.release_scheduler_failed', ['stage' => 'start']);

        $exception = release_failure(fn () => $handoff->run());

        expect($exception->errorCode)->toBe('gateway.release_scheduler_failed')
            ->and($schedulerAgents->commands())->toBe(['gateway.drain-scheduler', 'gateway.start-scheduler', 'gateway.start-scheduler'])
            ->and(Cache::lock(GatewaySchedulerHandoff::TickLock, 1)->get())->toBeTrue();
    });

    it('fails the handoff with a release error when Caddy cannot publish', function (): void {
        handoff_gateway();
        [$handoff, , , $builds] = runtime_handoff();
        $builds->failNext('gateway', 'reload');

        expect(release_failure(fn () => $handoff->run())->errorCode)->toBe('gateway.release_caddy_failed');
    });

    it('fails without a Gateway Node', function (): void {
        [$handoff] = runtime_handoff();

        expect(release_failure(fn () => $handoff->run())->errorCode)->toBe('gateway.release_gateway_node_missing');
    });
});

describe(GatewayCleanupHandoff::class, function (): void {
    it('skips when no extension contributes a cleanup', function (): void {
        $handoff = new GatewayCleanupHandoff([]);

        expect($handoff->generation())->toBe([])
            ->and($handoff->resume($handoff->generation(), true))->toBe(['outcome' => 'skipped', 'paused' => false]);
    });

    it('reports a paused cleanup before a resumed one, and resumes each cleanup in its own generation', function (): void {
        $paused = new FakeReleaseCleanup;
        $paused->statuses = [['cleanup_state' => 'paused', 'cleanup_generation' => 'a2']];
        $paused->report = ['report_id' => 'ra', 'report_state' => 'complete', 'difference_count' => 1];
        $resumed = new FakeReleaseCleanup;
        $resumed->statuses = [['cleanup_state' => 'paused', 'cleanup_generation' => 'b2']];

        expect(new GatewayCleanupHandoff([$resumed, $paused], sleep: static function (): void {})->resume(['b1', 'a1'], true))->toBe([
            'outcome' => 'paused',
            'paused' => true,
            'error_code' => 'gateway.release_cleanup_unresolved',
            'report_id' => 'ra',
            'difference_count' => 1,
        ])->and($resumed->calls)->toContain('resume:r1')
            ->and($paused->calls)->not->toContain('resume:ra');
    });

    it('skips cleanup when document storage is not configured', function (): void {
        $cleanup = new FakeReleaseCleanup;
        $cleanup->isConfigured = false;

        expect(new GatewayCleanupHandoff([$cleanup])->resume([null], true))->toBe(['outcome' => 'skipped', 'paused' => false])
            ->and($cleanup->calls)->toBe([]);
    });

    it('stays paused and reports why when the report has differences', function (): void {
        $cleanup = new FakeReleaseCleanup;
        $cleanup->statuses = [['cleanup_state' => 'paused', 'cleanup_generation' => 'g2']];
        $cleanup->report = ['report_id' => 'r2', 'report_state' => 'complete', 'difference_count' => 2];

        expect(new GatewayCleanupHandoff([$cleanup], sleep: static function (): void {})->resume(['g1'], true))->toBe([
            'outcome' => 'paused',
            'paused' => true,
            'error_code' => 'gateway.release_cleanup_unresolved',
            'report_id' => 'r2',
            'difference_count' => 2,
        ])->and($cleanup->calls)->not->toContain('resume:r2');
    });

    it('stays paused when the new scheduler never rotates the generation', function (): void {
        $cleanup = new FakeReleaseCleanup;
        $cleanup->statuses = [['cleanup_state' => 'paused', 'cleanup_generation' => 'g1']];

        expect(new GatewayCleanupHandoff([$cleanup], generationWaitSeconds: 0, sleep: static function (): void {})->resume(['g1'], true))
            ->toBe(['outcome' => 'paused', 'paused' => true, 'error_code' => 'gateway.release_cleanup_generation_unchanged']);
    });

    it('reconciles and resumes a cleanup that is paused without a restart', function (): void {
        $cleanup = new FakeReleaseCleanup;
        $cleanup->statuses = [['cleanup_state' => 'paused', 'cleanup_generation' => 'g1']];

        expect(new GatewayCleanupHandoff([$cleanup])->resume(['g1'], false))->toBe(['outcome' => 'resumed', 'paused' => false, 'report_id' => 'r1']);
    });

    it('stays paused when resume is refused', function (): void {
        $cleanup = new FakeReleaseCleanup;
        $cleanup->statuses = [['cleanup_state' => 'paused', 'cleanup_generation' => 'g1']];
        $cleanup->resumed = ['cleanup_state' => 'paused', 'error_code' => 'project_documents.cleanup_inventory_changed'];

        expect(new GatewayCleanupHandoff([$cleanup])->resume(['g1'], false))
            ->toBe(['outcome' => 'paused', 'paused' => true, 'error_code' => 'project_documents.cleanup_inventory_changed', 'report_id' => 'r1']);
    });
});

it('queues recipe images during release handoff and leaves dispatch recovery to the scheduler on failure', function (): void {
    handoff_scheduler(handoff_gateway());
    $calls = 0;
    [$handoff] = runtime_handoff(recipeImages: function () use (&$calls): void {
        $calls++;
        throw new RuntimeException('queue unavailable');
    });
    $handoff->schedule();
    expect($calls)->toBe(1);
});
