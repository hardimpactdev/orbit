<?php

declare(strict_types=1);

use App\Actions\Fleet\ContinueReleaseFleetAction;
use App\Domain\Extensions\ExtensionStore;
use App\Domain\Fleet\FleetServingRelease;
use App\Domain\Gateway\GatewayServingHost;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\OperationStatus;
use App\Domain\Problems\ProblemRecovery;
use App\Domain\Problems\ProblemSource;
use App\Domain\Releases\ReleaseAlert;
use App\Domain\Releases\ReleaseAlertNotifier;
use App\Domain\Releases\ReleaseAlertReceipt;
use App\Domain\Releases\ReleaseAlertStep;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\Swamp\SwampCommandStep;
use App\Infrastructure\Swamp\SwampDispatch;
use App\Models\GatewayRelease;
use App\Models\Node;
use App\Models\Operation;
use App\Models\ProblemFingerprint;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Orbit\Extensions\AiRouter\Actions\AdmitAiRouterModelForwarderAction;
use Orbit\Extensions\AiRouter\Actions\AdmitAiRouterTaskGuardAction;
use Orbit\Extensions\AiRouter\Domain\AiRouterState;
use Orbit\Extensions\AiRouter\Domain\AiRouterTaskGuard;
use Orbit\Extensions\AiRouter\Infrastructure\AiRouterTaskGuardRelease;

uses(LazilyRefreshDatabase::class);

const GuardReleaseCommit = 'cccccccccccc0000000000000000000000000000';

