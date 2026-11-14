<?php

declare(strict_types=1);

use App\Actions\GatewayReleases\WaitForGatewayReleaseIdleAction;
use App\Actions\Instances\StartInstanceCompleteAction;
use App\Domain\Certificates\LeafCertificateSigner;
use App\Domain\Extensions\ExtensionStore;
use App\Domain\GatewayReleases\GatewayReleaseLayout;
use App\Domain\Instances\DevelopmentInstanceCreateProjection;
use App\Domain\Instances\InstanceCompleteProjection;
use App\Domain\Instances\InstanceRuntimePowerState;
use App\Domain\Instances\InstanceState;
use App\Domain\Instances\NativeDevelopmentInstanceInput;
use App\Domain\Instances\NativeInstanceCompleteInput;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\OperationStatus;
use App\Domain\Operations\OperationStore;
use App\Domain\Operations\RoutePublicationHolds;
use App\Domain\Projects\ProjectType;
use App\Domain\Routes\NativeRouteAdmission;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\TaskVms\TaskVmModelRoute;
use App\Infrastructure\GatewayReleases\GatewayReleaseLock;
use App\Infrastructure\Swamp\NativeRunProgress;
use App\Jobs\Operations\PromoteRouteWrites;
use App\Models\Activity;
use App\Models\Instance;
use App\Models\InstanceRuntime;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Process;
use App\Models\ProcessDefinition;
use App\Models\Route;
use App\Support\ValidatedData;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Orbit\Extensions\Conn\Models\ConnEnvironment;

/*
 * A container clone's create ends when its thread is usable (docs/reference/development-containers.md#create): its terminal projection
 * reads the workspace baseline before its transaction, then records the T3 server and the baseline, keeps the clone `source_resolved` and
 * admits `instance.complete` in one transaction. Only the completion makes the clone, its Route and its Processes active.
 */

uses(LazilyRefreshDatabase::class);

require_once __DIR__.'/ContainerInstanceHelpers.php';

beforeEach(function (): void {
    Queue::fake();
    Http::preventStrayRequests();
    $swamp = Node::withoutEvents(fn (): Node => Node::query()->create([
        'name' => 'control', 'status' => 'active', 'platform' => 'linux', 'wireguard_ip' => '10.44.0.209', 'public_ssh_host' => '10.44.0.209', 'user' => 'orbit',
        'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'fixture-public-key',
    ]));
    $swamp->roles()->create(['role' => RoleName::Swamp, 'status' => 'active']);
    app(ExtensionStore::class)->set('conn', true);
    app()->instance(TaskVmModelRoute::class, new class implements TaskVmModelRoute
    {
        public function assertReady(): void {}

        public function origin(): string
        {
            return 'http://10.44.0.3:8318';
        }

        public function key(): string
        {
            return 'placeholder-model-key';
        }
    });
    $this->mock(LeafCertificateSigner::class)->shouldReceive('rootCertificate')->andReturn('root-certificate');
    $this->vault = swampVaultStandIn();
    $this->reader = workspaceReader();
});

/** A Project's container default with its seed and one development Process: by default a web-serving PHP Project. */
function handoverDefault(Node $node, ProjectType $type = ProjectType::LaravelApp, ?string $php = '8.5', bool $nested = false): Instance
{
    $node->update(['tld' => 'beast.test', ...($nested ? ['settings' => [...($node->settings ?? []), 'nested_fleet' => true]] : [])]);
    $project = containerDefaultProject($type, $php);
    if ($nested) {
        $project->forceFill(['nested_fleet' => true])->save();
    }
    ProcessDefinition::query()->create(['project_id' => $project->id, 'name' => 'worker', 'environments' => ['development'],
        'spec' => ['runtime' => 'systemd', 'command' => $php === null ? ['/usr/bin/env', 'node', 'worker.js'] : ['/usr/bin/env', 'php', 'artisan', 'queue:work']]]);
    // The default's own create ends active, with its Route and the seed snapshot of its commit.
    $seed = containerCreate($project, $node);
    $seed->forceFill(['status' => 'running', 'state' => ['swamp_run_id' => 'seed-run']])->save();
    app(DevelopmentInstanceCreateProjection::class)->apply($seed->refresh(), ['status' => 'succeeded', 'run_id' => 'seed-run', 'started_at' => now()->toIso8601String(),
        'finished_at' => now()->toIso8601String(), 'result' => ['instance_id' => $seed->target_id, 'ready' => true, 'commit' => str_repeat('c', 40)]]);

    // A nested-fleet default made before the inner volume records none, so its clone is admitted (docs/reference/development-containers.md#the-inner-volume).
    InstanceRuntime::query()->where('instance_id', $seed->target_id)->update(['inner_volume' => null]);

    return Instance::query()->findOrFail($seed->target_id);
}

