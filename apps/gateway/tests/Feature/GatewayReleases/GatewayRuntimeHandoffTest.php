<?php

declare(strict_types=1);

use App\Actions\Operations\StartOperationAction;
use App\Domain\AgentView\AgentViewConverger;
use App\Domain\Gateway\GatewayServiceStartHook;
use App\Domain\GatewayReleases\GatewayReleaseCleanup;
use App\Domain\GatewayReleases\GatewayReleaseException;
use App\Domain\GatewayReleases\GatewayReleaseRuntime;
use App\Domain\GatewayReleases\GatewayReleaseUnitConverger;
use App\Domain\Hibernation\RuntimeHibernatorConverger;
use App\Domain\Nodes\RoleName;
use App\Domain\Processes\DesiredProcessState;
use App\Domain\Processes\ProcessRuntime;
use App\Domain\Processes\ProcessRuntimeManager;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\AgentCommands\AgentCommandRefused;
use App\Infrastructure\AgentCommands\AgentDelivery;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\Caddy\Build\NodeCaddyfileRenderer;
use App\Infrastructure\Gateway\GatewayFpmConfigRenderer;
use App\Infrastructure\Gateway\GatewayHorizon;
use App\Infrastructure\Gateway\NativeGatewayFpmConverger;
use App\Infrastructure\GatewayReleases\GatewayCleanupHandoff;
use App\Infrastructure\GatewayReleases\GatewayNodeAgentUpdate;
use App\Infrastructure\GatewayReleases\GatewayRuntimeHandoff;
use App\Infrastructure\GatewayReleases\GatewaySchedulerHandoff;
use App\Models\GatewayRelease;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Process;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeAgentCommandRunner;
use Tests\Support\FakeRoutePublicationSocket;
use Tests\Support\GatewayReleasePipeline;
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
    $node = Node::query()->forceCreate([
        'id' => 14,
        'name' => 'gateway',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'architecture' => 'x86_64',
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
    public array $drain = ['outcome' => 'drained', 'waited_ms' => 1000, 'running' => ['php artisan problems:collect']];
}

/**
 * @return array{GatewayRuntimeHandoff, FakeAgentCommandRunner, FakeReleaseCleanup, FakeRoutePublicationSocket, RecordingHandoffUnits, SchedulerDrainProbe}
 */
function runtime_handoff(?string $livePool = null, int $drainSeconds = 5, ?FakeAgentCommandRunner $agent = null, ?GatewayHorizon $horizon = null, ?Closure $recipeImages = null, ?FakeAgentCommandRunner $hooks = null, string $caddyfilePath = '/nonexistent-fixture-Caddyfile', ?Closure $swampHealthy = null): array
{
    $probe = new SchedulerDrainProbe;
    $probe->time = new MonotonicTime;
    $schedulerAgents = new FakeAgentCommandRunner()->answer('gateway.drain-scheduler', static function () use ($probe): array {

        return $probe->drain;
    });
    foreach (['gateway.start-scheduler', 'gateway.stop-scheduler', 'gateway.restart-release-process'] as $command) {
        $schedulerAgents->answer($command, static function (): array {

            return [];
        });
    }
    $cleanup = new FakeReleaseCleanup;
    config(['swamp.run_token' => 'fixture-token']);
    $builds = new FakeRoutePublicationSocket;
    app()->instance(WebSocketClient::class, $builds);
    $units = new RecordingHandoffUnits('units');
    $orbitHome = sys_get_temp_dir().'/orbit-handoff-'.bin2hex(random_bytes(4));
    $rendered = new GatewayFpmConfigRenderer()->renderPool(HANDOFF_APP, $orbitHome);

    return [
        new GatewayRuntimeHandoff(
            recipeImages: $recipeImages,
            operations: app(StartOperationAction::class),
            fpmRenderer: new GatewayFpmConfigRenderer,
            fpm: new NativeGatewayFpmConverger($hooks ?? new FakeAgentCommandRunner()->answer('gateway.converge-fpm', ['changed' => true, 'activated' => $livePool !== null]), [...app()->tagged(GatewayServiceStartHook::class)]),
            hibernator: $units,
            agentView: $units,
            releaseUnits: $units,
            scheduler: new GatewaySchedulerHandoff(
                $schedulerAgents,
                HANDOFF_APP,
                drainSeconds: $drainSeconds,
                clearMutexes: static function () use ($probe): void {
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
            agent: $agent === null ? null : new GatewayNodeAgentUpdate($agent, app(AgentDelivery::class)),
            horizon: $horizon,
            caddyfilePath: $caddyfilePath,
            swampHealthy: $swampHealthy,
        ),
        $schedulerAgents,
        $cleanup,
        $builds,
        $units,
        $probe,
    ];
}

describe('gateway:release:handoff', function (): void {
    it('completes the real rollback command with serve unreachable when Caddy is unchanged', function (): void {
        $gateway = handoff_gateway();
        handoff_scheduler($gateway);
        $version = app(NodeCaddyfileRenderer::class)->render($gateway)->version;
        $path = sys_get_temp_dir().'/rollback-caddy-'.bin2hex(random_bytes(8));
        symlink('/etc/caddy/orbit-versions/'.$version.'/Caddyfile', $path);
        $pipeline = new GatewayReleasePipeline;
        try {
            $previous = substr($pipeline->adopt(), 0, 12);
            $pipeline->fixture->select($pipeline->fixture->prepared($pipeline->fixture->commit('New current release')));
            $pipeline->bindLeaves();
            [$handoff, , , $builds] = runtime_handoff(caddyfilePath: $path);
            $builds->onConnect = static fn () => throw new RuntimeException('Serve is unreachable.');
            app()->instance(GatewayRuntimeHandoff::class, $handoff);
            app()->instance(GatewayReleaseRuntime::class, new class implements GatewayReleaseRuntime
            {
                public function handoff(string $id): array
                {
                    throw new RuntimeException('The old retained target still requires Swamp.');
                }

                public function schedule(string $id): array
                {
                    throw new RuntimeException('The old retained target must not control rollback recovery.');
                }
            });

            $this->artisan('gateway:release:rollback', ['release' => $previous])->assertSuccessful();

            expect($pipeline->fixture->currentId())->toBe($previous)
                ->and($builds->connections)->toBe([])
                ->and(GatewayRelease::query()->latest('id')->first())
                ->toMatchArray(['outcome' => 'verified', 'trigger' => 'rollback'])
                ->and(Operation::query()->where('kind', 'route.caddy_converge')->count())->toBe(0);
        } finally {
            unlink($path);
            $pipeline->fixture->cleanup();
        }
    });

    it('skips the native publication with serve down when the rendered digest is already published', function (): void {
        $gateway = handoff_gateway();
        $version = app(NodeCaddyfileRenderer::class)->render($gateway)->version;
        $path = sys_get_temp_dir().'/handoff-caddy-'.bin2hex(random_bytes(8));
        symlink('/etc/caddy/orbit-versions/'.$version.'/Caddyfile', $path);
        try {
            [$handoff, , , $builds] = runtime_handoff(caddyfilePath: $path);
            $builds->onConnect = static fn () => throw new RuntimeException('Serve is down.');

            expect($handoff->serve())->toMatchArray(['caddy' => 'unchanged', 'caddy_operation_id' => null])
                ->and($builds->connections)->toBe([])
                ->and(Operation::query()->where('kind', 'route.caddy_converge')->count())->toBe(0);
        } finally {
            unlink($path);
        }
    });

    it('waits for configured TLS health after a connection refusal then submits once after recovery', function (): void {
        handoff_gateway();
        Http::fake(['https://swamp.orbit/health' => Http::sequence()->push(['status' => 'starting'], 503)->push(['status' => 'ok'])]);
        [$handoff, , , $builds, , $probe] = runtime_handoff();
        $builds->onConnect = static function (int $attempt): void {
            if ($attempt === 1) {
                throw new RuntimeException('Serve is down.');
            }
        };

        expect($handoff->serve()['caddy'])->toBe('submitted')
            ->and($builds->connections)->toHaveCount(2)
            ->and($probe->time->now())->toBe(5_000_000_000.0)
            ->and(Operation::query()->where('kind', 'route.caddy_converge')->pluck('status')->map->value->all())->toBe(['failed', 'running']);
        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => $request->url() === 'https://swamp.orbit/health'
            && $request->toPsrRequest()->getHeaderLine('Authorization') === '');
    });

    it('bounds the release-only health wait to 600 seconds without submitting while serve stays down', function (): void {
        handoff_gateway();
        $checks = 0;
        [$handoff, , , $builds, , $probe] = runtime_handoff(swampHealthy: static function (float $timeout) use (&$checks): bool {
            $checks++;
            expect($timeout)->toBeLessThanOrEqual(5);

            return false;
        });
        $builds->onConnect = static fn () => throw new RuntimeException('Serve is down.');

        expect(release_failure(fn () => $handoff->serve())->errorCode)->toBe('gateway.release_caddy_failed')
            ->and($probe->time->now())->toBe(600_000_000_000.0)
            ->and($checks)->toBe(120)
            ->and($builds->connections)->toHaveCount(1)
            ->and(Operation::query()->where('kind', 'route.caddy_converge')->count())->toBe(1);
    });

    it('does not repeat an uncertain admission even if health would be available', function (): void {
        handoff_gateway();
        [$handoff, , , $builds, , $probe] = runtime_handoff(swampHealthy: static fn (): never => throw new RuntimeException('Must not probe.'));
        $builds->reply = static fn (): array => [];

        expect(release_failure(fn () => $handoff->serve())->errorCode)->toBe('gateway.release_caddy_failed')
            ->and($builds->sent)->toHaveCount(1)
            ->and($probe->time->now())->toBe(0.0);
    });

    it('does not resubmit when the local run binding fails after Swamp confirms its start', function (): void {
        handoff_gateway();
        [$handoff, , , $builds, , $probe] = runtime_handoff(swampHealthy: static fn (): bool => true);
        $failed = false;
        Operation::updating(static function (Operation $operation) use (&$failed): void {
            if (! $failed && $operation->kind === 'route.caddy_converge' && $operation->isDirty('state')
                && isset($operation->state['swamp_run_id'])) {
                $failed = true;
                throw new RuntimeException('Local binding write failed after the native start.');
            }
        });

        expect(release_failure(fn () => $handoff->serve())->errorCode)->toBe('gateway.release_caddy_failed')
            ->and($failed)->toBeTrue()
            ->and($builds->sent)->toHaveCount(1)
            ->and(Operation::query()->where('kind', 'route.caddy_converge')->sole()->error_code)->toBe('swamp.start_uncertain')
            ->and($probe->time->now())->toBe(0.0);
    });

    it('fails a persistent explicit refusal after healthy serve without another recovery cycle', function (): void {
        handoff_gateway();
        [$handoff, , , $builds, , $probe] = runtime_handoff(swampHealthy: static fn (): bool => true);
        $builds->reply = static fn (array $request): array => [['type' => 'error', 'id' => $request['id'], 'error' => ['code' => 'unauthorized', 'message' => 'Denied.']]];

        expect(release_failure(fn () => $handoff->serve())->errorCode)->toBe('gateway.release_caddy_failed')
            ->and($builds->sent)->toHaveCount(2)
            ->and($probe->time->now())->toBe(0.0);
    });

    it('does not mistake a submitted publication for a successfully published digest', function (): void {
        handoff_gateway();
        [$handoff, , , $builds] = runtime_handoff();

        $handoff->serve();
        $handoff->serve();

        expect($builds->sent)->toHaveCount(2);
    });

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
            'caddy' => 'submitted',
            'caddy_operation_id' => Operation::query()->where('kind', 'route.caddy_converge')->latest('id')->value('id'),
            'fpm' => 'unchanged',
            'scheduler' => 'restarted',
            'scheduler_unit' => $unit,
            'cleanup' => 'resumed',
            'agent_view' => 'restarted',
            'cleanup_paused' => false,
        ])
            ->and($result['scheduler_drain']['outcome'])->toBe('drained')
            ->and($result['scheduler_drain'])->toBe($probe->drain)
            ->and($builds->sent[0]['payload']['workflowIdOrName'])->toBe('operation-route-caddy')
            ->and($units->converged)->toBe(['units', 'units', 'units'])
            ->and($schedulerAgents->calls)->toBe([
                ['node' => 'gateway', 'command' => 'gateway.drain-scheduler', 'input' => ['process_id' => $scheduler->id, 'name' => $unit, 'drain_seconds' => 5]],
                ['node' => 'gateway', 'command' => 'gateway.start-scheduler', 'input' => ['process_id' => $scheduler->id, 'name' => $unit]],
            ])
            ->and($probe->cleared)->toBe(0)
            ->and($cleanup->calls)->toContain('reconcile', 'resume:r1');
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

        expect($serve['horizon'])->toBe('submitted')
            ->and(Operation::query()->findOrFail($serve['horizon_operation_id'])->kind)->toBe('process.horizon_handoff')
            ->and((int) Cache::get('handoff-horizon-terminated'))->toBe(0)
            ->and($schedule)->not->toHaveKey('horizon')
            ->and($schedule)->toMatchArray(['scheduler' => 'restarted'])
            ->and($schedule['processes_restarted'])->toBe([])
            ->and($runtime->convergedProcessIds)->toBe([])
            ->and(array_column(array_column($schedulerAgents->calls, 'input'), 'process_id'))->not->toContain($horizon->id)
            ->and(DB::table('jobs')->pluck('queue')->all())->toBe([]);
    });

    it('uses the existing bounded release recovery for Horizon', function (string $outcome): void {
        config(['orbit.gateway_queue.connection' => 'services-valkey']);
        $gateway = handoff_gateway();
        handoff_scheduler($gateway);
        $version = app(NodeCaddyfileRenderer::class)->render($gateway)->version;
        $path = sys_get_temp_dir().'/horizon-caddy-'.bin2hex(random_bytes(8));
        symlink('/etc/caddy/orbit-versions/'.$version.'/Caddyfile', $path);
        $checks = 0;
        try {
            [$handoff, , , $builds, , $probe] = runtime_handoff(horizon: app(GatewayHorizon::class), caddyfilePath: $path,
                swampHealthy: static function (float $timeout) use (&$checks, $outcome): bool {
                    $checks++;
                    expect($timeout)->toBeLessThanOrEqual(5);

                    return match ($outcome) {
                        'recovered' => $checks > 1,
                        'refused' => true,
                        'deadline' => false,
                        default => throw new RuntimeException('Uncertain admission must not probe health.'),
                    };
                });
            if ($outcome === 'uncertain') {
                $builds->reply = static fn (): array => [];
            } elseif ($outcome === 'refused') {
                $builds->reply = static fn (array $request): array => [['type' => 'error', 'id' => $request['id'], 'error' => ['code' => 'unauthorized', 'message' => 'Denied.']]];
            } else {
                $builds->onConnect = static function (int $attempt) use ($outcome): void {
                    if ($outcome !== 'recovered' || $attempt === 1) {
                        throw new RuntimeException('Serve is down.');
                    }
                };
            }

            if ($outcome === 'recovered') {
                $result = $handoff->serve();
                expect($result)->toMatchArray(['caddy' => 'unchanged', 'horizon' => 'submitted'])
                    ->and(Operation::query()->findOrFail($result['horizon_operation_id'])->status->value)->toBe('running')
                    ->and(Process::query()->where('name', 'horizon')->sole()->status)->toBe(LifecycleStatus::Provisioning);
            } else {
                expect(release_failure(fn () => $handoff->serve())->errorCode)->toBe('gateway.release_units_failed');
            }
            expect(Operation::query()->where('kind', 'route.caddy_converge')->count())->toBe(0)
                ->and(Operation::query()->where('kind', 'process.horizon_handoff')->count())->toBe(in_array($outcome, ['recovered', 'refused'], true) ? 2 : 1)
                ->and($builds->connections)->toHaveCount(in_array($outcome, ['recovered', 'refused'], true) ? 2 : 1)
                ->and($checks)->toBe(match ($outcome) {
                    'recovered' => 2, 'refused' => 1, 'deadline' => 120, default => 0
                })
                ->and($probe->time->now())->toBe(match ($outcome) {
                    'recovered' => 5_000_000_000.0, 'deadline' => 600_000_000_000.0, default => 0.0
                });
        } finally {
            unlink($path);
        }
    })->with(['recovered', 'deadline', 'refused', 'uncertain']);

    it('fails the serving phase when native Horizon admission is refused', function (): void {
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

        app()->instance(WebSocketClient::class, new FakeRoutePublicationSocket(false));

        expect(fn () => $handoff->serve())
            ->toThrow(GatewayReleaseException::class);
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

    it('fails the handoff if the native Caddy root cannot start', function (): void {
        handoff_gateway();
        [$handoff, , , $builds] = runtime_handoff();
        $builds->reply = static fn (array $request): array => [];

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
