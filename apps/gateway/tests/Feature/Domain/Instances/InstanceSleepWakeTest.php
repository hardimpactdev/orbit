<?php

declare(strict_types=1);

use App\Actions\GatewayReleases\WaitForGatewayReleaseIdleAction;
use App\Actions\Operations\StartOperationAction;
use App\Data\Operations\OperationListItemData;
use App\Domain\Instances\InstanceContainer;
use App\Domain\Instances\InstanceLifecycleContribution;
use App\Domain\Instances\InstanceLifecycleContributions;
use App\Domain\Instances\InstancePlacement;
use App\Domain\Instances\InstanceRuntimePowerState;
use App\Domain\Instances\InstanceThreadContribution;
use App\Domain\Instances\InstanceThreadContributions;
use App\Domain\Instances\Power\ContainerIdleSweep;
use App\Domain\Instances\Power\ContainerResume;
use App\Domain\Instances\Power\InstanceSleep;
use App\Domain\Instances\Power\InstanceWake;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\OperationIdempotency;
use App\Domain\Operations\OperationRunBudgets;
use App\Domain\Operations\OperationStore;
use App\Domain\Projects\ProjectType;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\AgentCommands\AgentContract;
use App\Infrastructure\AgentCommands\AgentRunnerFailure;
use App\Infrastructure\AppDev\DevelopmentCaddyConfigRenderer;
use App\Infrastructure\AppDev\DevelopmentSite;
use App\Infrastructure\AppDev\DevelopmentSiteRepository;
use App\Jobs\Operations\StartAdmittedSwampRun;
use App\Models\Instance;
use App\Models\InstanceRuntime;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Route;
use App\Models\Task;
use Carbon\CarbonInterval;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Defer\DeferredCallbackCollection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Illuminate\Testing\TestResponse;

uses(LazilyRefreshDatabase::class);
require_once __DIR__.'/ContainerInstanceHelpers.php';

beforeEach(function (): void {
    Queue::fake();
    Http::preventStrayRequests();
});

/** A running development clone of a container default, with its own T3 port. */
function sleepingClone(bool $taskOwned = false): Instance
{
    $node = containerDefaultNode();
    $project = containerDefaultProject(ProjectType::LaravelApp, '8.5');
    $default = Instance::query()->create(['node_id' => $node->id, 'project_id' => $project->id, 'name' => 'default',
        'checkout_path' => '/workspace/default', 'root' => 'public', 'status' => 'active', 'first_setup_pending' => false, 'source_is_laravel' => true]);
    app(InstanceContainer::class)->reserve($default, $node)->update(['power_state' => 'running', 'seed_commit' => str_repeat('a', 40)]);
    $owner = $taskOwned ? Task::query()->forceCreate(['project_id' => $project->id, 'title' => 'task', 'brief' => 'Fixture task.', 'status' => 'running'])->id : null;
    $clone = Instance::query()->forceCreate(['node_id' => $node->id, 'project_id' => $project->id, 'name' => 'clone', 'task_workspace_owner_id' => $owner,
        'checkout_path' => '/workspace/clone', 'root' => 'public', 'status' => 'active', 'first_setup_pending' => false, 'source_is_laravel' => true]);
    app(InstanceContainer::class)->reserve($clone, $node, $default)->update(['power_state' => 'running']);

    return $clone->refresh();
}

/** A default sleeps as every development container does; it has no T3 server, so its result says so. */
it('puts a default Instance to sleep, says it has no T3 server, and keeps the swap subtree count', function (string $trigger): void {
    sleepingClone();
    $default = Instance::query()->where('name', 'default')->firstOrFail();

    $sleep = app(InstanceSleep::class)->start($default, $trigger === 'sweep' ? ContainerIdleSweep::TriggeredBy : null, null);
    $done = powerRun($sleep, 'succeeded', ['sleep' => 'frozen', 'swap_subtree' => 0]);

    expect($sleep->kind)->toBe(InstanceSleep::Kind)
        ->and($done->result)->toBe(['power_state' => 'stopped', 't3_turn' => 'no_server', 'sleep' => 'frozen', 'swap_subtree' => 0])
        ->and($default->runtime()->firstOrFail()->power_state)->toBe(InstanceRuntimePowerState::Stopped);
})->with(['command', 'sweep']);

function powerRun(Operation $operation, string $status, array $result = []): Operation
{
    $operation->forceFill(['status' => 'running', 'state' => ['swamp_run_id' => 'power-proof']])->save();

    return app(DirectOperation::class)->projection($operation)->apply($operation->refresh(), ['status' => $status, 'run_id' => 'power-proof',
        'started_at' => now()->toIso8601String(), 'finished_at' => now()->toIso8601String(), 'result' => $result]);
}

function activationPoll(Instance $instance, string $uri = '/'): TestResponse
{
    return test()->withServerVariables(['REMOTE_ADDR' => '10.44.0.7'])->withHeader('X-Forwarded-Uri', $uri)
        ->get('/api/v1/runtime-activations/app-instance/'.$instance->id);
}

it('admits a sleep with the container intent and the asleep marker under the Instance lock', function (): void {
    $clone = sleepingClone();

    $operation = app(InstanceSleep::class)->start($clone, 'node:1', null);

    expect($operation->kind)->toBe('instance.sleep')
        ->and($operation->lock_key)->toBe(OperationStore::lockKey('instance', $clone->id))
        ->and($operation->input['marker'])->toBe('/data/caddy/orbit/hibernation/app-instance-'.$clone->id.'.asleep')
        ->and($operation->input['container']['name'])->toBe('instance-'.$clone->id)
        ->and($operation->input['container']['clone']['t3']['unit'])->toBe(InstanceContainer::T3Unit)
        ->and(OperationRunBudgets::seconds($operation->forceFill(['state' => ['swamp_run_id' => 'r']])))->toBe(150);
});

it('tells the sleep whether a published Route has a Caddy site to check, so an Instance without one sleeps with nothing to check', function (string $case, bool $site): void {
    $clone = sleepingClone();
    if ($case !== 'no Route') {
        $route = Route::query()->create(['kind' => 'app', 'project_id' => $clone->project_id, 'node_id' => $clone->node_id, 'provenance' => 'explicit',
            'publication' => 'private', 'domain' => 'clone.tool.test', 'status' => 'pending']);
        $route->targets()->create(['instance_id' => $clone->id, 'position' => 0]);
        if ($case === 'a published Route') {
            $route->update(['status' => 'active', 'sites_published' => true]);
        }
    }

    $operation = app(InstanceSleep::class)->start($clone, null, null);

    expect($operation->input['route'])->toBe($site)
        ->and($operation->input['marker'])->toBe('/data/caddy/orbit/hibernation/app-instance-'.$clone->id.'.asleep');
})->with([['no Route', false], ['a Route not yet published', false], ['a published Route', true]]);

