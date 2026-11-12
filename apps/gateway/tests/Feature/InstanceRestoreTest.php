<?php

declare(strict_types=1);

use App\Actions\Instances\StartInstanceDestroyAction;
use App\Actions\Instances\StartInstanceRestoreAction;
use App\Actions\Operations\CancelOperationAction;
use App\Domain\Certificates\LeafCertificateSigner;
use App\Domain\DatabaseServers\InstanceRestoreFence;
use App\Domain\GitHub\GitReadEnvironment;
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
use App\Infrastructure\Swamp\StartSwampRun;
use App\Models\DatabaseServer;
use App\Models\Instance;
use App\Models\InstanceRemoval;
use App\Models\InstanceRemovalMember;
use App\Models\Node;
use App\Models\Operation;
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
    $this->destroy->update(['status' => 'failed', 'finished_at' => now(), 'error_code' => 'fixture.drop_blocked',
        'state' => ['swamp_run_id' => 'fixture-run', 'native_progress' => ['steps' => [['name' => 'Remove the source', 'status' => 'skipped']]]]]);
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

            return new ProgramResult($this->reason === '' ? 'intact' : 'refused',
                $this->reason === '' ? ['awake_before' => []] : ['reason' => $this->reason], '');
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

it('keeps publication holds when compensation fails and transfers them to a proved stopped retry', function (): void {
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
    $again = $action->execute($this->instance->fresh());
    expect($again->id)->not->toBe($restore->id)
        ->and(DB::table('route_publication_holds')->where('operation_id', $restore->id)->exists())->toBeFalse()
        ->and(DB::table('route_publication_holds')->where('operation_id', $again->id)->exists())->toBeTrue()
        ->and($again->input['awake_before'])->toBe($restore->input['awake_before']);
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
            ->and($captured[0]['statements'])->toContain('j.datname = p.name AND j.oid = p.oid::oid', 'd.datallowconn = p.allow')
            ->not->toContain('ALTER ', 'DROP ', 'unrelated');
    } catch (ResourceOperationException $error) {
        expect($error->errorCode)->toBe($code);
    }
})->with([
    ['intact', null], ['absent', null], ['transport', 'instance.restore_database_changed'],
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
    $first = $fence->capture($database);
    $this->reader->facts['fences'] = [];
    $again = $fence->capture($database, $first);
    expect($again[0]['fences'])->toBe($first[0]['fences']);
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
