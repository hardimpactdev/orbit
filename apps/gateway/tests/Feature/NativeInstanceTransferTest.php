<?php

declare(strict_types=1);

use App\Actions\Instances\StartInstanceTransferAction;
use App\Data\Instances\TransferInstanceData;
use App\Domain\Certificates\LeafCertificateSigner;
use App\Domain\Instances\InstanceState;
use App\Domain\Instances\InstanceTransferRouteEnvironment;
use App\Domain\Instances\NativeInstanceTransferIntent;
use App\Domain\Instances\NativeInstanceTransferProjection;
use App\Domain\Nodes\ManagedUserAccount;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Nodes\RoleName;
use App\Domain\Routes\NativeRouteTransferPlan;
use App\Domain\Routes\RoutePublication;
use App\Domain\Routes\RouteStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\AgentCommands\AgentDelivery;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Models\Cluster;
use App\Models\Instance;
use App\Models\InstanceTransfer;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Project;
use App\Models\Route;
use Tests\Support\FakeRoutePublicationSocket;

beforeEach(function (): void {
    app()->instance(WebSocketClient::class, new FakeRoutePublicationSocket);
    config(['swamp.run_token' => 'native-transfer-fixture']);
    $this->mock(AgentCommandRunner::class)->shouldNotReceive('run');
    $this->node = Node::query()->create(['name' => 'native-dev', 'public_ssh_host' => '192.0.2.11', 'user' => 'orbit', 'platform' => 'linux', 'architecture' => 'x86_64', 'status' => LifecycleStatus::Active]);
    $this->node->roles()->create(['role' => RoleName::AppDev, 'status' => LifecycleStatus::Active]);
    $project = Project::query()->create(['name' => 'Native', 'slug' => 'native', 'repository_url' => 'https://example.test/native.git', 'root' => 'public']);
    $this->instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $this->node->id, 'name' => 'native', 'checkout_path' => '/srv/native', 'branch' => 'main', 'source_is_laravel' => false, 'status' => InstanceState::SourceResolved]);
    $this->route = Route::query()->create(['project_id' => $project->id, 'node_id' => $this->node->id, 'domain' => 'native.test', 'provenance' => 'explicit', 'publication' => RoutePublication::Private, 'status' => RouteStatus::Pending]);
    $this->route->targets()->create(['instance_id' => $this->instance->id, 'position' => 0]);
    $this->manifest = tempnam(sys_get_temp_dir(), 'native-route-agent-');
    file_put_contents($this->manifest, json_encode(['commit' => str_repeat('b', 40), 'version' => '0.5.0', 'artifacts' => ['linux-x86_64' => ['sha256' => str_repeat('a', 64), 'url' => 'https://example.test/agent']]], JSON_THROW_ON_ERROR));
    app()->instance(AgentDelivery::class, new AgentDelivery($this->manifest, sys_get_temp_dir()));
    app()->instance(ManagedUserAccountResolver::class, new class implements ManagedUserAccountResolver
    {
        public function resolve(Node $node): ManagedUserAccount
        {
            return new ManagedUserAccount('orbit', 'orbit', '/home/orbit');
        }
    });
    $this->mock(LeafCertificateSigner::class)->shouldReceive('rootCertificate')->andReturn('public-root');
    $this->cluster = Cluster::query()->create(['name' => 'source', 'state' => 'active', 'tld' => 'source.test']);
    $this->destinationCluster = Cluster::query()->create(['name' => 'destination', 'state' => 'active', 'tld' => 'destination.test']);
    $this->node->update(['cluster_id' => $this->cluster->id]);
    $this->node->roles()->create(['cluster_id' => $this->cluster->id, 'role' => 'router', 'status' => 'active']);
    $this->destination = Node::query()->create(['name' => 'destination', 'cluster_id' => $this->destinationCluster->id, 'status' => 'active', 'platform' => 'linux', 'architecture' => 'x86_64', 'user' => 'orbit', 'public_ssh_host' => '192.0.2.2']);
    $this->destination->roles()->create(['cluster_id' => $this->destinationCluster->id, 'role' => 'router', 'status' => 'active']);
    $this->route->update(['node_id' => null, 'cluster_id' => $this->cluster->id, 'sites_published' => true, 'status' => 'active']);
    $this->extra = Route::query()->create(['project_id' => $this->instance->project_id, 'cluster_id' => $this->cluster->id, 'domain' => 'extra.test', 'web_root' => 'admin/public', 'status' => 'pending', 'sites_published' => true, 'publication' => 'private', 'provenance' => 'explicit']);
    $this->extra->targets()->create(['instance_id' => $this->instance->id, 'position' => 0]);
    $this->extra->update(['status' => 'active']);
    $this->transfer = InstanceTransfer::query()->create(['instance_id' => $this->instance->id, 'source_node_id' => $this->node->id, 'source_router_node_id' => $this->node->id, 'destination_node_id' => $this->destination->id, 'source_path' => '/srv/native', 'source_layout' => 'checkout', 'source_route_id' => $this->route->id, 'destination_name' => 'native', 'destination_path' => '/srv/new-native', 'destination_domain' => 'native.destination.test', 'status' => 'reserved', 'current_step' => 'reserved']);

});

afterEach(function (): void {
    unlink($this->manifest);
});