it('lets the extensions read the threads one last time before the sleep is admitted', function (): void {
    $clone = sleepingClone();
    $contribution = Mockery::mock(InstanceThreadContribution::class);
    $contribution->shouldReceive('beforeSleep')->once()->withArgs(function (Instance $sleeping) use ($clone): bool {
        expect(Operation::query()->where('kind', InstanceSleep::Kind)->count())->toBe(0);

        return $sleeping->id === $clone->id;
    });
    app()->instance(InstanceThreadContributions::class, new InstanceThreadContributions([$contribution]));

    expect(app(InstanceSleep::class)->start($clone, null, null)->kind)->toBe(InstanceSleep::Kind);
});

it('replays a sleep under the same Idempotency-Key without the extensions\' read', function (): void {
    $clone = sleepingClone();
    $contribution = Mockery::mock(InstanceThreadContribution::class);
    $contribution->shouldReceive('beforeSleep')->once();
    app()->instance(InstanceThreadContributions::class, new InstanceThreadContributions([$contribution]));
    $idempotency = app(OperationIdempotency::class);

    $first = $idempotency->under(InstanceSleep::Kind, 'key-1', 'print-1', fn () => app(InstanceSleep::class)->start($clone, null, null));
    $replayed = $idempotency->under(InstanceSleep::Kind, 'key-1', 'print-1', fn () => app(InstanceSleep::class)->start($clone, null, null));

    expect($replayed->id)->toBe($first->id)->and($first->idempotency_key)->toBe('key-1');
});

it('lets no extension read a refused Instance before its refusal', function (): void {
    sleepingClone(taskOwned: true);
    $contribution = Mockery::mock(InstanceThreadContribution::class);
    $contribution->shouldNotReceive('beforeSleep');
    app()->instance(InstanceThreadContributions::class, new InstanceThreadContributions([$contribution]));
    $clone = Instance::query()->where('name', 'clone')->firstOrFail();

    expect(fn () => app(InstanceSleep::class)->start($clone, null, null))->toThrow(
        fn (ResourceOperationException $error) => expect($error->errorCode)->toBe('instance.lifecycle_busy'));
});

it('refuses a sleep that the Instance does not allow, before any run', function (string $case, string $code): void {
    $clone = sleepingClone(taskOwned: $case === 'task');
    match ($case) {
        'checkout' => $clone->runtime()->delete(),
        'production' => $clone->node->roles()->update(['role' => RoleName::AppProd]),
        'lifecycle' => $clone->update(['status' => 'checkout_prepared']),
        'task' => null,
        default => Operation::query()->create(['kind' => $case, 'target_type' => 'instance', 'target_id' => $clone->id,
            'node_id' => $clone->node_id, 'lock_key' => OperationStore::lockKey('instance', $clone->id), 'status' => 'running', 'input' => [], 'state' => []]),
    };

    expect(fn () => app(InstanceSleep::class)->start($clone->refresh(), null, null))->toThrow(
        fn (ResourceOperationException $error) => expect($error->errorCode)->toBe($code));
    expect(Operation::query()->where('kind', InstanceSleep::Kind)->count())->toBe(0);
})->with([
    ['checkout', 'operation.kind_unavailable'],
    ['production', 'instance.sleep_unsupported'], ['lifecycle', 'instance.lifecycle_busy'],
    ['task', 'instance.lifecycle_busy'], ['instance.deploy', 'operation.target_busy'], ['instance.wake', 'operation.target_busy'],
]);

/** The power helper's plan of the sleep (docs/reference/prepared-restore.md#results): its typed fields join the existing keys. */
it('keeps every existing sleep key and adds the typed prepared restore of the helper, dropping what the contract does not name', function (): void {
    $clone = sleepingClone();
    $sleep = app(InstanceSleep::class)->start($clone, null, null);
    expect($sleep->input['container']['web_port'])->toBe(8080);

    $done = powerRun($sleep, 'succeeded', ['sleep' => 'frozen', 'swap_subtree' => 2, 'prepared_restore' => ['version' => 1, 'status' => 'ready',
        'generation' => '6f1c1d1e-0000-4000-8000-000000000001', 'processes' => 18, 'resident_mapping_bytes' => 336117760, 'metadata_bytes' => 1357673,
        'prepare_seconds' => 0.291, 'residual_anon_bytes' => 0, 'residual_file_bytes' => 4096, 'host_pid' => 4242, 'ranges' => [[1, 2]]]]);

    expect($done->result)->toBe(['power_state' => 'stopped', 't3_turn' => 'not_checked', 'sleep' => 'frozen', 'swap_subtree' => 2,
        'prepared_restore' => ['version' => 1, 'status' => 'ready', 'generation' => '6f1c1d1e-0000-4000-8000-000000000001', 'processes' => 18,
            'resident_mapping_bytes' => 336117760, 'metadata_bytes' => 1357673, 'prepare_seconds' => 0.291, 'residual_anon_bytes' => 0,
            'residual_file_bytes' => 4096]]);
});

