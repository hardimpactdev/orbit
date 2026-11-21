<?php

declare(strict_types=1);

use App\Actions\GatewayReleases\WaitForGatewayReleaseIdleAction;
use App\Actions\Instances\StartDevelopmentInstanceCreateAction;
use App\Actions\Instances\StartInstanceCompleteAction;
use App\Actions\Instances\StartInstanceDestroyAction;
use App\Data\Instances\CreateInstanceData;
use App\Data\Instances\InstanceData;
use App\Domain\Certificates\LeafCertificateSigner;
use App\Domain\Engine\Engine;
use App\Domain\Engine\EngineRun;
use App\Domain\Engine\MachineBus;
use App\Domain\Engine\StepSpec;
use App\Domain\Executor\SignedExecutorOrder;
use App\Domain\Extensions\ExtensionStore;
use App\Domain\GatewayReleases\GatewayReleaseLayout;
use App\Domain\Instances\ClonePublication;
use App\Domain\Instances\ContainerModelRoute;
use App\Domain\Instances\DevelopmentInstanceCreateProjection;
use App\Domain\Instances\InstanceCompleteEngineFlow;
use App\Domain\Instances\InstanceCompleteProjection;
use App\Domain\Instances\InstanceCreateEngineFlow;
use App\Domain\Instances\InstanceFleetEngineFlow;
use App\Domain\Instances\InstanceFleetOperation;
use App\Domain\Instances\InstancePlacement;
use App\Domain\Instances\InstanceRuntimePowerState;
use App\Domain\Instances\InstanceState;
use App\Domain\Instances\NativeDevelopmentInstanceInput;
use App\Domain\Instances\NativeInstanceCompleteInput;
use App\Domain\Instances\Power\InstanceSleep;
use App\Domain\Instances\Power\InstanceStart;
use App\Domain\Instances\Power\InstanceWake;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\OperationIdempotency;
use App\Domain\Operations\OperationStatus;
use App\Domain\Operations\OperationStore;
use App\Domain\Operations\RoutePublicationHolds;
use App\Domain\Projects\ProjectType;
use App\Domain\Routes\NativeRouteAdmission;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\TaskEnd;
use App\Infrastructure\AppDev\DevelopmentSiteRepository;
use App\Infrastructure\GatewayReleases\GatewayReleaseLock;
use App\Infrastructure\Swamp\NativeRunProgress;
use App\Infrastructure\Swamp\StartSwampRun;
use App\Infrastructure\Swamp\SwampDispatch;
use App\Jobs\Operations\PromoteRouteWrites;
use App\Models\Activity;
use App\Models\Instance;
use App\Models\InstanceRuntime;
use App\Models\Node;
use App\Models\Operation;
use App\Models\OperationEngineStep;
use App\Models\Process;
use App\Models\ProcessDefinition;
use App\Models\Route;
use App\Support\ValidatedData;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
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
    app()->instance(ContainerModelRoute::class, new class implements ContainerModelRoute
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
    // The start keeps what the admission recorded, such as the reason of a deferred publication.
    $operation->forceFill(['status' => 'running', 'finished_at' => null, 'state' => [...($operation->state ?? []), 'swamp_run_id' => 'run-1']])->save();

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
        // Only hex object ids and names enter the baseline. It is recorded in the create's end transaction, before the completion it admits,
        // so before any pairing; the run's own end time, recorded to the second, comes earlier.
        ->and($runtime->workspace_baseline)->toBe(['heads' => [str_repeat('a', 40)], 'stashes' => [], 'untracked' => ['notes.txt']])
        ->and($runtime->workspace_baseline_at->greaterThanOrEqualTo($operation->finished_at))->toBeTrue()
        ->and($runtime->workspace_baseline_at->lessThanOrEqualTo(handoverCompletion($operation)->created_at))->toBeTrue();
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

it('leaves a Conn-owned clone at T3 readiness so only the launch fence can admit completion', function (): void {
    $node = containerDefaultNode();
    $operation = handoverCreate(handoverDefault($node));
    $operation->update(['input' => [...$operation->input, 'conn_launch_operation_id' => 301]]);

    app(DevelopmentInstanceCreateProjection::class)->apply($operation, handoverSucceeded($operation));

    $create = $operation->refresh();
    expect($create->status)->toBe(OperationStatus::Succeeded)
        ->and($create->result['t3']['environment_id'])->toBe('env-'.$create->target_id)
        ->and($create->result)->not->toHaveKey('completion_operation_id')
        ->and(Instance::query()->findOrFail($create->target_id)->status)->toBe(InstanceState::SourceResolved);
    expect(Operation::query()->where('kind', 'instance.complete')->where('target_id', $create->target_id)->exists())->toBeFalse();
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

it('reads the baseline again once after a lost connection, so one lost connect does not fail the create', function (): void {
    Sleep::fake();
    Log::spy();
    $node = containerDefaultNode();
    $operation = handoverCreate(handoverDefault($node));
    $this->reader->lost = 1;

    app(DevelopmentInstanceCreateProjection::class)->apply($operation, handoverSucceeded($operation));

    $operation->refresh();
    expect($operation->status)->toBe(OperationStatus::Succeeded)
        ->and($this->reader->calls)->toHaveCount(2)
        // No try and no pause runs inside a transaction.
        ->and(array_unique(array_column($this->reader->calls, 'level')))->toBe([DB::transactionLevel()])
        ->and(InstanceRuntime::query()->where('instance_id', $operation->target_id)->firstOrFail()->workspace_baseline['heads'])->toBe([str_repeat('a', 40)]);
    Sleep::assertSequence([Sleep::for(1)->seconds()]);
    // Each failed try names what SSH said, so a lost connection shows in the Gateway log.
    Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message, array $context): bool => $message === 'A workspace baseline read failed.'
        && $context['exit_code'] === 255 && str_contains($context['stderr'], 'Connection timed out') && $context['tries'] === 2);
});

it('gives the baseline read its own bounds for a loaded host: a 6 s reader budget, an outer 8 s timeout and a 10 s SSH bound', function (): void {
    Sleep::fake();
    $node = containerDefaultNode();
    $operation = handoverCreate(handoverDefault($node));

    app(DevelopmentInstanceCreateProjection::class)->apply($operation, handoverSucceeded($operation));

    $call = $this->reader->calls[0];
    expect($operation->refresh()->status)->toBe(OperationStatus::Succeeded)
        ->and(array_slice($call['arguments'], 0, 5))->toBe(['timeout', '8', 'python3', '-', 'record'])
        ->and($call['budget'])->toBe('6')
        ->and([$call['command_timeout'], $call['timeout']])->toBe([10.0, 10.0]);
});

it('fails the create with instance.workspace_baseline_unavailable when the read fails, and keeps the clone for a retry', function (): void {
    Sleep::fake();
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
        ->and($instance->runtime()->firstOrFail()->workspace_baseline)->toBeNull()
        // Only the second failed read fails the create: a stuck read ends in about 20 s.
        ->and($this->reader->calls)->toHaveCount(2);
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

/** A bus whose Node reports `instance.fleet_ready`, and that keeps what the engine publishes. */
function fleetBus(): MachineBus
{
    return new class implements MachineBus
    {
        /** @var list<int> */
        public array $starts = [];

        /** @var list<int> */
        public array $nudges = [];

        /** @var list<int> */
        public array $orders = [];

        public function publishOrder(int $node, SignedExecutorOrder $order): void
        {
            $this->orders[] = $node;
        }

        public function publishStart(int $operation): void
        {
            $this->starts[] = $operation;
        }

        public function scheduleDeadline(int $operation, string $step, int $sends, int $atMs): void {}

        public function nudge(int $operation, string $id): void
        {
            $this->nudges[] = $operation;
        }

        public function putContent(int $node, string $content): array
        {
            return ['sha256' => hash('sha256', $content)];
        }

        public function nodeActions(int $node): array
        {
            return ['instance.fleet_ready'];
        }

        public function nodeTargetActions(int $node): array
        {
            return [];
        }

        public function nodeClock(int $node): ?bool
        {
            return null;
        }
    };
}

/** A nested clone whose create ended with its fleet in the background, and the `instance.fleet` it admitted, sent to the Node. */
function fleetWait(object $test): Operation
{
    config(['orbit.engine.kinds' => ['instance.create'], 'orbit.executor.signing_key' => base64_encode(sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair()))]);
    $test->bus = fleetBus();
    app()->instance(MachineBus::class, $test->bus);
    $create = handoverCreate(handoverDefault(containerDefaultNode(), ProjectType::NodePackage, null, true));
    $succeeded = handoverSucceeded($create);
    $succeeded['result']['fleet_start'] = 'background';
    app(DevelopmentInstanceCreateProjection::class)->apply($create, $succeeded);
    $fleet = Operation::query()->where('kind', InstanceFleetOperation::Kind)->sole();
    app(Engine::class)->onEvent('orbit.engine.start', ['operation' => $fleet->id]);

    return $fleet->refresh();
}

/** The executor's receipt for the wait's one step. */
function fleetReceipt(Operation $fleet, array $line): void
{
    app(Engine::class)->onReceipt(['node' => $fleet->node_id, 'line' => ['operation' => $fleet->id.':fleet', ...$line]]);
}

it('starts a nested clone\'s fleet beside its completion: the create has no fleet step, and the completion skips clone-fleet', function (bool $background): void {
    config(['orbit.engine.kinds' => ['instance.create'], 'orbit.executor.signing_key' => base64_encode(sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair()))]);
    $this->bus = fleetBus();
    app()->instance(MachineBus::class, $this->bus);
    $node = containerDefaultNode();
    $create = handoverCreate(handoverDefault($node, ProjectType::NodePackage, null, true));
    $succeeded = handoverSucceeded($create);
    if ($background) {
        $succeeded['result']['fleet_start'] = 'background';
    }

    $names = array_map(static fn (StepSpec $step): string => $step->name, app(InstanceCreateEngineFlow::class)->steps($create));
    app(DevelopmentInstanceCreateProjection::class)->apply($create, $succeeded);

    $fleet = Operation::query()->where('kind', InstanceFleetOperation::Kind)->first();
    expect($names)->not->toContain('fleet')
        ->and(array_key_exists('fleet', app(NativeInstanceCompleteInput::class)->input(handoverCompletion($create))['intent']))->toBe(! $background)
        ->and($fleet !== null)->toBe($background);
    if (! $background) {
        return;
    }
    // Started on the engine after the commit, on its own part of the Node, holding no lock: the completion and a destroy never wait for it.
    expect([$fleet->target_type, $fleet->target_id, $fleet->node_id, $fleet->lock_key, $fleet->status, $fleet->state['swamp_run_id']])
        ->toBe(['instance', $create->target_id, $node->id, null, OperationStatus::Running, 'engine-'.$fleet->id])
        ->and($this->bus->starts)->toBe([$fleet->id])
        ->and(OperationEngineStep::query()->where('operation_id', $fleet->id)->pluck('target', 'name')->all())->toBe(['fleet' => $node->id.':fleet-'.$create->target_id])
        ->and(app(InstanceFleetEngineFlow::class)->input($fleet, 'fleet', new EngineRun($fleet->id, [], 0, null)))->toBe(['project' => InstancePlacement::NestedProject,
            'name' => 'instance-'.$create->target_id, 'guests' => ['nested-gateway', 'nested-app-dev', 'nested-app-prod'], 'hub' => '10.45.0.1', 'host' => 'gateway.orbit',
            'nodes' => ['gateway', 'app-dev', 'app-prod'], 'seconds' => 300]);
})->with(['in the background' => true, 'a seed without the tunnel unit' => false]);

it('records the fleet\'s end on the Instance: its ready time, or its failure, without failing the Instance', function (array $line, ?string $code): void {
    $fleet = fleetWait($this);

    fleetReceipt($fleet, $line);

    $runtime = InstanceRuntime::query()->where('instance_id', $fleet->target_id)->sole();
    $instance = Instance::query()->findOrFail($fleet->target_id);
    expect([$fleet->refresh()->status, $fleet->error_code])->toBe([$code === null ? OperationStatus::Succeeded : OperationStatus::Failed, $code])
        ->and($runtime->fleet_ready_at !== null)->toBe($code === null)
        ->and([$runtime->fleet_failed_at !== null, $runtime->fleet_error_code])->toBe([$code !== null, $code])
        ->and($instance->status)->toBe(InstanceState::SourceResolved)
        // A failed wait's fleet start waits for the completion: the Instance is not active yet.
        ->and($fleet->state['fallback'] ?? null)->toBe($code === null ? null : InstanceFleetOperation::FallbackPending)
        ->and(InstanceData::fromModel($instance)->toArray())->toMatchArray(['fleet_ready_at' => $runtime->fleet_ready_at?->toIso8601String(),
            'fleet_error_code' => $code]);
})->with([
    'ready' => [['status' => 'output', 'stdout' => '{"fleet":"ready","started":["nested-app-prod"]}'], null],
    'a failed check' => [['status' => 'failed', 'code' => 'instance.container_fleet_resume_failed'], 'instance.container_fleet_resume_failed'],
    'a tunnel unit that failed at boot' => [['status' => 'output', 'stdout' => '{"fleet":"seed_without_tunnel"}'], InstanceFleetEngineFlow::TunnelFailed],
]);

it('cancels the fleet wait when a destroy or a sleep of the Instance is admitted, and records nothing', function (string $kind): void {
    $fleet = fleetWait($this);

    app(OperationStore::class)->create($kind, 'instance', $fleet->target_id, $fleet->node_id, ['instance_id' => $fleet->target_id], null, null);
    app(Engine::class)->onEvent('orbit.engine.deadline', ['operation' => $fleet->id]);
    // The executor's answer after the end changes nothing.
    fleetReceipt($fleet, ['status' => 'output', 'stdout' => '{"fleet":"ready","started":[]}']);

    $runtime = InstanceRuntime::query()->where('instance_id', $fleet->target_id)->sole();
    expect($fleet->refresh()->status)->toBe(OperationStatus::Cancelled)
        ->and($fleet->cancel_requested_at)->not->toBeNull()
        ->and($this->bus->nudges)->toContain($fleet->id)
        ->and([$runtime->fleet_ready_at, $runtime->fleet_failed_at, $runtime->fleet_error_code])->toBe([null, null, null]);
})->with(InstanceFleetOperation::Ends);

it('writes nothing to an Instance that a removal owns when its fleet answers after the destroy, or to one that is gone', function (): void {
    $fleet = fleetWait($this);
    $instance = Instance::query()->findOrFail($fleet->target_id);
    $destroy = app(StartInstanceDestroyAction::class)->execute($instance, true, cancelCompletion: true);

    // The Node answered before the cancel reached the run: the cancel wins, and the Instance is the removal's.
    fleetReceipt($fleet, ['status' => 'output', 'stdout' => '{"fleet":"ready","started":[]}']);
    InstanceFleetOperation::record($instance->id, null);
    InstanceFleetOperation::record($instance->id + 1000, 'instance.container_fleet_resume_failed');

    $runtime = InstanceRuntime::query()->where('instance_id', $instance->id)->sole();
    expect($destroy->kind)->toBe('instance.destroy')
        ->and($instance->refresh()->status)->toBe(InstanceState::Removing)
        ->and($fleet->refresh()->status)->toBe(OperationStatus::Cancelled)
        ->and([$runtime->fleet_ready_at, $runtime->fleet_failed_at, $runtime->fleet_error_code])->toBe([null, null, null]);
});

/** Ends the clone's completion as succeeded, so the clone is active. */
function fleetCompleted(Operation $fleet): Instance
{
    $create = Operation::query()->findOrFail($fleet->input['create_operation_id']);
    $completion = handoverCompletion($create);
    $completion->forceFill(['status' => 'running', 'state' => [...($completion->state ?? []), 'swamp_run_id' => 'complete-run']])->save();
    app(InstanceCompleteProjection::class)->apply($completion->refresh(), ['status' => 'succeeded', 'run_id' => 'complete-run',
        'started_at' => now()->toIso8601String(), 'finished_at' => now()->toIso8601String(), 'result' => ['instance_id' => $create->target_id, 'ready' => true]]);

    return Instance::query()->findOrFail($fleet->target_id);
}

it('starts the fleet as Swamp does when the wait fails, once the clone is active: no clone is left with a fleet nobody starts', function (array $line): void {
    $fleet = fleetWait($this);

    fleetReceipt($fleet, $line);
    app(InstanceFleetOperation::class)->sweep();
    expect($fleet->refresh()->state['fallback'])->toBe(InstanceFleetOperation::FallbackPending)
        ->and(Operation::query()->where('kind', InstanceStart::Kind)->exists())->toBeFalse();

    $instance = fleetCompleted($fleet);
    app(InstanceFleetOperation::class)->sweep();

    $start = Operation::query()->where('kind', InstanceStart::Kind)->sole();
    $runtime = InstanceRuntime::query()->where('instance_id', $instance->id)->sole();
    expect([$instance->status, $start->target_id, $start->lock_key])->toBe([InstanceState::Active, $instance->id, OperationStore::lockKey('instance', $instance->id)])
        ->and($start->input['fleet'])->toBeArray()
        ->and($fleet->refresh()->state['fallback'])->toBe($start->id)
        // The start brings its own end: the failure of the wait is no longer the Instance's fleet.
        ->and([$runtime->fleet_failed_at, $runtime->fleet_error_code])->toBe([null, null]);

    // A second pass admits nothing more.
    app(InstanceFleetOperation::class)->sweep();
    expect(Operation::query()->where('kind', InstanceStart::Kind)->count())->toBe(1);

    // The start's end is the fleet's.
    $start->forceFill(['status' => 'running', 'state' => [...($start->state ?? []), 'swamp_run_id' => 'start-run']])->save();
    app(InstanceStart::class)->apply($start->refresh(), ['status' => 'succeeded', 'run_id' => 'start-run', 'started_at' => now()->toIso8601String(),
        'finished_at' => now()->toIso8601String(), 'result' => ['fleet_seconds' => 12]]);
    $runtime = InstanceRuntime::query()->where('instance_id', $instance->id)->sole();
    expect($runtime->fleet_ready_at)->not->toBeNull()
        // The fleet is paid: the next wake of a frozen copy resumes it.
        ->and($runtime->fleet_start_pending)->toBeFalse();
})->with([
    'seed_without_tunnel after a background create' => [['status' => 'output', 'stdout' => '{"fleet":"seed_without_tunnel","started":["nested-app-dev"]}']],
    'a failed check' => [['status' => 'failed', 'code' => 'instance.container_fleet_resume_failed']],
]);

it('ends a cancelled wait as cancelled and records nothing, even when its step fails after the cancel', function (): void {
    $fleet = fleetWait($this);
    $fleet->forceFill(['cancel_requested_at' => now()])->save();

    fleetReceipt($fleet, ['status' => 'failed', 'code' => 'instance.container_fleet_resume_failed']);

    $runtime = InstanceRuntime::query()->where('instance_id', $fleet->target_id)->sole();
    expect([$fleet->refresh()->status, $fleet->error_code])->toBe([OperationStatus::Cancelled, 'operation.cancelled'])
        ->and($fleet->state['fallback'] ?? null)->toBeNull()
        ->and([$runtime->fleet_ready_at, $runtime->fleet_failed_at, $runtime->fleet_error_code])->toBe([null, null, null]);
});

it('clears the recorded fleet when the Instance starts or is created again, and drops a waiting fallback', function (string $kind): void {
    $fleet = fleetWait($this);
    fleetReceipt($fleet, ['status' => 'failed', 'code' => 'instance.container_fleet_resume_failed']);
    expect(InstanceRuntime::query()->where('instance_id', $fleet->target_id)->value('fleet_error_code'))->toBe('instance.container_fleet_resume_failed');

    app(OperationStore::class)->create($kind, 'instance', $fleet->target_id, $fleet->node_id, ['instance_id' => $fleet->target_id], null, null);

    $runtime = InstanceRuntime::query()->where('instance_id', $fleet->target_id)->sole();
    expect([$runtime->fleet_ready_at, $runtime->fleet_failed_at, $runtime->fleet_error_code])->toBe([null, null, null])
        // The fleet stays owed until a start proves it ready.
        ->and($runtime->fleet_start_pending)->toBeTrue()
        ->and($fleet->refresh()->state['fallback'])->toBe('superseded');
})->with(InstanceFleetOperation::Resets);

/** Sleeps the clone as a freeze does: its sleep is admitted, then ends frozen. */
function fleetSleep(Operation $fleet): Operation
{
    $sleep = app(OperationStore::class)->create(InstanceSleep::Kind, 'instance', $fleet->target_id, $fleet->node_id, ['instance_id' => $fleet->target_id], null, null);
    $sleep->forceFill(['status' => OperationStatus::Succeeded, 'finished_at' => now(), 'result' => ['sleep' => 'frozen']])->save();
    InstanceRuntime::query()->where('instance_id', $fleet->target_id)->update(['power_state' => InstanceRuntimePowerState::Stopped]);

    return $sleep;
}

/** The Operation that a wake of the clone admits: a resume, or the start of a fleet that is still owed. */
function fleetWakeKind(Operation $fleet): string
{
    $instance = Instance::query()->findOrFail($fleet->target_id);

    return (new ReflectionMethod(InstanceWake::class, 'admitWake'))->invoke(app(InstanceWake::class), $instance, null, null)->kind;
}

it('keeps a fleet owed across a sleep during its wait, and the wake starts it instead of resuming it', function (): void {
    $fleet = fleetWait($this);
    fleetCompleted($fleet);

    fleetSleep($fleet);
    app(Engine::class)->onEvent('orbit.engine.deadline', ['operation' => $fleet->id]);

    $runtime = InstanceRuntime::query()->where('instance_id', $fleet->target_id)->sole();
    expect($fleet->refresh()->status)->toBe(OperationStatus::Cancelled)
        ->and($runtime->fleet_start_pending)->toBeTrue()
        ->and(fleetWakeKind($fleet))->toBe(InstanceStart::Kind);
});

it('keeps a pending fallback while the clone sleeps, and the wake starts the fleet in its place', function (): void {
    $fleet = fleetWait($this);
    fleetReceipt($fleet, ['status' => 'failed', 'code' => 'instance.container_fleet_resume_failed']);
    fleetCompleted($fleet);
    fleetSleep($fleet);

    // Asleep: the fallback does not wake the copy.
    app(InstanceFleetOperation::class)->sweep();
    expect(Operation::query()->where('kind', InstanceStart::Kind)->exists())->toBeFalse()
        ->and($fleet->refresh()->state['fallback'])->toBe(InstanceFleetOperation::FallbackPending);

    expect(fleetWakeKind($fleet))->toBe(InstanceStart::Kind)
        ->and($fleet->refresh()->state['fallback'])->toBe('superseded');
});

it('resumes a clone whose fleet a start proved ready: nothing is owed', function (): void {
    $fleet = fleetWait($this);
    fleetReceipt($fleet, ['status' => 'output', 'stdout' => '{"fleet":"ready","started":[]}']);
    fleetCompleted($fleet);
    fleetSleep($fleet);

    expect(InstanceRuntime::query()->where('instance_id', $fleet->target_id)->value('fleet_start_pending'))->toBeFalse()
        ->and(fleetWakeKind($fleet))->toBe(InstanceWake::Kind);
});

it('drops the fallback of a clone whose completion failed, and the completion\'s retry starts the fleet itself', function (): void {
    $fleet = fleetWait($this);
    fleetReceipt($fleet, ['status' => 'failed', 'code' => 'instance.container_fleet_resume_failed']);
    $create = Operation::query()->findOrFail($fleet->input['create_operation_id']);
    $completion = handoverCompletion($create);
    $completion->forceFill(['status' => 'running', 'state' => [...($completion->state ?? []), 'swamp_run_id' => 'complete-run']])->save();
    app(InstanceCompleteProjection::class)->apply($completion->refresh(), ['status' => 'failed', 'run_id' => 'complete-run', 'error_code' => 'instance.create_workspace_failed',
        'started_at' => now()->toIso8601String(), 'finished_at' => now()->toIso8601String(),
        'result' => ['instance_id' => $create->target_id, 'ready' => false, 'failed_step' => 'workspace', 'error_code' => 'instance.create_workspace_failed']]);

    app(InstanceFleetOperation::class)->sweep();

    expect($fleet->refresh()->state['fallback'])->toBe('dropped')
        ->and(Operation::query()->where('kind', InstanceStart::Kind)->exists())->toBeFalse();

    // The retry starts the fleet itself, and its success proves the fleet ready.
    $instance = Instance::query()->findOrFail($create->target_id);
    $retry = DB::transaction(fn (): Operation => app(OperationIdempotency::class)->suspended(
        fn (): Operation => app(StartInstanceCompleteAction::class)->admit($instance, null, null)));
    expect(app(NativeInstanceCompleteInput::class)->input($retry)['intent'])->toHaveKey('fleet')
        ->and($retry->refresh()->state['fleet_start'])->toBeTrue();
    $retry->forceFill(['status' => 'running', 'state' => [...($retry->state ?? []), 'swamp_run_id' => 'retry-run']])->save();
    app(InstanceCompleteProjection::class)->apply($retry->refresh(), ['status' => 'succeeded', 'run_id' => 'retry-run',
        'started_at' => now()->toIso8601String(), 'finished_at' => now()->toIso8601String(), 'result' => ['instance_id' => $create->target_id, 'ready' => true]]);

    $runtime = InstanceRuntime::query()->where('instance_id', $create->target_id)->sole();
    expect([$runtime->fleet_ready_at !== null, $runtime->fleet_failed_at, $runtime->fleet_error_code, $runtime->fleet_start_pending])
        ->toBe([true, null, null, false]);
});

it('starts an owed fleet on a wake while the cancelled wait is still open, and the wait then ends without a write', function (): void {
    $fleet = fleetWait($this);
    fleetCompleted($fleet);
    fleetSleep($fleet);
    expect($fleet->refresh()->status)->toBe(OperationStatus::Running)
        ->and($fleet->cancel_requested_at)->not->toBeNull();

    expect(fleetWakeKind($fleet))->toBe(InstanceStart::Kind);

    // The Node's late answer after the wake: the cancel wins, and the start's own end will be the fleet's.
    fleetReceipt($fleet, ['status' => 'output', 'stdout' => '{"fleet":"ready","started":[]}']);
    $runtime = InstanceRuntime::query()->where('instance_id', $fleet->target_id)->sole();
    expect($fleet->refresh()->status)->toBe(OperationStatus::Cancelled)
        ->and([$runtime->fleet_ready_at, $runtime->fleet_start_pending])->toBe([null, true]);
});

it('lets a task cancel, a copy destroy and a settle pass an open fleet wait: it holds nothing', function (): void {
    $fleet = fleetWait($this);
    $instance = fleetCompleted($fleet);

    TaskEnd::assertFree($instance);

    expect($fleet->refresh()->status)->toBe(OperationStatus::Running)
        ->and(Operation::query()->exceptFleetWait()->where('target_type', 'instance')->where('target_id', $instance->id)
            ->whereNotIn('status', OperationStatus::terminalValues())->exists())->toBeFalse();
});

it('starts the fleet beside a Conn launch\'s own completion too', function (): void {
    config(['orbit.engine.kinds' => ['instance.create'], 'orbit.executor.signing_key' => base64_encode(sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair()))]);
    $this->bus = fleetBus();
    app()->instance(MachineBus::class, $this->bus);
    $create = handoverCreate(handoverDefault(containerDefaultNode(), ProjectType::NodePackage, null, true));
    $create->forceFill(['input' => [...$create->input, 'conn_launch_operation_id' => 987]])->save();
    $succeeded = handoverSucceeded($create);
    $succeeded['result']['fleet_start'] = 'background';

    app(DevelopmentInstanceCreateProjection::class)->apply($create->refresh(), $succeeded);

    $fleet = Operation::query()->where('kind', InstanceFleetOperation::Kind)->sole();
    expect($create->refresh()->result)->not->toHaveKey('completion_operation_id')
        ->and([$fleet->target_id, $fleet->status, $fleet->input['create_operation_id']])->toBe([$create->target_id, OperationStatus::Running, $create->id]);
});