/** The admitted create of a clone, bound to its run as the native start would. */
function handoverCreate(Instance $default, string $name = 'feature'): Operation
{
    $operation = containerCreate($default->project, $default->node, $name);
    $operation->forceFill(['status' => 'running', 'finished_at' => null, 'state' => ['swamp_run_id' => 'run-1']])->save();

    return $operation->refresh();
}

/** @return array<string, mixed> */
function handoverSucceeded(Operation $operation): array
{
    return ['status' => 'succeeded', 'run_id' => 'run-1', 'started_at' => now()->subSeconds(24)->toIso8601String(), 'finished_at' => now()->toIso8601String(),
        'result' => ['ready' => true, 'instance_id' => $operation->target_id, 'commit' => str_repeat('c', 40), 't3' => [
            'environment_id' => 'env-'.$operation->target_id, 'server_version' => '0.1.7', 'expires_at' => '2026-12-10T10:00:00.000Z',
            'gateway_session' => ['vault' => 'orbit-control-inputs', 'key' => 'nckrtl-instance-t3-model-register-session-gateway-1-token'],
            'swamp_session' => ['vault' => 'orbit-control-inputs', 'key' => 'nckrtl-instance-t3-model-register-session-swamp-1-token']]]];
}

function handoverCompletion(Operation $create): Operation
{
    return Operation::query()->findOrFail($create->refresh()->result['completion_operation_id']);
}

it('ends a clone\'s create at its thread: one transaction records T3 and the baseline, keeps it source_resolved and admits instance.complete', function (): void {
    $node = containerDefaultNode();
    $operation = handoverCreate(handoverDefault($node));
    $base = DB::transactionLevel();
    // Each write of the hand-over names the top-level transaction it ran in.
    $top = 0;
    $written = [];
    Event::listen(TransactionBeginning::class, function () use (&$top, $base): void {
        if (DB::transactionLevel() === $base + 1) {
            $top++;
        }
    });
    Operation::updated(function (Operation $changed) use (&$written, &$top, $operation): void {
        if ($changed->id === $operation->id && $changed->wasChanged('status')) {
            $written['create ended'] = $top;
        }
    });
    Operation::created(function (Operation $created) use (&$written, &$top): void {
        $written['admitted '.$created->kind] = $top;
    });
    InstanceRuntime::updated(function (InstanceRuntime $runtime) use (&$written, &$top): void {
        if ($runtime->wasChanged('workspace_baseline')) {
            $written['baseline'] = $top;
        }
    });
    ConnEnvironment::created(function () use (&$written, &$top): void {
        $written['T3 server'] = $top;
    });

    app(DevelopmentInstanceCreateProjection::class)->apply($operation, handoverSucceeded($operation));

    $operation->refresh();
    $instance = Instance::query()->findOrFail($operation->target_id);
    $runtime = InstanceRuntime::query()->where('instance_id', $instance->id)->firstOrFail();
    $completion = handoverCompletion($operation);
    expect($operation->status)->toBe(OperationStatus::Succeeded)
        ->and($operation->lock_key)->toBeNull()
        ->and($operation->result['completion_operation_id'])->toBe($completion->id)
        ->and($completion->kind)->toBe('instance.complete')
        ->and($completion->lock_key)->toBe(OperationStore::lockKey('instance', $instance->id))
        ->and($completion->input['create_operation_id'])->toBe($operation->id)
        ->and($completion->status->terminal())->toBeFalse()
        // The thread is usable, but the clone is not fully set up: only its completion makes it active.
        ->and($instance->status)->toBe(InstanceState::SourceResolved)
        ->and($instance->starting_commit)->toBe(str_repeat('c', 40))
        ->and($instance->first_setup_pending)->toBeTrue()
        ->and($runtime->power_state)->toBe(InstanceRuntimePowerState::Running)
        ->and(ConnEnvironment::query()->where('instance_id', $instance->id)->value('admin_session'))->toBe('gateway-session')
        ->and(Route::query()->findOrFail($operation->input['route_id'])->status->value)->toBe('pending')
        ->and(Process::query()->where('owner_id', $instance->id)->pluck('status')->all())->not->toContain(LifecycleStatus::Active)
        // Only hex object ids and names enter the baseline, and it is older than the create's end, so than any pairing.
        ->and($runtime->workspace_baseline)->toBe(['heads' => [str_repeat('a', 40)], 'stashes' => [], 'untracked' => ['notes.txt']])
        ->and($runtime->workspace_baseline_at->lessThanOrEqualTo($operation->finished_at))->toBeTrue();
    // One top-level transaction holds the end of the create, the T3 server, the baseline and the completion.
    expect(array_keys($written))->toEqualCanonicalizing(['create ended', 'T3 server', 'baseline', 'admitted instance.complete'])
        ->and(array_unique(array_values($written)))->toHaveCount(1);
    // No network call runs inside a transaction: the SSH read and every vault frame (the T3 session read, the completion's copies and its
    // start) run at the test's own level.
    expect($this->reader->calls)->toHaveCount(1)
        ->and(array_slice($this->reader->calls[0]['arguments'], 4, 3))->toBe(['record', 'orbit-instances', 'instance-'.$instance->id])
        ->and(array_unique(array_column($this->reader->calls, 'level')))->toBe([$base])
        ->and(array_unique(array_column($this->vault->frames, 'level')))->toBe([$base]);
});

