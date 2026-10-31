<?php

declare(strict_types=1);

use App\Domain\Certificates\LeafCertificateSigner;
use App\Domain\Instances\InstanceState;
use App\Domain\Nodes\ManagedUserAccount;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\OperationStatus;
use App\Domain\Routes\RouteStatus;
use App\Infrastructure\AgentCommands\AgentDelivery;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Models\Cluster;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Process;
use App\Models\Project;
use App\Models\Route;
use Tests\Support\FakeRoutePublicationSocket;

beforeEach(function (): void {
    $this->manifest = tempnam(sys_get_temp_dir(), 'create-admission-agent-');
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
    $this->socket = new FakeRoutePublicationSocket;
    app()->instance(WebSocketClient::class, $this->socket);
    config(['swamp.run_token' => 'fixture.fake']);
});

afterEach(function (): void {
    unlink($this->manifest);
});

/** @return array{Instance,Node} */
function native_create_admission_fixture(): array
{
    $node = Node::query()->create(['name' => 'create-admission-workload', 'public_ssh_host' => '192.0.2.41', 'wireguard_ip' => '10.44.0.41',
        'user' => 'orbit', 'platform' => 'linux', 'architecture' => 'x86_64', 'status' => 'active']);
    $node->roles()->create(['role' => RoleName::AppDev, 'status' => 'active']);
    $control = Node::query()->create(['name' => 'create-admission-control', 'public_ssh_host' => '192.0.2.42', 'wireguard_ip' => '10.44.0.42',
        'user' => 'orbit', 'platform' => 'linux', 'architecture' => 'x86_64', 'status' => 'active']);
    $control->roles()->create(['role' => RoleName::Gateway, 'status' => 'active']);
    $node->accessibleNodes()->attach($control);
    config(['dependency_detection.services_node_id' => $control->id]);
    $project = Project::query()->create(['name' => 'Create admission', 'slug' => 'create-admission', 'root' => 'public', 'repository_url' => 'https://example.test/create-admission.git']);
    $instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $node->id, 'name' => 'create', 'checkout_path' => '/srv/create',
        'environment' => 'development', 'source_is_laravel' => false, 'provisioning_step' => 'active', 'status' => InstanceState::Active]);

    return [$instance, $node];
}

it('records one unpublished Route intent then starts its exact native create workflow', function (): void {
    [$instance, $node] = native_create_admission_fixture();
    $this->withServerVariables(['REMOTE_ADDR' => $node->wireguard_ip]);

    $this->postJson('/api/v1/routes', ['instance_id' => $instance->id, 'domain' => 'created.private.test'])->assertAccepted();

    $route = Route::query()->sole();
    $operation = Operation::query()->sole();
    expect($route->status)->toBe(RouteStatus::Pending)->and($route->sites_published)->toBeFalse()
        ->and($route->targets()->pluck('instance_id')->all())->toBe([$instance->id])
        ->and($operation->kind)->toBe('route.create')
        ->and($operation->input['route_convergence']['status'])->toBe('pending')
        ->and($operation->input['route_convergence']['sites_published'])->toBeFalse()
        ->and($operation->input['participant_node_ids'])->toBe([$node->id, 209])
        ->and($operation->input['certificates'][0])->toMatchArray(['node_id' => $node->id, 'scope' => 'app-instance-'.$instance->id])
        ->and($operation->state)->not->toHaveKeys(['route_plan', 'executor_runs', 'steps'])
        ->and($this->socket->sent)->toHaveCount(1)
        ->and($this->socket->sent[0]['payload']['workflowIdOrName'])->toBe('operation-route-create');
    $field = $operation->input['environment_fields'][$node->id];
    expect($operation->input['private_bindings'][$field])->toBe(['owner_node_id' => $node->id, 'read_node_ids' => [$node->id], 'write_node_ids' => []]);
    $path = '/api/v1/direct-operations/'.$operation->id;
    $this->getJson($path.'?run_id=a075eb23-7ac4-4487-80d1-57a038a92676')->assertOk()
        ->assertJsonPath('data.node_id', $node->id)
        ->assertJsonPath('data.input.route.route_id', $route->id)
        ->assertJsonPath('data.input.participant_node_id', $node->id)
        ->assertJsonPath('data.result', null);
    $this->getJson($path.'/private-material?run_id=a075eb23-7ac4-4487-80d1-57a038a92676')->assertOk()->assertJsonPath('data.'.$field, []);
});

