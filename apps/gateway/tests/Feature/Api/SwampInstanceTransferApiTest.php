<?php

declare(strict_types=1);

use App\Domain\Clusters\ClusterState;
use App\Domain\Instances\InstanceDestinationGuard;
use App\Domain\Instances\InstanceState;
use App\Domain\Nodes\ManagedUserAccount;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\OperationChildAdmission;
use App\Domain\Operations\OperationKinds;
use App\Domain\Routes\RouteProvenance;
use App\Domain\Routes\RoutePublication;
use App\Domain\Routes\RouteStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Models\Activity;
use App\Models\Cluster;
use App\Models\Instance;
use App\Models\InstanceTransfer;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Project;
use App\Models\Route;
use Illuminate\Support\Facades\Queue;

final readonly class TransferApiChildAdmission implements OperationChildAdmission
{
    public function admit(Operation $parent, array $intent): Operation
    {
        throw new LogicException('Transfer admission must not execute a child.');
    }
}

function swamp_transfer_admission_registry(): OperationKinds
{
    $definitions = [...OperationKinds::definitions(), ...require app_path('Domain/Instances/lifecycle-operation-kinds.php')];
    foreach ($definitions['instance.transfer']['children'] as $child) {
        $definition = $definitions[$child['kind']] ?? null;
        if (! is_array($definition) || ! isset($definition['admission'])) {
            $definitions[$child['kind']] = ['executor' => 'swamp', 'admission' => TransferApiChildAdmission::class,
                'runs' => [['transport' => 'gateway', 'name' => 'test.transfer-owner']],
                'completion_contract' => ['step' => 'fixture', 'transport' => 'gateway', 'name' => 'test.transfer-owner', 'result' => []],
                'result_fields' => []];
        }
    }

    return new OperationKinds(app(), $definitions);
}

beforeEach(function (): void {
    $this->caller = swamp_transfer_api_node('transfer-api-caller');
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
    $this->sourceNode = swamp_transfer_api_app_dev('transfer-api-source', $sourceCluster, '10.44.46.10');
    $this->destinationNode = swamp_transfer_api_app_dev('transfer-api-destination', $destinationCluster, '10.44.46.11');
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
    $caller = swamp_transfer_api_node('transfer-api-direct-caller');
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

it('records a replayable transfer Operation without moving placement or executing effects', function (string $layout): void {
    app()->instance(OperationKinds::class, swamp_transfer_admission_registry());
    $accounts = Mockery::mock(ManagedUserAccountResolver::class);
    $accounts->shouldReceive('resolve')->andReturn(new ManagedUserAccount('orbit', 'orbit', '/home/orbit'));
    app()->instance(ManagedUserAccountResolver::class, $accounts);
    $destinations = Mockery::mock(InstanceDestinationGuard::class);
    $destinations->shouldReceive('assertUnoccupied')->once();
    app()->instance(InstanceDestinationGuard::class, $destinations);
    $agents = Mockery::mock(AgentCommandRunner::class);
    $agents->shouldNotReceive('run');
    app()->instance(AgentCommandRunner::class, $agents);
    Queue::fake();
    $this->instance->update(['source_layout' => $layout]);
    $path = $this->instance->checkout_path.'/database/selected [1].sqlite';
    $body = ['node_id' => $this->destinationNode->id, 'name' => 'preview', 'sqlite_source_path' => $path];
    $this->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip])->withHeader('Idempotency-Key', 'transfer-admission');

    $id = $this->postJson($this->transferUrl, $body)->assertAccepted()->assertJsonPath('data.kind', 'instance.transfer')
        ->assertJsonPath('data.status', 'queued')->json('data.id');
    $this->postJson($this->transferUrl, $body)->assertAccepted()->assertJsonPath('data.id', $id);

    $operation = Operation::query()->findOrFail($id);
    $transfer = InstanceTransfer::query()->sole();
    expect($operation->node_id)->toBe($this->sourceNode->id)->and($operation->target_id)->toBe($this->instance->id);
    expect($operation->input)->toMatchArray(['source_path' => $this->instance->checkout_path, 'source_layout' => $layout,
        'destination_node_id' => $this->destinationNode->id, 'destination_path' => '/srv/orbit/apps/transfer-api/preview',
        'destination_name' => 'preview', 'destination_domain' => 'preview.transfer-api.other.orbit', 'sqlite_source_path' => $path,
        'transfer_id' => $transfer->id, 'initial_cutover' => false, 'initial_completed' => false]);
    expect($transfer->current_step->value)->toBe('reserved')->and($transfer->cutover_at)->toBeNull();
    expect($this->instance->refresh()->node_id)->toBe($this->sourceNode->id)->and($this->instance->name)->toBe('web');
    expect(Route::query()->count())->toBe(1)->and(Operation::query()->count())->toBe(1);
    $activity = Activity::query()->where('command', 'instance:transfer')->firstOrFail();
    expect($activity->properties?->get('input'))->toBe(['node_id' => $this->destinationNode->id, 'name' => 'preview', 'sqlite_selected' => true]);
    expect(json_encode($activity->toArray()))->not->toContain($path, 'sqlite_source_path');
    Queue::assertNothingPushed();
})->with(['checkout', 'worktree']);

