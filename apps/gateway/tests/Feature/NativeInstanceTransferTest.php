<?php

declare(strict_types=1);

use App\Actions\Instances\StartInstanceTransferAction;
use App\Data\Instances\TransferInstanceData;
use App\Domain\AppDev\VitePortRuntime;
use App\Domain\Certificates\LeafCertificateSigner;
use App\Domain\Instances\InstanceState;
use App\Domain\Instances\InstanceTransferProcesses;
use App\Domain\Instances\NativeInstanceTransferIntent;
use App\Domain\Instances\NativeInstanceTransferProjection;
use App\Domain\Nodes\ManagedUserAccount;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Nodes\RoleName;
use App\Domain\Routes\NativeRouteTransferPlan;
use App\Domain\Routes\NativeRouteTransferPublication;
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
use Illuminate\Support\Facades\DB;
use Tests\Support\FakeRoutePublicationSocket;

beforeEach(function (): void {
    $this->socket = new FakeRoutePublicationSocket;
    $this->socket->reply = static fn (array $sent): array => $sent['type'] === 'vault.put'
        ? [['type' => 'vault.put', 'id' => $sent['id'], 'payload' => ['data' => ['vaultName' => $sent['payload']['vaultName'], 'secretKey' => $sent['payload']['key'], 'vaultType' => 'local_encryption']]]]
        : [['type' => 'event', 'id' => $sent['id'], 'event' => ['kind' => 'started', 'workflowName' => 'operation-instance-transfer', 'runId' => 'a075eb23-7ac4-4487-80d1-57a038a92676']]];
    app()->instance(WebSocketClient::class, $this->socket);
    config(['swamp.run_token' => 'native-transfer-fixture']);
    $this->mock(AgentCommandRunner::class)->shouldNotReceive('run');
    $this->mock(VitePortRuntime::class)->shouldNotReceive('selectPort');
    $this->node = Node::query()->create(['name' => 'native-dev', 'public_ssh_host' => '192.0.2.11', 'wireguard_ip' => '10.44.0.11', 'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'AAAAC3NzaC1lZDI1NTE5AAAAIGaNG9xwGkyymXqkaSEKl6+u5VLBHPbi0ga0ya7sdCtG', 'user' => 'orbit', 'platform' => 'linux', 'architecture' => 'x86_64', 'status' => LifecycleStatus::Active]);
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
    $this->destination = Node::query()->create(['name' => 'destination', 'cluster_id' => $this->destinationCluster->id, 'status' => 'active', 'platform' => 'linux', 'architecture' => 'x86_64', 'user' => 'orbit', 'public_ssh_host' => '192.0.2.2', 'wireguard_ip' => '10.44.0.12', 'settings' => ['apps' => ['path' => '/srv']], 'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'AAAAC3NzaC1lZDI1NTE5AAAAIGaNG9xwGkyymXqkaSEKl6+u5VLBHPbi0ga0ya7sdCtG']);
    $this->destination->roles()->create(['cluster_id' => $this->destinationCluster->id, 'role' => 'router', 'status' => 'active']);
    $this->destination->roles()->create(['role' => RoleName::AppDev, 'status' => 'active']);
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
    $plan = app(NativeInstanceTransferIntent::class)->capture($test->transfer);
    $publication = app(NativeRouteTransferPublication::class)->capture($snapshot, $processes['destination_installations']);

    return Operation::query()->create(['kind' => 'instance.transfer', 'target_type' => 'instance', 'target_id' => $test->instance->id,
        'node_id' => $test->node->id, 'status' => 'running', 'lock_key' => 'native-transfer-proof',
        'input' => ['route_publication' => $publication, 'process_installations' => $processes, 'instance_id' => $test->instance->id, 'transfer_id' => $test->transfer->id, 'route_transfer' => $snapshot,
            'participant_node_ids' => $snapshot['participant_node_ids'], 'direct_transfer' => $plan,
            'private_bindings' => ['source_installations' => ['owner_node_id' => $test->node->id, 'read_node_ids' => [$test->node->id], 'write_node_ids' => []],
                'destination_installations' => ['owner_node_id' => $test->destination->id, 'read_node_ids' => [$test->destination->id], 'write_node_ids' => []],
                'source_route_environment' => ['owner_node_id' => $test->node->id, 'read_node_ids' => [$test->node->id], 'write_node_ids' => []],
                'destination_route_environment' => ['owner_node_id' => $test->destination->id, 'read_node_ids' => [$test->destination->id], 'write_node_ids' => []]]],
        'state' => ['swamp_run_id' => 'a075eb23-7ac4-4487-80d1-57a038a92676']]);
}
function native_transfer_result(string $checkpoint, string $status = 'succeeded'): array
{
    return ['run_id' => 'a075eb23-7ac4-4487-80d1-57a038a92676', 'status' => $status,
        'started_at' => now()->toISOString(), 'finished_at' => now()->toISOString(), 'error_code' => $status === 'failed' ? 'instance.transfer_failed' : null,
        'result' => ['cutover' => in_array($checkpoint, ['cutover', 'retire'], true), 'restored' => $checkpoint === 'rollback']];
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
    expect($operation->fresh()->status->value)->toBe('succeeded')->and($operation->fresh()->lock_key)->toBeNull()
        ->and($this->transfer->fresh()->status->value)->toBe('completed')
        ->and(Route::query()->find($this->route->id))->toBeNull();
});
it('records actual native cutover even when cancellation was requested before terminal projection', function (): void {
    $operation = native_transfer_operation($this);
    $operation->update(['cancel_requested_at' => now()]);
    app(NativeInstanceTransferProjection::class)->apply($operation, native_transfer_result('cutover', 'cancelled'));
    expect($this->instance->fresh()->node_id)->toBe($this->destination->id)
        ->and($this->transfer->fresh()->cutover_at)->not->toBeNull();
});
it('rolls back pre-cutover reservations while refusing rollback after committed placement', function (): void {
    $operation = native_transfer_operation($this);
    $projection = app(NativeInstanceTransferProjection::class);
    $projection->apply($operation, native_transfer_result('rollback', 'failed'));
    expect($this->transfer->fresh()->destination_route_id)->toBeNull()->and($this->extra->fresh()->cluster_id)->toBe($this->cluster->id);
    $operation = native_transfer_operation($this);
    $projection->apply($operation, native_transfer_result('cutover'));
    expect(fn () => $projection->apply($operation, native_transfer_result('rollback')))->toThrow(ResourceOperationException::class);
});
it('captures full native placement and secrets as vault names before any physical cutover', function (): void {
    $operation = native_transfer_operation($this);
    $input = app(NativeInstanceTransferProjection::class)->workflowInput($operation);
    expect($input['intent']['source']['ssh_execution']['target']['host'])->toBe($this->node->wireguard_ip)
        ->and($input['intent']['destination']['ssh_execution']['target']['host'])->toBe($this->destination->wireguard_ip)
        ->and($input['intent']['route_mutations'])->toHaveKeys(['forward', 'restore'])
        ->and($this->instance->fresh()->node_id)->toBe($this->node->id);
    expect(json_encode($input))->not->toContain('$secret', 'transfer-private-value');
});

it('rejects unimplemented SQLite composition before reserving a transfer', function (): void {
    expect(fn () => app(NativeInstanceTransferIntent::class)->assertSupported($this->instance, '/srv/native/db.sqlite'))->toThrow(ResourceOperationException::class);
    expect(Operation::query()->count())->toBe(0);
});

it('records a DB-only native transfer and scopes URL material to its own Node', function (): void {
    $this->transfer->delete();
    $this->instance->update(['status' => 'active', 'provisioning_step' => 'active']);
    $this->destination->update(['settings' => ['apps' => ['path' => '/srv/apps']]]);
    $this->mock(ManagedUserAccountResolver::class)->shouldNotReceive('resolve');
    $operation = app(StartInstanceTransferAction::class)->execute($this->instance,
        new TransferInstanceData($this->destination->id, null, null));
    expect($operation->kind)->toBe('instance.transfer')->and($operation->node_id)->toBe($this->node->id)
        ->and($operation->lock_key)->toBe('instance:'.$this->instance->id)
        ->and($operation->input['direct_transfer']['destination']['destination'])->toBe('/srv/apps/native/native')
        ->and($operation->input['route_publication']['route_mutations'])->toHaveKeys(['forward', 'restore'])->and($this->instance->fresh()->node_id)->toBe($this->node->id)
        ->and(Operation::query()->count())->toBe(1);
});

it('returns one native source-root Operation through the transfer API', function (): void {
    $this->transfer->delete();
    $this->instance->update(['status' => 'active', 'provisioning_step' => 'active']);
    $this->node->update(['wireguard_ip' => '10.44.0.9']);
    $this->destination->update(['wireguard_ip' => '10.44.0.17', 'settings' => ['apps' => ['path' => '/srv/apps']]]);
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

it('captures both native Process placements and commits paths only from terminal cutover', function (): void {
    $process = native_transfer_process($this);
    $operation = native_transfer_operation($this);
    $input = app(NativeInstanceTransferProjection::class)->workflowInput($operation);
    expect(json_encode($input))->not->toContain('transfer-private-value');
    expect($input['intent']['processes']['source'][0]['id'])->toBe($process->id)
        ->and($input['intent']['processes']['destination'][0]['intent']['ssh_execution']['target']['host'])->toBe($this->destination->wireguard_ip)
        ->and($input['execution_secrets'])->toHaveCount(2);
    app(NativeInstanceTransferProjection::class)->apply($operation, native_transfer_result('cutover', 'failed'));
    expect($process->fresh()->working_directory)->toBe('/srv/new-native/app')
        ->and($process->fresh()->runtime_config['environment_file'])->toBe('/srv/new-native/app/.env')
        ->and($this->transfer->fresh()->status->value)->toBe('failed')
        ->and($this->instance->fresh()->node_id)->toBe($this->destination->id);
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
    expect(fn () => app(StartInstanceTransferAction::class)->execute($this->instance, new TransferInstanceData($this->destination->id, null, null)))
        ->toThrow(ResourceOperationException::class);
    expect(InstanceTransfer::query()->count())->toBe(0)->and(Operation::query()->count())->toBe(0);
});

it('keeps Process source material and defaults usable during cancelled pre-cutover recovery', function (): void {
    $process = native_transfer_process($this);
    $process->update(['runtime' => 'docker', 'working_directory' => null, 'runtime_config' => ['image' => 'example:1', 'command' => ['sleep', '300'], 'environment_file' => null]]);
    $operation = native_transfer_operation($this);
    $operation->update(['cancel_requested_at' => now()]);
    app(NativeInstanceTransferProjection::class)->apply($operation, native_transfer_result('rollback', 'cancelled'));
    expect($process->fresh()->working_directory)->toBeNull()->and($this->instance->fresh()->node_id)->toBe($this->node->id);
    expect($process->fresh()->runtime_config['environment_file'])->toBeNull();
});

it('captures Vite and SSR port intent without Gateway runtime discovery', function (): void {
    $process = native_transfer_process($this);
    $process->update(['runtime_config' => [...$process->runtime_config, 'preset' => 'vp-dev']]);
    $this->instance->update(['vite_port' => 5173, 'ssr_port' => 13714]);
    foreach (['vite_port_assignments' => 5173, 'ssr_port_assignments' => 13714] as $table => $port) {
        DB::table($table)->insert(['instance_id' => $this->instance->id, 'node_id' => $this->node->id, 'port' => $port]);
    }
    $this->transfer->delete();
    $this->instance->update(['status' => 'active', 'provisioning_step' => 'active']);
    $this->destination->update(['settings' => ['apps' => ['path' => '/srv/apps']]]);
    $operation = app(StartInstanceTransferAction::class)->execute($this->instance, new TransferInstanceData($this->destination->id, null, null));
    $public = app(NativeInstanceTransferProjection::class)->workflowInput($operation);
    expect($public['intent']['processes']['destination'][0]['intent']['vite_plan']['listener']['port'])->toBe(5173)
        ->and($public['intent']['processes']['source'][0]['stop_intent']['control']['vite'])->toBeNull()
        ->and(array_keys($public['intent']['processes']['source'][0]['stop_intent']['control']))->toEqualCanonicalizing(['process_id', 'runtime', 'name', 'on_demand', 'vite']);
    $operation->update(['state' => ['swamp_run_id' => 'a075eb23-7ac4-4487-80d1-57a038a92676']]);
    app(NativeInstanceTransferProjection::class)->apply($operation, native_transfer_result('cutover'));
    expect($this->instance->fresh()->vite_port)->toBe(5173)->and($this->instance->fresh()->ssr_port)->toBe(13714);
    foreach (['vite_port_assignments', 'ssr_port_assignments'] as $table) {
        expect(DB::table($table)->where('node_id', $this->node->id)->count())->toBe(0)
            ->and(DB::table($table)->where('node_id', $this->destination->id)->count())->toBe(1);
    }
});

it('keeps source placement when native failure has no physical cutover receipt', function (): void {
    native_transfer_process($this);
    $operation = native_transfer_operation($this);
    app(NativeInstanceTransferProjection::class)->apply($operation, ['run_id' => $operation->state['swamp_run_id'], 'status' => 'failed',
        'started_at' => now()->toISOString(), 'finished_at' => now()->toISOString(), 'error_code' => 'operation.native_failed', 'result' => []]);
    expect($this->instance->fresh()->node_id)->toBe($this->node->id)->and($this->transfer->fresh()->cutover_at)->toBeNull()
        ->and($this->transfer->fresh()->status->value)->toBe('failed');
});

it('keeps root and participant public reads effect-free before and after cutover', function (): void {
    native_transfer_process($this);
    $operation = native_transfer_operation($this);
    $projection = app(NativeInstanceTransferProjection::class);
    $socket = app(WebSocketClient::class);
    $before = $socket->sent;
    foreach ([false, true] as $cutover) {
        if ($cutover) {
            $projection->apply($operation, native_transfer_result('cutover'));
        }
        expect($projection->input($operation))->toHaveKeys(['direct_transfer', 'process_runtime'])
            ->and($projection->publicInput($operation, $this->destination->id))->toHaveKeys(['direct_transfer', 'process_runtime'])
            ->and(json_encode($projection->input($operation)))->not->toContain('transfer-private-value', 'execution_secrets');
    }
    expect($socket->sent)->toBe($before);
});
