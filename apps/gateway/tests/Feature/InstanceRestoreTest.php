<?php

declare(strict_types=1);

use App\Actions\Certificates\SignDirectCertificatesAction;
use App\Actions\Instances\StartInstanceDestroyAction;
use App\Actions\Instances\StartInstanceRestoreAction;
use App\Domain\AgentView\AgentNodeView;
use App\Domain\AgentView\AgentStateView;
use App\Domain\AgentView\AgentViewFreshness;
use App\Domain\AgentView\AgentViewSubscriberHealth;
use App\Domain\Certificates\LeafCertificateSigner;
use App\Domain\DatabaseServers\DatabaseStatements;
use App\Domain\Instances\InstanceRemovalStatus;
use App\Domain\Instances\InstanceRemovalStep;
use App\Domain\Instances\InstanceRestoreProjection;
use App\Domain\Instances\InstanceState;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\RoutePublicationHolds;
use App\Domain\RemotePrograms\ProgramResult;
use App\Domain\RemotePrograms\RemoteProgramRunner;
use App\Domain\Routes\NativeRouteAdmission;
use App\Domain\Routes\NativeRouteCreatePlan;
use App\Domain\Routes\RouteStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Swamp\SwampDispatch;
use App\Models\DatabaseServer;
use App\Models\Instance;
use App\Models\InstanceRemoval;
use App\Models\Node;
use App\Models\NodeFact;
use App\Models\Operation;
use App\Models\Project;
use App\Models\Route;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(LazilyRefreshDatabase::class);