it('admits a plain clone of a nested-fleet default with an inner volume: the copy records its own volume from the default\'s seed', function (): void {
    $node = containerDefaultNode();
    $default = handoverDefault($node, nested: true);
    InstanceRuntime::query()->where('instance_id', $default->id)->update(['inner_volume' => 'inner-'.$default->id]);

    $operation = handoverCreate($default);

    $container = ValidatedData::object(ValidatedData::object($operation->input['direct_provisioning'])['container']);
    $clone = ValidatedData::object($container['clone']);
    // docs/reference/development-containers.md#the-inner-volume: before this the create refused with instance.inner_volume_clone_unavailable.
    expect(InstanceRuntime::query()->where('instance_id', $operation->target_id)->sole()->inner_volume)->toBe('inner-'.$operation->target_id)
        ->and(ValidatedData::object($container['inner_volume']))->toMatchArray(['name' => 'inner-'.$operation->target_id, 'device' => 'inner-pool'])
        ->and($clone['inner_volume'])->toBe('inner-'.$default->id)
        ->and($clone['snapshot'])->toBe('seed-'.str_repeat('c', 40));
});

it('fails the create with instance.workspace_baseline_unavailable when the read fails, and keeps the clone for a retry', function (): void {
    $node = containerDefaultNode();
    $operation = handoverCreate(handoverDefault($node));
    $this->reader->answer = null;

    app(DevelopmentInstanceCreateProjection::class)->apply($operation, handoverSucceeded($operation));

    $operation->refresh();
    $instance = Instance::query()->findOrFail($operation->target_id);
    expect($operation->status)->toBe(OperationStatus::Failed)
        ->and($operation->error_code)->toBe('instance.workspace_baseline_unavailable')
        // Conn names the failed row from the Operation's failed step: `Register with Orbit`.
        ->and($operation->failed_step)->toBe('clone-register')
        ->and($operation->result['failed_step'])->toBe('register')
        ->and($operation->result)->not->toHaveKey('t3')
        ->and($operation->result)->not->toHaveKey('completion_operation_id')
        ->and($operation->result['recovery']['retry']['command'])->toBe('instance:create')
        ->and([$instance->status, $instance->failed_step, $instance->error_code])->toBe([InstanceState::SourceResolved, 'register', 'instance.workspace_baseline_unavailable'])
        ->and(ConnEnvironment::query()->where('instance_id', $instance->id)->exists())->toBeFalse()
        ->and(Operation::query()->where('kind', 'instance.complete')->exists())->toBeFalse()
        // The run ended without its container stop: the copy runs until a retry or a destroy.
        ->and($instance->runtime()->firstOrFail()->power_state)->toBe(InstanceRuntimePowerState::Running)
        ->and($instance->runtime()->firstOrFail()->workspace_baseline)->toBeNull();
});

/**
 * `NativeRouteAdmission::capture` is the one call of the completion's admission that the create's own transaction cannot make pass: it
 * reads fleet state (a Cluster's Router, the stored environment, an Ingress firewall) that can change between the create's admission and
 * its end. A stand-in at that call refuses as the real one does when a Cluster loses its Router, or fails as an unexpected error.
 */
function captureRefuses(Throwable $error): void
{
    app()->instance(NativeRouteAdmission::class, new class($error)
    {
        public function __construct(private Throwable $error) {}

        /** @param array<string, mixed> $input */
        public function capture(string $kind, Route $route, array $input, int $rootNodeId, mixed ...$options): never
        {
            throw $this->error;
        }
    });
}

