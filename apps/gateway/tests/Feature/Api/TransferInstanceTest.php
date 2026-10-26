<?php

declare(strict_types=1);

use App\Actions\Instances\TransferInstanceAction;
use App\Domain\AppDev\AppDevSourceOperationLock;
use App\Domain\Clusters\ClusterState;
use App\Domain\Instances\DevelopmentRouteProjector;
use App\Domain\Instances\Environment\InstanceEnvironmentOperationLock;
use App\Domain\Instances\Environment\InstanceEnvironmentReader;
use App\Domain\Instances\Environment\InstanceEnvironmentWriter;
use App\Domain\Instances\InstanceDestinationGuard;
use App\Domain\Instances\InstanceState;
use App\Domain\Instances\Sqlite\InstanceSqliteSeeder;
use App\Domain\Instances\Transfer\InstanceTransferRouteProjector;
use App\Domain\Instances\Transfer\InstanceTransferRuntime;
use App\Domain\Instances\Transfer\InstanceTransferSource;
use App\Domain\Instances\Transfer\InstanceTransferStatus;
use App\Domain\Instances\Transfer\InstanceTransferStep;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Nodes\RoleName;
use App\Domain\Routes\RouteProvenance;
use App\Domain\Routes\RoutePublication;
use App\Domain\Routes\RouteStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Activity;
use App\Models\Cluster;
use App\Models\Instance;
use App\Models\InstanceTransfer;
use App\Models\Node;
use App\Models\Project;
use App\Models\Route;
use Illuminate\Support\Facades\DB;
use Tests\Support\FakeInstanceTransferAgents;
use Tests\Support\Orb245Accounts;
use Tests\Support\Orb245DestinationGuard;
use Tests\Support\Orb245EnvironmentLock;
use Tests\Support\Orb245EnvironmentReader;
use Tests\Support\Orb245EnvironmentWriter;
use Tests\Support\Orb245Projection;
use Tests\Support\Orb245SourceLock;
use Tests\Support\Orb245SqliteSeeder;
use Tests\Support\Orb245TransferRuntime;
use Tests\Support\Orb245TransferSource;

beforeEach(function (): void {
    $this->caller = transfer_api_node('transfer-api-caller');
    $this->caller->roles()->create(['role' => RoleName::Gateway, 'status' => LifecycleStatus::Active]);
    $sourceCluster = Cluster::query()->create([
        'name' => 'transfer-api-source',
        'tld' => 'dev.orbit',
        'state' => ClusterState::Active,
    ]);
    $destinationCluster = Cluster::query()->create([
        'name' => 'transfer-api-destination',
        'tld' => 'other.orbit',
        'state' => ClusterState::Active,
    ]);
    $this->sourceNode = transfer_api_app_dev('transfer-api-source', $sourceCluster, '10.44.46.10');
    $this->destinationNode = transfer_api_app_dev('transfer-api-destination', $destinationCluster, '10.44.46.11');
    $project = Project::query()->create([
        'name' => 'Transfer API',
        'slug' => 'transfer-api',
        'repository_url' => 'https://example.test/transfer-api.git',
        'default_branch' => 'main',
    ]);
    $this->instance = Instance::query()->create([
        'project_id' => $project->id,
        'node_id' => $this->sourceNode->id,
        'name' => 'web',
        'environment' => 'development',
        'checkout_path' => '/srv/orbit/apps/transfer-api/web',
        'branch' => 'main',
        'source_is_laravel' => true,
        'provisioning_step' => 'active',
        'status' => InstanceState::Active,
    ]);
    $route = Route::query()->create([
        'project_id' => $project->id,
        'cluster_id' => $sourceCluster->id,
        'generation_basis_node_id' => $this->sourceNode->id,
        'domain' => 'web.transfer-api.dev.orbit',
        'provenance' => RouteProvenance::Generated,
        'publication' => RoutePublication::Private,
        'status' => RouteStatus::Pending,
    ]);
    $route->targets()->create([
        'instance_id' => $this->instance->id,
        'position' => 0,
    ]);
    $route->update(['status' => RouteStatus::Active]);
    $this->transferUrl = "/api/v1/instances/{$this->instance->id}/transfer";
});