it('projects a skipped, unsupported or not applicable plan with its closed reason, and leaves out a status or reason outside the contract', function (array $prepared, ?array $expected): void {
    $clone = sleepingClone();

    $done = powerRun(app(InstanceSleep::class)->start($clone, null, null), 'succeeded', ['sleep' => 'stopped', 'swap_subtree' => 0, 'prepared_restore' => $prepared]);

    expect($done->result)->toBe(['power_state' => 'stopped', 't3_turn' => 'not_checked', 'sleep' => 'stopped', 'swap_subtree' => 0,
        ...($expected === null ? [] : ['prepared_restore' => $expected])]);
})->with([
    'stop path' => [['version' => 1, 'status' => 'skipped', 'reason' => 'stopped', 'residual_anon_bytes' => 314572800, 'residual_file_bytes' => 0],
        ['version' => 1, 'status' => 'skipped', 'reason' => 'stopped', 'residual_anon_bytes' => 314572800, 'residual_file_bytes' => 0]],
    'nested fleet' => [['version' => 1, 'status' => 'skipped', 'reason' => 'placement_unproven'], ['version' => 1, 'status' => 'skipped', 'reason' => 'placement_unproven']],
    'no capability' => [['version' => 1, 'status' => 'unsupported', 'reason' => 'unsupported'], ['version' => 1, 'status' => 'unsupported', 'reason' => 'unsupported']],
    'stopped container' => [['version' => 1, 'status' => 'not_applicable'], ['version' => 1, 'status' => 'not_applicable']],
    'unknown reason' => [['version' => 1, 'status' => 'skipped', 'reason' => 'trained_route'], null],
    'a wake reason' => [['version' => 1, 'status' => 'skipped', 'reason' => 'deadline'], null],
    'ready without its numbers' => [['version' => 1, 'status' => 'ready', 'generation' => 'not-a-uuid'], null],
    'wake status' => [['version' => 1, 'status' => 'complete'], null],
]);

it('keeps the helper\'s failure code and no prepared restore on a failed sleep', function (): void {
    $clone = sleepingClone();

    $failed = powerRun(app(InstanceSleep::class)->start($clone, null, null), 'failed', ['error_code' => 'instance.power_helper_busy']);

    expect($failed->status->value)->toBe('failed')
        ->and($failed->error_code)->toBe('instance.power_helper_busy')
        ->and($failed->result)->toBe(['error_code' => 'instance.power_helper_busy'])
        ->and($clone->runtime()->firstOrFail()->power_state)->toBe(InstanceRuntimePowerState::Running);
});

it('records a sleeping container as stopped, says that the command did not check T3, and names how it sleeps', function (): void {
    $clone = sleepingClone();

    $sleep = app(InstanceSleep::class)->start($clone, null, null);
    expect($sleep->input['freeze'])->toBe(['resident_anon_mib' => 32]);

    $operation = powerRun($sleep, 'succeeded', ['sleep' => 'frozen']);

    expect($operation->status->value)->toBe('succeeded')
        ->and($operation->result)->toBe(['power_state' => 'stopped', 't3_turn' => 'not_checked', 'sleep' => 'frozen'])
        ->and($operation->lock_key)->toBeNull()
        ->and($clone->runtime()->firstOrFail()->power_state)->toBe(InstanceRuntimePowerState::Stopped);
});

it('keeps the power state after a failed sleep or wake, with the phase error code', function (string $kind, string $state, string $code): void {
    $clone = sleepingClone();
    $clone->runtime()->update(['power_state' => $state]);
    $action = $kind === 'sleep' ? app(InstanceSleep::class) : app(InstanceWake::class);

    $operation = powerRun($action->start($clone, null, null), 'failed', ['error_code' => $code]);

    expect($operation->status->value)->toBe('failed')
        ->and($operation->error_code)->toBe($code)
        ->and($clone->runtime()->firstOrFail()->power_state->value)->toBe($state);
})->with([
    ['sleep', 'running', 'instance.sleep_route_unready'],
    ['wake', 'stopped', 'instance.container_start_failed'],
    ['wake', 'stopped', 'instance.web_unready'],
]);

/** The wake removed the marker once the app answered; only T3 stayed silent. */
it('records a wake whose T3 stayed silent as failed but running, because the container runs and its site serves', function (): void {
    $clone = sleepingClone();
    $clone->runtime()->update(['power_state' => 'stopped']);
    powerRun(app(InstanceSleep::class)->start($clone, null, null), 'succeeded');

    $wake = app(InstanceWake::class)->start($clone, null, null);
    expect($wake->input['web'])->toBe(['port' => InstanceContainer::WebPort, 'ready_seconds' => 30, 'settle_seconds' => 5, 'resume_seconds' => 10])
        ->and($wake->input['marker'])->toBe(InstanceWake::markerPath($clone));

    $operation = powerRun($wake, 'failed', ['error_code' => 'instance.t3_unready', 'wake' => 'started', 'marker_found' => true, 'commit' => null]);

    expect($operation->status->value)->toBe('failed')
        ->and($operation->error_code)->toBe('instance.t3_unready')
        ->and($operation->result)->toBe(['error_code' => 'instance.t3_unready', 'wake' => 'started', 'marker_found' => true])
        ->and($clone->runtime()->firstOrFail()->power_state)->toBe(InstanceRuntimePowerState::Running);
});

it('admits one wake per Instance, which a second caller joins, and records it awake', function (): void {
    $clone = sleepingClone();
    $clone->runtime()->update(['power_state' => 'stopped']);

    $first = app(InstanceWake::class)->start($clone, 'node:1', null);
    $second = app(InstanceWake::class)->start($clone, 'node:2', null);

    expect($second->id)->toBe($first->id)
        ->and(Operation::query()->where('kind', InstanceWake::Kind)->count())->toBe(1)
        ->and(OperationRunBudgets::seconds((clone $first)->forceFill(['state' => ['swamp_run_id' => 'r']])))->toBe(300);

    $done = powerRun($first, 'succeeded', ['commit' => null, 'wake' => 'resumed', 'marker_found' => true, 'resume_seconds' => 0.021, 'web_probe_seconds' => 0.4]);

    expect($done->result)->toBe(['power_state' => 'running', 'wake' => 'resumed', 'marker_found' => true, 'resume_seconds' => 0.021, 'web_probe_seconds' => 0.4])
        ->and($clone->runtime()->firstOrFail()->power_state)->toBe(InstanceRuntimePowerState::Running);
});

it('wakes a default too', function (): void {
    sleepingClone();
    $default = Instance::query()->where('name', 'default')->firstOrFail();

    expect(app(InstanceWake::class)->start($default, null, null)->kind)->toBe('instance.wake');
});