it('refuses at the create\'s admission a Route that the completion could not capture, before anything is recorded', function (): void {
    $node = containerDefaultNode();
    $default = handoverDefault($node);
    $before = [Instance::query()->count(), Operation::query()->count(), Route::query()->count()];
    captureRefuses(new ResourceOperationException('cluster.router_required', 'The Cluster Route requires a recorded Router.', 409));

    expect(containerRefusal(fn () => containerCreate($default->project, $node, 'feature')))->toBe('cluster.router_required')
        ->and([Instance::query()->count(), Operation::query()->count(), Route::query()->count()])->toBe($before);
});

it('records a completion refused at the create\'s end as a failed instance.complete that the create names, with its alert and recovery', function (Throwable $error, string $code): void {
    $node = containerDefaultNode();
    $operation = handoverCreate(handoverDefault($node));
    // The Cluster lost its Router after the create's admission, or the capture failed in another way.
    captureRefuses($error);

    app(DevelopmentInstanceCreateProjection::class)->apply($operation, handoverSucceeded($operation));

    $operation->refresh();
    $instance = Instance::query()->findOrFail($operation->target_id);
    $completion = handoverCompletion($operation);
    expect($operation->status)->toBe(OperationStatus::Succeeded)
        ->and([$completion->kind, $completion->status, $completion->error_code, $completion->failed_step, $completion->lock_key])
        ->toBe(['instance.complete', OperationStatus::Failed, $code, 'complete', null])
        ->and($completion->result['recovery']['retry'])->toBe(['command' => 'instance:complete', 'instance_id' => $instance->id, 'new_idempotency_key' => true])
        ->and([$instance->status, $instance->failed_step, $instance->error_code])->toBe([InstanceState::SourceResolved, 'complete', $code])
        ->and(ConnEnvironment::query()->where('instance_id', $instance->id)->exists())->toBeTrue()
        ->and(Activity::query()->where('description', 'instance.operation_failed')->get()->pluck('properties.operation_id')->all())->toContain($completion->id);
})->with([
    'a refusal' => [new ResourceOperationException('cluster.router_required', 'The Cluster Route requires a recorded Router.', 409), 'cluster.router_required'],
    'an unexpected error' => [new RuntimeException('boom'), 'operation.admission_failed'],
]);

it('takes no deploy or rollback while a create or a completion is open: the release waits on both kinds', function (): void {
    expect(WaitForGatewayReleaseIdleAction::BlockingKindPatterns)->toContain('instance.create', 'instance.complete');
});

it('runs a non-PHP clone and a nested-fleet clone from create to active: the Club key in the create, the fleet in the completion', function (bool $nested): void {
    $node = containerDefaultNode();
    if ($nested) {
        $base = sys_get_temp_dir().'/orbit-handover-env-'.bin2hex(random_bytes(4));
        mkdir($base.'/shared', 0700, true);
        file_put_contents($base.'/shared/gateway.env', "APP_NAME=Orbit\nORBIT_NESTED_FLEET_CLUB_API_KEY=fake-nested-club-key-0123456789\n");
        chmod($base.'/shared/gateway.env', 0600);
        app()->instance(GatewayReleaseLayout::class, new GatewayReleaseLayout($base.'/current/apps/gateway'));
        app()->instance(GatewayReleaseLock::class, new GatewayReleaseLock($base.'/release.lock'));
    }
    $create = handoverCreate(handoverDefault($node, ProjectType::NodePackage, null, $nested));
    $run = app(DevelopmentInstanceCreateProjection::class)->input($create);

    app(DevelopmentInstanceCreateProjection::class)->apply($create, handoverSucceeded($create));
    $completion = handoverCompletion($create);
    $complete = app(NativeInstanceCompleteInput::class)->input($completion);
    $completion->forceFill(['status' => 'running', 'state' => [...($completion->state ?? []), 'swamp_run_id' => 'complete-run']])->save();
    app(InstanceCompleteProjection::class)->apply($completion->refresh(), ['status' => 'succeeded', 'run_id' => 'complete-run',
        'started_at' => now()->toIso8601String(), 'finished_at' => now()->toIso8601String(), 'result' => ['instance_id' => $create->target_id, 'ready' => true]]);

    $instance = Instance::query()->findOrFail($create->target_id);
    expect($run['intent'])->not->toHaveKey('fleet')
        ->and($run['intent']['container']['club_key']['available'] ?? false)->toBe($nested)
        ->and(array_key_exists('club_key', $run['execution_secrets']))->toBe($nested)
        ->and(array_key_exists('fleet', $complete['intent']))->toBe($nested)
        ->and([$create->refresh()->status, $completion->refresh()->status, $instance->status])
        ->toBe([OperationStatus::Succeeded, OperationStatus::Succeeded, InstanceState::Active]);
})->with(['non-PHP' => false, 'nested fleet' => true]);

