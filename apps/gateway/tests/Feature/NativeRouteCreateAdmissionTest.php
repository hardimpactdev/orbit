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
    $node = Node::query()->create(['ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'fixture-host-key', 'name' => 'create-admission-workload', 'public_ssh_host' => '192.0.2.41', 'wireguard_ip' => '10.44.0.41',
        'user' => 'orbit', 'platform' => 'linux', 'architecture' => 'x86_64', 'status' => 'active']);
    $node->roles()->create(['role' => RoleName::AppDev, 'status' => 'active']);
    $control = Node::query()->create(['ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'fixture-host-key', 'name' => 'create-admission-control', 'public_ssh_host' => '192.0.2.42', 'wireguard_ip' => '10.44.0.42',
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
        ->and($operation->input['route_lifecycle']['route_id'])->toBe($route->id)
        ->and($operation->input['route_lifecycle']['effectful'])->toBeTrue()
        ->and($operation->input['participant_node_ids'])->toBe([$node->id, 209])
        ->and($operation->input['certificates'][0])->toMatchArray(['node_id' => $node->id, 'scope' => 'app-instance-'.$instance->id])
        ->and($operation->state)->not->toHaveKeys(['route_plan', 'executor_runs', 'steps'])
        ->and($this->socket->sent)->toHaveCount(1)
        ->and($this->socket->sent[0]['payload']['workflowIdOrName'])->toBe('operation-route-create');
    expect($operation->input)->not->toHaveKeys(['environment_fields', 'private_bindings'])
        ->and($this->socket->sent[0]['payload']['inputs']['intent'])->toBe($operation->input['route_lifecycle'])
        ->and($this->socket->sent[0]['payload']['inputs']['execution_secrets'])->toEqual((object) [])
        ->and($operation->state['swamp_run_id'])->toBe('a075eb23-7ac4-4487-80d1-57a038a92676');
});

it('refuses executor callbacks without publishing or deleting admitted Route intent', function (string $status): void {
    [$instance, $node] = native_create_admission_fixture();
    $this->withServerVariables(['REMOTE_ADDR' => $node->wireguard_ip]);
    $this->postJson('/api/v1/routes', ['instance_id' => $instance->id, 'domain' => 'native-only.private.test'])->assertAccepted();
    $operation = Operation::query()->sole();
    $route = Route::query()->sole();

    $this->postJson('/api/v1/direct-operations/'.$operation->id.'/result', ['run_id' => 'a075eb23-7ac4-4487-80d1-57a038a92676', 'status' => $status,
        'started_at' => '2026-10-31T10:00:00.000Z', 'finished_at' => '2026-10-31T10:00:01.000Z', 'error_code' => 'route.create_failed',
        'result' => ['ready' => true, 'checkpoint' => 'sites-published']])->assertForbidden()->assertJsonPath('error.code', 'operation.executor_forbidden');

    expect($operation->fresh()->status)->toBe(OperationStatus::Running)
        ->and($route->fresh()->status)->toBe(RouteStatus::Pending)
        ->and($route->fresh()->sites_published)->toBeFalse()
        ->and($route->targets()->pluck('instance_id')->all())->toBe([$instance->id]);
})->with(['running', 'succeeded', 'failed', 'cancelled']);

