<?php

declare(strict_types=1);

use App\Actions\DatabaseConnections\CreateDatabaseUserAction;
use App\Actions\DatabaseConnections\CreateServerDatabaseAction;
use App\Actions\Instances\StartInstanceDestroyAction;
use App\Actions\Instances\StartInstanceRestoreAction;
use App\Actions\Operations\CancelOperationAction;
use App\Data\DatabaseConnections\CreateDatabaseUserData;
use App\Data\DatabaseConnections\CreateServerDatabaseData;
use App\Domain\Certificates\LeafCertificateSigner;
use App\Domain\DatabaseServers\DatabaseDestroySafety;
use App\Domain\DatabaseServers\InstanceRestoreFence;
use App\Domain\GitHub\GitReadEnvironment;
use App\Domain\Instances\DirectInstanceDestroyIntent;
use App\Domain\Instances\InstanceRemovalStatus;
use App\Domain\Instances\InstanceRemovalStep;
use App\Domain\Instances\InstanceRestoreProjection;
use App\Domain\Instances\InstanceState;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\RoutePublicationHolds;
use App\Domain\RemotePrograms\ProgramResult;
use App\Domain\RemotePrograms\RemoteProgram;
use App\Domain\RemotePrograms\RemoteProgramFailed;
use App\Domain\RemotePrograms\RemoteProgramFailure;
use App\Domain\RemotePrograms\RemoteProgramRunner;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\AgentView\WebSocketEndpoint;
use App\Infrastructure\Swamp\NativeRunProgress;
use App\Infrastructure\Swamp\StartSwampRun;
use App\Models\DatabaseConnection;
use App\Models\DatabaseExecutionIntent;
use App\Models\DatabaseServer;
use App\Models\Instance;
use App\Models\InstanceRemoval;
use App\Models\InstanceRemovalMember;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Process;
use App\Models\Project;
use App\Models\Route;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(LazilyRefreshDatabase::class);