it('starts one wake from Route visits behind the asleep marker, shows the failure page after a failed wake, and retries on request', function (): void {
    $clone = sleepingClone();
    $clone->runtime()->update(['power_state' => 'stopped']);
    powerRun(app(InstanceSleep::class)->start($clone, null, null), 'succeeded');

    activationPoll($clone, '/dashboard')->assertStatus(401)->assertHeader('X-Orbit-Runtime-Activation-State', 'pending')
        ->assertHeader('Retry-After', '1')->assertSee('aria-label="Orbit"', false)->assertDontSee('Waking');
    activationPoll($clone, '/dashboard')->assertStatus(401)->assertHeader('X-Orbit-Runtime-Activation-State', 'pending');

    $wakes = Operation::query()->where('kind', InstanceWake::Kind)->get();
    expect($wakes)->toHaveCount(1)->and($wakes->sole()->triggered_by)->toBe('node:'.$clone->node_id);

    powerRun($wakes->sole(), 'failed', ['error_code' => 'instance.container_start_failed']);

    activationPoll($clone, '/dashboard')->assertStatus(503)->assertHeader('X-Orbit-Runtime-Activation-State', 'failed')
        ->assertSee('The wake failed (instance.container_start_failed). Try again.')->assertSee('Try again');
    expect(Operation::query()->where('kind', InstanceWake::Kind)->count())->toBe(1);

    activationPoll($clone, '/dashboard?orbit-wake-retry=1')->assertStatus(401);
    expect(Operation::query()->where('kind', InstanceWake::Kind)->count())->toBe(2);
});

it('keeps the progress page while other lifecycle work holds the Instance', function (): void {
    $clone = sleepingClone();
    Operation::query()->create(['kind' => 'instance.deploy', 'target_type' => 'instance', 'target_id' => $clone->id, 'node_id' => $clone->node_id,
        'lock_key' => OperationStore::lockKey('instance', $clone->id), 'status' => 'running', 'input' => [], 'state' => []]);

    activationPoll($clone)->assertStatus(401)->assertHeader('X-Orbit-Runtime-Activation-State', 'pending');
    expect(Operation::query()->where('kind', InstanceWake::Kind)->count())->toBe(0);
});

it('holds Gateway release idle until sleep and wake settle', function (string $kind): void {
    Sleep::fake(syncWithCarbon: true);
    $operation = Operation::query()->create(['kind' => $kind, 'target_type' => 'instance', 'target_id' => 1,
        'status' => 'running', 'input' => [], 'state' => []]);
    Sleep::whenFakingSleep(fn () => $operation->update(['status' => 'succeeded']));
    app(WaitForGatewayReleaseIdleAction::class)->execute();
    Sleep::assertSleptTimes(1);
})->with(['instance.sleep', 'instance.wake', 'instance.start']);

it('renders a running container site as before plus only the asleep check and the access log, which call nothing while the marker is missing', function (): void {
    $clone = sleepingClone();
    $route = Route::query()->create(['kind' => 'app', 'project_id' => $clone->project_id, 'node_id' => $clone->node_id, 'provenance' => 'explicit',
        'publication' => 'private', 'domain' => 'clone.tool.test', 'status' => 'pending']);
    $renderer = app(DevelopmentCaddyConfigRenderer::class);

    $site = app(DevelopmentSiteRepository::class)->workloadSite($clone, $route);
    $before = $renderer->render(collect([new DevelopmentSite(...[...get_object_vars($site), 'asleepMarker' => null])]));
    $after = $renderer->render(collect([$site]));

    $marker = '/data/caddy/orbit/hibernation/app-instance-'.$clone->id.'.asleep';
    expect($site->asleepMarker)->toBe($marker)
        ->and(substr_count($after, 'forward_auth'))->toBe(1)
        ->and(substr_count($before, 'forward_auth'))->toBe(0)
        ->and($after)->toContain('try_files /app-instance-'.$clone->id.'.asleep')
        ->and($after)->toContain('uri /api/v1/runtime-activations/app-instance/'.$clone->id)
        ->and($after)->not->toContain('.awake')
        // The idle sleep reads the log's mtime; a probe and a request that meets the asleep marker write nothing, and one roll bounds it.
        ->and($after)->toContain('output file /data/caddy/orbit/hibernation/app-instance-'.$clone->id.'.log {')
        ->and($after)->toContain('roll_size 1MiB')->and($after)->toContain('roll_keep 1')
        ->and($after)->toContain('@orbit_probe header X-Orbit-Probe 1')
        ->and($after)->toContain('log_skip @orbit_probe')->and($after)->toContain('log_skip @orbit_asleep')
        ->and($before)->not->toContain('log {');

    // The original proxy and its options stay inside the ordered route, after forward_auth.
    $proxy = substr($before, strpos($before, 'reverse_proxy'));
    $proxy = substr($proxy, 0, strrpos($proxy, '}'));
    expect($after)->toContain(trim($proxy))
        ->toContain('forward_auth @orbit_asleep https://gateway.orbit')
        ->toContain('route {');
    expect(strpos($after, 'forward_auth'))->toBeLessThan(strpos($after, 'reverse_proxy'));

});

it('gives a production or checkout site no asleep check', function (): void {
    $site = new DevelopmentSite(nodeId: 1, nodeAddress: '10.44.0.7', scope: 'app-instance-9', checkoutPath: '', documentRoot: '',
        phpVersion: null, domain: 'x.test', localHttpUpstream: '10.253.0.2:8080');

    expect(app(DevelopmentCaddyConfigRenderer::class)->render(collect([$site])))->not->toContain('orbit_asleep');
});

/**
 * The Instance's Node for the fast wake: the frozen agent's `hibernation.marker` writes the Operation's wake request and
 * `hibernation.times` reads the files, while the app-dev role's dispatcher and worker (when the Node has them) act on the request at
 * the read number `after`: the worker calls the helper's restore and checks readiness, then removes the marker and writes one ready
 * file and done, or it writes failed. A `silent` worker never acts.
 *
 * @param  array{worker?: bool, status?: string, marker?: bool, web?: bool, restore?: string, after?: int, silent?: bool, fails?: ?string}  $node
 */