it('returns 422 for malformed duplicate unknown or forbidden transfer input before mutation', function (
    string $body,
): void {
    $sentinel = 'TRANSFER_API_INPUT_SENTINEL';

    $response = $this
        ->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip])
        ->call(
            'POST',
            $this->transferUrl,
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
            ],
            content: str_replace('__SENTINEL__', $sentinel, $body),
        );

    $response
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation.failed');

    expect($this->instance->refresh()->node_id)
        ->toBe($this->sourceNode->id)
        ->and(json_encode(Activity::query()->sole()->toArray(), JSON_THROW_ON_ERROR))
        ->not->toContain($sentinel);
})->with([
    'malformed JSON' => ['{"node_id":'],
    'array body' => ['[]'],
    'duplicate member' => ['{"node_id":1,"node_id":2}'],
    'unknown member' => ['{"node_id":1,"unknown":"__SENTINEL__"}'],
    'destination path' => ['{"node_id":1,"destination_path":"__SENTINEL__"}'],
    'missing required members' => ['{}'],
    'malformed Node ID' => ['{"node_id":"bad"}'],
    'signed Node ID' => ['{"node_id":"+3"}'],
    'boolean Node ID' => ['{"node_id":true}'],
    'invalid target name' => ['{"node_id":1,"name":"Not Normalized"}'],
    'relative SQLite path' => ['{"node_id":1,"sqlite_source_path":"database.sqlite"}'],
]);

it('returns 403 before transfer when the caller lacks destination Node access', function (): void {
    $caller = transfer_api_node('transfer-api-direct-caller');
    $caller->accessibleNodes()->attach($this->sourceNode);
    $sqliteSourcePath = '/srv/orbit/apps/transfer-api/web/TRANSFER_SQLITE_PATH_SENTINEL.sqlite';

    $this
        ->withServerVariables(['REMOTE_ADDR' => $caller->wireguard_ip])
        ->postJson($this->transferUrl, [
            'node_id' => $this->destinationNode->id,
            'name' => 'preview',
            'sqlite_source_path' => $sqliteSourcePath,
        ])
        ->assertForbidden()
        ->assertJsonPath('error.code', 'node_access.required')
        ->assertJsonPath('error.details.serving_node.id', $this->destinationNode->id);

    $activity = Activity::query()->where('command', 'instance:transfer')->sole();
    expect($this->instance->refresh()->node_id)
        ->toBe($this->sourceNode->id)
        ->and($activity->properties?->get('input'))
        ->toBe([
            'node_id' => $this->destinationNode->id,
            'name' => 'preview',
            'sqlite_selected' => true,
        ])
        ->and(json_encode($activity->toArray(), JSON_THROW_ON_ERROR))
        ->not->toContain($sqliteSourcePath, 'sqlite_source_path');
});

it('transfers the Instance and records sanitized activity', function (): void {
    transfer_api_bind_fakes();

    $response = $this
        ->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip])
        ->postJson($this->transferUrl, [
            'node_id' => $this->destinationNode->id,
        ]);

    $instance = $this->instance->refresh()->load('routes');
    $response
        ->assertCreated()
        ->assertJsonPath('data.id', $instance->id)
        ->assertJsonPath('data.node_id', $this->destinationNode->id)
        ->assertJsonPath('data.name', 'web')
        ->assertJsonPath('data.checkout_path', '/srv/orbit/apps/transfer-api/web')
        ->assertJsonPath('data.domain', 'web.transfer-api.other.orbit')
        ->assertJsonPath('data.transfer.status', 'completed')
        ->assertJsonPath('data.transfer.cleanup_completed', true)
        ->assertJsonPath('data.transfer.sqlite_selected', false);

    $activity = Activity::query()->where('command', 'instance:transfer')->sole();
    expect($activity->status)->toBe('succeeded')
        ->and($activity->subject_id)->toBe($instance->id)
        ->and($activity->properties?->get('input'))
        ->toBe([
            'node_id' => $this->destinationNode->id,
            'sqlite_selected' => false,
        ]);
});

it('returns 200 for an identical completed transfer request', function (): void {
    transfer_api_bind_fakes();

    $this
        ->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip])
        ->postJson($this->transferUrl, ['node_id' => $this->destinationNode->id])
        ->assertCreated();

    $this
        ->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip])
        ->postJson($this->transferUrl, ['node_id' => $this->destinationNode->id])
        ->assertOk()
        ->assertJsonPath('data.transfer.status', 'completed');
});