it('returns 404 for a missing source Instance before recording a transfer', function (): void {
    $this->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip])
        ->postJson('/api/v1/instances/999999/transfer', ['node_id' => $this->destinationNode->id])->assertNotFound();
    expect(InstanceTransfer::query()->count())->toBe(0)->and(Operation::query()->count())->toBe(0);
});

it('refuses identical new admission while the stored attempt has pending recovery', function (array $evidence): void {
    app()->instance(OperationKinds::class, swamp_transfer_admission_registry());
    InstanceTransfer::query()->create([
        'instance_id' => $this->instance->id, 'source_node_id' => $this->sourceNode->id,
        'destination_node_id' => $this->destinationNode->id, 'requested_name' => 'preview', 'destination_name' => 'preview',
        'source_path' => $this->instance->checkout_path, 'source_layout' => 'checkout',
        'destination_path' => '/srv/orbit/apps/transfer-api/preview', 'destination_domain' => 'preview.transfer-api.other.orbit',
        'source_route_id' => $this->instance->routes()->sole()->id, 'status' => 'failed', 'current_step' => 'source-captured',
        'recovery_evidence' => $evidence,
    ]);
    $agent = Mockery::mock(AgentCommandRunner::class);
    $agent->shouldNotReceive('run');
    app()->instance(AgentCommandRunner::class, $agent);

    $this->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip])
        ->postJson($this->transferUrl, ['node_id' => $this->destinationNode->id, 'name' => 'preview'])
        ->assertConflict()->assertJsonPath('error.code', 'instance.transfer_recovery_pending');

    expect(Operation::query()->count())->toBe(0)->and(InstanceTransfer::query()->sole()->recovery_evidence)->toBe($evidence);
    $this->assertDatabaseHas('instances', ['id' => $this->instance->id, 'node_id' => $this->sourceNode->id]);
    $this->assertDatabaseCount('vite_port_assignments', 0);
    $this->assertDatabaseCount('ssr_port_assignments', 0);
})->with([
    'uncertain capture' => [['rollback_pending' => true, 'capture_pending' => true, 'transfer_operation_id' => 123]],
    'interrupted rollback' => [['rollback_pending' => true]],
]);

it('keeps transfer gated closed without recording intent while handlers or cancel guards are missing', function (): void {
    $this->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip])
        ->postJson($this->transferUrl, ['node_id' => $this->destinationNode->id])
        ->assertStatus(503)->assertJsonPath('error.code', 'operation.kind_unavailable');

    $this->assertDatabaseCount('operations', 0);
    $this->assertDatabaseCount('instance_transfers', 0);
    $this->assertDatabaseHas('instances', ['id' => $this->instance->id, 'node_id' => $this->sourceNode->id]);
});

function swamp_transfer_api_node(string $name): Node
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

function swamp_transfer_api_app_dev(string $name, Cluster $cluster, string $address): Node
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
    $router = swamp_transfer_api_node("{$name}-router");
    $router->update(['cluster_id' => $cluster->id]);
    $router->roles()->create([
        'cluster_id' => $cluster->id,
        'role' => RoleName::Router,
        'status' => LifecycleStatus::Active,
    ]);

    return $node;
}