it('resumes a clone whose completion failed: the baseline stays, and the create admits a new completion', function (): void {
    $node = containerDefaultNode();
    $default = handoverDefault($node);
    $first = handoverCreate($default);
    app(DevelopmentInstanceCreateProjection::class)->apply($first, handoverSucceeded($first));
    $failed = handoverCompletion($first);
    $failed->forceFill(['status' => 'running', 'state' => [...($failed->state ?? []), 'swamp_run_id' => 'complete-run']])->save();
    app(InstanceCompleteProjection::class)->apply($failed->refresh(), ['status' => 'failed', 'run_id' => 'complete-run', 'error_code' => 'instance.create_workspace_failed',
        'started_at' => now()->toIso8601String(), 'finished_at' => now()->toIso8601String(),
        'result' => ['instance_id' => $first->target_id, 'ready' => false, 'failed_step' => 'workspace', 'error_code' => 'instance.create_workspace_failed']]);
    $baseline = InstanceRuntime::query()->where('instance_id', $first->target_id)->firstOrFail()->workspace_baseline;
    $this->reader->answer = (string) json_encode(['heads' => [str_repeat('f', 40)], 'stashes' => [], 'untracked' => []]);

    $retry = handoverCreate($default);
    app(DevelopmentInstanceCreateProjection::class)->apply($retry, handoverSucceeded($retry));

    $instance = Instance::query()->findOrFail($first->target_id);
    $again = handoverCompletion($retry);
    expect($retry->target_id)->toBe($first->target_id)
        ->and($retry->refresh()->status)->toBe(OperationStatus::Succeeded)
        ->and($again->id)->not->toBe($failed->id)
        ->and($again->kind)->toBe('instance.complete')
        ->and([$instance->status, $instance->failed_step, $instance->error_code])->toBe([InstanceState::SourceResolved, null, null])
        // A resumed create never reads the baseline again: the user may have worked in the thread since.
        ->and($instance->runtime()->firstOrFail()->workspace_baseline)->toBe($baseline)
        ->and($this->reader->calls)->toHaveCount(1);
});

it('runs the chain create, completion, active: the completion turns the Route, the Processes and then the clone active', function (): void {
    $node = containerDefaultNode();
    $create = handoverCreate(handoverDefault($node));
    app(DevelopmentInstanceCreateProjection::class)->apply($create, handoverSucceeded($create));
    $completion = handoverCompletion($create);
    $completion->forceFill(['status' => 'running', 'state' => [...($completion->state ?? []), 'swamp_run_id' => 'complete-run']])->save();

    app(InstanceCompleteProjection::class)->apply($completion->refresh(), ['status' => 'succeeded', 'run_id' => 'complete-run',
        'started_at' => now()->toIso8601String(), 'finished_at' => now()->toIso8601String(),
        'result' => ['instance_id' => $create->target_id, 'ready' => true]]);

    $instance = Instance::query()->findOrFail($create->target_id);
    expect($completion->refresh()->status)->toBe(OperationStatus::Succeeded)
        ->and([$instance->status, $instance->provisioning_step, $instance->first_setup_pending])->toBe([InstanceState::Active, 'active', false])
        ->and(Route::query()->findOrFail($create->input['route_id'])->status->value)->toBe('active')
        ->and(Process::query()->where('owner_id', $instance->id)->pluck('status')->unique()->all())->toBe([LifecycleStatus::Active]);
});

/** The run's progress event of a root step of the create, as the Swamp subscriber hands it over. */
function handoverStep(Operation $operation, string $job, string $step, string $kind, int $seq): void
{
    app(NativeRunProgress::class)->apply($operation->id, 'run-1', ['kind' => $kind, 'jobId' => $job, 'stepId' => $step, 'seq' => $seq,
        ...($kind === 'step_started' ? ['startedAt' => now()->toIso8601String()] : ['completedAt' => now()->toIso8601String()])]);
}

/** @return array<string, mixed> */
function handoverPublished(Operation $operation, string $publication): array
{
    $data = handoverSucceeded($operation);
    $data['result']['route_publication'] = $publication;

    return $data;
}

