<?php

declare(strict_types=1);

use App\Actions\Instances\StartInstanceTransferAction;
use App\Data\Instances\TransferInstanceData;
use App\Domain\AppDev\VitePortRuntime;
use App\Domain\Certificates\LeafCertificateSigner;
use App\Domain\Instances\InstanceState;
use App\Domain\Instances\InstanceTransferProcesses;
use App\Domain\Instances\InstanceTransferProcessMaterial;
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
use App\Models\Process;
use App\Models\Project;
use App\Models\Route;
use Tests\Support\FakeRoutePublicationSocket;

beforeEach(function (): void {
    app()->instance(WebSocketClient::class, new FakeRoutePublicationSocket);
    config(['swamp.run_token' => 'native-transfer-fixture']);
    $this->mock(AgentCommandRunner::class)->shouldNotReceive('run');
    $this->mock(VitePortRuntime::class)->shouldNotReceive('selectPort');
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
    $processes = app(InstanceTransferProcesses::class)->capture($test->transfer);
    $plan = ['source_node_id' => $test->node->id, 'destination_node_id' => $test->destination->id];

    return Operation::query()->create(['kind' => 'instance.transfer', 'target_type' => 'instance', 'target_id' => $test->instance->id,
        'node_id' => $test->node->id, 'status' => 'running', 'lock_key' => 'native-transfer-proof',
        'input' => ['process_installations' => $processes, 'instance_id' => $test->instance->id, 'transfer_id' => $test->transfer->id, 'route_transfer' => $snapshot,
            'participant_node_ids' => $snapshot['participant_node_ids'], 'direct_transfer' => $plan,
            'private_bindings' => ['source_installations' => ['owner_node_id' => $test->node->id, 'read_node_ids' => [$test->node->id], 'write_node_ids' => []],
                'destination_installations' => ['owner_node_id' => $test->destination->id, 'read_node_ids' => [$test->destination->id], 'write_node_ids' => []],
                'source_route_environment' => ['owner_node_id' => $test->node->id, 'read_node_ids' => [$test->node->id], 'write_node_ids' => []],
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

it('returns one native source-root Operation through the transfer API', function (): void {
    $this->transfer->delete();
    $this->instance->update(['status' => 'active', 'provisioning_step' => 'active']);
    $this->node->update(['wireguard_ip' => '10.44.0.9']);
    $this->destination->update(['wireguard_ip' => '10.44.0.17', 'settings' => ['apps' => ['path' => '/srv/apps']]]);
    $this->destination->roles()->create(['role' => 'app-dev', 'status' => 'active']);
    $caller = Node::query()->create(['name' => 'native-transfer-caller', 'user' => 'orbit', 'platform' => 'linux',
        'status' => 'active', 'wireguard_ip' => '10.44.0.1', 'public_ssh_host' => '192.0.2.1']);
    $this->markAsGateway($caller);
    $this->node->accessibleNodes()->attach($caller);
    $this->destination->accessibleNodes()->attach($caller);
    $this->withServerVariables(['REMOTE_ADDR' => $caller->wireguard_ip])
        ->postJson('/api/v1/instances/'.$this->instance->id.'/transfer', ['node_id' => $this->destination->id])
        ->assertAccepted()->assertJsonPath('data.kind', 'instance.transfer');
    expect(Operation::query()->count())->toBe(1)->and(Operation::query()->sole()->node_id)->toBe($this->node->id)
        ->and($this->instance->fresh()->node_id)->toBe($this->node->id);
});

it('rejects malformed or caller-selected transfer authority before mutation', function (string $body): void {
    $caller = Node::query()->create(['name' => 'native-transfer-caller', 'user' => 'orbit', 'platform' => 'linux',
        'status' => 'active', 'wireguard_ip' => '10.44.0.1', 'public_ssh_host' => '192.0.2.1']);
    $this->markAsGateway($caller);
    $this->withServerVariables(['REMOTE_ADDR' => $caller->wireguard_ip])->call('POST',
        '/api/v1/instances/'.$this->instance->id.'/transfer',
        server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], content: $body)
        ->assertUnprocessable();
    expect(Operation::query()->count())->toBe(0)->and(Instance::query()->count())->toBe(1);
})->with(['malformed' => ['{"node_id":'], 'array' => ['[]'], 'duplicate' => ['{"node_id":2,"node_id":3}'],
    'path authority' => ['{"node_id":2,"destination_path":"/foreign"}'],
    'secret authority' => ['{"node_id":2,"environment":"private"}'], 'boolean' => ['{"node_id":true}']]);

function native_transfer_process($test): Process
{
    return Process::query()->create(['owner_type' => Instance::MorphAlias, 'owner_id' => $test->instance->id,
        'name' => 'transfer-worker', 'runtime' => 'systemd', 'working_directory' => '/srv/native/app',
        'runtime_config' => ['command' => ['/usr/bin/sleep', '300'], 'environment_file' => '/srv/native/app/.env',
            'environment' => ['TOKEN' => 'transfer-private-value']],
        'desired_state' => 'running', 'restart_policy' => 'always', 'status' => 'active']);
}

it('commits Process paths with Instance placement and hydrates only the actual placement', function (): void {
    $process = native_transfer_process($this);
    $operation = native_transfer_operation($this);
    $source = app(InstanceTransferProcessMaterial::class);
    expect(json_encode($operation->input))->not->toContain('transfer-private-value');
    expect($source->resolve($operation, $this->instance->id, '', 'source_installations')->value)
        ->toHaveKey((string) $process->id);
    expect(fn () => $source->resolve($operation, $this->instance->id, '', 'destination_installations'))->toThrow(ResourceOperationException::class);
    app(NativeInstanceTransferProjection::class)->apply($operation, native_transfer_result('cutover'));
    expect($process->fresh()->working_directory)->toBe('/srv/new-native/app')
        ->and($process->fresh()->runtime_config['environment_file'])->toBe('/srv/new-native/app/.env');
    expect($source->resolve($operation->fresh(), $this->instance->id, '', 'destination_installations')->value)
        ->toHaveKey((string) $process->id);
    expect(fn () => $source->resolve($operation->fresh(), $this->instance->id, '', 'source_installations'))->toThrow(ResourceOperationException::class);
});

it('refuses changed Process intent without moving Instance or Route placement', function (): void {
    $process = native_transfer_process($this);
    $operation = native_transfer_operation($this);
    $process->update(['working_directory' => '/srv/native/changed']);
    expect(fn () => app(NativeInstanceTransferProjection::class)->apply($operation, native_transfer_result('cutover')))->toThrow(ResourceOperationException::class);
    expect($this->instance->fresh()->node_id)->toBe($this->node->id)
        ->and($this->transfer->fresh()->cutover_at)->toBeNull()->and($this->extra->fresh()->cluster_id)->toBe($this->cluster->id);
});

it('rejects foreign Process paths before admitting any transfer mutation', function (): void {
    $process = native_transfer_process($this);
    $process->update(['working_directory' => '/srv/native-sibling']);
    $this->transfer->delete();
    $this->instance->update(['status' => 'active', 'provisioning_step' => 'active']);
    $this->destination->update(['settings' => ['apps' => ['path' => '/srv/apps']]]);
    $this->destination->roles()->create(['role' => 'app-dev', 'status' => 'active']);
    expect(fn () => app(StartInstanceTransferAction::class)->execute($this->instance, new TransferInstanceData($this->destination->id, null, null)))
        ->toThrow(ResourceOperationException::class);
    expect(InstanceTransfer::query()->count())->toBe(0)->and(Operation::query()->count())->toBe(0);
});

it('keeps Process source material and defaults usable during cancelled pre-cutover recovery', function (): void {
    $process = native_transfer_process($this);
    $process->update(['runtime' => 'docker', 'working_directory' => null, 'runtime_config' => ['image' => 'example:1', 'command' => ['sleep', '300'], 'environment_file' => null]]);
    $operation = native_transfer_operation($this);
    $operation->update(['cancel_requested_at' => now()]);
    app(NativeInstanceTransferProjection::class)->apply($operation, native_transfer_result('rollback'));
    expect($process->fresh()->working_directory)->toBeNull()->and($this->instance->fresh()->node_id)->toBe($this->node->id);
    expect(app(InstanceTransferProcessMaterial::class)->resolve($operation->fresh(), $this->instance->id, '', 'source_installations')->value)
        ->toHaveKey((string) $process->id);
});

it('captures Vite and SSR port intent without Gateway runtime discovery', function (): void {
    $process = native_transfer_process($this);
    $process->update(['runtime_config' => [...$process->runtime_config, 'preset' => 'vp-dev']]);
    $this->instance->update(['vite_port' => 5173, 'ssr_port' => 13714]);
    $this->transfer->delete();
    $this->instance->update(['status' => 'active', 'provisioning_step' => 'active']);
    $this->destination->update(['settings' => ['apps' => ['path' => '/srv/apps']]]);
    $this->destination->roles()->create(['role' => 'app-dev', 'status' => 'active']);
    $operation = app(StartInstanceTransferAction::class)->execute($this->instance, new TransferInstanceData($this->destination->id, null, null));
    $public = app(NativeInstanceTransferProjection::class)->publicInput($operation, $this->destination->id);
    expect($public['process_runtime']['ports'])->toBe([5173, 13714])
        ->and($public['process_runtime']['members'][0]['input']['control']['vite_plan']['listener']['port'])->toBe(5173);
    $operation->update(['state' => ['swamp_run_id' => 'a075eb23-7ac4-4487-80d1-57a038a92676']]);
    app(NativeInstanceTransferProjection::class)->apply($operation, native_transfer_result('cutover'));
    expect($this->instance->fresh()->vite_port)->toBe(5173)->and($this->instance->fresh()->ssr_port)->toBe(13714);
});

it('omits Process installation descriptors from unrelated Route participants', function (): void {
    native_transfer_process($this);
    $router = Node::query()->create(['name' => 'third-router', 'cluster_id' => $this->cluster->id,
        'platform' => 'linux', 'architecture' => 'x86_64', 'status' => 'active', 'user' => 'orbit', 'public_ssh_host' => '192.0.2.22']);
    $this->node->roles()->where('role', 'router')->delete();
    $router->roles()->create(['cluster_id' => $this->cluster->id, 'role' => 'router', 'status' => 'active']);
    $this->transfer->update(['source_router_node_id' => $router->id]);
    $operation = native_transfer_operation($this);
    $public = app(NativeInstanceTransferProjection::class)->publicInput($operation, $router->id);
    expect($public['process_runtime'])->toBe(['node_id' => $router->id, 'field' => null, 'members' => [], 'ports' => []]);
    expect(fn () => app(InstanceTransferProcessMaterial::class)->resolve($operation, $this->instance->id, '', 'unknown'))
        ->toThrow(ResourceOperationException::class);
});