function wakeNode(Instance $clone, array $node = []): object
{
    $state = (object) [...['worker' => true, 'status' => 'Frozen', 'marker' => true, 'web' => true, 'restore' => 'complete', 'after' => 1,
        'silent' => false, 'fails' => null, 'onRead' => null, 'readyAt' => null], ...$node, 'files' => [], 'calls' => [], 'reads' => 0, 'restores' => 0];
    $marker = InstanceWake::markerPath($clone);
    if ($state->marker) {
        $state->files[$marker] = time();
    }
    if ($state->worker) {
        $state->files[ContainerResume::WorkerPath] = time();
    }
    $agents = Mockery::mock(AgentCommandRunner::class);
    $agents->shouldReceive('run')->andReturnUsing(function (Node $node, string $command, array $input = []) use ($state, $clone, $marker): array {
        app(AgentContract::class)->command($command);
        $state->calls[] = $command.($command === 'hibernation.marker' ? ':'.$input['operation'] : '');
        if ($state->fails === $command) {
            throw new AgentRunnerFailure($command, AgentRunnerFailure::Timeout, 'The agent call timed out.');
        }
        if ($command === 'hibernation.marker') {
            if ($input['operation'] === 'mark') {
                expect($input['path'])->toMatch('#^/dev/shm/orbit/hibernation/app-instance-'.$clone->id.'-op-[1-9][0-9]*\.wake$#')
                    ->and($input['marker_directory'])->toBe('/dev/shm/orbit/hibernation')->and($input['log_directory'])->toBe('/data/caddy/orbit/hibernation');
                $state->files[$input['path']] = time();
            } else {
                unset($state->files[$input['path']]);
            }

            return [];
        }
        expect($command)->toBe('hibernation.times');
        if ($state->onRead !== null) {
            ($state->onRead)();
        }
        $state->reads++;
        foreach (array_keys($state->files) as $path) {
            if (! $state->worker || $state->silent || $state->reads < $state->after
                || preg_match('#^/dev/shm/orbit/hibernation/app-instance-'.$clone->id.'-op-([0-9]+)\.wake$#', (string) $path, $match) !== 1) {
                continue;
            }
            unset($state->files[$path]);
            $operationId = (int) $match[1];
            $state->files[ContainerResume::requestPath($clone->id, $operationId, 'taken')] = time();
            if ($state->status === 'Frozen' && isset($state->files[$marker])) {
                $state->restores++;
                $state->status = 'Running';
                if ($state->web) {
                    unset($state->files[$marker]);
                    $state->files[ContainerResume::outcomePath($clone->id, $operationId, 'ready-'.$state->restore)] = $state->readyAt ?? time();
                    $state->files[ContainerResume::outcomePath($clone->id, $operationId, 'done')] = time();

                    continue;
                }
            }
            $state->files[ContainerResume::outcomePath($clone->id, $operationId, 'failed')] = time();
        }

        return ['times' => collect($input['paths'])->mapWithKeys(fn (string $path): array => [$path => $state->files[$path] ?? null])->all()];
    });
    app()->instance(AgentCommandRunner::class, $agents);

    return $state;
}

/** @return list<string> The Gateway's removal of a fast wake's files after the projection: one read, then each file found. */
function cleanUpCalls(int $files): array
{
    return ['hibernation.times', ...array_fill(0, $files, 'hibernation.marker:remove')];
}

/** A clone whose last sleep froze it (`sleep: frozen`), as the freeze sleep records it. */
function frozenClone(string $sleep = 'frozen'): Instance
{
    Sleep::fake(syncWithCarbon: true);
    $clone = sleepingClone();
    powerRun(app(InstanceSleep::class)->start($clone, null, null), 'succeeded', ['sleep' => $sleep]);
    Queue::fake();

    return $clone->refresh();
}

it('tries the Node\'s fast wake whatever swap the sleep opened, since the helper\'s restore closes every limit', function (): void {
    Sleep::fake(syncWithCarbon: true);
    $clone = sleepingClone();
    powerRun(app(InstanceSleep::class)->start($clone, null, null), 'succeeded', ['sleep' => 'frozen', 'swap_subtree' => 3]);
    Queue::fake();
    $state = wakeNode($clone->refresh());

    $wake = app(InstanceWake::class)->start($clone, null, null);

    expect($wake->status->value)->toBe('succeeded')->and($state->restores)->toBe(1);
});

it('never takes the fast wake for a nested-fleet Instance: its run wakes it, with no Node call', function (): void {
    $clone = frozenClone();
    // A runtime keeps its container: the nested-fleet placement is a new record of the same Instance.
    $runtime = $clone->runtime()->firstOrFail();
    $runtime->delete();
    InstanceRuntime::query()->forceCreate([...Arr::except($runtime->getAttributes(), ['id']), 'incus_project' => InstancePlacement::NestedProject,
        'idmap_size' => 101000000]);
    $state = wakeNode($clone->refresh());

    $wake = app(InstanceWake::class)->start($clone, null, null);

    expect($wake->status->value)->toBe('queued')
        ->and($wake->state[InstanceWake::ResumeState] ?? null)->toBeNull()
        ->and($state->calls)->toBe([]);
    Queue::assertPushed(StartAdmittedSwampRun::class, fn (StartAdmittedSwampRun $job): bool => $job->operationId === $wake->id && $job->delay === null);
});

it('answers a visit to a frozen container with the waiting page before any Node call, and resumes it after the answer', function (): void {
    $clone = frozenClone();
    $node = wakeNode($clone);

    // Within the visitor's call the wake is only admitted: no Node call runs, so the waiting page answers at once.
    expect(app(InstanceWake::class)->activate($clone, false, 'node:'.$clone->node_id))->toBeNull()
        ->and($node->calls)->toBe([]);
    $wake = Operation::query()->where('kind', InstanceWake::Kind)->sole();
    expect($wake->status->value)->toBe('queued')->and($wake->state[InstanceWake::ResumeState])->toBe('node');

    // After the answer the Node resumes the container, in the same Operation.
    app(DeferredCallbackCollection::class)->invoke();

    $wake->refresh();
    expect($wake->status->value)->toBe('succeeded')
        ->and($wake->result)->toMatchArray(['power_state' => 'running', 'wake' => 'resumed', 'marker_found' => true])
        ->and($wake->result)->toHaveKeys(['trigger_seconds', 'confirm_seconds', 'ready_at'])
        ->and($wake->lock_key)->toBeNull()
        ->and($wake->triggered_by)->toBe('node:'.$clone->node_id)
        ->and($wake->state[InstanceWake::ResumeState])->toBe('resumed')
        ->and($clone->runtime()->firstOrFail()->power_state)->toBe(InstanceRuntimePowerState::Running)
        ->and($node->calls)->toBe(['hibernation.marker:mark', 'hibernation.times', ...cleanUpCalls(3)])
        ->and(array_keys($node->files))->toBe([ContainerResume::WorkerPath]);
    // Only the safety net is queued, well after the bound: it starts nothing for an ended Operation.
    Queue::assertPushed(StartAdmittedSwampRun::class, 1);
    Queue::assertPushed(StartAdmittedSwampRun::class, fn (StartAdmittedSwampRun $job): bool => $job->operationId === $wake->id
        && $job->delay !== null && now()->diffInSeconds($job->delay) >= InstanceWake::SafetyNetSeconds - 1);
});