it('lets exactly one of two clone creates admitted together take the free Route Nodes; the other never waits and leaves its address to its completion', function (): void {
    $node = containerDefaultNode();
    $default = handoverDefault($node);

    $first = containerCreate($default->project, $node, 'first');
    $second = containerCreate($default->project, $node, 'second');

    // The hold table's key is the Node: the first insert takes it, the second finds it held and drops its publication.
    $holders = DB::table('route_publication_holds')->pluck('operation_id', 'node_id')->all();
    expect($holders)->toBe([$node->id => $first->id])
        ->and([$first->refresh()->status, $second->refresh()->status])->not->toContain(OperationStatus::Waiting)
        ->and($first->input)->toHaveKeys(['route_create', 'route_lifecycle'])
        ->and(RoutePublicationHolds::nodes($first))->toBe([$node->id])
        ->and($second->input['route_id'])->not->toBeNull()
        ->and($second->input)->not->toHaveKeys(['route_create', 'route_lifecycle', 'certificates', 'execution_secrets'])
        ->and($second->input['direct_provisioning']['route'])->toBeNull()
        ->and($second->state)->not->toHaveKey('hold_nodes')
        ->and(RoutePublicationHolds::nodes($second))->toBe([]);

    $run = app(NativeDevelopmentInstanceInput::class)->create($first);
    $deferred = app(NativeDevelopmentInstanceInput::class)->create($second);
    expect($run['intent']['route'])->toBe($first->input['route_create'])
        ->and($run['intent']['route_mutations'])->not->toBeNull()
        ->and($deferred['intent']['route'])->toBeNull()
        ->and($deferred['intent']['route_mutations'])->toBeNull()
        ->and([$run['intent']['database'], $run['intent']['server_database'], $run['intent']['server_valkey']])->toBe([null, null, null])
        ->and($run['intent'])->not->toHaveKey('fleet')
        ->and(collect($this->vault->frames)->pluck('key')->filter(fn (string $key): bool => str_starts_with($key, 'database-create-'))->all())->toBe([]);
});

it('frees the Route Nodes when clone-route succeeds, with the Route active in that transaction, so the next create renders its site', function (): void {
    Queue::fake();
    $node = containerDefaultNode();
    $default = handoverDefault($node);
    $create = handoverCreate($default, 'first');
    $route = Route::query()->findOrFail($create->input['route_id']);
    expect(DB::table('route_publication_holds')->pluck('operation_id')->all())->toBe([$create->id]);

    // A step of the clone job or the CSR frees nothing; only clone-route's success does.
    handoverStep($create, 'publication', 'clone-route-csr', 'step_completed', 1);
    handoverStep($create, 'clone', 'clone-container', 'step_completed', 2);
    handoverStep($create, 'publication', 'clone-route', 'step_started', 3);
    expect(DB::table('route_publication_holds')->count())->toBe(1)
        ->and($route->refresh()->sites_published)->toBeFalse();
    $released = null;
    DB::listen(function ($query) use (&$released): void {
        if (str_contains($query->sql, 'delete from "route_publication_holds"')) {
            $released = DB::transactionLevel();
        }
    });
    handoverStep($create, 'publication', 'clone-route', 'step_completed', 4);

    expect([$route->refresh()->status->value, $route->sites_published])->toBe(['active', true])
        ->and(DB::table('route_publication_holds')->count())->toBe(0)
        ->and($released)->toBeGreaterThan(0)
        ->and($create->refresh()->status->terminal())->toBeFalse();
    Queue::assertPushed(PromoteRouteWrites::class);

    // The next clone create on the Node takes the free Nodes, and its whole-file render keeps the first clone's site.
    $next = containerCreate($default->project, $node, 'second');
    expect(RoutePublicationHolds::nodes($next))->toBe([$node->id])
        ->and(json_encode($next->input['route_create']['caddy']))->toContain($route->domain);

    // The create ends at its registration, published; its completion captures no Route and holds no Node.
    app(DevelopmentInstanceCreateProjection::class)->apply($create->refresh(), handoverPublished($create, 'published'));
    $completion = handoverCompletion($create);
    expect($create->refresh()->result['route_publication'])->toBe('published')
        ->and($completion->input)->not->toHaveKeys(['route_create', 'route_lifecycle'])
        ->and(RoutePublicationHolds::nodes($completion))->toBe([])
        ->and($completion->status)->not->toBe(OperationStatus::Waiting)
        ->and(app(NativeInstanceCompleteInput::class)->input($completion)['intent']['route'])->toBeNull();
});