it('passes the selected SQLite and checkout layout to the agents', function (string $layout): void {
    $transport = transfer_api_sqlite_recovery_fixture();
    $this->instance->update(['source_layout' => $layout]);
    $sqlite = $this->instance->checkout_path.'/database/selected [1].sqlite';

    $this->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip])
        ->postJson($this->transferUrl, ['node_id' => $this->destinationNode->id, 'sqlite_source_path' => $sqlite])
        ->assertCreated()->assertJsonPath('data.transfer.status', 'completed');

    $capture = array_find($transport->agents->calls, static fn (array $call): bool => $call['command'] === 'instance.transfer-source');
    $target = array_find($transport->agents->calls, static fn (array $call): bool => $call['command'] === 'instance.install-sqlite-seed');
    expect($capture['input'])->toMatchArray(['layout' => $layout, 'sqlite_source' => $sqlite])
        ->and($target['input']['relative_path'])->toBe('database/selected [1].sqlite')
        ->and($this->instance->refresh()->node_id)->toBe($this->destinationNode->id);
})->with(['checkout', 'worktree']);

it('recaptures after rollback before retrying a failed SQLite copy with a lost discard response', function (): void {
    $transport = transfer_api_sqlite_recovery_fixture();
    $transport->snapshotCopyFailures = 1;
    $transport->lostDiscardResponse = true;
    $snapshotsAtRestore = [];
    app(InstanceTransferRuntime::class)->onRestore = static function () use ($transport, &$snapshotsAtRestore): void {
        $snapshotsAtRestore[] = $transport->snapshot;
        $transport->sourceBytes = 'writes after source restoration';
    };
    $input = ['node_id' => $this->destinationNode->id, 'sqlite_source_path' => $this->instance->checkout_path.'/database.sqlite'];

    $this->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip])
        ->postJson($this->transferUrl, $input)->assertConflict()->assertJsonPath('error.code', 'sqlite.seed_transfer_failed');
    $failed = InstanceTransfer::query()->sole();
    expect($failed->recovery_evidence['incomplete'])->toContain('destination-checkout')
        ->and($transport->destinationExists)->toBeFalse()->and($snapshotsAtRestore)->toBe([null]);

    $this->postJson($this->transferUrl, $input)->assertOk()->assertJsonPath('data.transfer.status', 'completed');
    expect($transport->installed)->toBe('writes after source restoration')
        ->and($transport->copiedDigests)->toHaveCount(2)
        ->and($transport->copiedDigests[1])->not->toBe($transport->copiedDigests[0])
        ->and(InstanceTransfer::query()->sole()->id)->toBe($failed->id);
});

it('closes a failed transfer only after both seed cleanups are confirmed', function (): void {
    $transport = transfer_api_sqlite_recovery_fixture();
    $transport->snapshotCopyFailures = 1;

    $this->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip])
        ->postJson($this->transferUrl, ['node_id' => $this->destinationNode->id, 'sqlite_source_path' => $this->instance->checkout_path.'/database.sqlite'])
        ->assertConflict()->assertJsonPath('error.code', 'sqlite.seed_transfer_failed');

    $failed = InstanceTransfer::query()->sole();
    expect($failed->recovery_evidence)->toBeNull()
        ->and(InstanceTransfer::query()->closed()->whereKey($failed->id)->exists())->toBeTrue()
        ->and($transport->snapshot)->toBeNull()->and($transport->received)->toBeNull();
});