it('gives the visit the waiting page, then ends the wake through the Node once the answer went', function (): void {
    $clone = frozenClone();
    $node = wakeNode($clone);

    activationPoll($clone, '/dashboard?tab=1')->assertStatus(401)->assertHeader('X-Orbit-Runtime-Activation-State', 'pending')
        ->assertHeader('Cache-Control', 'no-store, private')->assertSee(json_encode('/dashboard?tab=1', JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT), false);

    expect(Operation::query()->where('kind', InstanceWake::Kind)->sole()->status->value)->toBe('succeeded')
        ->and($node->calls)->toBe(['hibernation.marker:mark', 'hibernation.times', ...cleanUpCalls(3)]);
});

it('makes no Node call after the answer for a wake that was cancelled in the meantime', function (): void {
    $clone = frozenClone();
    $node = wakeNode($clone);

    expect(app(InstanceWake::class)->activate($clone, false, 'node:'.$clone->node_id))->toBeNull();
    Operation::query()->where('kind', InstanceWake::Kind)->update(['status' => 'cancelled', 'lock_key' => null]);
    app(DeferredCallbackCollection::class)->invoke();

    expect($node->calls)->toBe([])
        ->and(Operation::query()->where('kind', InstanceWake::Kind)->sole()->status->value)->toBe('cancelled');
});

it('answers the wake command with the ended Operation when the Node resumed the container', function (): void {
    $clone = frozenClone();
    wakeNode($clone);

    $operation = app(InstanceWake::class)->start($clone, 'node:5', null);

    expect($operation->status->value)->toBe('succeeded')->and($operation->result['wake'])->toBe('resumed');
    Queue::assertPushed(StartAdmittedSwampRun::class, fn (StartAdmittedSwampRun $job): bool => $job->delay !== null);
    Queue::assertNotPushed(StartAdmittedSwampRun::class, fn (StartAdmittedSwampRun $job): bool => $job->delay === null);
});

it('projects the ready file\'s status and time: restore_mode, prepared_restore and ready_at in whole seconds, and no number it was not sent', function (string $suffix, string $status, string $mode): void {
    $clone = frozenClone();
    wakeNode($clone, ['restore' => $suffix, 'readyAt' => 1794790000]);

    $operation = app(InstanceWake::class)->start($clone, null, null);

    expect($operation->status->value)->toBe('succeeded')
        ->and(Arr::except($operation->result, ['trigger_seconds', 'confirm_seconds']))->toBe(['power_state' => 'running', 'wake' => 'resumed',
            'marker_found' => true, 'restore_mode' => $mode, 'prepared_restore' => ['status' => $status],
            'ready_at' => '2026-11-16T00:46:40Z', 'ready_at_resolution_seconds' => 1])
        // finished_at is the projection, not the readiness time.
        ->and($operation->finished_at?->getTimestamp())->not->toBe(1794790000);
})->with([
    'complete' => ['complete', 'complete', 'prepared'],
    'partial' => ['partial', 'partial', 'prepared'],
    'timed out' => ['timed-out', 'timed_out', 'prepared'],
    'skipped: an ordinary resume' => ['skipped', 'skipped', 'ordinary'],
]);

it('reads the outcome at once and then every 25 ms, never waiting first', function (int $after, int $pauses): void {
    $clone = frozenClone();
    $state = wakeNode($clone, ['after' => $after]);

    expect(app(InstanceWake::class)->start($clone, null, null)->status->value)->toBe('succeeded')
        ->and($state->reads)->toBe($after);
    Sleep::assertSleptTimes($pauses);
    Sleep::assertSlept(fn (CarbonInterval $pause): bool => (int) $pause->totalMicroseconds === 25_000, $pauses);
})->with([
    'ready at the first read' => [1, 0],
    'ready at the fourth read' => [4, 3],
]);

it('gives up on a silent worker after 6 s, well before the safety net, and continues the same Operation in its run', function (): void {
    $clone = frozenClone();
    $state = wakeNode($clone, ['silent' => true]);
    // Measure the simulated poll window from a fixed clock, including before its first sleep.
    $this->freezeTime();
    $started = ContainerResume::now();

    $operation = app(InstanceWake::class)->start($clone, null, null);

    expect($operation->status->value)->toBe('queued')
        ->and($operation->state[InstanceWake::ResumeState])->toBe('workflow')
        ->and(ContainerResume::now() - $started)->toBeGreaterThanOrEqual(ContainerResume::BoundSeconds - 0.05)->toBeLessThan(ContainerResume::BoundSeconds + 0.1)
        ->and(ContainerResume::BoundSeconds + 1.0)->toBeLessThan((float) InstanceWake::SafetyNetSeconds)
        ->and(array_slice($state->calls, -1))->toBe(['hibernation.marker:remove'])
        ->and($state->files)->not->toHaveKey(ContainerResume::requestPath($clone->id, $operation->id));
    Queue::assertPushed(StartAdmittedSwampRun::class, fn (StartAdmittedSwampRun $job): bool => $job->operationId === $operation->id && $job->delay === null);
});

it('never takes another Operation\'s outcome, nor the old unqualified answer, for this wake', function (): void {
    $clone = frozenClone();
    $state = wakeNode($clone, ['silent' => true]);
    $other = Operation::query()->max('id') + 100;
    foreach (['ready-complete', 'done'] as $suffix) {
        $state->files[ContainerResume::outcomePath($clone->id, $other, $suffix)] = time();
    }
    $state->files['/dev/shm/orbit/hibernation/app-instance-'.$clone->id.'.resumed'] = time();

    $operation = app(InstanceWake::class)->start($clone, null, null);

    expect($operation->status->value)->toBe('queued')->and($operation->state[InstanceWake::ResumeState])->toBe('workflow');
});