it('ends a wait cancelled before its start without running it', function (): void {
    config(['orbit.engine.kinds' => ['instance.create']]);
    $this->bus = fleetBus();
    app()->instance(MachineBus::class, $this->bus);
    $create = handoverCreate(handoverDefault(containerDefaultNode(), ProjectType::NodePackage, null, true));
    $fleet = DB::transaction(function () use ($create): Operation {
        $fleet = app(InstanceFleetOperation::class)->admit($create);
        // A destroy admitted in the same transaction, before the start after the commit.
        InstanceFleetOperation::cancelFor((int) $create->target_id);

        return $fleet;
    });

    expect([$fleet->refresh()->status, $fleet->error_code])->toBe([OperationStatus::Cancelled, 'operation.cancelled'])
        ->and($this->bus->starts)->toBe([])
        ->and(OperationEngineStep::query()->where('operation_id', $fleet->id)->exists())->toBeFalse();
});

it('starts a wait whose start was lost, nudges a stalled one, and ends one past its budget on the reconciler\'s pass', function (): void {
    $fleet = fleetWait($this);
    $lost = $fleet->replicate()->forceFill(['status' => OperationStatus::Queued, 'state' => [], 'created_at' => now()->subMinutes(2)]);
    $lost->save();

    app(InstanceFleetOperation::class)->sweep();
    expect($lost->refresh()->status)->toBe(OperationStatus::Running)
        ->and($this->bus->starts)->toContain($lost->id);

    $this->travel(451)->seconds();
    app(InstanceFleetOperation::class)->sweep();

    $runtime = InstanceRuntime::query()->where('instance_id', $fleet->target_id)->sole();
    expect([$fleet->refresh()->status, $fleet->error_code])->toBe([OperationStatus::Failed, 'operation.timed_out'])
        ->and($runtime->fleet_error_code)->toBe('operation.timed_out');
});