it('finishes persisted rollback before fresh capture after interruption at a forward SQLite checkpoint', function (string $boundary): void {
    $transport = transfer_api_sqlite_recovery_fixture();
    $persistedAtInterruption = null;
    $failOnce = true;
    Route::creating(static function (Route $route) use (&$failOnce): void {
        if ($route->status === RouteStatus::Pending && $failOnce) {
            $failOnce = false;
            throw new ResourceOperationException('route.prepare_failed', 'Injected failure after the SQLite forward checkpoint.', 409);
        }
    });
    app(InstanceTransferRuntime::class)->onRestore = static function () use ($transport, $boundary, &$persistedAtInterruption): void {
        $transport->sourceBytes = 'writes after source restoration';
        if ($boundary === 'source restored') {
            $persistedAtInterruption = InstanceTransfer::query()->sole()->getAttributes();
        }
    };
    if ($boundary === 'destination deleted') {
        $transport->onDiscard = static function () use (&$persistedAtInterruption): void {
            $persistedAtInterruption = InstanceTransfer::query()->sole()->getAttributes();
        };
    }
    $input = ['node_id' => $this->destinationNode->id, 'sqlite_source_path' => $this->instance->checkout_path.'/database.sqlite'];

    $this->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip])
        ->postJson($this->transferUrl, $input)->assertConflict()->assertJsonPath('error.code', 'route.prepare_failed');
    expect($persistedAtInterruption)->toBeArray()
        ->and($persistedAtInterruption['current_step'])->toBe(InstanceTransferStep::RuntimeRelocated->value)
        ->and($persistedAtInterruption['cutover_at'])->toBeNull();

    DB::table('instance_transfers')->where('id', $persistedAtInterruption['id'])->update($persistedAtInterruption);
    $transport->destinationExists = $boundary === 'source restored';
    app(InstanceTransferRuntime::class)->onRestore = null;
    $transport->onDiscard = null;
    app()->forgetInstance(TransferInstanceAction::class);
    $this->postJson($this->transferUrl, [...$input, 'name' => 'other'])->assertConflict()->assertJsonPath('error.code', 'instance.transfer_retry_conflict');
    $this->deleteJson('/api/v1/instances/'.$this->instance->id)->assertConflict()->assertJsonPath('error.code', 'instance.transfer_incomplete');
    $this->postJson($this->transferUrl, $input)->assertOk()->assertJsonPath('data.transfer.status', 'completed');

    expect($transport->installed)->toBe('writes after source restoration')
        ->and($transport->captures())->toBe(2)
        ->and(array_count_values(app(InstanceTransferRuntime::class)->calls)['pause'])->toBe(2)
        ->and($transport->copiedDigests)->toHaveCount(2)
        ->and($transport->copiedDigests[1])->not->toBe($transport->copiedDigests[0])
        ->and(InstanceTransfer::query()->sole()->id)->toBe($persistedAtInterruption['id'])
        ->and($persistedAtInterruption['status'])->toBe(InstanceTransferStatus::Failed->value)
        ->and(json_decode($persistedAtInterruption['recovery_evidence'], true, flags: JSON_THROW_ON_ERROR)['rollback_pending'])->toBeTrue();
})->with(['source restored', 'destination deleted']);

it('keeps failed seed cleanup open and blocks recapture until an identical retry confirms cleanup', function (string $side): void {
    $transport = transfer_api_sqlite_recovery_fixture();
    $transport->snapshotCopyFailures = 1;
    $transport->failSourceCleanup = $side === 'source';
    $transport->failTargetCleanup = $side === 'target';
    $input = ['node_id' => $this->destinationNode->id, 'sqlite_source_path' => $this->instance->checkout_path.'/database.sqlite'];

    $this->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip])
        ->postJson($this->transferUrl, $input)->assertConflict()->assertJsonPath('error.code', 'sqlite.seed_transfer_failed');
    $failed = InstanceTransfer::query()->sole();
    expect($failed->recovery_evidence['incomplete'])->toContain('sqlite-seed')
        ->and(InstanceTransfer::query()->open()->whereKey($failed->id)->exists())->toBeTrue()
        ->and($transport->snapshot !== null)->toBe($side === 'source')
        ->and($transport->received !== null)->toBe($side === 'target');
    $transport->sourceBytes = 'writes after source restoration';
    $this->postJson($this->transferUrl, [...$input, 'name' => 'other'])->assertConflict()->assertJsonPath('error.code', 'instance.transfer_retry_conflict');
    $this->deleteJson('/api/v1/instances/'.$this->instance->id)->assertConflict()->assertJsonPath('error.code', 'instance.transfer_incomplete');
    $this->postJson($this->transferUrl, $input)->assertConflict()->assertJsonPath('error.code', 'instance.transfer_failed');
    expect($transport->captures())->toBe(1)->and($transport->copiedDigests)->toHaveCount(1)
        ->and($failed->refresh()->recovery_evidence['incomplete'])->toContain('sqlite-seed');

    $transport->failSourceCleanup = false;
    $transport->failTargetCleanup = false;
    $this->postJson($this->transferUrl, $input)->assertOk()->assertJsonPath('data.transfer.status', 'completed');
    expect($transport->installed)->toBe('writes after source restoration')
        ->and($transport->snapshot)->toBeNull()->and($transport->received)->toBeNull();
})->with(['source', 'target']);

