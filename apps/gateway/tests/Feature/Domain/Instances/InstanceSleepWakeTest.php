<?php

declare(strict_types=1);

use App\Actions\GatewayReleases\WaitForGatewayReleaseIdleAction;
use App\Actions\Operations\StartOperationAction;
use App\Domain\Instances\InstanceContainer;
use App\Domain\Instances\InstanceLifecycleContribution;
use App\Domain\Instances\InstanceLifecycleContributions;
use App\Domain\Instances\InstanceRuntimePowerState;
use App\Domain\Instances\InstanceThreadContribution;
use App\Domain\Instances\InstanceThreadContributions;
use App\Domain\Instances\Power\ContainerResume;
use App\Domain\Instances\Power\InstanceSleep;
use App\Domain\Instances\Power\InstanceWake;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\DirectOperation;
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
use App\Models\Node;
use App\Models\Operation;
use App\Models\Route;
use App\Models\Task;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
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

/** A default does not sleep in this part of the plan. */
it('refuses to put a default Instance to sleep', function (): void {
    sleepingClone();
    $default = Instance::query()->where('name', 'default')->firstOrFail();

    expect(fn () => app(InstanceSleep::class)->start($default, null, null))->toThrow(
        fn (ResourceOperationException $error) => expect($error->errorCode)->toBe('instance.sleep_unsupported'));
});

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