it('ends a completion with nothing left on its Node at once on the engine, and the clone goes active without a Swamp run', function (bool $seed): void {
    config(['orbit.engine.kinds' => ['instance.create'], 'orbit.executor.signing_key' => base64_encode(sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair()))]);
    $this->bus = fleetBus();
    app()->instance(MachineBus::class, $this->bus);
    $default = handoverDefault(containerDefaultNode(), ProjectType::NodePackage, null);
    // A Project without Processes: the seed holds all of the clone's services part.
    ProcessDefinition::query()->where('project_id', $default->project_id)->delete();
    $create = handoverCreate($default);
    $succeeded = handoverSucceeded($create);
    if ($seed) {
        $succeeded['result']['services'] = 'seed';
    }

    app(DevelopmentInstanceCreateProjection::class)->apply($create, $succeeded);
    $completion = handoverCompletion($create);
    expect(InstanceCompleteEngineFlow::nothingLeft($completion))->toBe($seed);
    if (! $seed) {
        return;
    }
    app(StartSwampRun::class)->start($completion->refresh());
    app(Engine::class)->onEvent('orbit.engine.start', ['operation' => $completion->id]);

    expect([$completion->refresh()->status, $completion->state['swamp_run_id'], $completion->result['ready']])
        ->toBe([OperationStatus::Succeeded, 'engine-'.$completion->id, true])
        ->and(OperationEngineStep::query()->where('operation_id', $completion->id)->exists())->toBeFalse()
        ->and(Instance::query()->findOrFail($create->target_id)->status)->toBe(InstanceState::Active);
})->with(['the seed holds the services part' => true, 'a seed without the digest' => false]);