beforeEach(function (): void {
    Queue::fake();
    $this->mock(LeafCertificateSigner::class)->shouldReceive('rootCertificate')->andReturn('fixture-root-certificate');
    Http::preventStrayRequests();
    $node = Node::withoutEvents(fn (): Node => Node::query()->create(['name' => 'restore-node', 'status' => 'active', 'platform' => 'linux',
        'architecture' => 'x86_64', 'wireguard_ip' => '10.44.0.9', 'public_ssh_host' => '10.44.0.9', 'user' => 'orbit',
        'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'fixture-public-key']));
    $node->roles()->create(['role' => RoleName::AppDev, 'status' => 'active']);
    $control = Node::withoutEvents(fn (): Node => Node::query()->create(['name' => 'control', 'status' => 'active', 'platform' => 'linux',
        'wireguard_ip' => '10.44.0.209', 'public_ssh_host' => '10.44.0.209', 'user' => 'orbit', 'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'fixture-control-key']));
    $control->roles()->create(['role' => RoleName::Swamp, 'status' => 'active']);
    $project = Project::query()->create(['name' => 'Restore fixture', 'slug' => 'restore-fixture', 'type' => 'node-package',
        'repository_url' => 'https://github.com/example/fixture.git', 'default_branch' => 'main', 'root' => 'public']);
    $this->instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $node->id, 'name' => 'fixture',
        'checkout_path' => '/home/orbit/apps/fixture', 'status' => 'active', 'source_layout' => 'checkout', 'root' => 'public',
        'branch' => 'main', 'starting_commit' => str_repeat('a', 40)]);
    $this->route = Route::query()->create(['kind' => 'app', 'project_id' => $project->id, 'node_id' => $node->id,
        'provenance' => 'explicit', 'publication' => 'private', 'domain' => 'fixture.restore.test', 'status' => 'pending']);
    $this->route->targets()->create(['instance_id' => $this->instance->id, 'position' => 0]);
    $this->route->update(['status' => 'active', 'sites_published' => true]);
    $this->destroy = app(StartInstanceDestroyAction::class)->execute($this->instance, true);
    $this->instance->refresh();
    $this->removal = InstanceRemoval::query()->findOrFail($this->destroy->input['removal_id']);
    $this->removal->update(['status' => InstanceRemovalStatus::Failed, 'current_step' => InstanceRemovalStep::RuntimeCleanup,
        'failed_step' => InstanceRemovalStep::RuntimeCleanup, 'error_code' => 'fixture.drop_blocked']);
    $this->destroy->update(['status' => 'running', 'state' => ['swamp_run_id' => 'fixture-run']]);
    app(NativeRunProgress::class)->apply($this->destroy->id, 'fixture-run', [
        'kind' => 'step_skipped', 'seq' => 1, 'jobId' => 'destroy', 'stepId' => 'Remove the source',
        'completedAt' => now()->toISOString(),
    ]);
    $this->destroy->refresh()->update(['status' => 'failed', 'finished_at' => now(), 'error_code' => 'fixture.drop_blocked']);
    app(RoutePublicationHolds::class)->release($this->destroy->id);
    $this->socket = new class implements WebSocketClient
    {
        public array $last = [];

        public string $runStatus = 'failed';

        public array $statuses = [];

        public function connect(WebSocketEndpoint $endpoint, float $timeoutSeconds): void {}

        public function send(array $message): void
        {
            $this->last = $message;
        }

        public function receive(float $timeoutSeconds): array
        {
            $id = $this->last['payload']['workflowIdOrName'] ?? '';
            $operation = Operation::query()->where('state->swamp_run_id', $id)->firstOrFail();

            return [['id' => $this->last['id'], 'type' => $this->last['type'], 'payload' => ['data' => [
                'id' => $id, 'workflowName' => $operation->kind === 'instance.destroy' ? 'operation-instance-destroy' : 'operation-instance-restore',
                'inputs' => ['operation_id' => $operation->id, 'node_id' => $operation->node_id, 'kind' => $operation->kind],
                'status' => $this->statuses[$operation->kind] ?? $this->runStatus, 'completedAt' => now()->toISOString(), 'jobs' => [],
            ]]]];
        }

        public function close(): void {}

        public function isConnected(): bool
        {
            return true;
        }
    };
    app()->instance(WebSocketClient::class, $this->socket);
    config(['swamp.address' => 'fixture.test', 'swamp.read_token' => 'fixture', 'swamp.ca_path' => '/tmp/fixture-ca']);
    $this->reader = new class implements RemoteProgramRunner
    {
        public string $reason = '';

        public int $calls = 0;

        public array $facts = [];

        public bool $failCatalog = false;

        public array $sourceInput = [];

        public function run(Node $node, RemoteProgram $program, array $input = [],
            ?GitReadEnvironment $gitRead = null, ?float $timeoutSeconds = null, ?Closure $cancelled = null): ProgramResult
        {
            $this->calls++;
            if (($input['action'] ?? '') === 'catalog') {
                if ($this->failCatalog) {
                    throw new RemoteProgramFailed($program->name, RemoteProgramFailure::TimedOut);
                }

                return new ProgramResult('intact', $this->facts, '');
            }
            if ($program->name === 'instance.destroy-git') {
                return new ProgramResult('inspected', ['dependents' => []], '');
            }

            $this->sourceInput = $input;

            return new ProgramResult($this->reason === '' ? 'intact' : 'refused',
                $this->reason === '' ? ['awake_before' => []] : ['reason' => $this->reason, 'path' => '/home/orbit/apps/fixture/missing.sqlite'], '');
        }
    };
    app()->instance(RemoteProgramRunner::class, $this->reader);
});

it('admits restoration without activating stored records and commits only after publication proof', function (): void {
    $restore = app(StartInstanceRestoreAction::class)->execute($this->instance);
    expect($restore->kind)->toBe('instance.restore')
        ->and($this->instance->fresh()->status)->toBe(InstanceState::Removing)
        ->and($this->route->fresh()->status->value)->toBe('retiring')
        ->and($restore->input['route_create']['site_nodes'])->not->toBeEmpty();
    $restore->update(['state' => ['swamp_run_id' => 'restore-run'], 'status' => 'running']);
    $data = ['status' => 'succeeded', 'run_id' => 'restore-run', 'started_at' => now()->toISOString(),
        'finished_at' => now()->toISOString(), 'result' => ['steps' => ['Republish the Route' => ['status' => 'succeeded']]]];
    $completed = app(InstanceRestoreProjection::class)->apply($restore, $data);
    expect($completed->result['restored'])->toBeTrue()
        ->and($this->instance->fresh()->status)->toBe(InstanceState::Active)
        ->and($this->route->fresh()->status->value)->toBe('active')
        ->and($this->removal->fresh()->status)->toBe(InstanceRemovalStatus::Abandoned)
        ->and($this->removal->members()->sole()->row_deleted_at)->toBeNull()
        ->and($this->destroy->fresh()->error_code)->toBe('fixture.drop_blocked');
    $repeat = app(StartInstanceRestoreAction::class)->execute($this->instance->fresh());
    expect($repeat->result['skipped'])->toBe('instance.already_restored')
        ->and($repeat->state)->not->toHaveKey('swamp_run_id');
});

it('refuses destructive step evidence before source or database contact', function (string $step, string $code): void {
    $state = $this->destroy->state;
    $state['native_progress']['steps'][] = ['name' => $step, 'status' => 'succeeded'];
    $this->destroy->update(['state' => $state]);
    expect(fn () => app(StartInstanceRestoreAction::class)->execute($this->instance))
        ->toThrow(ResourceOperationException::class, 'publication cannot reverse');
    expect($this->reader->calls)->toBe(0)
        ->and(Operation::query()->where('kind', 'instance.restore')->count())->toBe(0);
    try {
        app(StartInstanceRestoreAction::class)->execute($this->instance);
    } catch (ResourceOperationException $error) {
        expect($error->errorCode)->toBe($code);
    }
})->with([
    ['Delete the container', 'instance.restore_already_removed'],
    ['Remove the source', 'instance.restore_already_removed'],
    ['Stop, remove Processes-0', 'instance.restore_already_removed'],
    ['Run teardown steps-0', 'instance.restore_already_removed'],
    ['Remove SQLite files-0', 'instance.restore_already_removed'],
]);

it('refuses a terminal Operation whose native run is still running', function (): void {
    $this->socket->runStatus = 'running';
    try {
        app(StartInstanceRestoreAction::class)->execute($this->instance);
        test()->fail('Unsettled run admitted');
    } catch (ResourceOperationException $error) {
        expect($error->errorCode)->toBe('instance.restore_state_invalid');
    }
    expect($this->reader->calls)->toBe(0);
});

it('retains a failed removal after a publication failure', function (): void {
    $restore = app(StartInstanceRestoreAction::class)->execute($this->instance);
    $restore->update(['state' => ['swamp_run_id' => 'restore-failed'], 'status' => 'running']);
    $failed = app(InstanceRestoreProjection::class)->apply($restore, [
        'status' => 'failed', 'run_id' => 'restore-failed', 'started_at' => now()->toISOString(), 'finished_at' => now()->toISOString(),
        'result' => ['steps' => ['Republish the Route' => ['status' => 'failed'], 'Compensate publication-0' => ['status' => 'succeeded']],
            'withdrawal' => ['ready' => true]],
    ]);
    expect($failed->error_code)->toBe('instance.restore_publication_failed')
        ->and($this->instance->fresh()->status)->toBe(InstanceState::Removing)
        ->and($this->removal->fresh()->status)->toBe(InstanceRemovalStatus::Failed)
        ->and(InstanceRemovalMember::query()->live()->count())->toBe(1);
});

it('preserves a named source survivor refusal without recording a restore', function (string $reason): void {
    $this->reader->reason = $reason;
    try {
        app(StartInstanceRestoreAction::class)->execute($this->instance);
        test()->fail('Missing source admitted');
    } catch (ResourceOperationException $error) {
        expect($error->errorCode)->toBe($reason === 'source_changed' ? 'instance.restore_state_invalid' : 'instance.restore_already_removed')
            ->and($error->details['reason'])->toBe($reason);
    }
    expect(Operation::query()->where('kind', 'instance.restore')->count())->toBe(0)
        ->and($this->instance->fresh()->status)->toBe(InstanceState::Removing);
})->with(['source_removed', 'source_changed', 'container_removed']);

it('refuses queued running and waiting lifecycle work before any source contact', function (string $status): void {
    Operation::query()->create(['kind' => 'instance.deploy', 'target_type' => 'instance', 'target_id' => $this->instance->id,
        'node_id' => $this->instance->node_id, 'status' => $status, 'input' => [], 'state' => [], 'sequence' => 1]);
    try {
        app(StartInstanceRestoreAction::class)->execute($this->instance);
        test()->fail('Busy Instance admitted');
    } catch (ResourceOperationException $error) {
        expect($error->errorCode)->toBe('instance.restore_busy');
    }
    expect($this->reader->calls)->toBe(0);
})->with(['queued', 'running', 'waiting']);

it('refuses a removal that has not failed', function (): void {
    $this->removal->update(['status' => InstanceRemovalStatus::Removing, 'failed_step' => null, 'error_code' => null]);
    try {
        app(StartInstanceRestoreAction::class)->execute($this->instance);
        test()->fail('Nonfailed removal admitted');
    } catch (ResourceOperationException $error) {
        expect($error->errorCode)->toBe('instance.restore_state_invalid');
    }
    expect($this->reader->calls)->toBe(0);
});

it('keeps recovery runnable ahead of older waiters when transferring incomplete compensation holds', function (): void {
    $action = app(StartInstanceRestoreAction::class);
    $restore = $action->execute($this->instance);
    $restore->update(['state' => ['swamp_run_id' => 'restore-failed'], 'status' => 'running']);
    $failed = app(InstanceRestoreProjection::class)->apply($restore, [
        'status' => 'failed', 'run_id' => 'restore-failed', 'started_at' => now()->toISOString(), 'finished_at' => now()->toISOString(),
        'result' => ['steps' => ['Republish the Route' => ['status' => 'failed'], 'Compensate publication-0' => ['status' => 'failed']],
            'withdrawal' => ['ready' => false]],
    ]);
    expect($failed->error_code)->toBe('instance.restore_compensation_failed')
        ->and(DB::table('route_publication_holds')->where('operation_id', $restore->id)->exists())->toBeTrue();
    $nodes = RoutePublicationHolds::nodes($restore);
    $waiter = Operation::query()->create(['kind' => 'route.create', 'target_type' => 'route', 'target_id' => $this->route->id,
        'node_id' => $this->instance->node_id, 'status' => 'queued', 'sequence' => 1, 'state' => [],
        'input' => ['publications' => array_map(static fn (int $id): array => ['node_id' => $id, 'serves_routes' => true], $nodes)]]);
    $holds = app(RoutePublicationHolds::class);
    expect($holds->admit($waiter))->toBeFalse();
    $again = $action->execute($this->instance->fresh());
    expect($again->id)->not->toBe($restore->id)
        ->and($again->status->value)->toBe('queued')
        ->and($waiter->fresh()->status->value)->toBe('waiting')
        ->and($holds->promote())->toBe([])
        ->and(DB::table('route_publication_holds')->where('operation_id', $restore->id)->exists())->toBeFalse()
        ->and(DB::table('route_publication_holds')->where('operation_id', $again->id)->exists())->toBeTrue()
        ->and($again->input['awake_before'])->toBe($restore->input['awake_before']);
    app(CancelOperationAction::class)->execute($again);
    expect(array_map(static fn (Operation $operation): int => $operation->id, $holds->promote()))->toBe([$waiter->id]);
});

it('checks the entire database and user catalog and captures only matched fence rows', function (string $fault, ?string $code): void {
    $server = DatabaseServer::query()->create(['slug' => 'fixture-postgres', 'driver' => 'pgsql',
        'node_id' => $this->instance->node_id, 'tag' => '18', 'port' => 5432, 'root_password' => 'fixture-secret', 'status' => 'active']);
    $facts = ['databases' => [
        ['name' => 'fixture', 'oid' => 42, 'owner' => 'owner', 'allow' => true],
        ['name' => 'fixture_test', 'oid' => 43, 'owner' => 'owner', 'allow' => true],
    ], 'users' => [['name' => 'owner', 'oid' => 44]],
        'fences' => [['name' => 'fixture', 'oid' => 42, 'allow' => true]]];
    match ($fault) {
        'database' => array_pop($facts['databases']),
        'user' => $facts['users'] = [],
        'owner' => $facts['databases'][0]['owner'] = 'foreign',
        'oid' => $facts['fences'][0]['oid'] = 99,
        'admission' => $facts['fences'][0]['allow'] = false,
        'absent' => $facts['fences'] = [],
        'closed' => $facts['databases'][0]['allow'] = false,
        'closed_without_journal' => [$facts['databases'][0]['allow'] = false, $facts['fences'] = []],
        default => null,
    };
    $this->reader->facts = $facts;
    $this->reader->failCatalog = $fault === 'transport';
    $database = ['servers' => [['server_id' => $server->id, 'node_id' => $server->node_id,
        'intent' => ['driver' => 'pgsql', 'container' => 'fixture-postgres',
            'databases' => ['fixture', 'fixture_test'], 'users' => ['owner'],
            'owners' => ['fixture' => 'owner', 'fixture_test' => 'owner']]]]];
    try {
        $captured = app(InstanceRestoreFence::class)->capture($database);
        expect($code)->toBeNull()->and($captured[0]['fences'])->toBe($facts['fences'])
            ->and($captured[0]['databases'][0]['allow'])->toBeTrue()
            ->and($captured[0]['statements'])->toContain('j.datname = p.name AND j.oid = p.oid::oid', 'd.datallowconn = p.allow',
                "format('ALTER DATABASE %I ALLOW_CONNECTIONS %s'", 'LOCK TABLE pg_catalog.pg_database', 'removed <> json_array_length(live_journal)')
            ->not->toContain('DROP ', 'unrelated');
    } catch (ResourceOperationException $error) {
        expect($error->errorCode)->toBe($code);
    }
})->with([
    ['intact', null], ['closed', null], ['absent', null], ['closed_without_journal', 'instance.restore_database_changed'], ['transport', 'instance.restore_database_changed'],
    ['database', 'instance.restore_database_changed'],
    ['user', 'instance.restore_database_changed'],
    ['owner', 'instance.restore_database_changed'],
    ['oid', 'instance.restore_database_changed'],
    ['admission', 'instance.restore_database_changed'],
]);

it('retains the first restore fence capture after settlement and refuses replacement identities on a later attempt', function (): void {
    $server = DatabaseServer::query()->create(['slug' => 'retry-postgres', 'driver' => 'pgsql',
        'node_id' => $this->instance->node_id, 'tag' => '18', 'port' => 5432, 'root_password' => 'fixture-secret', 'status' => 'active']);
    $database = ['servers' => [['server_id' => $server->id, 'node_id' => $server->node_id,
        'intent' => ['driver' => 'pgsql', 'container' => 'fixture-postgres', 'databases' => ['fixture'],
            'users' => ['owner'], 'owners' => ['fixture' => 'owner']]]]];
    $this->reader->facts = ['databases' => [['name' => 'fixture', 'oid' => 42, 'owner' => 'owner', 'allow' => true]],
        'users' => [['name' => 'owner', 'oid' => 44]], 'fences' => [['name' => 'fixture', 'oid' => 42, 'allow' => true]]];
    $fence = app(InstanceRestoreFence::class);
    $this->reader->facts['databases'][0]['allow'] = false;
    $first = $fence->capture($database);
    expect($first[0]['databases'][0]['allow'])->toBeTrue();
    $this->reader->facts['databases'][0]['allow'] = true;
    $this->reader->facts['fences'] = [];
    $again = $fence->capture($database, $first);
    expect($again[0]['fences'])->toBe($first[0]['fences']);
    $this->reader->facts['databases'][0]['allow'] = false;
    try {
        $fence->capture($database, $first);
        test()->fail('A closed database without its required journal was accepted');
    } catch (ResourceOperationException $error) {
        expect($error->errorCode)->toBe('instance.restore_database_changed')
            ->and($error->details['databases'])->toBe(['fixture']);
    }
    $this->reader->facts['databases'][0]['allow'] = true;
    $this->reader->facts['databases'][0]['oid'] = 99;
    try {
        $fence->capture($database, $first);
        test()->fail('Replacement database accepted');
    } catch (ResourceOperationException $error) {
        expect($error->errorCode)->toBe('instance.restore_database_changed');
    }
});

it('names lost member Route and target records without starting recovery', function (string $lost, string $code): void {
    // Simulate external record loss only in the disposable test database.
    if ($lost === 'member') {
        DB::unprepared('DROP TRIGGER instance_removal_members_delete');
        DB::table('instance_removal_members')->where('instance_removal_id', $this->removal->id)->delete();
    } else {
        DB::unprepared('DROP TRIGGER route_targets_active_delete');
        DB::table('route_targets')->where('route_id', $this->route->id)->delete();
        if ($lost === 'route') {
            DB::table('routes')->where('id', $this->route->id)->delete();
        }
    }
    try {
        app(StartInstanceRestoreAction::class)->execute($this->instance);
        test()->fail('Lost resource admitted');
    } catch (ResourceOperationException $error) {
        expect($error->errorCode)->toBe($code);
    }
    expect(Operation::query()->where('kind', 'instance.restore')->count())->toBe(0)->and($this->reader->calls)->toBe(0);
})->with([
    ['member', 'instance.restore_already_removed'],
    ['route', 'instance.restore_already_removed'],
    ['target', 'instance.restore_already_removed'],
]);

it('refuses withdrawn Schedule history and missing step evidence', function (string $fault, string $code): void {
    if ($fault === 'schedule') {
        DB::unprepared('DROP TRIGGER instance_removals_native_intent_immutable');
        $input = $this->removal->native_intent;
        $input['direct_destroy']['runtime']['schedules'] = [['id' => 1, 'group' => 'fixture', 'cascade' => true]];
        $this->removal->update(['native_intent' => $input]);
    } else {
        $this->destroy->update(['state' => ['swamp_run_id' => 'fixture-run']]);
    }
    try {
        app(StartInstanceRestoreAction::class)->execute($this->instance);
        test()->fail('Incomplete recovery evidence admitted');
    } catch (ResourceOperationException $error) {
        expect($error->errorCode)->toBe($code);
    }
    expect($this->reader->calls)->toBe(0);
})->with([
    ['schedule', 'instance.restore_already_removed'],
    ['evidence', 'instance.restore_database_changed'],
]);

it('retries a definite unsubmitted restore failure through the shared start-refusal hook', function (): void {
    $action = app(StartInstanceRestoreAction::class);
    $restore = $action->execute($this->instance);
    app(StartSwampRun::class)->refuse($restore,
        new ResourceOperationException('swamp.start_refused', 'Fixture native start refusal.', 503));
    expect($restore->fresh()->state['native_start_not_submitted'])->toBeTrue();
    $again = $action->execute($this->instance->fresh());
    expect($again->id)->not->toBe($restore->id)
        ->and($again->input['removal_id'])->toBe($restore->input['removal_id'])
        ->and($this->instance->fresh()->status)->toBe(InstanceState::Removing)
        ->and($this->removal->fresh()->status)->toBe(InstanceRemovalStatus::Failed);
});

it('projects a cancelled native restore as failed and admits a fresh attempt', function (): void {
    $restore = app(StartInstanceRestoreAction::class)->execute($this->instance);
    $restore->update(['status' => 'running', 'state' => ['swamp_run_id' => 'cancelled-restore']]);
    $failed = app(InstanceRestoreProjection::class)->apply($restore, [
        'run_id' => 'cancelled-restore', 'status' => 'cancelled',
        'started_at' => now()->toISOString(), 'finished_at' => now()->toISOString(),
        'result' => ['steps' => ['Republish the Route' => ['status' => 'skipped']]],
    ]);
    expect($failed->status->value)->toBe('failed')
        ->and($this->instance->fresh()->status)->toBe(InstanceState::Removing)
        ->and($this->removal->fresh()->status)->toBe(InstanceRemovalStatus::Failed)
        ->and(InstanceRemovalMember::query()->live()->count())->toBe(1);
    $this->socket->statuses['instance.restore'] = 'cancelled';
    $retry = app(StartInstanceRestoreAction::class)->execute($this->instance->fresh());
    expect($retry->id)->not->toBe($failed->id)
        ->and($retry->input['removal_id'])->toBe($this->removal->id);
});

it('repeats an unstarted restore ended before native submission', function (): void {
    $restore = app(StartInstanceRestoreAction::class)->execute($this->instance);
    app(CancelOperationAction::class)->execute($restore);
    expect($restore->fresh()->status->value)->toBe('cancelled')
        ->and($this->instance->fresh()->status)->toBe(InstanceState::Removing)
        ->and($this->removal->fresh()->status)->toBe(InstanceRemovalStatus::Failed);
    $retry = app(StartInstanceRestoreAction::class)->execute($this->instance->fresh());
    expect($retry->id)->not->toBe($restore->id);
});

it('reports settlement failure from the native step without parsing administrator output', function (): void {
    $restore = app(StartInstanceRestoreAction::class)->execute($this->instance);
    $restore->update(['status' => 'running', 'state' => ['swamp_run_id' => 'settlement-failure']]);
    $failed = app(InstanceRestoreProjection::class)->apply($restore, [
        'run_id' => 'settlement-failure', 'status' => 'failed',
        'started_at' => now()->toISOString(), 'finished_at' => now()->toISOString(),
        'result' => ['steps' => ['Settle database fences-0' => ['status' => 'failed'],
            'Republish the Route' => ['status' => 'skipped']]],
    ]);
    expect($failed->error_code)->toBe('instance.restore_database_changed')
        ->and($failed->result['compensation_complete'])->toBeTrue()
        ->and($this->instance->fresh()->status)->toBe(InstanceState::Removing)
        ->and($this->removal->fresh()->status)->toBe(InstanceRemovalStatus::Failed);
});

it('accepts the production hash-keyed progress map from the real projector', function (): void {
    $steps = $this->destroy->fresh()->state['native_progress']['steps'];
    expect(array_is_list($steps))->toBeFalse()->and(array_values($steps)[0]['status'])->toBe('skipped');
    expect(app(StartInstanceRestoreAction::class)->execute($this->instance)->kind)->toBe('instance.restore');
});

it('bounds terminal restore publication holds after compensation failure or an unknown timeout', function (string $failure): void {
    $restore = app(StartInstanceRestoreAction::class)->execute($this->instance);
    $nodes = RoutePublicationHolds::nodes($restore);
    $restore->update(['status' => 'failed', 'finished_at' => now(), 'error_code' => $failure,
        'state' => [...$restore->state, 'swamp_run_id' => 'unsettled-restore',
            ...($failure === 'operation.timed_out' ? ['native_timeout' => ['budget_seconds' => 900]] : [])],
        'result' => $failure === 'operation.timed_out' ? null : ['restored' => false, 'compensation_complete' => false]]);
    $holds = app(RoutePublicationHolds::class);
    expect(RoutePublicationHolds::keepsNodes($restore))->toBeTrue();
    $this->travel(14)->minutes();
    expect($holds->sweep())->toBe(0)
        ->and(DB::table('route_publication_holds')->where('operation_id', $restore->id)->count())->toBe(count($nodes));
    $this->travel(2)->minutes();
    expect($holds->sweep())->toBe(count($nodes))
        ->and(RoutePublicationHolds::keepsNodes($restore->fresh()))->toBeFalse();
    $this->travel(48)->hours();
    expect($holds->sweep())->toBe(0)
        ->and(DB::table('route_publication_holds')->where('operation_id', $restore->id)->exists())->toBeFalse()
        ->and($this->instance->fresh()->status)->toBe(InstanceState::Removing)
        ->and($this->removal->fresh()->status)->toBe(InstanceRemovalStatus::Failed);
    $writer = Operation::query()->create(['kind' => 'route.create', 'target_type' => 'route', 'target_id' => $this->route->id,
        'node_id' => $this->instance->node_id, 'status' => 'queued', 'sequence' => 1, 'state' => [],
        'input' => ['publications' => array_map(static fn (int $id): array => ['node_id' => $id, 'serves_routes' => true], $nodes)]]);
    expect($holds->admit($writer))->toBeTrue();
})->with(['instance.restore_compensation_failed', 'operation.timed_out']);

it('serializes destroy retries against queued running and waiting restore roots', function (string $status): void {
    $restore = app(StartInstanceRestoreAction::class)->execute($this->instance);
    $restore->update(['status' => $status]);
    foreach ([
        fn () => app(StartInstanceDestroyAction::class)->execute($this->instance->fresh(), true),
        fn () => app(DirectInstanceDestroyIntent::class)->record($this->destroy->input),
    ] as $admit) {
        try {
            $admit();
            test()->fail('Destroy retry overlapped restoration');
        } catch (ResourceOperationException $error) {
            expect($error->errorCode)->toBe('operation.target_busy');
        }
    }
    expect($this->removal->fresh()->status)->toBe(InstanceRemovalStatus::Failed)
        ->and($this->removal->fresh()->error_code)->toBe('fixture.drop_blocked')
        ->and(Operation::query()->where('kind', 'instance.destroy')->count())->toBe(1);
})->with(['queued', 'running', 'waiting']);

it('serializes restoration against a queued running or waiting destroy retry', function (string $status): void {
    $this->destroy->update(['status' => $status]);
    try {
        app(StartInstanceRestoreAction::class)->execute($this->instance);
        test()->fail('Restore overlapped a destroy retry');
    } catch (ResourceOperationException $error) {
        expect($error->errorCode)->toBe('instance.restore_busy');
    }
    expect($this->reader->calls)->toBe(0)->and($this->removal->fresh()->status)->toBe(InstanceRemovalStatus::Failed);
})->with(['queued', 'running', 'waiting']);

it('includes every planned SQLite file in survivor admission and preserves a missing-file refusal', function (bool $missing): void {
    $path = '/home/orbit/apps/fixture/missing.sqlite';
    // Reconstruct the connection that existed before the failed removal.
    $connectionId = DB::table('database_connections')->insertGetId(['slug' => 'restore-sqlite', 'driver' => 'sqlite',
        'node_id' => $this->instance->node_id, 'owner_instance_id' => $this->instance->id, 'path' => $path]);
    DB::unprepared('DROP TRIGGER instance_removals_native_intent_immutable');
    $input = $this->removal->native_intent;
    $sqlite = ['connection_id' => $connectionId, 'node_id' => $this->instance->node_id,
        'checkout' => $this->instance->checkout_path, 'path' => $path];
    $input['direct_destroy']['database']['connection_ids'] = [$connectionId];
    $input['direct_destroy']['database']['sqlite'] = [$sqlite];
    $this->removal->update(['native_intent' => $input]);
    $state = $this->destroy->state;
    $state['native_progress']['steps'][hash('sha256', 'sqlite')] = ['name' => 'Remove SQLite files-0', 'status' => 'skipped'];
    $this->destroy->update(['state' => $state]);
    $this->reader->reason = $missing ? 'sqlite_removed' : '';
    try {
        $restore = app(StartInstanceRestoreAction::class)->execute($this->instance);
        expect($missing)->toBeFalse()->and($restore->kind)->toBe('instance.restore');
    } catch (ResourceOperationException $error) {
        expect($missing)->toBeTrue()->and($error->errorCode)->toBe('instance.restore_already_removed')
            ->and($error->details)->toBe(['reason' => 'sqlite_removed', 'path' => $path]);
    }
    expect($this->reader->sourceInput['sqlite'])->toBe([$sqlite]);
})->with([false, true]);

it('limits restore create exclusion to the captured database and role names', function (): void {
    $server = DatabaseServer::query()->create(['slug' => 'scoped-postgres', 'driver' => 'pgsql',
        'node_id' => $this->instance->node_id, 'tag' => '18', 'port' => 5432, 'root_password' => 'fixture-secret', 'status' => 'active']);
    $process = Process::query()->create(['owner_type' => Node::class, 'owner_id' => $server->node_id, 'name' => 'scoped-postgres',
        'runtime' => 'docker', 'status' => 'active', 'runtime_config' => ['image' => 'postgres:18']]);
    $server->update(['process_id' => $process->id]);
    Operation::query()->create(['kind' => 'instance.restore', 'target_type' => 'instance', 'target_id' => $this->instance->id,
        'node_id' => $this->instance->node_id, 'status' => 'running', 'sequence' => 1, 'state' => [],
        'input' => ['servers' => [['server_id' => $server->id,
            'databases' => [['name' => 'captured', 'oid' => 101, 'owner' => 'captured_owner', 'allow' => true]],
            'users' => [['name' => 'captured_owner', 'oid' => 201]]]]]]);
    $safety = app(DatabaseDestroySafety::class);
    foreach ([[['captured'], ['disjoint_owner']], [['disjoint'], ['captured_owner']]] as [$databases, $users]) {
        try {
            $safety->assertAcceptsCreates($server, $databases, $users);
            test()->fail('Captured restore names were not excluded');
        } catch (ResourceOperationException $error) {
            expect($error->errorCode)->toBe('database.server_in_use');
        }
    }
    $prepared = app(CreateServerDatabaseAction::class)->prepare(new CreateServerDatabaseData('disjoint', $server->slug, null), null);
    expect($prepared->kind)->toBe('database.create');
    $connection = DatabaseConnection::query()->where('slug', 'disjoint')->sole();
    $user = app(CreateDatabaseUserAction::class)->execute($connection, new CreateDatabaseUserData('disjoint_reader', 'fixture-user-password', true), 'fixture');
    expect($user->kind)->toBe('database_user.create');
    try {
        app(CreateDatabaseUserAction::class)->execute($connection, new CreateDatabaseUserData('captured_owner', 'fixture-user-password', true), 'fixture');
        test()->fail('Captured user was not excluded');
    } catch (ResourceOperationException $error) {
        expect($error->errorCode)->toBe('database.server_in_use');
    }
});

it('renders transactional fence restoration for both fresh-closed and already-reopened catalogs', function (bool $open): void {
    $databases = [
        ['name' => 'fixture', 'oid' => 101, 'owner' => 'owner', 'allow' => $open],
        ['name' => 'fixture_test', 'oid' => 102, 'owner' => 'owner', 'allow' => $open],
    ];
    $users = [['name' => 'owner', 'oid' => 201]];
    $fences = [['name' => 'fixture', 'oid' => 101, 'allow' => true], ['name' => 'fixture_test', 'oid' => 102, 'allow' => true]];
    $sql = InstanceRestoreFence::settlementSql($databases, $users, $fences);
    expect($sql)->toStartWith("BEGIN;\n")
        ->toEndWith('COMMIT;')
        ->toContain('LOCK TABLE pg_catalog.pg_database IN SHARE ROW EXCLUSIVE MODE',
            'LOCK TABLE pg_catalog.pg_authid IN SHARE MODE',
            'd.datallowconn IS DISTINCT FROM COALESCE(f.allow, p.allow)',
            'j.oid IS NOT NULL AND d.datallowconn AND NOT j.allow',
            "format('ALTER DATABASE %I ALLOW_CONNECTIONS %s', entry.name, CASE WHEN entry.allow THEN 'true' ELSE 'false' END)",
            'j.datname = p.name AND j.oid = p.oid::oid AND j.allow_connections = p.allow',
            'removed <> json_array_length(live_journal)')
        ->not->toContain('DROP ', 'unrelated_fixture', '303',
            "format('ALTER DATABASE %I ALLOW_CONNECTIONS %s', entry.name, entry.allow)");
    expect(strpos($sql, 'database_admission_changed'))->toBeLessThan(strpos($sql, 'EXECUTE format('))
        ->and(strpos($sql, 'EXECUTE format('))->toBeLessThan(strpos($sql, 'DELETE FROM'));
})->with([false, true]);

it('excludes earlier creates of captured names while admitting disjoint creates before survivor reads', function (string $kind, bool $overlap): void {
    $server = DatabaseServer::query()->create(['slug' => 'earlier-postgres', 'driver' => 'pgsql',
        'node_id' => $this->instance->node_id, 'tag' => '18', 'port' => 5432, 'root_password' => 'fixture-secret', 'status' => 'active']);
    $name = $overlap ? 'fixture' : 'other';
    $safe = $kind === 'database.create' ? ['databases' => [$name], 'username' => $name.'_owner']
        : ['database' => $name, 'username' => $name.'_reader', 'owner_username' => $name.'_owner'];
    $intent = DatabaseExecutionIntent::query()->create(['target_type' => 'database_server', 'target_id' => $server->id,
        'node_id' => $server->node_id, 'payload' => ['commands' => ['database-apply' => ['name' => $kind, 'input' => $safe]], 'projection' => []]]);
    Operation::query()->create(['kind' => $kind, 'target_type' => 'database_server', 'target_id' => $server->id,
        'node_id' => $server->node_id, 'status' => 'queued', 'sequence' => 1, 'state' => [], 'input' => ['intent_id' => $intent->id]]);
    $this->reader->facts = ['databases' => [['name' => 'fixture', 'oid' => 101, 'owner' => 'owner', 'allow' => false]],
        'users' => [['name' => 'owner', 'oid' => 201]], 'fences' => [['name' => 'fixture', 'oid' => 101, 'allow' => true]]];
    $database = ['servers' => [['server_id' => $server->id,
        'intent' => ['driver' => 'pgsql', 'container' => 'fixture-postgres', 'databases' => ['fixture'],
            'users' => ['owner'], 'owners' => ['fixture' => 'owner']]]]];
    try {
        $capture = app(InstanceRestoreFence::class)->capture($database);
        expect($overlap)->toBeFalse()->and($capture[0]['databases'][0]['allow'])->toBeTrue()
            ->and($this->reader->calls)->toBe(1);
    } catch (ResourceOperationException $error) {
        expect($overlap)->toBeTrue()->and($error->errorCode)->toBe('instance.restore_busy')
            ->and($error->details['databases'])->toBe(['fixture'])
            ->and($this->reader->calls)->toBe(0);
    }
})->with([['database.create', true], ['database.create', false], ['database_user.create', true], ['database_user.create', false]]);
