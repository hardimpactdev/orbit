<?php

declare(strict_types=1);

use App\Actions\Routes\AdmitRouteOperationAction;
use App\Data\Routes\SetRouteTargetsData;
use App\Domain\Certificates\LeafCertificateSigner;
use App\Domain\Instances\DevelopmentSourceAccess;
use App\Domain\Nodes\ManagedUserAccount;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Nodes\RoleName;
use App\Domain\Routes\RouteStatus;
use App\Infrastructure\AgentCommands\AgentDelivery;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Models\Cluster;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Project;
use App\Models\Route;
use Tests\Support\FakeRoutePublicationSocket;

beforeEach(function (): void {
    $this->manifest = tempnam(sys_get_temp_dir(), 'target-admission-agent-');
    file_put_contents($this->manifest, json_encode(['commit' => str_repeat('b', 40), 'version' => '0.5.0', 'artifacts' => ['linux-x86_64' => ['sha256' => str_repeat('a', 64), 'url' => 'https://example.test/agent']]], JSON_THROW_ON_ERROR));
    app()->instance(AgentDelivery::class, new AgentDelivery($this->manifest, sys_get_temp_dir()));
    app()->instance(ManagedUserAccountResolver::class, new class implements ManagedUserAccountResolver
    {
        public function resolve(Node $node): ManagedUserAccount
        {
            return new ManagedUserAccount('orbit', 'orbit', '/home/orbit');
        }
    });
    $this->mock(DevelopmentSourceAccess::class)->shouldNotReceive('grant');
    $this->socket = new FakeRoutePublicationSocket;
    app()->instance(WebSocketClient::class, $this->socket);
    config(['swamp.run_token' => 'fixture.fake']);
});

afterEach(function (): void {
    unlink($this->manifest);
});

it('admits a pending development binding and starts exactly one native target root without effects', function (): void {
    $node = Node::query()->create(['name' => 'target-dev', 'public_ssh_host' => '192.0.2.19', 'wireguard_ip' => '10.44.0.19', 'user' => 'orbit', 'platform' => 'linux', 'architecture' => 'x86_64', 'status' => 'active']);
    $node->roles()->create(['role' => RoleName::AppDev, 'status' => 'active']);
    $project = Project::query()->create(['name' => 'Binding', 'slug' => 'binding', 'repository_url' => 'https://example.test/binding.git', 'root' => 'public']);
    $instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $node->id, 'name' => 'binding', 'checkout_path' => '/home/orbit/binding', 'source_is_laravel' => false, 'status' => 'active']);
    $route = Route::query()->create(['project_id' => $project->id, 'node_id' => $node->id, 'domain' => 'binding.private.test', 'publication' => 'private', 'provenance' => 'explicit', 'status' => 'pending']);

    $operation = app(AdmitRouteOperationAction::class)->setTarget($route, $instance->id);

    expect($operation->kind)->toBe('route.target_set')
        ->and($operation->input['participant_node_ids'])->toBe([$node->id, 209])
        ->and($operation->input['route_target']['binding_only'])->toBeTrue()
        ->and($operation->input['route_target']['effectful'])->toBeFalse()
        ->and($operation->input['route_target']['workloads'])->toBe([])
        ->and($route->fresh()->status)->toBe(RouteStatus::Pending)
        ->and($route->targets()->pluck('instance_id')->all())->toBe([$instance->id])
        ->and(Operation::query()->count())->toBe(1)
        ->and($this->socket->sent)->toHaveCount(1)
        ->and($this->socket->sent[0]['payload']['workflowIdOrName'])->toBe('operation-route-target-set')
        ->and($operation->state['swamp_run_id'])->toBe('a075eb23-7ac4-4487-80d1-57a038a92676')
        ->and($operation->state)->not->toHaveKeys(['route_plan', 'executor_runs', 'steps']);
    expect($operation->input)->not->toHaveKeys(['environment_fields', 'private_bindings'])
        ->and($this->socket->sent[0]['payload']['inputs']['intent'])->toBe($operation->input['route_target'])
        ->and($this->socket->sent[0]['payload']['inputs']['execution_secrets'])->toEqual((object) []);
});