it('activates a published Route at the create\'s end when the progress event was lost, and frees the Nodes in that transaction', function (): void {
    $node = containerDefaultNode();
    $create = handoverCreate(handoverDefault($node));

    app(DevelopmentInstanceCreateProjection::class)->apply($create, handoverPublished($create, 'published'));

    $route = Route::query()->findOrFail($create->input['route_id']);
    expect([$route->status->value, $route->sites_published])->toBe(['active', true])
        ->and(DB::table('route_publication_holds')->where('operation_id', $create->id)->exists())->toBeFalse();
});

it('leaves the address to the completion when another Route write holds the Node: the create says deferred, the completion publishes with today\'s names', function (): void {
    $node = containerDefaultNode();
    $default = handoverDefault($node);
    // Another Route write holds the clone's Node.
    DB::table('route_publication_holds')->insert(['node_id' => $node->id, 'operation_id' => 999999, 'created_at' => now(), 'lease_until' => null]);

    $create = handoverCreate($default);
    expect($create->status)->not->toBe(OperationStatus::Waiting)
        ->and($create->input)->not->toHaveKey('route_create')
        ->and(app(NativeDevelopmentInstanceInput::class)->create($create)['intent']['route'])->toBeNull();
    DB::table('route_publication_holds')->where('operation_id', 999999)->delete();

    app(DevelopmentInstanceCreateProjection::class)->apply($create, handoverSucceeded($create));
    $completion = handoverCompletion($create);
    $route = Route::query()->findOrFail($create->input['route_id']);
    expect($create->refresh()->result['route_publication'])->toBe('deferred')
        ->and([$route->status->value, $route->sites_published, $route->failed_step])->toBe(['pending', false, null])
        // The completion captures the Route and holds its Node for its whole run, as before.
        ->and($completion->input)->toHaveKey('route_create')
        ->and(RoutePublicationHolds::nodes($completion))->toBe([$node->id])
        ->and(app(NativeInstanceCompleteInput::class)->input($completion)['intent']['route'])->toBe($completion->input['route_create']);
});

it('keeps a create whose publication failed: the Route records it, the completion publishes again, and a second failure keeps today\'s code and retry', function (): void {
    $node = containerDefaultNode();
    $create = handoverCreate(handoverDefault($node));
    handoverStep($create, 'publication', 'clone-route', 'step_failed', 1);
    // A failed clone-route frees nothing early; the create's terminal state does.
    expect(DB::table('route_publication_holds')->where('operation_id', $create->id)->exists())->toBeTrue();

    $failed = handoverPublished($create, 'failed');
    $failed['result']['warnings'] = [['code' => 'instance.create_route_failed', 'step' => 'route']];
    app(DevelopmentInstanceCreateProjection::class)->apply($create->refresh(), $failed);

    $route = Route::query()->findOrFail($create->input['route_id']);
    $completion = handoverCompletion($create);
    expect($create->refresh()->status)->toBe(OperationStatus::Succeeded)
        ->and($create->result['route_publication'])->toBe('failed')
        ->and([$route->sites_published, $route->failed_step, $route->error_code])->toBe([false, 'native-create', 'instance.create_route_failed'])
        ->and(DB::table('route_publication_holds')->where('operation_id', $create->id)->exists())->toBeFalse()
        ->and($completion->input)->toHaveKey('route_create')
        ->and(RoutePublicationHolds::nodes($completion))->toBe([$node->id]);

    // The completion's own publication fails too: failed_step route, instance.create_route_failed, and the retry is instance:complete.
    $completion->forceFill(['status' => 'running', 'state' => [...($completion->state ?? []), 'swamp_run_id' => 'complete-run']])->save();
    app(InstanceCompleteProjection::class)->apply($completion->refresh(), ['status' => 'failed', 'run_id' => 'complete-run', 'error_code' => 'instance.create_route_failed',
        'started_at' => now()->toIso8601String(), 'finished_at' => now()->toIso8601String(),
        'result' => ['instance_id' => $create->target_id, 'ready' => false, 'failed_step' => 'route', 'error_code' => 'instance.create_route_failed']]);
    expect($completion->refresh()->result['recovery']['retry']['command'])->toBe('instance:complete')
        ->and($completion->result['failed_step'])->toBe('route');

    // The retry captures the Route again, because it is still not published.
    $retry = app(StartInstanceCompleteAction::class)->execute(Instance::query()->findOrFail($create->target_id));
    expect($retry->input)->toHaveKey('route_create')
        ->and(RoutePublicationHolds::nodes($retry))->toBe([$node->id]);
});