it('projects initial publication and native success without Gateway execution receipts', function (): void {
    [$instance, $node] = native_create_admission_fixture();
    $this->withServerVariables(['REMOTE_ADDR' => $node->wireguard_ip]);
    $this->postJson('/api/v1/routes', ['instance_id' => $instance->id, 'domain' => 'finished.private.test'])->assertAccepted();
    $operation = Operation::query()->sole();
    $route = Route::query()->sole();
    $path = '/api/v1/direct-operations/'.$operation->id.'/result';
    $started = ['run_id' => 'a075eb23-7ac4-4487-80d1-57a038a92676', 'status' => 'running', 'started_at' => '2026-10-31T10:00:00.000Z'];

    $this->postJson($path, [...$started, 'result' => ['checkpoint' => 'sites-published']])->assertOk();
    expect($route->fresh()->sites_published)->toBeTrue()->and($route->fresh()->status)->toBe(RouteStatus::Pending);
    $terminal = [...$started, 'status' => 'succeeded', 'finished_at' => '2026-10-31T10:00:01.000Z', 'result' => ['ready' => true, 'route_id' => $route->id]];
    $this->postJson($path, $terminal)->assertOk();
    $this->postJson($path, $terminal)->assertOk();
    expect($route->fresh()->status)->toBe(RouteStatus::Active)
        ->and($operation->fresh()->status)->toBe(OperationStatus::Succeeded)
        ->and($operation->fresh()->state)->not->toHaveKeys(['executor_runs', 'steps']);
});

it('withdraws initial sites before native recovery and keeps failed Route intent', function (): void {
    [$instance, $node] = native_create_admission_fixture();
    $this->withServerVariables(['REMOTE_ADDR' => $node->wireguard_ip]);
    $this->postJson('/api/v1/routes', ['instance_id' => $instance->id, 'domain' => 'failed.private.test'])->assertAccepted();
    $operation = Operation::query()->sole();
    $route = Route::query()->sole();
    $path = '/api/v1/direct-operations/'.$operation->id.'/result';
    $started = ['run_id' => 'a075eb23-7ac4-4487-80d1-57a038a92676', 'status' => 'running', 'started_at' => '2026-10-31T10:00:00.000Z'];
    $this->postJson($path, [...$started, 'result' => ['checkpoint' => 'sites-published']])->assertOk();

    $this->postJson($path, [...$started, 'result' => ['checkpoint' => 'rollback-initial']])->assertOk();
    expect($route->fresh()->status)->toBe(RouteStatus::Failed)->and($route->fresh()->sites_published)->toBeFalse();
    $this->postJson($path, [...$started, 'status' => 'failed', 'finished_at' => '2026-10-31T10:00:01.000Z', 'error_code' => 'caddy.publication_failed',
        'result' => ['route_id' => $route->id, 'error_code' => 'caddy.publication_failed']])->assertOk();
    expect($operation->fresh()->status)->toBe(OperationStatus::Failed)
        ->and($route->fresh()->error_code)->toBe('caddy.publication_failed')
        ->and($route->targets()->pluck('instance_id')->all())->toBe([$instance->id]);
});