function native_transfer_operation($test): Operation
{
    $snapshot = app(NativeRouteTransferPlan::class)->capture($test->transfer);
    $plan = ['source_node_id' => $test->node->id, 'destination_node_id' => $test->destination->id];

    return Operation::query()->create(['kind' => 'instance.transfer', 'target_type' => 'instance', 'target_id' => $test->instance->id,
        'node_id' => $test->node->id, 'status' => 'running', 'lock_key' => 'native-transfer-proof',
        'input' => ['instance_id' => $test->instance->id, 'transfer_id' => $test->transfer->id, 'route_transfer' => $snapshot,
            'participant_node_ids' => $snapshot['participant_node_ids'], 'direct_transfer' => $plan,
            'private_bindings' => ['source_route_environment' => ['owner_node_id' => $test->node->id, 'read_node_ids' => [$test->node->id], 'write_node_ids' => []],
                'destination_route_environment' => ['owner_node_id' => $test->destination->id, 'read_node_ids' => [$test->destination->id], 'write_node_ids' => []]]],
        'state' => ['swamp_run_id' => 'a075eb23-7ac4-4487-80d1-57a038a92676']]);
}
function native_transfer_result(string $checkpoint, string $status = 'running'): array
{
    return ['run_id' => 'a075eb23-7ac4-4487-80d1-57a038a92676', 'status' => $status,
        'started_at' => now()->toISOString(), 'finished_at' => now()->toISOString(), 'result' => ['checkpoint' => $checkpoint]];
}
it('composes Instance placement and every Route in one idempotent cutover transaction', function (): void {
    $operation = native_transfer_operation($this);
    $projection = app(NativeInstanceTransferProjection::class);
    $projection->apply($operation, native_transfer_result('cutover'));
    expect($this->instance->fresh()->node_id)->toBe($this->destination->id)
        ->and($this->instance->fresh()->checkout_path)->toBe('/srv/new-native')
        ->and($this->extra->fresh()->cluster_id)->toBe($this->destinationCluster->id)
        ->and($operation->fresh()->node_id)->toBe($this->node->id);
    $first = $this->transfer->fresh()->cutover_at;
    $projection->apply($operation, native_transfer_result('cutover'));
    expect($this->transfer->fresh()->cutover_at->equalTo($first))->toBeTrue();
    $projection->apply($operation, native_transfer_result('retire'));
    $projection->apply($operation, native_transfer_result('', 'succeeded'));
    expect($operation->fresh()->status->value)->toBe('succeeded')->and($operation->fresh()->lock_key)->toBeNull()
        ->and($this->transfer->fresh()->status->value)->toBe('completed')
        ->and(Route::query()->find($this->route->id))->toBeNull();
});
it('refuses cutover after cancellation and preserves source placement and Routes', function (): void {
    $operation = native_transfer_operation($this);
    $operation->update(['cancel_requested_at' => now()]);
    expect(fn () => app(NativeInstanceTransferProjection::class)->apply($operation, native_transfer_result('cutover')))->toThrow(ResourceOperationException::class);
    expect($this->instance->fresh()->node_id)->toBe($this->node->id)->and($this->route->fresh()->status)->toBe(RouteStatus::Active)
        ->and($this->transfer->fresh()->cutover_at)->toBeNull();
});
it('rolls back pre-cutover reservations while refusing rollback after committed placement', function (): void {
    $operation = native_transfer_operation($this);
    $projection = app(NativeInstanceTransferProjection::class);
    $projection->apply($operation, native_transfer_result('rollback'));
    expect($this->transfer->fresh()->destination_route_id)->toBeNull()->and($this->extra->fresh()->cluster_id)->toBe($this->cluster->id);
    $projection->apply($operation, native_transfer_result('', 'failed'));
    $operation = native_transfer_operation($this);
    $projection->apply($operation, native_transfer_result('cutover'));
    expect(fn () => $projection->apply($operation, native_transfer_result('rollback')))->toThrow(ResourceOperationException::class);
});
it('resolves URL material only for the current authoritative admitted participant', function (): void {
    $operation = native_transfer_operation($this);
    $source = app(InstanceTransferRouteEnvironment::class);
    expect($source->resolve($operation, $this->instance->id, '', 'source_route_environment')->value)->toBe([]);
    expect(fn () => $source->resolve($operation, $this->instance->id, '', 'destination_route_environment'))->toThrow(ResourceOperationException::class);
    app(NativeInstanceTransferProjection::class)->apply($operation, native_transfer_result('cutover'));
    expect($source->resolve($operation, $this->instance->id, '', 'destination_route_environment')->value)->toBe([]);
    expect(fn () => $source->resolve($operation, $this->instance->id, '', 'source_route_environment'))->toThrow(ResourceOperationException::class);
});
it('rejects unimplemented SQLite composition before reserving a transfer', function (): void {
    expect(fn () => app(NativeInstanceTransferIntent::class)->assertSupported($this->instance, '/srv/native/db.sqlite'))->toThrow(ResourceOperationException::class);
    expect(Operation::query()->count())->toBe(0);
});

it('records a DB-only native transfer and scopes URL material to its own Node', function (): void {
    $this->transfer->delete();
    $this->instance->update(['status' => 'active', 'provisioning_step' => 'active']);
    $this->destination->update(['settings' => ['apps' => ['path' => '/srv/apps']]]);
    $this->destination->roles()->create(['role' => 'app-dev', 'status' => 'active']);
    $this->mock(ManagedUserAccountResolver::class)->shouldNotReceive('resolve');
    $operation = app(StartInstanceTransferAction::class)->execute($this->instance,
        new TransferInstanceData($this->destination->id, null, null));
    expect($operation->kind)->toBe('instance.transfer')->and($operation->node_id)->toBe($this->node->id)
        ->and($operation->input['direct_transfer']['destination']['destination'])->toBe('/srv/apps/native/native')
        ->and($operation->input['private_bindings']['destination_route_environment'])->toBe([
            'owner_node_id' => $this->destination->id, 'read_node_ids' => [$this->destination->id], 'write_node_ids' => [],
        ])->and($this->instance->fresh()->node_id)->toBe($this->node->id)
        ->and(Operation::query()->count())->toBe(1);
});