it('keeps a completion on Swamp while its clone has a database, a Route, a Process or a fleet to start', function (Closure $change): void {
    $default = handoverDefault(containerDefaultNode(), ProjectType::NodePackage, null);
    ProcessDefinition::query()->where('project_id', $default->project_id)->delete();
    $create = handoverCreate($default);
    $succeeded = handoverSucceeded($create);
    $succeeded['result']['services'] = 'seed';
    app(DevelopmentInstanceCreateProjection::class)->apply($create, $succeeded);
    $completion = handoverCompletion($create);
    $completion->forceFill(['input' => $change($completion->input)])->save();

    expect(InstanceCompleteEngineFlow::nothingLeft($completion->refresh()))->toBeFalse();
})->with([
    'a database' => [static fn (array $input): array => [...$input, 'direct_provisioning' => [...$input['direct_provisioning'], 'database' => ['name' => 'app']]]],
    'a server database' => [static fn (array $input): array => [...$input, 'direct_provisioning' => [...$input['direct_provisioning'], 'server_database' => ['id' => 1]]]],
    'a Route to publish' => [static fn (array $input): array => [...$input, 'route_create' => ['route_id' => 1]]],
    'a Process' => [static function (array $input): array {
        $input['direct_provisioning']['container']['processes'] = [['id' => 4, 'name' => 'orbit-process-4.service']];

        return $input;
    }],
]);

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