it('frees the Nodes of a create cancelled before clone-route ended and leaves its Route unpublished', function (): void {
    $node = containerDefaultNode();
    $create = handoverCreate(handoverDefault($node));
    handoverStep($create, 'publication', 'clone-route', 'step_started', 1);

    app(DevelopmentInstanceCreateProjection::class)->apply($create, ['status' => 'cancelled', 'run_id' => 'run-1', 'error_code' => 'operation.cancelled',
        'started_at' => now()->toIso8601String(), 'finished_at' => now()->toIso8601String(),
        'result' => ['instance_id' => $create->target_id, 'ready' => false, 'failed_step' => 'clone', 'error_code' => 'instance.create_clone_failed', 'route_publication' => 'failed']]);

    $route = Route::query()->findOrFail($create->input['route_id']);
    expect($create->refresh()->status)->toBe(OperationStatus::Cancelled)
        ->and(DB::table('route_publication_holds')->count())->toBe(0)
        ->and($route->sites_published)->toBeFalse()
        ->and($create->result['recovery']['retry']['command'])->toBe('instance:create')
        ->and(Operation::query()->where('kind', 'instance.complete')->exists())->toBeFalse();
});

it('keeps an address the create published when the completion fails at its check; a retry that succeeds leaves it as it is', function (): void {
    $node = containerDefaultNode();
    $create = handoverCreate(handoverDefault($node));
    app(DevelopmentInstanceCreateProjection::class)->apply($create, handoverPublished($create, 'published'));
    $completion = handoverCompletion($create);
    $completion->forceFill(['status' => 'running', 'state' => [...($completion->state ?? []), 'swamp_run_id' => 'complete-run']])->save();
    app(InstanceCompleteProjection::class)->apply($completion->refresh(), ['status' => 'failed', 'run_id' => 'complete-run', 'error_code' => 'instance.container_process_inactive',
        'started_at' => now()->toIso8601String(), 'finished_at' => now()->toIso8601String(),
        'result' => ['instance_id' => $create->target_id, 'ready' => false, 'failed_step' => 'check', 'error_code' => 'instance.container_process_inactive']]);
    $route = Route::query()->findOrFail($create->input['route_id']);
    expect([$route->refresh()->status->value, $route->sites_published])->toBe(['active', true]);

    $retry = app(StartInstanceCompleteAction::class)->execute(Instance::query()->findOrFail($create->target_id));
    expect($retry->input)->not->toHaveKey('route_create')
        ->and(RoutePublicationHolds::nodes($retry))->toBe([]);
    $retry->forceFill(['status' => 'running', 'state' => [...($retry->state ?? []), 'swamp_run_id' => 'retry-run']])->save();
    $before = $route->refresh()->updated_at;
    app(InstanceCompleteProjection::class)->apply($retry->refresh(), ['status' => 'succeeded', 'run_id' => 'retry-run',
        'started_at' => now()->toIso8601String(), 'finished_at' => now()->toIso8601String(), 'result' => ['instance_id' => $create->target_id, 'ready' => true]]);
    expect(Instance::query()->findOrFail($create->target_id)->status)->toBe(InstanceState::Active)
        ->and([$route->refresh()->status->value, $route->sites_published, $route->updated_at->equalTo($before)])->toBe(['active', true, true]);
});

it('gives a clone without a Route and a task copy no publication and no hold', function (): void {
    $node = containerDefaultNode();
    // A Project that serves no web pages has no Route: `requiresRoute()` is false and the admission captures nothing.
    $create = handoverCreate(handoverDefault($node, ProjectType::NodePackage, null));
    expect($create->input['route_id'])->toBeNull()
        ->and($create->input)->not->toHaveKey('route_create')
        ->and(RoutePublicationHolds::nodes($create))->toBe([])
        ->and(app(NativeDevelopmentInstanceInput::class)->create($create)['intent']['route'])->toBeNull();
    app(DevelopmentInstanceCreateProjection::class)->apply($create, handoverSucceeded($create));
    expect($create->refresh()->result)->not->toHaveKey('route_publication');
});

it('hands the completion the clone\'s database plan from the create it admitted', function (): void {
    $node = containerDefaultNode();
    $create = handoverCreate(handoverDefault($node));
    app(DevelopmentInstanceCreateProjection::class)->apply($create, handoverSucceeded($create));
    $completion = handoverCompletion($create);

    $run = app(NativeInstanceCompleteInput::class)->input($completion);

    expect($completion->input['direct_provisioning']['database'])->toBe($create->input['direct_provisioning']['database'])
        ->and($run['intent']['route'])->toBe($completion->input['route_create'])
        ->and($run['execution_secrets']['process_environments']['key'])->toBe('instance-complete-processes-'.$completion->id);
});