it('refuses a sleep that the Instance does not allow, before any run', function (string $case, string $code): void {
    $clone = sleepingClone(taskOwned: $case === 'task');
    match ($case) {
        'checkout' => $clone->runtime()->delete(),
        'nested' => $clone->project->forceFill(['nested_fleet' => true])->save(),
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
    ['checkout', 'operation.kind_unavailable'], ['nested', 'instance.sleep_unsupported'],
    ['production', 'instance.sleep_unsupported'], ['lifecycle', 'instance.lifecycle_busy'],
    ['task', 'instance.lifecycle_busy'], ['instance.deploy', 'operation.target_busy'], ['instance.wake', 'operation.target_busy'],
]);

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

it('wakes a default too, but never a nested-fleet Instance', function (): void {
    $clone = sleepingClone();
    $default = Instance::query()->where('name', 'default')->firstOrFail();

    expect(app(InstanceWake::class)->start($default, null, null)->kind)->toBe('instance.wake');

    $clone->project->forceFill(['nested_fleet' => true])->save();
    expect(fn () => app(InstanceWake::class)->start($clone->refresh(), null, null))->toThrow(
        fn (ResourceOperationException $error) => expect($error->errorCode)->toBe('instance.sleep_unsupported'));
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

    // Removing the one inserted block gives back the site as it was rendered before: the proxy, its order and its options stay.
    $start = strpos($after, '@orbit_probe header');
    $end = strpos($after, 'reverse_proxy', $start);
    expect($start)->not->toBeFalse()->and($end)->not->toBeFalse();
    $lineStart = strrpos(substr($after, 0, $start), PHP_EOL) + 1;
    $lineEnd = strrpos(substr($after, 0, $end), PHP_EOL) + 1;
    expect(substr($after, 0, $lineStart).substr($after, $lineEnd))->toBe($before);
});

it('gives a production or checkout site no asleep check', function (): void {
    $site = new DevelopmentSite(nodeId: 1, nodeAddress: '10.44.0.7', scope: 'app-instance-9', checkoutPath: '', documentRoot: '',
        phpVersion: null, domain: 'x.test', localHttpUpstream: '10.253.0.2:8080');

    expect(app(DevelopmentCaddyConfigRenderer::class)->render(collect([$site])))->not->toContain('orbit_asleep');
});

/**
 * The Instance's Node for the fast wake: the frozen agent's `hibernation.marker` writes the wake request and `hibernation.times`
 * reads the request folder, while the app-dev role's script (when the Node has it) takes the request at the first read.
 *
 * @param  array{script?: bool, status?: string, marker?: bool, web?: bool, fails?: ?string}  $node
 */
function wakeNode(Instance $clone, array $node = []): object
{
    $state = (object) [...['script' => true, 'status' => 'Frozen', 'marker' => true, 'web' => true, 'fails' => null, 'onRead' => null], ...$node,
        'files' => [], 'calls' => []];
    $marker = InstanceWake::markerPath($clone);
    if ($state->marker) {
        $state->files[$marker] = time();
    }
    if ($state->script) {
        $state->files[ContainerResume::ScriptPath] = time();
    }
    $agents = Mockery::mock(AgentCommandRunner::class);
    $agents->shouldReceive('run')->andReturnUsing(function (Node $node, string $command, array $input = []) use ($state, $clone, $marker): array {
        app(AgentContract::class)->command($command);
        $state->calls[] = $command.($command === 'hibernation.marker' ? ':'.$input['operation'] : '');
        if ($state->fails === $command) {
            throw new AgentRunnerFailure($command, AgentRunnerFailure::Timeout, 'The agent call timed out.');
        }
        $request = ContainerResume::requestPath($clone->id);
        if ($command === 'hibernation.marker') {
            expect($input['path'])->toBe($request);
            if ($input['operation'] === 'mark') {
                expect($input['marker_directory'])->toBe('/dev/shm/orbit/hibernation')->and($input['log_directory'])->toBe('/data/caddy/orbit/hibernation');
                $state->files[$request] = time();
            } else {
                unset($state->files[$request]);
            }

            return [];
        }
        expect($command)->toBe('hibernation.times');
        if ($state->onRead !== null) {
            ($state->onRead)();
        }
        if ($state->script && isset($state->files[$request])) {
            unset($state->files[$request]);
            if ($state->status === 'Frozen' && isset($state->files[$marker])) {
                $state->status = 'Running';
                if ($state->web) {
                    unset($state->files[$marker]);
                    $state->files[ContainerResume::requestPath($clone->id, 'resumed')] = time();
                }
            }
        }

        return ['times' => collect($input['paths'])->mapWithKeys(fn (string $path): array => [$path => $state->files[$path] ?? null])->all()];
    });
    app()->instance(AgentCommandRunner::class, $agents);

    return $state;
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

it('wakes a frozen container through the Node in the same Operation and sends the visitor back to the same request', function (): void {
    $clone = frozenClone();
    $node = wakeNode($clone);

    activationPoll($clone, '/dashboard?tab=1')->assertStatus(307)->assertHeader('Location', '/dashboard?tab=1')
        ->assertHeader('Cache-Control', 'no-store, private');

    $wake = Operation::query()->where('kind', InstanceWake::Kind)->sole();
    expect($wake->status->value)->toBe('succeeded')
        ->and($wake->result)->toMatchArray(['power_state' => 'running', 'wake' => 'resumed', 'marker_found' => true])
        ->and($wake->result)->toHaveKeys(['trigger_seconds', 'confirm_seconds'])
        ->and($wake->lock_key)->toBeNull()
        ->and($wake->triggered_by)->toBe('node:'.$clone->node_id)
        ->and($wake->state[InstanceWake::ResumeState])->toBe('resumed')
        ->and($clone->runtime()->firstOrFail()->power_state)->toBe(InstanceRuntimePowerState::Running)
        ->and($node->calls)->toBe(['hibernation.marker:mark', 'hibernation.times']);
    // Only the safety net is queued, well after the bound: it starts nothing for an ended Operation.
    Queue::assertPushed(StartAdmittedSwampRun::class, 1);
    Queue::assertPushed(StartAdmittedSwampRun::class, fn (StartAdmittedSwampRun $job): bool => $job->operationId === $wake->id
        && $job->delay !== null && now()->diffInSeconds($job->delay) >= InstanceWake::SafetyNetSeconds - 1);
});

it('answers the wake command with the ended Operation when the Node resumed the container', function (): void {
    $clone = frozenClone();
    wakeNode($clone);

    $operation = app(InstanceWake::class)->start($clone, 'node:5', null);

    expect($operation->status->value)->toBe('succeeded')->and($operation->result['wake'])->toBe('resumed');
    Queue::assertPushed(StartAdmittedSwampRun::class, fn (StartAdmittedSwampRun $job): bool => $job->delay !== null);
    Queue::assertNotPushed(StartAdmittedSwampRun::class, fn (StartAdmittedSwampRun $job): bool => $job->delay === null);
});

it('runs the wake workflow in the same Operation for every other state, removes an untaken request, and keeps the progress page', function (array $node, string $sleep, array $calls): void {
    $clone = frozenClone($sleep);
    $state = wakeNode($clone, $node);

    activationPoll($clone, '/dashboard')->assertStatus(401)->assertHeader('X-Orbit-Runtime-Activation-State', 'pending');

    $wake = Operation::query()->where('kind', InstanceWake::Kind)->sole();
    expect($wake->status->value)->toBe('queued')
        ->and($wake->state[InstanceWake::ResumeState] ?? null)->toBe($calls === [] ? null : 'workflow')
        ->and($state->calls)->toBe($calls)
        ->and($state->files)->not->toHaveKey(ContainerResume::requestPath($clone->id));
    Queue::assertPushed(StartAdmittedSwampRun::class, fn (StartAdmittedSwampRun $job): bool => $job->delay === null);
})->with([
    'stopped and marker: the sleep fell back to a stop' => [[], 'stopped', []],
    'stopped and marker, found by the Node' => [['status' => 'Stopped'], 'frozen', ['hibernation.marker:mark', 'hibernation.times', 'hibernation.marker:remove']],
    'running and marker after a reboot' => [['status' => 'Running'], 'frozen', ['hibernation.marker:mark', 'hibernation.times', 'hibernation.marker:remove']],
    'frozen without its marker' => [['marker' => false], 'frozen', ['hibernation.marker:mark', 'hibernation.times', 'hibernation.marker:remove']],
    'FrankenPHP silent after the resume' => [['web' => false], 'frozen', ['hibernation.marker:mark', 'hibernation.times', 'hibernation.marker:remove']],
    'a Node without the script' => [['script' => false], 'frozen', ['hibernation.marker:mark', 'hibernation.times', 'hibernation.marker:remove']],
    'the request call fails' => [['fails' => 'hibernation.marker'], 'frozen', ['hibernation.marker:mark', 'hibernation.marker:remove']],
    'the read call fails' => [['fails' => 'hibernation.times'], 'frozen', ['hibernation.marker:mark', 'hibernation.times', 'hibernation.marker:remove']],
]);

it('never calls the Node for a running Instance without its marker', function (): void {
    $clone = sleepingClone();
    $state = wakeNode($clone, ['status' => 'Running', 'marker' => false]);

    expect(app(InstanceWake::class)->start($clone, null, null)->status->value)->toBe('queued')
        ->and($state->calls)->toBe([]);
});

it('lets a second visit during the resume join the wake and get the app too', function (): void {
    $clone = frozenClone();
    $state = wakeNode($clone);
    // The first visit admitted the wake and its Node call runs; the second visit finds it open.
    $first = app(StartOperationAction::class)->execute(InstanceWake::Kind, 'instance', $clone->id, $clone->node_id,
        ['container' => [], 'marker' => InstanceWake::markerPath($clone)], 'node:1', null, lockKey: OperationStore::lockKey('instance', $clone->id), start: false);
    $first->forceFill(['state' => [InstanceWake::ResumeState => 'node']])->save();
    Sleep::whenFakingSleep(function () use ($first): void {
        $first->refresh()->forceFill(['status' => 'succeeded', 'lock_key' => null, 'state' => [InstanceWake::ResumeState => 'resumed']])->save();
    });

    activationPoll($clone, '/second')->assertStatus(307)->assertHeader('Location', '/second');

    expect(Operation::query()->where('kind', InstanceWake::Kind)->count())->toBe(1)->and($state->calls)->toBe([]);
});

it('gives a joining visit the progress page when the joined wake falls back to its workflow', function (): void {
    $clone = frozenClone();
    wakeNode($clone);
    $first = app(StartOperationAction::class)->execute(InstanceWake::Kind, 'instance', $clone->id, $clone->node_id,
        ['container' => [], 'marker' => InstanceWake::markerPath($clone)], 'node:1', null, lockKey: OperationStore::lockKey('instance', $clone->id), start: false);
    $first->forceFill(['state' => [InstanceWake::ResumeState => 'workflow']])->save();

    activationPoll($clone)->assertStatus(401);
});

it('sends the visitor back only to a path of its own site', function (string $forwarded, string $location): void {
    $clone = frozenClone();
    wakeNode($clone);

    activationPoll($clone, $forwarded)->assertStatus(307)->assertHeader('Location', $location);
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

    activationPoll($clone)->assertStatus(307);
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

it('still sends the visitor to the app when an extension fails after the Node resume', function (): void {
    $clone = frozenClone();
    wakeNode($clone);
    $contribution = Mockery::mock(InstanceLifecycleContribution::class)->shouldIgnoreMissing();
    $contribution->shouldReceive('projectWake')->andThrow(new RuntimeException('extension down'));
    app()->instance(InstanceLifecycleContributions::class, new InstanceLifecycleContributions([$contribution]));

    activationPoll($clone)->assertStatus(307);
    expect(Operation::query()->where('kind', InstanceWake::Kind)->sole()->status->value)->toBe('succeeded');
    Queue::assertNotPushed(StartAdmittedSwampRun::class, fn (StartAdmittedSwampRun $job): bool => $job->delay === null);
});

it('leaves a Node without the script out of the fast path for ten minutes, then tries it again', function (): void {
    $clone = frozenClone();
    $state = wakeNode($clone, ['script' => false]);

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