it('cancels the native create root without inventing publication or deleting the Route', function (): void {
    [$instance, $node] = native_create_admission_fixture();
    $this->withServerVariables(['REMOTE_ADDR' => $node->wireguard_ip]);
    $this->postJson('/api/v1/routes', ['instance_id' => $instance->id, 'domain' => 'cancelled.private.test'])->assertAccepted();
    $operation = Operation::query()->sole();
    $route = Route::query()->sole();

    $this->postJson('/api/v1/direct-operations/'.$operation->id.'/result', ['run_id' => 'a075eb23-7ac4-4487-80d1-57a038a92676', 'status' => 'cancelled',
        'started_at' => '2026-10-31T10:00:00.000Z', 'finished_at' => '2026-10-31T10:00:01.000Z', 'result' => []])->assertOk();

    expect($operation->fresh()->status)->toBe(OperationStatus::Cancelled)
        ->and($route->fresh()->status)->toBe(RouteStatus::Pending)
        ->and($route->fresh()->sites_published)->toBeFalse()
        ->and($route->targets()->pluck('instance_id')->all())->toBe([$instance->id]);
});

it('admits public Process publication without requesting a private ingress certificate', function (bool $lan): void {
    [, $node] = native_create_admission_fixture();
    $cluster = Cluster::query()->create(['name' => 'create-public', 'state' => 'active']);
    $ingress = Node::query()->create(['name' => 'create-public-ingress', 'public_ssh_host' => '192.0.2.43', 'wireguard_ip' => '10.44.0.43', 'cluster_id' => $cluster->id,
        'user' => 'orbit', 'platform' => 'linux', 'architecture' => 'x86_64', 'status' => 'active']);
    $ingress->roles()->create(['role' => RoleName::Ingress, 'cluster_id' => $cluster->id, 'status' => 'active']);
    $router = Node::query()->create(['name' => 'create-public-router', 'public_ssh_host' => '192.0.2.44', 'wireguard_ip' => '10.44.0.44', 'cluster_id' => $cluster->id,
        'user' => 'orbit', 'platform' => 'linux', 'architecture' => 'x86_64', 'status' => 'active']);
    $router->roles()->create(['role' => RoleName::Router, 'cluster_id' => $cluster->id, 'status' => 'active']);
    $process = Process::query()->create(['owner_type' => Node::class, 'owner_id' => $node->id, 'name' => 'create-public-http',
        'runtime' => 'docker', 'runtime_config' => ['ports' => [$node->wireguard_ip.':8787:8080/tcp']], 'restart_policy' => 'unless-stopped', 'desired_state' => 'running', 'status' => 'active']);
    if ($lan) {
        $node->update(['lan_ip' => '10.6.0.42']);
        $ingress->update(['lan_ip' => '10.6.0.43']);
        $process->update(['runtime_config' => ['ports' => ['10.6.0.42:8787:8080/tcp']]]);
    }
    $this->withServerVariables(['REMOTE_ADDR' => $node->wireguard_ip]);

    $this->postJson('/api/v1/routes', ['domain' => 'create-public.example.test', 'publication' => 'public', 'process_id' => $process->id, 'ingress_node_id' => $ingress->id])->assertAccepted();

    $route = Route::query()->sole();
    $operation = Operation::query()->sole();
    expect($operation->input['route_convergence']['certificates'])->toBe([])
        ->and($operation->input['route_convergence']['public_node_ids'])->toBe([$ingress->id, $router->id])
        ->and($operation->input['participant_node_ids'])->toBe([$node->id, $ingress->id, $router->id])
        ->and($operation->input['route_convergence']['upstream_firewall_node_ids'])->toBe([$node->id])
        ->and($route->sites_published)->toBeFalse();
    $this->withServerVariables(['REMOTE_ADDR' => $ingress->wireguard_ip]);
    $path = '/api/v1/direct-operations/'.$operation->id;
    $this->getJson($path.'?run_id=a075eb23-7ac4-4487-80d1-57a038a92676')->assertOk()
        ->assertJsonPath('data.node_id', $router->id)
        ->assertJsonPath('data.input.participant_node_id', $ingress->id)
        ->assertJsonPath('data.input.caddy_publication.node_id', $ingress->id)
        ->assertJsonPath('data.input.probes.0.protocol', 'http')
        ->assertJsonPath('data.input.probes.0.probe.address', $lan ? $node->lan_ip : $node->wireguard_ip)
        ->assertJsonPath('data.input.probes.0.probe.port', 8787)
        ->assertJsonMissingPath('data.result');
    $this->withServerVariables(['REMOTE_ADDR' => $node->wireguard_ip]);
    $snapshot = $this->getJson($path.'?run_id=a075eb23-7ac4-4487-80d1-57a038a92676')->assertOk()
        ->assertJsonPath('data.input.upstream_firewall.node_id', $node->id);
    if ($lan) {
        $snapshot->assertJsonPath('data.input.upstream_firewall.expected', []);
        $node->firewallRules()->create(['name' => 'process-lan', 'action' => 'allow', 'source' => $ingress->lan_ip, 'port' => '8787', 'protocol' => 'tcp', 'status' => 'active']);
        $this->getJson($path.'?run_id=a075eb23-7ac4-4487-80d1-57a038a92676')->assertOk()
            ->assertJsonPath('data.input.upstream_firewall.expected.0.comment', 'orbit:node:'.$node->id.':firewall:process-lan')
            ->assertJsonPath('data.input.upstream_firewall.expected.0.source', $ingress->lan_ip)
            ->assertJsonPath('data.input.upstream_firewall.expected.0.port', '8787');
    } else {
        $snapshot->assertJsonPath('data.input.upstream_firewall.expected.0.comment', 'orbit:wireguard-members');
    }
    $this->withServerVariables(['REMOTE_ADDR' => $ingress->wireguard_ip]);
    $this->postJson($path.'/result', ['run_id' => 'a075eb23-7ac4-4487-80d1-57a038a92676', 'status' => 'succeeded',
        'started_at' => '2026-10-31T10:00:00.000Z', 'finished_at' => '2026-10-31T10:00:01.000Z', 'result' => ['ready' => true, 'route_id' => $route->id]])->assertForbidden();
})->with(['WireGuard' => [false], 'LAN' => [true]]);