it('lets the safety net\'s run and a late Node outcome make one transition and one projection, with the extensions once', function (): void {
    $clone = frozenClone();
    $state = wakeNode($clone);
    $contribution = Mockery::mock(InstanceLifecycleContribution::class)->shouldIgnoreMissing();
    $contribution->shouldReceive('projectWake')->once();
    app()->instance(InstanceLifecycleContributions::class, new InstanceLifecycleContributions([$contribution]));
    // The safety net started the run just as the worker wrote its outcome.
    $state->onRead = function () use ($clone): void {
        $wake = Operation::query()->where('kind', InstanceWake::Kind)->where('target_id', $clone->id)->sole();
        $wake->forceFill(['status' => 'running', 'state' => [...$wake->state, 'swamp_run_id' => 'safety-net']])->save();
    };

    $operation = app(InstanceWake::class)->start($clone, null, null);

    expect($operation->status->value)->toBe('running')->and($operation->result)->toBeNull()->and($state->restores)->toBe(1)
        ->and($clone->runtime()->firstOrFail()->power_state)->toBe(InstanceRuntimePowerState::Stopped);
    // The run waited for the helper's lock, found the container running and reports the worker's outcome of this Operation.
    $done = app(DirectOperation::class)->projection($operation)->apply($operation->refresh(), ['status' => 'succeeded', 'run_id' => 'safety-net',
        'started_at' => now()->toIso8601String(), 'finished_at' => now()->toIso8601String(), 'result' => ['commit' => null, 'wake' => 'already_running',
            'marker_found' => true, 'prepared_restore' => ['status' => 'complete'], 'ready_at' => '2026-11-16T00:46:40Z', 'ready_at_resolution_seconds' => 1]]);
    app(DeferredCallbackCollection::class)->invoke();

    expect($done->status->value)->toBe('succeeded')
        ->and($done->result)->toMatchArray(['power_state' => 'running', 'wake' => 'already_running', 'restore_mode' => 'prepared',
            'prepared_restore' => ['status' => 'complete'], 'ready_at' => '2026-11-16T00:46:40Z', 'ready_at_resolution_seconds' => 1])
        ->and($state->restores)->toBe(1)
        // After the projection the Gateway removes the Operation's files, off the visitor's path.
        ->and(array_slice($state->calls, -4))->toBe(cleanUpCalls(3))
        ->and(array_keys($state->files))->toBe([ContainerResume::WorkerPath]);
});

it('keeps the run\'s prepared restore, restore_mode and ready_at, and drops values outside the contract', function (array $phase, array $expected): void {
    $clone = frozenClone();
    wakeNode($clone, ['worker' => false]);
    $wake = app(InstanceWake::class)->start($clone, null, null);

    $done = powerRun($wake, 'succeeded', ['commit' => null, 'wake' => 'resumed', 'marker_found' => true, ...$phase]);

    expect(Arr::except($done->result, ['power_state', 'wake', 'marker_found']))->toBe($expected)
        ->and(OperationListItemData::fromModel($done)->toArray()['ready_at'])->toBe($expected['ready_at'] ?? null);
})->with([
    'a prepared restore with its reason and milliseconds' => [
        ['prepared_restore' => ['status' => 'partial', 'reason' => 'deadline', 'pages' => 12, 'host_pid' => 4],
            'ready_at' => '2026-11-16T00:46:40.123Z', 'ready_at_resolution_seconds' => 0.001],
        ['prepared_restore' => ['status' => 'partial', 'reason' => 'deadline'], 'restore_mode' => 'prepared',
            'ready_at' => '2026-11-16T00:46:40.123Z', 'ready_at_resolution_seconds' => 0.001]],
    'an ordinary resume without a plan' => [
        ['prepared_restore' => ['status' => 'skipped', 'reason' => 'no_plan']],
        ['prepared_restore' => ['status' => 'skipped', 'reason' => 'no_plan'], 'restore_mode' => 'ordinary']],
    'a boot: nothing to restore' => [['prepared_restore' => ['status' => 'not_applicable']],
        ['prepared_restore' => ['status' => 'not_applicable'], 'restore_mode' => 'ordinary']],
    'values outside the contract' => [
        ['prepared_restore' => ['status' => 'ready', 'reason' => 'whatever'], 'restore_mode' => 'prepared', 'ready_at' => 'yesterday',
            'ready_at_resolution_seconds' => 1],
        []],
    'a restore that read no page: ordinary' => [['prepared_restore' => ['status' => 'skipped', 'reason' => 'nothing_read']],
        ['prepared_restore' => ['status' => 'skipped', 'reason' => 'nothing_read'], 'restore_mode' => 'ordinary']],
    'a sleep reason is no wake reason' => [['prepared_restore' => ['status' => 'skipped', 'reason' => 'prepare_timeout']],
        ['prepared_restore' => ['status' => 'skipped'], 'restore_mode' => 'ordinary']],
    'a reason outside the contract' => [['prepared_restore' => ['status' => 'skipped', 'reason' => '/proc/4/maps']],
        ['prepared_restore' => ['status' => 'skipped'], 'restore_mode' => 'ordinary']],
]);

it('runs the wake workflow in the same Operation for every other state, removes an untaken request, and keeps the progress page', function (array $node, string $sleep, array $calls): void {
    $clone = frozenClone($sleep);
    $state = wakeNode($clone, $node);

    activationPoll($clone, '/dashboard')->assertStatus(401)->assertHeader('X-Orbit-Runtime-Activation-State', 'pending');

    $wake = Operation::query()->where('kind', InstanceWake::Kind)->sole();
    expect($wake->status->value)->toBe('queued')
        ->and($wake->state[InstanceWake::ResumeState] ?? null)->toBe($calls === [] ? null : 'workflow')
        ->and($state->calls)->toBe($calls)
        ->and($state->files)->not->toHaveKey(ContainerResume::requestPath($clone->id, $wake->id));
    Queue::assertPushed(StartAdmittedSwampRun::class, fn (StartAdmittedSwampRun $job): bool => $job->delay === null);
})->with([
    'stopped and marker: the sleep fell back to a stop' => [[], 'stopped', []],
    'stopped and marker, found by the Node' => [['status' => 'Stopped'], 'frozen', ['hibernation.marker:mark', 'hibernation.times', 'hibernation.marker:remove']],
    'running and marker after a reboot' => [['status' => 'Running'], 'frozen', ['hibernation.marker:mark', 'hibernation.times', 'hibernation.marker:remove']],
    'frozen without its marker' => [['marker' => false], 'frozen', ['hibernation.marker:mark', 'hibernation.times', 'hibernation.marker:remove']],
    'a web unit silent after the resume' => [['web' => false], 'frozen', ['hibernation.marker:mark', 'hibernation.times', 'hibernation.marker:remove']],
    'a Node without the worker' => [['worker' => false], 'frozen', ['hibernation.marker:mark', 'hibernation.times', 'hibernation.marker:remove']],
    'the request call fails' => [['fails' => 'hibernation.marker'], 'frozen', ['hibernation.marker:mark', 'hibernation.marker:remove']],
    'the read call fails' => [['fails' => 'hibernation.times'], 'frozen', ['hibernation.marker:mark', 'hibernation.times', 'hibernation.marker:remove']],
]);