it('admits public Process publication without requesting a private ingress certificate', function (bool $lan, bool $allowed): void {
    [, $node] = native_create_admission_fixture();
    $cluster = Cluster::query()->create(['name' => 'create-public', 'state' => 'active']);
    $ingress = Node::query()->create(['ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'fixture-host-key', 'name' => 'create-public-ingress', 'public_ssh_host' => '192.0.2.43', 'wireguard_ip' => '10.44.0.43', 'cluster_id' => $cluster->id,
        'user' => 'orbit', 'platform' => 'linux', 'architecture' => 'x86_64', 'status' => 'active']);
    $ingress->roles()->create(['role' => RoleName::Ingress, 'cluster_id' => $cluster->id, 'status' => 'active']);
    $router = Node::query()->create(['ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'fixture-host-key', 'name' => 'create-public-router', 'public_ssh_host' => '192.0.2.44', 'wireguard_ip' => '10.44.0.44', 'cluster_id' => $cluster->id,
        'user' => 'orbit', 'platform' => 'linux', 'architecture' => 'x86_64', 'status' => 'active']);
    $router->roles()->create(['role' => RoleName::Router, 'cluster_id' => $cluster->id, 'status' => 'active']);
    $process = Process::query()->create(['owner_type' => Node::class, 'owner_id' => $node->id, 'name' => 'create-public-http',
        'runtime' => 'docker', 'runtime_config' => ['ports' => [$node->wireguard_ip.':8787:8080/tcp']], 'restart_policy' => 'unless-stopped', 'desired_state' => 'running', 'status' => 'active']);
    if ($lan) {
        $node->update(['lan_ip' => '10.6.0.42']);
        $ingress->update(['lan_ip' => '10.6.0.43']);
        $process->update(['runtime_config' => ['ports' => ['10.6.0.42:8787:8080/tcp']]]);
    }
    if ($allowed) {
        $node->firewallRules()->create(['label' => 'process-lan', 'action' => 'allow', 'source' => $ingress->lan_ip, 'port' => '8787', 'protocol' => 'tcp', 'status' => 'active']);
    }
    $this->withServerVariables(['REMOTE_ADDR' => $node->wireguard_ip]);

    $this->postJson('/api/v1/routes', ['domain' => 'create-public.example.test', 'publication' => 'public', 'process_id' => $process->id, 'ingress_node_id' => $ingress->id])->assertAccepted();

    $route = Route::query()->sole();
    $operation = Operation::query()->sole();
    expect($operation->input['certificates'])->toBe([])
        ->and($operation->input['participant_node_ids'])->toEqualCanonicalizing([$node->id, $ingress->id, $router->id, 209])
        ->and($route->sites_published)->toBeFalse();
    $intent = $operation->input['route_lifecycle'];
    expect($this->socket->sent[0]['payload']['inputs']['intent'])->toBe($intent)
        ->and($intent['preparation']['probes'][0]['protocol'])->toBe('http')
        ->and($intent['preparation']['probes'][0]['probe'])->toMatchArray(['address' => $lan ? $node->lan_ip : $node->wireguard_ip, 'port' => 8787]);
    $expected = $intent['preparation']['upstream_firewalls'][0];
    expect($expected['node_id'])->toBe($node->id);
    if ($allowed) {
        expect($expected['expected'][0])->toMatchArray(['comment' => 'orbit process-lan', 'source' => $ingress->lan_ip, 'port' => '8787']);
        $node->firewallRules()->delete();
        expect($operation->fresh()->input['route_lifecycle']['preparation']['upstream_firewalls'][0])->toBe($expected);
    } elseif ($lan) {
        expect($expected['expected'])->toBe([]);
        $node->firewallRules()->create(['label' => 'process-lan', 'action' => 'allow', 'source' => $ingress->lan_ip, 'port' => '8787', 'protocol' => 'tcp', 'status' => 'active']);
        expect($operation->fresh()->input['route_lifecycle']['preparation']['upstream_firewalls'][0]['expected'])->toBe([])
            ->and($this->socket->sent)->toHaveCount(1);
    } else {
        expect($expected['expected'][0]['comment'])->toBe('orbit:wireguard-members');
    }
    $path = '/api/v1/direct-operations/'.$operation->id;
    $this->withServerVariables(['REMOTE_ADDR' => $ingress->wireguard_ip]);
    $this->postJson($path.'/result', ['run_id' => 'a075eb23-7ac4-4487-80d1-57a038a92676', 'status' => 'succeeded',
        'started_at' => '2026-10-31T10:00:00.000Z', 'finished_at' => '2026-10-31T10:00:01.000Z', 'result' => ['ready' => true, 'route_id' => $route->id]])->assertForbidden();
})->with(['WireGuard' => [false, false], 'LAN missing allowance' => [true, false], 'LAN admitted allowance' => [true, true]]);

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