it('repeats cleanup after its successful response is lost before recapturing fresh data', function (string $side): void {
    $transport = transfer_api_sqlite_recovery_fixture();
    $transport->snapshotCopyFailures = 1;
    $transport->lostSourceCleanupResponse = $side === 'source';
    $transport->lostTargetCleanupResponse = $side === 'target';
    $input = ['node_id' => $this->destinationNode->id, 'sqlite_source_path' => $this->instance->checkout_path.'/database.sqlite'];

    $this->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip])
        ->postJson($this->transferUrl, $input)->assertConflict()->assertJsonPath('error.code', 'sqlite.seed_transfer_failed');
    $failed = InstanceTransfer::query()->sole();
    expect($failed->recovery_evidence['incomplete'])->toContain('sqlite-seed')
        ->and($transport->snapshot)->toBeNull()->and($transport->received)->toBeNull();
    $transport->sourceBytes = 'writes after source restoration';

    $this->postJson($this->transferUrl, $input)->assertOk()->assertJsonPath('data.transfer.status', 'completed');
    expect($transport->installed)->toBe('writes after source restoration')
        ->and(InstanceTransfer::query()->sole()->id)->toBe($failed->id);
})->with(['source', 'target']);

it('starts a new transfer with different input after a failed pre-cutover transfer finishes rollback', function (): void {
    transfer_api_bind_fakes();
    $source = app(InstanceTransferSource::class);
    $source->failMaterialize = true;
    $this->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip])
        ->postJson($this->transferUrl, ['node_id' => $this->destinationNode->id])
        ->assertConflict()->assertJsonPath('error.code', 'instance.transfer_failed');
    $failed = InstanceTransfer::query()->sole();
    expect($failed->status)->toBe(InstanceTransferStatus::Failed)
        ->and($failed->cutover_at)->toBeNull()
        ->and($failed->recovery_evidence)->toBeNull();

    $source->failMaterialize = false;
    $this->postJson($this->transferUrl, ['node_id' => $this->destinationNode->id, 'name' => 'preview'])
        ->assertCreated()->assertJsonPath('data.name', 'preview')
        ->assertJsonPath('data.transfer.status', 'completed');
    expect(InstanceTransfer::query()->count())->toBe(2)
        ->and($failed->refresh()->status)->toBe(InstanceTransferStatus::Failed);
});

function transfer_api_sqlite_recovery_fixture(): FakeInstanceTransferAgents
{
    transfer_api_bind_fakes();
    $transport = new FakeInstanceTransferAgents;
    app()->instance(InstanceTransferSource::class, $transport->source());
    app()->instance(InstanceSqliteSeeder::class, $transport->seeder());
    app()->forgetInstance(TransferInstanceAction::class);

    return $transport;
}

function transfer_api_node(string $name): Node
{
    return Node::query()->create([
        'name' => $name,
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'architecture' => 'x86_64',
        'public_ssh_host' => "{$name}.example.test",
        'wireguard_ip' => '10.44.10.'.(Node::query()->count() + 2),
        'user' => 'orbit',
    ]);
}

function transfer_api_app_dev(string $name, Cluster $cluster, string $address): Node
{
    $node = Node::query()->create([
        'name' => $name,
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'architecture' => 'x86_64',
        'tld' => null,
        'cluster_id' => $cluster->id,
        'public_ssh_host' => "{$name}.example.test",
        'wireguard_ip' => $address,
        'user' => 'orbit',
        'settings' => ['apps' => ['path' => '/srv/orbit/apps']],
    ]);
    $node->roles()->create([
        'role' => RoleName::AppDev,
        'status' => LifecycleStatus::Active,
    ]);
    $router = transfer_api_node("{$name}-router");
    $router->update(['cluster_id' => $cluster->id]);
    $router->roles()->create([
        'cluster_id' => $cluster->id,
        'role' => RoleName::Router,
        'status' => LifecycleStatus::Active,
    ]);

    return $node;
}

function transfer_api_bind_fakes(): void
{
    app()->instance(ManagedUserAccountResolver::class, new Orb245Accounts);
    app()->instance(InstanceDestinationGuard::class, new Orb245DestinationGuard);
    app()->instance(InstanceEnvironmentOperationLock::class, new Orb245EnvironmentLock);
    app()->instance(AppDevSourceOperationLock::class, new Orb245SourceLock);
    app()->instance(InstanceTransferSource::class, new Orb245TransferSource);
    app()->instance(InstanceTransferRuntime::class, new Orb245TransferRuntime);
    app()->instance(InstanceSqliteSeeder::class, new Orb245SqliteSeeder);
    app()->instance(InstanceEnvironmentReader::class, new Orb245EnvironmentReader);
    app()->instance(InstanceEnvironmentWriter::class, new Orb245EnvironmentWriter);
    app()->instance(DevelopmentRouteProjector::class, new Orb245Projection);
    app()->instance(InstanceTransferRouteProjector::class, new Orb245Projection);
    app()->instance(TransferInstanceAction::class, app(TransferInstanceAction::class));
}