it('refuses package roots, inactive targets and caller-owned scope before recording Route intent', function (string $case): void {
    [$instance, $node] = native_create_admission_fixture();
    $this->withServerVariables(['REMOTE_ADDR' => $node->wireguard_ip]);
    $body = ['instance_id' => $instance->id, 'domain' => 'boundary.private.test'];
    if ($case === 'package-root') {
        $instance->project->update(['type' => 'node-package', 'root' => '.']);
    } elseif ($case === 'inactive') {
        $instance->update(['status' => InstanceState::Reserved]);
    } else {
        $body['project_id'] = $instance->project_id;
    }

    $response = $this->postJson('/api/v1/routes', $body);
    match ($case) {
        'package-root' => $response->assertConflict()->assertJsonPath('error.code', 'route.target_web_root_unsupported'),
        'inactive' => $response->assertConflict()->assertJsonPath('error.code', 'route.target_inactive'),
        default => $response->assertUnprocessable(),
    };
    expect(Route::query()->count())->toBe(0)->and(Operation::query()->count())->toBe(0)->and($this->socket->sent)->toBe([]);
})->with(['package-root', 'inactive', 'caller-scope']);

it('refuses reserved proxy names, remote upstreams and mixed scope before native submission', function (string $case): void {
    [$instance, $node] = native_create_admission_fixture();
    $this->withServerVariables(['REMOTE_ADDR' => $node->wireguard_ip]);
    $body = ['node_id' => $node->id, 'domain' => 'proxy.private.test', 'upstream' => 'http://127.0.0.1:8787'];
    if ($case === 'reserved') {
        $body['domain'] = 'gateway.orbit';
    } elseif ($case === 'remote') {
        $body['upstream'] = 'http://192.0.2.99:8787';
    } else {
        $body['instance_id'] = $instance->id;
    }

    $response = $this->postJson('/api/v1/routes', $body);
    match ($case) {
        'reserved' => $response->assertConflict()->assertJsonPath('error.code', 'route.domain_conflict'),
        'remote' => $response->assertUnprocessable()->assertJsonPath('error.code', 'route.upstream_invalid'),
        default => $response->assertUnprocessable(),
    };
    expect(Route::query()->count())->toBe(0)->and(Operation::query()->count())->toBe(0)->and($this->socket->sent)->toBe([]);
})->with(['reserved', 'remote', 'mixed']);