it('lets clone creates admitted together share the free Route Nodes: each publishes in its create, under the Node lock', function (): void {
    $node = containerDefaultNode();
    $default = handoverDefault($node);

    $creates = array_map(fn (int $index): Operation => containerCreate($default->project, $node, 'clone'.$index), range(1, 7));

    // Seven Caddy-only creates hold the Node side by side in the shared table; none takes it alone, none waits, none defers.
    expect(DB::table('route_publication_holds')->count())->toBe(0)
        ->and(DB::table('route_withdrawal_holds')->where('node_id', $node->id)->orderBy('operation_id')->pluck('operation_id')->all())
        ->toBe(array_map(static fn (Operation $create): int => $create->id, $creates));
    foreach ($creates as $create) {
        $create->refresh();
        expect($create->status)->not->toBe(OperationStatus::Waiting)
            ->and($create->input)->toHaveKeys(['route_create', 'route_lifecycle'])
            ->and(ClonePublication::sharesNodes($create))->toBeTrue()
            ->and(RoutePublicationHolds::nodes($create))->toBe([$node->id]);
        $run = app(NativeDevelopmentInstanceInput::class)->create($create);
        expect($run['intent']['route'])->toBe($create->input['route_create'])
            ->and($run['intent']['route_mutations'])->not->toBeNull();
    }
    expect([$creates[0]->input['route_create']['php_files'], $creates[0]->input['route_create']['dns'], $creates[0]->input['route_create']['ingress'],
        $creates[0]->input['route_create']['lan']])->toBe([[], [], [], []]);

    // The ninth shared writer on the Node leaves its address to its completion, as when the Node is held. One info line says why, with
    // the shared writers on the Node, and its result names the cap (Operation 23271 had neither).
    $eighth = containerCreate($default->project, $node, 'clone8');
    Log::spy();
    $ninth = containerCreate($default->project, $node, 'clone9');
    expect($eighth->refresh()->input)->toHaveKey('route_create')
        ->and($ninth->refresh()->input)->not->toHaveKeys(['route_create', 'route_lifecycle'])
        ->and($ninth->input['direct_provisioning']['route'])->toBeNull()
        ->and(RoutePublicationHolds::nodes($ninth))->toBe([]);
    Log::shouldHaveReceived('info')->once()->with('Clone create deferred its Route publication',
        ['operation_id' => $ninth->id, 'reason' => 'share_cap', 'sharers' => [$node->id => 8]]);
    $ninth->forceFill(['status' => 'running', 'state' => [...$ninth->state, 'swamp_run_id' => 'run-1']])->save();
    app(DevelopmentInstanceCreateProjection::class)->apply($ninth->refresh(), handoverSucceeded($ninth));
    expect($ninth->refresh()->result)->toMatchArray(['route_publication' => 'deferred', 'route_publication_reason' => 'share_cap']);
    $eighth->forceFill(['status' => 'running', 'state' => [...($eighth->state ?? []), 'swamp_run_id' => 'run-1']])->save();
    app(DevelopmentInstanceCreateProjection::class)->apply($eighth->refresh(), handoverPublished($eighth, 'published'));
    expect($eighth->refresh()->result['route_publication'])->toBe('published')
        ->and($eighth->result)->not->toHaveKey('route_publication_reason');
});