function guardReleaseNode(string $name, string $address): Node
{
    return Node::withoutEvents(fn (): Node => Node::query()->create(['name' => $name, 'status' => 'active', 'platform' => 'linux',
        'architecture' => 'x86_64', 'public_ssh_host' => $address, 'wireguard_ip' => $address, 'user' => 'orbit',
        'ssh_host_fingerprint' => 'SHA256:fixture', 'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'fixture-public-key']));
}

/** A guard or forwarder run on the router's Node, with the digest it recorded, if any. */
function guardReleaseRun(OperationStatus $status, ?string $digest, string $state = 'present', string $kind = 'ai_router.task_guard'): Operation
{
    $node = test()->services;

    return Operation::query()->create(['kind' => $kind, 'target_type' => 'node', 'target_id' => $node->id, 'node_id' => $node->id,
        'status' => $status, 'input' => ['intent' => ['state' => $state], 'execution_secrets' => [], ...($digest === null ? [] : ['guard_digest' => $digest])],
        'finished_at' => $status->terminal() ? now() : null]);
}

/** The digest of the guard the release would apply now. */
function guardReleaseDigest(): string
{
    $guard = app(AiRouterTaskGuard::class);
    $node = test()->services;

    return $guard->digest($guard->intent($node, $guard->forwarders($node), false));
}

function guardReleasePass(): ?int
{
    return app(ContinueReleaseFleetAction::class)->execute(GatewayRelease::query()->sole());
}

beforeEach(function (): void {
    Queue::fake();
    // A verified release of the commit, served from the release layout, with fleet rollout and the default refresh off.
    $this->base = sys_get_temp_dir().'/orbit-guard-release-'.bin2hex(random_bytes(4));
    $release = $this->base.'/releases/'.substr(GuardReleaseCommit, 0, 12);
    mkdir($release.'/apps/gateway', 0700, true);
    file_put_contents($release.'/REVISION', GuardReleaseCommit."\n");
    symlink('releases/'.substr(GuardReleaseCommit, 0, 12), $this->base.'/orbit');
    config(['fleet.rollout' => false, 'instances.nested_fleet_refresh' => false, 'app.version' => GuardReleaseCommit,
        'orbit.gateway_checkout' => $this->base.'/orbit/apps/gateway']);
    app()->instance(FleetServingRelease::class, new FleetServingRelease($release.'/apps/gateway'));
    GatewayRelease::query()->create(['sha' => GuardReleaseCommit, 'trigger' => 'auto', 'outcome' => 'verified',
        'phases' => ['verify' => ['version' => GuardReleaseCommit], 'fleet' => ['status' => 'pending', 'nodes' => []]], 'duration_ms' => 0]);

    // The router on `services`, with the ai-router set up and switched on.
    $this->services = guardReleaseNode('services', '10.44.0.3');
    guardReleaseNode('swamp', '10.44.0.15')->roles()->create(['role' => RoleName::Swamp, 'status' => LifecycleStatus::Active]);
    app()->instance(SwampCommandStep::class, new class
    {
        /** @return array{vault: string, key: string} */
        public function executionCopy(string $vault, string $key, string $value): array
        {
            return ['vault' => $vault, 'key' => $key];
        }
    });
    app()->instance(SwampDispatch::class, new class
    {
        public function start(Operation $operation): void {}
    });
    app(AiRouterState::class)->enable($this->services->id, 'services-valkey', 'http://10.44.0.3:8317', 'management', 'read', 'control');
    app(ExtensionStore::class)->set('ai-router', true);

    $this->alerts = new class implements ReleaseAlertNotifier
    {
        /** @var list<ReleaseAlert> */
        public array $sent = [];

        public function alert(ReleaseAlert $alert): ReleaseAlertReceipt
        {
            $this->sent[] = $alert;

            return new ReleaseAlertReceipt('request', null, ReleaseAlertStep::skipped('test'), ReleaseAlertStep::skipped('test'));
        }
    };
    app()->instance(ReleaseAlertNotifier::class, $this->alerts);
});

afterEach(function (): void {
    Process::run(['rm', '-rf', $this->base]);
});

it('applies the guard once when its last run recorded no digest, then settles current when the run succeeds', function (): void {
    // Today's live guard: applied by a run from before digests were recorded.
    guardReleaseRun(OperationStatus::Succeeded, null);
    $fleet = app(ContinueReleaseFleetAction::class);

    expect(guardReleasePass())->toBe(60);
    $operation = Operation::query()->where('kind', 'ai_router.task_guard')->where('status', '!=', OperationStatus::Succeeded)->sole();
    $release = GatewayRelease::query()->sole();
    expect($operation->triggered_by)->toBe('release:'.substr(GuardReleaseCommit, 0, 12))
        ->and($operation->input['guard_digest'])->toBe(guardReleaseDigest())
        ->and($operation->input['intent']['state'])->toBe('present')
        ->and($release->phases['fleet']['status'])->toBe('disabled')
        ->and($release->phases[AiRouterTaskGuardRelease::Phase])->toBe(['status' => 'pending', 'digest' => guardReleaseDigest(), 'operation_id' => $operation->id])
        // The release owns its downstream work until the phase settles.
        ->and($fleet->pending(GuardReleaseCommit)?->id)->toBe($release->id);

    // A pass while the run is open admits nothing more.
    expect(guardReleasePass())->toBe(60)
        ->and(Operation::query()->where('kind', 'ai_router.task_guard')->count())->toBe(2);

    $operation->forceFill(['status' => OperationStatus::Succeeded, 'lock_key' => null, 'finished_at' => now()])->save();
    expect(guardReleasePass())->toBeNull()
        ->and(GatewayRelease::query()->sole()->phases[AiRouterTaskGuardRelease::Phase]['status'])->toBe('current')
        ->and(app(AiRouterTaskGuard::class)->appliedDigest($this->services))->toBe(guardReleaseDigest())
        ->and($fleet->pending(GuardReleaseCommit))->toBeNull()
        ->and($this->alerts->sent)->toBe([]);
});

it('applies the guard when its config moved since the digest its last run applied', function (): void {
    guardReleaseRun(OperationStatus::Succeeded, 'sha256:'.str_repeat('0', 64));

    guardReleasePass();

    expect(Operation::query()->where('kind', 'ai_router.task_guard')->where('triggered_by', 'release:'.substr(GuardReleaseCommit, 0, 12))->count())->toBe(1);
});

it('admits nothing when the guard already runs the config of the release', function (): void {
    $applied = app(AdmitAiRouterTaskGuardAction::class)->execute(triggeredBy: 'ai-router:task-guard');
    $applied->forceFill(['status' => OperationStatus::Succeeded, 'lock_key' => null, 'finished_at' => now()])->save();

    expect(guardReleasePass())->toBeNull()
        ->and(GatewayRelease::query()->sole()->phases[AiRouterTaskGuardRelease::Phase])->toBe(['status' => 'current', 'digest' => guardReleaseDigest()])
        ->and(Operation::query()->count())->toBe(1);
});

it('counts the guard phase of a forwarder run as the guard it applied', function (): void {
    $agent = guardReleaseNode('shark', '10.44.0.7');
    $gateway = guardReleaseNode('gateway', '10.44.0.2');
    $gateway->roles()->create(['role' => RoleName::Gateway, 'status' => LifecycleStatus::Active]);
    app(GatewayServingHost::class)->remember($gateway);
    guardReleaseRun(OperationStatus::Succeeded, null);
    $forwarder = app(AdmitAiRouterModelForwarderAction::class)->execute($agent, 'incusbr0', '10.10.0.1', triggeredBy: 'ai-router:model-forwarder');
    $forwarder->forceFill(['status' => OperationStatus::Succeeded, 'lock_key' => null, 'finished_at' => now()])->save();

    // The forwarder run applied the guard with the Node among its sources: the release finds that same guard.
    expect($forwarder->input['guard_digest'])->toBe(guardReleaseDigest())
        ->and(guardReleasePass())->toBeNull()
        ->and(GatewayRelease::query()->sole()->phases[AiRouterTaskGuardRelease::Phase]['status'])->toBe('current')
        ->and(Operation::query()->where('kind', 'ai_router.task_guard')->count())->toBe(1);
});

it('never brings back a guard that was taken off or never applied', function (?string $state): void {
    if ($state !== null) {
        guardReleaseRun(OperationStatus::Succeeded, null);
        guardReleaseRun(OperationStatus::Succeeded, null, $state);
    }

    expect(guardReleasePass())->toBeNull()
        ->and(GatewayRelease::query()->sole()->phases[AiRouterTaskGuardRelease::Phase])->toBe(['status' => 'absent'])
        ->and(Operation::query()->where('kind', 'ai_router.task_guard')->where('triggered_by', 'like', 'release:%')->count())->toBe(0);
})->with(['removed with --remove' => ['absent'], 'never applied' => [null]]);

it('admits nothing while the ai-router is switched off or not set up', function (string $off): void {
    guardReleaseRun(OperationStatus::Succeeded, null);
    $off === 'switch' ? app(ExtensionStore::class)->set('ai-router', false) : app(AiRouterState::class)->disable();

    expect(guardReleasePass())->toBeNull()
        ->and(GatewayRelease::query()->sole()->phases[AiRouterTaskGuardRelease::Phase])->toBe(['status' => 'disabled'])
        ->and(Operation::query()->count())->toBe(1);
})->with(['switch' => ['switch'], 'setup' => ['setup']]);

it('retries while another run holds the guard, and gives up for the release after the bounded wait', function (): void {
    guardReleaseRun(OperationStatus::Succeeded, null);
    $holder = guardReleaseRun(OperationStatus::Running, null);
    $holder->forceFill(['lock_key' => 'ai_router_guard:'.$this->services->id])->save();

    expect(guardReleasePass())->toBe(60);
    $phase = GatewayRelease::query()->sole()->phases[AiRouterTaskGuardRelease::Phase];
    expect($phase)->toMatchArray(['status' => 'pending', 'error_code' => 'operation.target_busy'])
        ->and(app(ContinueReleaseFleetAction::class)->pending(GuardReleaseCommit))->not->toBeNull();

    $this->travel(10)->minutes();
    expect(guardReleasePass())->toBe(60)
        ->and(GatewayRelease::query()->sole()->phases[AiRouterTaskGuardRelease::Phase]['busy_since'])->toBe($phase['busy_since']);

    // The holder ends: the next pass applies the guard.
    $holder->forceFill(['status' => OperationStatus::Succeeded, 'lock_key' => null, 'finished_at' => now()])->save();
    guardReleasePass();
    expect(GatewayRelease::query()->sole()->phases[AiRouterTaskGuardRelease::Phase])->toHaveKey('operation_id');
});

it('fails the phase after the busy wait without blocking the release', function (): void {
    guardReleaseRun(OperationStatus::Succeeded, null);
    guardReleaseRun(OperationStatus::Running, null)->forceFill(['lock_key' => 'ai_router_guard:'.$this->services->id])->save();

    guardReleasePass();
    $this->travel(AiRouterTaskGuardRelease::BusyMinutes + 1)->minutes();

    expect(guardReleasePass())->toBeNull()
        ->and(GatewayRelease::query()->sole()->phases[AiRouterTaskGuardRelease::Phase])->toMatchArray(['status' => 'failed', 'error_code' => 'operation.target_busy'])
        ->and(GatewayRelease::query()->sole()->outcome)->toBe('verified')
        ->and(app(ContinueReleaseFleetAction::class)->pending(GuardReleaseCommit))->toBeNull();
});

it('records a failed run on the phase, alerts once, and leaves the release verified and settled', function (): void {
    guardReleaseRun(OperationStatus::Succeeded, null);
    guardReleasePass();
    $operation = Operation::query()->where('triggered_by', 'release:'.substr(GuardReleaseCommit, 0, 12))->sole();
    $operation->forceFill(['status' => OperationStatus::Failed, 'error_code' => 'ai_router.guard_refusal_unproven', 'lock_key' => null, 'finished_at' => now()])->save();

    expect(guardReleasePass())->toBeNull();
    $release = GatewayRelease::query()->sole();
    expect($release->outcome)->toBe('verified')
        ->and($release->phases[AiRouterTaskGuardRelease::Phase])->toMatchArray(['status' => 'failed', 'error_code' => 'ai_router.guard_refusal_unproven', 'operation_id' => $operation->id])
        ->and(app(ContinueReleaseFleetAction::class)->pending(GuardReleaseCommit))->toBeNull()
        ->and(array_map(static fn (ReleaseAlert $alert): string => $alert->incident->key($alert->kind), $this->alerts->sent))
        ->toBe(['release_follow_up_failed|release-phase:ai_router_guard|'.GuardReleaseCommit.'|ai_router.guard_refusal_unproven']);

    // A later pass, such as the daily one, raises nothing new and admits no other run.
    guardReleasePass();
    expect($this->alerts->sent)->toHaveCount(1)
        ->and(Operation::query()->where('kind', 'ai_router.task_guard')->count())->toBe(2);

    // The problem resolves once a later release settles the phase current.
    $problem = new ProblemFingerprint(['source' => ProblemSource::Release, 'filed_at' => now()->subMinute(), 'evidence' => [
        'release_kind' => 'release_follow_up_failed', 'incident_resource' => 'release-phase:ai_router_guard', 'incident_attempt' => GuardReleaseCommit,
        'incident_fault' => 'ai_router.guard_refusal_unproven']]);
    expect(app(ProblemRecovery::class)->reason($problem))->toBeNull();
    $this->travel(1)->minutes();
    GatewayRelease::query()->create(['sha' => str_repeat('d', 40), 'trigger' => 'auto', 'outcome' => 'verified',
        'phases' => ['fleet' => ['status' => 'disabled'], AiRouterTaskGuardRelease::Phase => ['status' => 'current']], 'duration_ms' => 0]);
    expect(app(ProblemRecovery::class)->reason($problem))->toBe('release_phase_current');
});

it('applies the guard after a fleet pass that failed at a Node', function (): void {
    guardReleaseRun(OperationStatus::Succeeded, null);
    $release = GatewayRelease::query()->sole();
    $release->update(['phases' => [...$release->phases, 'fleet' => ['status' => 'failed', 'nodes' => []]]]);
    config(['fleet.rollout' => true]);

    expect(guardReleasePass())->toBe(60)
        ->and(GatewayRelease::query()->sole()->phases)->not->toHaveKey('default_refresh')
        ->and(GatewayRelease::query()->sole()->phases[AiRouterTaskGuardRelease::Phase]['status'])->toBe('pending')
        ->and(app(ContinueReleaseFleetAction::class)->pending(GuardReleaseCommit)?->id)->toBe($release->id);
});