it('never calls the Node for a running Instance without its marker', function (): void {
    $clone = sleepingClone();
    $state = wakeNode($clone, ['status' => 'Running', 'marker' => false]);

    expect(app(InstanceWake::class)->start($clone, null, null)->status->value)->toBe('queued')
        ->and($state->calls)->toBe([]);
});

it('gives a second visit during the resume the waiting page at once, joining the wake without waiting for it', function (): void {
    $clone = frozenClone();
    $state = wakeNode($clone);
    // The first visit admitted the wake and its Node call runs; the second visit finds it open.
    $first = app(StartOperationAction::class)->execute(InstanceWake::Kind, 'instance', $clone->id, $clone->node_id,
        ['container' => [], 'marker' => InstanceWake::markerPath($clone)], 'node:1', null, lockKey: OperationStore::lockKey('instance', $clone->id), start: false);
    $first->forceFill(['state' => [InstanceWake::ResumeState => 'node']])->save();
    Sleep::whenFakingSleep(function () use ($first): void {
        $first->refresh()->forceFill(['status' => 'succeeded', 'lock_key' => null, 'state' => [InstanceWake::ResumeState => 'resumed']])->save();
    });

    activationPoll($clone, '/second')->assertStatus(401)->assertHeader('X-Orbit-Runtime-Activation-State', 'pending');

    expect(Operation::query()->where('kind', InstanceWake::Kind)->count())->toBe(1)->and($state->calls)->toBe([])
        ->and($first->refresh()->status->value)->toBe('queued');
    Sleep::assertNeverSlept();
});

it('gives a joining visit the progress page when the joined wake falls back to its workflow', function (): void {
    $clone = frozenClone();
    wakeNode($clone);
    $first = app(StartOperationAction::class)->execute(InstanceWake::Kind, 'instance', $clone->id, $clone->node_id,
        ['container' => [], 'marker' => InstanceWake::markerPath($clone)], 'node:1', null, lockKey: OperationStore::lockKey('instance', $clone->id), start: false);
    $first->forceFill(['state' => [InstanceWake::ResumeState => 'workflow']])->save();

    activationPoll($clone)->assertStatus(401);
});

it('lets the waiting page reload only a path of its own site', function (string $forwarded, string $location): void {
    $clone = frozenClone();
    wakeNode($clone);

    activationPoll($clone, $forwarded)->assertStatus(401)
        ->assertSee('const uri = '.json_encode($location, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT).';', false);
})->with([
    ['/a/b?x=1&orbit-wake-retry=1', '/a/b?x=1'],
    ['//evil.example/x', '/'],
    ['/\\evil.example/x', '/'],
    ["/\t/evil.example", '/'],
    ['https://evil.example/', '/'],
    ['', '/'],
]);

it('lets the extensions catch up after a wake through the Node, as after a workflow', function (): void {
    $clone = frozenClone();
    wakeNode($clone);
    $contribution = Mockery::mock(InstanceLifecycleContribution::class)->shouldIgnoreMissing();
    $contribution->shouldReceive('projectWake')->once()->withArgs(fn (Instance $woken): bool => $woken->id === $clone->id);
    app()->instance(InstanceLifecycleContributions::class, new InstanceLifecycleContributions([$contribution]));

    activationPoll($clone)->assertStatus(401);
});

it('keeps a cancel that came during the Node resume, and starts no workflow for it', function (): void {
    $clone = frozenClone();
    $state = wakeNode($clone);
    $state->onRead = function () use ($clone): void {
        Operation::query()->where('kind', InstanceWake::Kind)->where('target_id', $clone->id)->update(['status' => 'cancelled', 'lock_key' => null]);
    };

    $operation = app(InstanceWake::class)->start($clone, null, null);

    expect($operation->status->value)->toBe('cancelled')
        ->and($clone->runtime()->firstOrFail()->power_state)->toBe(InstanceRuntimePowerState::Stopped);
    Queue::assertNotPushed(StartAdmittedSwampRun::class, fn (StartAdmittedSwampRun $job): bool => $job->delay === null);
});

it('still ends the wake when an extension fails after the Node resume', function (): void {
    $clone = frozenClone();
    wakeNode($clone);
    $contribution = Mockery::mock(InstanceLifecycleContribution::class)->shouldIgnoreMissing();
    $contribution->shouldReceive('projectWake')->andThrow(new RuntimeException('extension down'));
    app()->instance(InstanceLifecycleContributions::class, new InstanceLifecycleContributions([$contribution]));

    activationPoll($clone)->assertStatus(401);
    expect(Operation::query()->where('kind', InstanceWake::Kind)->sole()->status->value)->toBe('succeeded');
    Queue::assertNotPushed(StartAdmittedSwampRun::class, fn (StartAdmittedSwampRun $job): bool => $job->delay === null);
});

it('leaves a Node without the worker out of the fast path for ten minutes, then tries it again', function (): void {
    $clone = frozenClone();
    $state = wakeNode($clone, ['worker' => false]);

    activationPoll($clone)->assertStatus(401);
    expect(Cache::has(ContainerResume::missingScriptKey($clone->node_id)))->toBeTrue();

    // The next wake of any Instance on that Node goes straight to its workflow, with no Node call.
    Operation::query()->where('kind', InstanceWake::Kind)->update(['status' => 'failed', 'lock_key' => null]);
    $calls = count($state->calls);
    expect(app(InstanceWake::class)->start($clone, null, null)->state[InstanceWake::ResumeState] ?? null)->toBeNull()
        ->and(count($state->calls))->toBe($calls);

    $this->travel(ContainerResume::MissingScriptSeconds + 1)->seconds();
    expect(Cache::has(ContainerResume::missingScriptKey($clone->node_id)))->toBeFalse();
});