it('admits an entire production pool without committing its cutover before the native root runs', function (): void {
    $this->mock(LeafCertificateSigner::class)->shouldReceive('rootCertificate')->andReturn('public-root');
    $cluster = Cluster::query()->create(['name' => 'admission-pool', 'state' => 'active']);
    $router = Node::query()->create(['ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'fixture-host-key', 'name' => 'pool-router', 'cluster_id' => $cluster->id, 'public_ssh_host' => '192.0.2.20', 'wireguard_ip' => '10.44.0.20', 'user' => 'orbit', 'platform' => 'linux', 'architecture' => 'x86_64', 'status' => 'active']);
    $router->roles()->create(['role' => RoleName::Router, 'cluster_id' => $cluster->id, 'status' => 'active']);
    $project = Project::query()->create(['name' => 'Pool', 'slug' => 'admission-pool', 'repository_url' => 'https://example.test/pool.git', 'root' => 'public']);
    $instances = [];
    foreach (['old', 'new'] as $index => $name) {
        $node = Node::query()->create(['ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'fixture-host-key', 'name' => 'pool-'.$name, 'cluster_id' => $cluster->id, 'public_ssh_host' => '192.0.2.'.(21 + $index), 'wireguard_ip' => '10.44.0.'.(21 + $index), 'user' => 'orbit', 'platform' => 'linux', 'architecture' => 'x86_64', 'status' => 'active']);
        $node->roles()->create(['role' => RoleName::AppProd, 'status' => 'active']);
        $instances[] = Instance::query()->create(['project_id' => $project->id, 'node_id' => $node->id, 'name' => $name, 'environment' => 'production', 'checkout_path' => '/srv/'.$name, 'production_home' => '/srv/'.$name, 'production_user' => 'orbit-app-'.$project->id, 'source_is_laravel' => false, 'status' => 'active']);
    }
    $route = Route::query()->create(['project_id' => $project->id, 'cluster_id' => $cluster->id, 'domain' => 'pool.private.test', 'publication' => 'private', 'provenance' => 'explicit', 'status' => 'pending']);
    $route->targets()->create(['instance_id' => $instances[0]->id, 'position' => 0]);
    $route->update(['status' => RouteStatus::Active]);

    $operation = app(AdmitRouteOperationAction::class)->setTargets($route, new SetRouteTargetsData(array_map(static fn (Instance $instance): int => $instance->id, $instances), []));

    $participants = [$router->id, $instances[0]->node_id, $instances[1]->node_id, 209];
    sort($participants);
    expect($operation->input['participant_node_ids'])->toEqualCanonicalizing($participants)
        ->and($operation->input['route_target']['binding_only'])->toBeFalse()
        ->and($operation->input['route_target']['proposal']['targets'])->toBe(array_map(static fn (Instance $instance): int => $instance->id, $instances))
        ->and($route->targets()->pluck('instance_id')->all())->toBe([$instances[0]->id])
        ->and($route->fresh()->target_set_intent['targets'])->toBe(array_map(static fn (Instance $instance): int => $instance->id, $instances))
        ->and(Operation::query()->count())->toBe(1)
        ->and($this->socket->sent)->toHaveCount(1)
        ->and($this->socket->sent[0]['payload']['workflowIdOrName'])->toBe('operation-route-target-set');
    expect($operation->input)->not->toHaveKeys(['environment_fields', 'private_bindings'])
        ->and($operation->input['route_target']['route_mutations']['forward']['publications'])->not->toBeEmpty()
        ->and($this->socket->sent[0]['payload']['inputs']['intent'])->toBe($operation->input['route_target']);
});