/** The failed removal that 606 is in: Route withdrawn, databases blocked, nothing deleted. */
beforeEach(function (): void {
    Queue::fake();
    $this->freezeTime();
    $this->mock(LeafCertificateSigner::class)->shouldReceive('rootCertificate')->andReturn('fixture-root-certificate');
    Http::preventStrayRequests();
    $this->node = Node::withoutEvents(fn (): Node => Node::query()->create(['name' => 'restore-node', 'status' => 'active', 'platform' => 'linux',
        'architecture' => 'x86_64', 'wireguard_ip' => '10.44.0.9', 'public_ssh_host' => '10.44.0.9', 'user' => 'orbit',
        'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'fixture-public-key', 'ssh_host_fingerprint' => 'fixture']));
    $this->node->roles()->create(['role' => RoleName::AppDev, 'status' => 'active']);
    $control = Node::withoutEvents(fn (): Node => Node::query()->create(['name' => 'control', 'status' => 'active', 'platform' => 'linux',
        'wireguard_ip' => '10.44.0.209', 'public_ssh_host' => '10.44.0.209', 'user' => 'orbit', 'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'fixture-control-key']));
    $control->roles()->create(['role' => RoleName::Swamp, 'status' => 'active']);
    $this->project = Project::query()->create(['name' => 'Restore fixture', 'slug' => 'restore-fixture', 'type' => 'node-package',
        'repository_url' => 'https://github.com/example/fixture.git', 'default_branch' => 'main', 'root' => 'public']);
    $this->instance = Instance::query()->create(['project_id' => $this->project->id, 'node_id' => $this->node->id, 'name' => 'fixture',
        'checkout_path' => '/home/orbit/apps/fixture', 'status' => 'active', 'source_layout' => 'worktree', 'root' => 'public',
        'branch' => 'main', 'starting_commit' => str_repeat('a', 40)]);
    $this->route = Route::query()->create(['kind' => 'app', 'project_id' => $this->project->id, 'node_id' => $this->node->id,
        'provenance' => 'explicit', 'publication' => 'private', 'domain' => 'fixture.restore.test', 'status' => 'pending']);
    $this->route->targets()->create(['instance_id' => $this->instance->id, 'position' => 0]);
    // What a first `route:create` of this Route plans: restore must publish the same thing.
    $this->createPlan = app(NativeRouteAdmission::class)->capture('route.create', $this->route->fresh(), [], $this->node->id)['route_create'];
    $this->route->update(['status' => 'active', 'sites_published' => true]);
    $this->mock(RemoteProgramRunner::class)->shouldReceive('run')->andReturn(new ProgramResult('inspected', ['dependents' => []], ''));
    $this->destroy = app(StartInstanceDestroyAction::class)->execute($this->instance, true);
    $this->removal = InstanceRemoval::query()->findOrFail($this->destroy->input['removal_id']);
    $this->removal->update(['status' => InstanceRemovalStatus::Failed, 'current_step' => InstanceRemovalStep::RuntimeCleanup,
        'failed_step' => InstanceRemovalStep::RuntimeCleanup, 'error_code' => 'instance.removal_incomplete']);
    // The progress map as NativeRunProgress stores it: keyed by event hash, not a list.
    $this->destroy->update(['status' => 'failed', 'finished_at' => now(), 'error_code' => 'instance.removal_incomplete',
        'state' => ['swamp_run_id' => 'fixture-run', 'native_progress' => ['steps' => [
            'a1' => ['name' => 'Withdraw the Route', 'job' => 'destroy', 'status' => 'succeeded'],
            'b2' => ['name' => 'Remove hibernation files-0-0', 'job' => 'destroy', 'status' => 'succeeded'],
            'c3' => ['name' => 'Drop owned databases-0-0', 'job' => 'destroy', 'status' => 'failed'],
            'd4' => ['name' => 'Remove the source', 'job' => 'destroy', 'status' => 'skipped'],
            'e5' => ['name' => 'Delete the container', 'job' => 'destroy', 'status' => 'skipped'],
        ]]]]);
    app(RoutePublicationHolds::class)->release($this->destroy->id);
    $this->checkout = function (array $data = []): void {
        $path = $this->instance->checkout_path;
        NodeFact::query()->updateOrCreate(['node_id' => $this->node->id, 'kind' => 'checkout', 'subject' => $path], [
            'digest' => str_repeat('a', 64), 'observed_at' => now(), 'received_at' => now(),
            'data' => [...['application' => $path, 'top_level' => $path, 'common_dir' => '/home/orbit/apps/default/.git', 'linked' => true,
                'worktrees' => [$path], 'origin' => 'https://github.com/example/fixture.git', 'owner' => 'orbit', 'head' => str_repeat('c', 40),
                'branch' => 'other-branch', 'default_branch' => 'main', 'artisan' => 'absent', 'composer' => null, 'env' => null], ...$data]]);
    };
    ($this->checkout)();
    $this->agent = function (bool $online): void {
        $view = Mockery::mock(AgentStateView::class);
        $clock = (float) now()->format('U.u');
        $view->shouldReceive('subscriber')->andReturn(new AgentViewSubscriberHealth($clock, true, true, 1));
        $view->shouldReceive('node')->andReturn(new AgentNodeView($this->node->id, AgentViewFreshness::Fresh, receivedAt: $online ? $clock : $clock - 3600));
        $view->shouldReceive('lastSeen')->andReturn(null);
        app()->instance(AgentStateView::class, $view);
    };
    ($this->agent)(true);
    $this->restore = fn (): Operation => app(StartInstanceRestoreAction::class)->execute($this->instance->fresh());
    $this->finish = function (Operation $operation, string $status, array $steps, ?array $failure = null): Operation {
        $operation->update(['status' => 'running', 'started_at' => now(), 'state' => [...($operation->state ?? []), 'swamp_run_id' => 'restore-run']]);

        return app(InstanceRestoreProjection::class)->apply($operation->fresh(), ['run_id' => 'restore-run', 'status' => $status,
            'started_at' => now()->toIso8601String(), 'finished_at' => now()->toIso8601String(),
            'result' => ['steps' => $steps], ...($failure === null ? [] : ['failure' => $failure])]);
    };
});

function expectRestoreRefusal(Closure $call, string $code): void
{
    try {
        $call();
        test()->fail("Expected {$code}.");
    } catch (ResourceOperationException $exception) {
        expect($exception->errorCode)->toBe($code);
    }
}

it('republishes the same Route through the Route create plan and records the Instance active in one step', function (): void {
    expect($this->instance->fresh()->status)->toBe(InstanceState::Removing)
        ->and($this->route->fresh()->status)->toBe(RouteStatus::Retiring);
    $operation = ($this->restore)();
    $plan = $operation->input['route_create'];
    expect($operation->kind)->toBe('instance.restore')
        ->and($operation->lock_key)->toBe('instance:'.$this->instance->id)
        ->and($operation->input['route_id'])->toBe($this->route->id)
        ->and($plan['route_id'])->toBe($this->route->id)
        ->and($plan['site_nodes'])->toBe($this->createPlan['site_nodes'])
        ->and(array_column($plan['caddy'], 'node_id'))->toBe(array_column($this->createPlan['caddy'], 'node_id'))
        ->and(array_column($plan['probes'], 'domain'))->toBe(['fixture.restore.test'])
        ->and(collect($plan['caddy'])->every(fn (array $row): bool => str_contains($row['content'], 'fixture.restore.test')))->toBeTrue()
        ->and(RoutePublicationHolds::nodes($operation))->toBe([$this->node->id])
        // Nothing changes before the run succeeds.
        ->and($this->instance->fresh()->status)->toBe(InstanceState::Removing)
        ->and($this->route->fresh()->status)->toBe(RouteStatus::Retiring);

    ($this->finish)($operation, 'succeeded', ['Check the databases' => ['status' => 'skipped'], 'Republish the Route' => ['status' => 'succeeded']]);

    $route = Route::query()->sole();
    expect($operation->fresh()->status->value)->toBe('succeeded')
        ->and($operation->fresh()->lock_key)->toBeNull()
        ->and($this->instance->fresh()->status)->toBe(InstanceState::Active)
        ->and($this->instance->fresh()->removalMember)->toBeNull()
        ->and($this->removal->fresh()->status)->toBe(InstanceRemovalStatus::Abandoned)
        ->and($this->removal->members()->sole()->abandoned_at)->not->toBeNull()
        ->and($route->id)->toBe($this->route->id)
        ->and($route->domain)->toBe('fixture.restore.test')
        ->and($route->status)->toBe(RouteStatus::Active)
        ->and($route->sites_published)->toBeTrue()
        ->and($this->destroy->fresh()->status->value)->toBe('failed');
    // A later destroy starts a new removal.
    $next = app(StartInstanceDestroyAction::class)->execute($this->instance->fresh(), true);
    expect($next->input['removal_id'])->not->toBe($this->removal->id);
});

it('lets the Route create sign step sign the admitted leaf of a restore', function (): void {
    $operation = ($this->restore)();
    $operation->update(['status' => 'running', 'started_at' => now(), 'state' => [...($operation->state ?? []), 'swamp_run_id' => 'restore-run']]);
    $leaf = ['node_id' => $this->node->id, 'scope' => 'app-instance-'.$this->instance->id, 'state' => 'current'];
    expect(app(SignDirectCertificatesAction::class)->handle($operation->fresh(), $this->node->id, 'restore-run', [$leaf]))->toBe([]);
    expectRestoreRefusal(fn () => app(SignDirectCertificatesAction::class)->handle($operation->fresh(), $this->node->id, 'restore-run',
        [[...$leaf, 'scope' => 'app-instance-999']]), 'certificate.intent_mismatch');
});

it('keeps the failed removal when a database is closed and names it', function (): void {
    $operation = ($this->restore)();
    ($this->finish)($operation, 'failed', ['Check the databases-0' => ['status' => 'failed']], ['failed_step' => 'Check the databases-0',
        'detail' => "exec failed on 1/1 host(s): target (exit 3: ERROR:  instance.restore_database_closed: fx, fx_test\n)", 'stderr_recorded' => false]);
    expect($operation->fresh()->error_code)->toBe('instance.restore_database_closed')
        ->and($operation->fresh()->result['failed_step'])->toBe('Check the databases')
        ->and($this->instance->fresh()->status)->toBe(InstanceState::Removing)
        ->and($this->removal->fresh()->status)->toBe(InstanceRemovalStatus::Failed)
        ->and($this->route->fresh()->status)->toBe(RouteStatus::Retiring);
    // A failed restore can be repeated.
    expect(($this->restore)()->id)->not->toBe($operation->id);
});

it('maps a failed journal clear after publication and keeps the records', function (string $detail, string $code): void {
    $operation = ($this->restore)();
    ($this->finish)($operation, 'failed', ['Republish the Route' => ['status' => 'succeeded'], 'Clear the fence journal-0' => ['status' => 'failed']],
        ['failed_step' => 'Clear the fence journal-0', 'detail' => $detail, 'stderr_recorded' => false]);
    expect($operation->fresh()->error_code)->toBe($code)
        ->and($this->instance->fresh()->status)->toBe(InstanceState::Removing)
        ->and($this->removal->fresh()->status)->toBe(InstanceRemovalStatus::Failed)
        ->and($this->route->fresh()->status)->toBe(RouteStatus::Retiring);
})->with([
    'closed meanwhile' => ['exec failed (exit 3: ERROR:  instance.restore_database_closed: fx)', 'instance.restore_database_closed'],
    'server unreachable' => ['ssh: connect to host 10.44.0.3 port 22: Connection refused', 'instance.restore_database_check_failed'],
]);

it('reports a failed publication and leaves the records', function (): void {
    $operation = ($this->restore)();
    ($this->finish)($operation, 'failed', ['Republish the Route' => ['status' => 'failed']],
        ['failed_step' => 'Republish the Route', 'detail' => 'probe failed', 'stderr_recorded' => false]);
    expect($operation->fresh()->error_code)->toBe('instance.restore_publication_failed')
        ->and($this->instance->fresh()->status)->toBe(InstanceState::Removing)
        ->and($this->route->fresh()->status)->toBe(RouteStatus::Retiring);
});

it('serializes restore and destroy in both directions', function (): void {
    $operation = ($this->restore)();
    expectRestoreRefusal(fn () => app(StartInstanceDestroyAction::class)->execute($this->instance->fresh(), true), 'operation.target_busy');
    expect($this->removal->fresh()->status)->toBe(InstanceRemovalStatus::Failed);
    // A timed-out restore keeps the Instance until its run is proven ended.
    $operation->update(['status' => 'failed', 'error_code' => 'operation.timed_out', 'finished_at' => now(),
        'state' => ['native_timeout' => ['budget_seconds' => 900, 'deadline' => now()->toISOString()]]]);
    expectRestoreRefusal(fn () => app(StartInstanceDestroyAction::class)->execute($this->instance->fresh(), true), 'operation.target_busy');
    expectRestoreRefusal($this->restore, 'instance.restore_busy');
    $operation->update(['lock_key' => null]);
    // And a running destroy refuses a restore.
    $retry = app(StartInstanceDestroyAction::class)->execute($this->instance->fresh(), true);
    expectRestoreRefusal($this->restore, 'instance.restore_state_invalid');
    expect($retry->status->terminal())->toBeFalse();
});

it('refuses a removal run that has not ended, timed-out runs included', function (array $changes): void {
    $this->destroy->update($changes);
    expectRestoreRefusal($this->restore, 'instance.restore_state_invalid');
    expect(Operation::query()->where('kind', 'instance.restore')->count())->toBe(0);
})->with([
    'running' => [['status' => 'running', 'finished_at' => null]],
    'timed out, native end unproven' => [['error_code' => 'operation.timed_out', 'state' => ['native_timeout' => ['budget_seconds' => 420, 'deadline' => '2026-11-12T09:00:00Z']]]],
]);

it('refuses a removal run with no record that it skipped a planned deleting step', function (Closure $change): void {
    $change($this);
    expectRestoreRefusal($this->restore, 'instance.restore_state_invalid');
})->with([
    'no progress at all' => [fn ($test) => $test->destroy->update(['state' => ['swamp_run_id' => 'fixture-run']])],
    'a planned Process step missing' => [fn ($test) => seedSavedIntent($test, ['direct_destroy' => ['runtime' => ['processes' => [['process_id' => 1]]]]])],
    'a planned teardown step missing' => [fn ($test) => seedSavedIntent($test, ['direct_destroy' => ['teardown' => [['instance_id' => 1, 'steps' => [['name' => 'down']]]]]])],
]);

it('refuses a removal that deleted data', function (string $step): void {
    $state = $this->destroy->state;
    $state['native_progress']['steps']['z9'] = ['name' => $step, 'job' => 'destroy', 'status' => 'succeeded'];
    $this->destroy->update(['state' => $state]);
    expectRestoreRefusal($this->restore, 'instance.restore_already_removed');
})->with(['Remove the source', 'Delete the container', 'Stop, remove Processes-0', 'Run teardown steps-0', 'Remove SQLite files-0-0']);

it('refuses what it cannot restore', function (Closure $change, string $code): void {
    $change($this);
    expectRestoreRefusal($this->restore, $code);
    expect(Operation::query()->where('kind', 'instance.restore')->count())->toBe(0)
        ->and($this->instance->fresh()->status)->toBe(InstanceState::Removing);
})->with([
    'an active Instance' => [abandonRestoreFixture(...), 'instance.restore_state_invalid'],
    'a saved Schedule' => [fn ($test) => seedSavedIntent($test,
        ['direct_destroy' => ['runtime' => ['schedules' => [['id' => 1]]]]]), 'instance.restore_unsupported'],
    'a saved SQLite file' => [fn ($test) => seedSavedIntent($test,
        ['direct_destroy' => ['database' => ['sqlite' => [['connection_id' => 1, 'path' => '/x']]]]]), 'instance.restore_unsupported'],
    'a Route that is no longer retiring' => [fn ($test) => $test->route->fresh()->update(['status' => 'active', 'sites_published' => true]), 'instance.restore_already_removed'],
    'another checkout origin' => [fn ($test) => ($test->checkout)(['origin' => 'https://github.com/example/other.git']), 'instance.restore_source_changed'],
    'another top level' => [fn ($test) => ($test->checkout)(['top_level' => '/home/orbit/apps']), 'instance.restore_source_changed'],
    'a gone checkout' => [fn ($test) => NodeFact::query()->delete(), 'instance.restore_source_changed'],
    'a silent agent' => [fn ($test) => ($test->agent)(false), 'instance.restore_source_changed'],
    'another open Operation' => [fn ($test) => Operation::query()->create(['kind' => 'route.update', 'target_type' => 'route', 'target_id' => $test->route->id,
        'node_id' => $test->node->id, 'input' => [], 'state' => [], 'status' => 'running', 'sequence' => 1, 'attempts' => 0]), 'instance.restore_busy'],
]);

it('renders the database batches into the workflow input without a password in the intent', function (): void {
    $server = DatabaseServer::query()->create(['slug' => 'fixture-postgres', 'node_id' => $this->node->id, 'driver' => 'pgsql',
        'tag' => '18', 'port' => 5434, 'root_password' => 'fixture-only', 'status' => 'active']);
    seedSavedIntent($this, ['direct_destroy' => ['database' => ['servers' => [
        ['server_id' => $server->id, 'node_id' => $this->node->id, 'intent' => ['driver' => 'pgsql', 'container' => 'orbit-process-6-fixture-postgres',
            'databases' => ['fx', 'fx_test'], 'users' => ['fxu'], 'owners' => ['fx' => 'fxu', 'fx_test' => 'fxu']]],
    ]]]]);
    $operation = ($this->restore)();
    // A terminal Operation renders vault references without pushing a copy.
    $operation->update(['status' => 'failed', 'finished_at' => now(), 'lock_key' => null]);
    $inputs = app(DirectOperation::class)->workflowInputs($operation->fresh());
    $id = $this->instance->id;
    $statements = app(DatabaseStatements::class)->restore(['fx', 'fx_test']);
    expect($inputs['execution_secrets']["server_{$server->id}_check"])->toBe(['vault' => 'orbit-control-inputs', 'key' => "instance-restore-{$id}-{$server->id}-check"])
        ->and($inputs['execution_secrets']["server_{$server->id}_clear"]['key'])->toBe("instance-restore-{$id}-{$server->id}-clear")
        ->and(json_encode($inputs))->not->toContain('fixture-only')
        ->and($inputs['intent']['servers'][0]['server_id'])->toBe($server->id)
        ->and($inputs['intent']['servers'][0]['admin_client'][3])->toBe('orbit-process-6-fixture-postgres')
        ->and($inputs['intent']['servers'][0]['ssh_execution']['target']['host'])->toBe('10.44.0.9')
        ->and($inputs['intent']['route_create']['route_id'])->toBe($this->route->id)
        ->and($inputs['intent']['route_secrets'])->toBe($operation->input['route_secrets'])
        ->and($statements['check'])->toContain("'fx','fx_test'")->not->toContain('DELETE')
        ->and($statements['clear'])->toStartWith("BEGIN;\n".$statements['check'])
        ->and($statements['clear'])->toContain('DELETE FROM orbit_admin.database_removal_fences j USING pg_database d WHERE d.oid = j.oid AND d.datname = j.datname');
});

it('renders a waiting restore with its target serving, unlike the stored retiring Route', function (): void {
    $operation = ($this->restore)();
    $plan = $operation->input['route_create'];
    $stored = app(NativeRouteCreatePlan::class)->refreshCaddy($this->route->fresh(), $plan, false, false);
    $candidate = app(NativeRouteCreatePlan::class)->refreshCaddy(StartInstanceRestoreAction::candidate($this->route->fresh()), $plan, false, false);
    expect($stored['caddy'][0]['content'])->not->toContain('fixture.restore.test')
        ->and($candidate['caddy'][0]['content'])->toContain('fixture.restore.test');
});

it('refreshes a waiting restore from the catalog with its own site still in it', function (): void {
    $other = Operation::query()->create(['kind' => 'route.create', 'target_type' => 'route', 'target_id' => 999, 'node_id' => $this->node->id,
        'input' => ['route_lifecycle' => ['route_mutations' => ['forward' => ['publications' => [['node_id' => $this->node->id, 'serves_routes' => true]]]]]],
        'status' => 'queued', 'state' => [], 'sequence' => 1, 'attempts' => 0]);
    $holds = app(RoutePublicationHolds::class);
    expect($holds->admit($other))->toBeTrue();
    $operation = ($this->restore)();
    expect($holds->admit($operation))->toBeFalse()
        ->and($operation->fresh()->status->value)->toBe('waiting');
    // A stale plan: the refresh must render the restored site again, not drop it as the stored retiring Route would.
    $input = $operation->fresh()->input;
    $input['route_create']['caddy'][0]['content'] = 'stale';
    $operation->update(['input' => $input]);
    $started = new ArrayObject;
    app()->instance(SwampDispatch::class, new readonly class($started)
    {
        public function __construct(private ArrayObject $log) {}

        public function start(Operation $operation): void
        {
            $this->log[] = $operation->id;
        }
    });
    $other->update(['status' => 'succeeded', 'finished_at' => now()]);
    $holds->release($other->id);
    $holds->promoteAndStart();
    expect($started->getArrayCopy())->toBe([$operation->id])
        ->and($operation->fresh()->input['route_create']['caddy'][0]['content'])->toContain('https://fixture.restore.test');
});

function abandonRestoreFixture(object $test): void
{
    $test->removal->members()->update(['abandoned_at' => now()]);
    $test->removal->update(['status' => InstanceRemovalStatus::Abandoned, 'current_step' => null, 'failed_step' => null, 'error_code' => null]);
    $test->instance->update(['status' => InstanceState::Active]);
}

/** Tests only: the saved intent is immutable by trigger, so this drops the trigger to seed a saved Schedule, SQLite file or server. */
function seedSavedIntent(object $test, array $patch): void
{
    DB::statement('DROP TRIGGER IF EXISTS instance_removals_native_intent_immutable');
    $test->removal->update(['native_intent' => array_replace_recursive($test->removal->native_intent, $patch)]);
}