it('shares the Node for clone creates on a fleet with private DNS: a domain under the Node TLD needs no record (ADR 0234)', function (): void {
    $node = containerDefaultNode();
    $default = handoverDefault($node);
    // The live fleet publishes private DNS on a vpn Node; the clone domain sits under the Node's TLD `beast.test`.
    $vpn = Node::withoutEvents(fn (): Node => Node::query()->create(['name' => 'gateway', 'status' => 'active', 'platform' => 'linux', 'architecture' => 'x86_64',
        'wireguard_ip' => '10.44.0.2', 'public_ssh_host' => '10.44.0.2', 'user' => 'orbit', 'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'vpn-host-key']));
    $vpn->roles()->create(['role' => RoleName::Vpn, 'status' => 'active']);

    $first = containerCreate($default->project, $node, 'first');
    $second = containerCreate($default->project, $node, 'second');
    foreach ([$first, $second] as $create) {
        expect($create->refresh()->input['route_create']['dns'])->toBe([])
            ->and(ClonePublication::sharesNodes($create))->toBeTrue();
    }
    expect(DB::table('route_withdrawal_holds')->orderBy('operation_id')->pluck('operation_id')->all())->toBe([$first->id, $second->id]);

    // A domain outside every TLD still needs its record: that create keeps its private DNS work and holds the Node alone (or defers).
    $outside = app(StartDevelopmentInstanceCreateAction::class)->execute(
        new CreateInstanceData($default->project->id, $node->id, 'outside', null, 'outside.example.com', null), null, null);
    expect(ClonePublication::sharesNodes($outside->refresh()))->toBeFalse()
        ->and(DB::table('route_publication_holds')->where('operation_id', $outside->id)->exists())->toBeFalse();
});

it('keeps a clone create whose publication writes more than the Caddyfile on the exclusive hold', function (string $list): void {
    $node = containerDefaultNode();
    $create = handoverCreate(handoverDefault($node));
    $input = $create->input;
    $input['route_create'][$list] = [['node_id' => $node->id]];
    $create->forceFill(['input' => $input])->save();

    expect(ClonePublication::sharesNodes($create->refresh()))->toBeFalse();
})->with(['php_files', 'dns', 'ingress', 'lan']);

it('defers a clone create while a whole-file writer holds the Node, and makes a whole-file writer wait while a create shares it', function (): void {
    $node = containerDefaultNode();
    $default = handoverDefault($node);
    $create = containerCreate($default->project, $node, 'shared');
    expect(DB::table('route_withdrawal_holds')->pluck('operation_id')->all())->toBe([$create->id]);

    // A whole-file Route write waits behind the shared create: it never writes beside a merge.
    $writer = handoverRouteWrite($node, 'route.update');
    expect($writer->status)->toBe(OperationStatus::Waiting);
    // A newer create defers behind the older waiting writer, so the writer is not starved.
    $later = containerCreate($default->project, $node, 'later');
    expect($later->refresh()->input)->not->toHaveKey('route_create')
        ->and($later->state['route_publication_reason'])->toBe('waiter_first');

    // Once the shared create published, its row goes and the writer starts.
    $create->refresh()->forceFill(['status' => 'running', 'state' => ['swamp_run_id' => 'run-1']])->save();
    handoverStep($create, 'publication', 'clone-route', 'step_completed', 1);
    app(RoutePublicationHolds::class)->promoteAndStart();
    // It rendered before the clone's site was published, so it is refused at its start rather than dropping that site.
    expect(DB::table('route_withdrawal_holds')->count())->toBe(0)
        ->and([$writer->refresh()->status, $writer->error_code])->toBe([OperationStatus::Failed, 'route.publication_stale']);
});

it('frees the Route Nodes when clone-route succeeds, with the Route active in that transaction, so the next create renders its site', function (): void {
    Queue::fake();
    $node = containerDefaultNode();
    $default = handoverDefault($node);
    $create = handoverCreate($default, 'first');
    $route = Route::query()->findOrFail($create->input['route_id']);
    expect(DB::table('route_withdrawal_holds')->pluck('operation_id')->all())->toBe([$create->id]);

    // A step of the clone job or the CSR frees nothing; only clone-route's success does.
    handoverStep($create, 'publication', 'clone-route-csr', 'step_completed', 1);
    handoverStep($create, 'clone', 'clone-container', 'step_completed', 2);
    handoverStep($create, 'publication', 'clone-route', 'step_started', 3);
    expect(DB::table('route_withdrawal_holds')->count())->toBe(1)
        ->and($route->refresh()->sites_published)->toBeFalse();
    $released = null;
    DB::listen(function ($query) use (&$released): void {
        if (str_contains($query->sql, 'delete from "route_withdrawal_holds"')) {
            $released = DB::transactionLevel();
        }
    });
    handoverStep($create, 'publication', 'clone-route', 'step_completed', 4);

    expect([$route->refresh()->status->value, $route->sites_published])->toBe(['active', true])
        ->and(DB::table('route_withdrawal_holds')->count())->toBe(0)
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

it('leaves a Route that another write has taken alone when clone-route succeeds, and frees the Nodes', function (): void {
    $node = containerDefaultNode();
    $create = handoverCreate(handoverDefault($node));
    $route = Route::query()->findOrFail($create->input['route_id']);
    $route->update(['status' => 'retiring']);

    handoverStep($create, 'publication', 'clone-route', 'step_completed', 1);

    expect([$route->refresh()->status->value, $route->sites_published])->toBe(['retiring', false])
        ->and(DB::table('route_withdrawal_holds')->count())->toBe(0);
});

/** A Route write on the Node that renders its whole Caddyfile at its admission and publishes it later. */
function handoverRouteWrite(Node $node, string $kind): Operation
{
    $operation = Operation::query()->create(['kind' => $kind, 'target_type' => 'route', 'target_id' => 1, 'node_id' => $node->id,
        'status' => OperationStatus::Queued, 'sequence' => 1, 'attempts' => 0,
        'input' => ['plan' => ['publications' => [['node_id' => $node->id, 'serves_routes' => true]]]], 'state' => []]);
    app(RoutePublicationHolds::class)->admit($operation);

    return $operation->refresh();
}

it('refuses at its start a Route write that rendered before the clone\'s address was published, so it never drops the site', function (string $kind): void {
    $node = containerDefaultNode();
    $create = handoverCreate(handoverDefault($node));
    $route = Route::query()->findOrFail($create->input['route_id']);
    // Admitted while the create holds the Node: it waits, and its render has no clone site.
    $waiter = handoverRouteWrite($node, $kind);
    expect($waiter->status)->toBe(OperationStatus::Waiting)
        ->and($waiter->state['hold_sites'])->toHaveKey((string) $node->id);

    handoverStep($create, 'publication', 'clone-route', 'step_completed', 1);
    app(RoutePublicationHolds::class)->promoteAndStart();

    expect([$waiter->refresh()->status, $waiter->error_code])->toBe([OperationStatus::Failed, 'route.publication_stale'])
        ->and([$route->refresh()->status->value, $route->sites_published])->toBe(['active', true])
        ->and(DB::table('route_publication_holds')->where('operation_id', $waiter->id)->exists())->toBeFalse();
    // Any render made now, a write run again or a create that renders at its start, serves the clone's site.
    expect(app(DevelopmentSiteRepository::class)->forNode($node)->pluck('domain')->all())->toContain($route->domain);
    $again = handoverRouteWrite($node, $kind);
    expect($again->status)->not->toBe(OperationStatus::Waiting);
})->with(['route.update', 'route.target_set', 'instance.migrate', 'instance.transfer']);

it('starts a waiting Route write whose Nodes serve the same sites as at its admission', function (): void {
    $node = containerDefaultNode();
    $create = handoverCreate(handoverDefault($node));
    $waiter = handoverRouteWrite($node, 'route.update');

    // clone-route failed: no site was published, so the waiter's render is still current.
    handoverStep($create, 'publication', 'clone-route', 'step_failed', 1);
    $failed = handoverPublished($create, 'failed');
    app(DevelopmentInstanceCreateProjection::class)->apply($create->refresh(), $failed);
    app()->instance(SwampDispatch::class, new class
    {
        public array $started = [];

        public function start(Operation $operation): void
        {
            $this->started[] = $operation->id;
        }
    });
    app(RoutePublicationHolds::class)->promoteAndStart();

    expect($waiter->refresh()->error_code)->toBeNull()
        ->and(app(SwampDispatch::class)->started)->toBe([$waiter->id]);
});

it('keeps the address of a create whose later clone step failed after clone-route published it: Route active, Nodes free, no completion', function (): void {
    $node = containerDefaultNode();
    $create = handoverCreate(handoverDefault($node));
    $route = Route::query()->findOrFail($create->input['route_id']);
    handoverStep($create, 'publication', 'clone-route', 'step_completed', 1);
    // The Route is active and the Nodes are free while the clone steps still run.
    expect([$route->refresh()->status->value, $route->sites_published, DB::table('route_withdrawal_holds')->count()])->toBe(['active', true, 0]);
    handoverStep($create, 'clone', 'clone-t3', 'step_failed', 2);
    expect($create->refresh()->failed_step)->toBe('clone-t3');

    app(DevelopmentInstanceCreateProjection::class)->apply($create->refresh(), ['status' => 'failed', 'run_id' => 'run-1', 'error_code' => 'instance.t3_unready',
        'started_at' => now()->toIso8601String(), 'finished_at' => now()->toIso8601String(),
        'result' => ['instance_id' => $create->target_id, 'ready' => false, 'failed_step' => 't3', 'error_code' => 'instance.t3_unready', 'route_publication' => 'published']]);

    $instance = Instance::query()->findOrFail($create->target_id);
    expect([$create->refresh()->status, $create->failed_step, $create->error_code])->toBe([OperationStatus::Failed, 'clone-t3', 'instance.t3_unready'])
        ->and($create->result['route_publication'])->toBe('published')
        ->and($create->result['recovery']['retry']['command'])->toBe('instance:create')
        ->and($create->result)->not->toHaveKey('completion_operation_id')
        ->and(Operation::query()->where('kind', 'instance.complete')->exists())->toBeFalse()
        ->and([$instance->failed_step, $instance->error_code])->toBe(['t3', 'instance.t3_unready'])
        ->and([$route->refresh()->status->value, $route->sites_published, $route->failed_step])->toBe(['active', true, null]);
});

it('activates a published Route at the create\'s end when the progress event was lost, and frees the Nodes in that transaction', function (): void {
    $node = containerDefaultNode();
    $create = handoverCreate(handoverDefault($node));

    app(DevelopmentInstanceCreateProjection::class)->apply($create, handoverPublished($create, 'published'));

    $route = Route::query()->findOrFail($create->input['route_id']);
    expect([$route->status->value, $route->sites_published])->toBe(['active', true])
        ->and(DB::table('route_withdrawal_holds')->where('operation_id', $create->id)->exists())->toBeFalse();
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
        ->and($create->result['route_publication_reason'])->toBe('nodes_held')
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
    expect(DB::table('route_withdrawal_holds')->where('operation_id', $create->id)->exists())->toBeTrue();

    $failed = handoverPublished($create, 'failed');
    $failed['result']['warnings'] = [['code' => 'instance.create_route_failed', 'step' => 'route']];
    app(DevelopmentInstanceCreateProjection::class)->apply($create->refresh(), $failed);

    $route = Route::query()->findOrFail($create->input['route_id']);
    $completion = handoverCompletion($create);
    expect($create->refresh()->status)->toBe(OperationStatus::Succeeded)
        ->and($create->result['route_publication'])->toBe('failed')
        ->and($create->result)->not->toHaveKey('route_publication_reason')
        ->and([$route->sites_published, $route->failed_step, $route->error_code])->toBe([false, 'native-create', 'instance.create_route_failed'])
        ->and(DB::table('route_withdrawal_holds')->where('operation_id', $create->id)->exists())->toBeFalse()
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

it('takes a merge that a Node refused as deferred: the Route is not marked failed, and the completion publishes it', function (): void {
    $node = containerDefaultNode();
    $create = handoverCreate(handoverDefault($node));
    // The site step failed after its refusal; the run's result names the address deferred (route-create-site.yml, create_result).
    handoverStep($create, 'publication', 'clone-route', 'step_failed', 1);
    Log::spy();
    app(DevelopmentInstanceCreateProjection::class)->apply($create->refresh(), handoverPublished($create, 'deferred'));

    $route = Route::query()->findOrFail($create->input['route_id']);
    $completion = handoverCompletion($create);
    Log::shouldHaveReceived('info')->once()->with('Clone create deferred its Route publication', ['operation_id' => $create->id, 'reason' => 'node_refused']);
    expect($create->refresh()->status)->toBe(OperationStatus::Succeeded)
        ->and($create->result['route_publication'])->toBe('deferred')
        ->and($create->result['route_publication_reason'])->toBe('node_refused')
        ->and([$route->status->value, $route->sites_published, $route->failed_step, $route->error_code])->toBe(['pending', false, null, null])
        ->and(DB::table('route_withdrawal_holds')->where('operation_id', $create->id)->exists())->toBeFalse()
        ->and($completion->input)->toHaveKey('route_create')
        ->and(RoutePublicationHolds::nodes($completion))->toBe([$node->id]);
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
        ->and(DB::table('route_withdrawal_holds')->count())->toBe(0)
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

it('gives a clone of a Project without a Route no publication and no hold', function (): void {
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
