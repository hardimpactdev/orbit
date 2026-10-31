<?php

declare(strict_types=1);

use App\Actions\Routes\RouteMutationState;
use App\Domain\Certificates\LeafCertificateSigner;
use App\Domain\Instances\DevelopmentSourceAccess;
use App\Domain\Nodes\ManagedUserAccount;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\OperationStatus;
use App\Domain\Routes\RoutePublication;
use App\Domain\Routes\RouteStatus;
use App\Domain\Routes\RouteTargetSetStep;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\AgentCommands\AgentDelivery;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Models\Cluster;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Project;
use App\Models\Route;
use Tests\Support\FakeAgentCommandRunner;
use Tests\Support\FakeRoutePublicationSocket;

beforeEach(function (): void {
    $this->manifest = tempnam(sys_get_temp_dir(), 'converge-admission-agent-');
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
    $this->mock(DevelopmentSourceAccess::class)->shouldNotReceive('grant');
    $this->agents = new FakeAgentCommandRunner;
    app()->instance(AgentCommandRunner::class, $this->agents);
    $this->socket = new FakeRoutePublicationSocket;
    app()->instance(WebSocketClient::class, $this->socket);
    config(['swamp.run_token' => 'fixture-converge']);
    $this->gateway = Node::query()->create(['name' => 'converge-gateway', 'public_ssh_host' => '192.0.2.81', 'wireguard_ip' => '10.44.0.81', 'user' => 'orbit', 'platform' => 'linux', 'architecture' => 'x86_64', 'status' => 'active']);
    $this->gateway->roles()->create(['role' => RoleName::Gateway, 'status' => 'active']);
    $this->withServerVariables(['REMOTE_ADDR' => $this->gateway->wireguard_ip]);
});

afterEach(function (): void {
    unlink($this->manifest);
});

/** @return array{Route,Instance,Node} */
function native_converge_admission_route(string $status = 'active'): array
{
    $node = Node::query()->create(['name' => 'converge-app', 'public_ssh_host' => '192.0.2.82', 'wireguard_ip' => '10.44.0.82', 'user' => 'orbit', 'platform' => 'linux', 'architecture' => 'x86_64', 'status' => 'active']);
    $node->roles()->create(['role' => RoleName::AppDev, 'status' => 'active']);
    $project = Project::query()->create(['name' => 'Converge', 'slug' => 'converge', 'root' => 'public', 'repository_url' => 'https://example.test/converge.git']);
    $instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $node->id, 'name' => 'converge', 'checkout_path' => '/srv/converge', 'environment' => 'development', 'source_is_laravel' => false, 'status' => 'active']);
    $route = Route::query()->create(['project_id' => $project->id, 'node_id' => $node->id, 'domain' => 'converge.private.test', 'publication' => 'private', 'provenance' => 'explicit', 'status' => 'pending']);
    $route->targets()->create(['instance_id' => $instance->id, 'position' => 0]);
    $route->update(['status' => $status, ...($status === 'failed' ? ['failed_step' => 'native-convergence', 'error_code' => 'caddy.publication_failed'] : [])]);

    return [$route, $instance, $node];
}

it('admits an existing private Route without changing its intent or invoking Gateway effects', function (string $status): void {
    [$route, $instance, $node] = native_converge_admission_route($status);
    $before = $route->fresh()->getAttributes();

    $this->postJson('/api/v1/routes/'.$route->id.'/converge')->assertAccepted()->assertJsonPath('data.kind', 'route.converge');

    $operation = Operation::query()->sole();
    expect($operation->input['route_convergence']['route_id'])->toBe($route->id)
        ->and($operation->input['route_convergence']['status'])->toBe($status)
        ->and($operation->input['participant_node_ids'])->toBe([$node->id, 209])
        ->and($operation->input['target_instance_ids'])->toBe([$instance->id])
        ->and($route->fresh()->getAttributes())->toBe($before)
        ->and($this->agents->calls)->toBe([])
        ->and($this->socket->sent)->toHaveCount(1)
        ->and($this->socket->sent[0]['payload']['workflowIdOrName'])->toBe('operation-route-converge')
        ->and($operation->state)->not->toHaveKeys(['route_plan', 'steps', 'executor_runs']);
})->with(['pending', 'failed', 'active']);

it('captures the full public app Route participants and public CA plan', function (): void {
    $cluster = Cluster::query()->create(['name' => 'converge-public', 'state' => 'active']);
    $router = instance_analytics_node('converge-router', '10.44.0.83', $cluster, RoleName::Router);
    $ingress = instance_analytics_node('converge-ingress', '10.44.0.84', $cluster, RoleName::Ingress);
    $workload = instance_analytics_node('converge-workload', '10.44.0.85', $cluster, RoleName::AppProd);
    $project = Project::query()->create(['name' => 'Public Converge', 'slug' => 'public-converge', 'root' => 'public', 'repository_url' => 'https://example.test/public-converge.git']);
    $instance = instance_analytics_instance($project, $workload, 'production');
    $route = instance_analytics_app_route($instance, 'public-converge.example.test', RoutePublication::Public);

    $this->postJson('/api/v1/routes/'.$route->id.'/converge')->assertAccepted();

    $operation = Operation::query()->sole();
    expect($operation->input['participant_node_ids'])->toBe([$router->id, $ingress->id, $workload->id, 209])
        ->and($operation->input['route_convergence']['public'])->toBeTrue()
        ->and($operation->input['route_convergence']['certificates'])->toHaveCount(2)
        ->and(array_column($operation->input['certificates'], 'scope'))->not->toContain('route-'.$route->id.'-ingress')
        ->and($this->agents->calls)->toBe([]);
});

it('admits a private custom proxy through the same native parent', function (): void {
    $node = Node::query()->create(['name' => 'converge-proxy', 'public_ssh_host' => '192.0.2.86', 'wireguard_ip' => '10.44.0.86', 'user' => 'orbit', 'platform' => 'linux', 'architecture' => 'x86_64', 'status' => 'active']);
    $route = Route::query()->create(['kind' => 'custom_proxy', 'node_id' => $node->id, 'domain' => 'converge-proxy.private.test', 'publication' => 'private', 'provenance' => 'explicit', 'status' => 'pending']);
    $route->customProxy()->create(['node_id' => $node->id, 'upstream' => 'http://127.0.0.1:8787']);

    $this->postJson('/api/v1/routes/'.$route->id.'/converge')->assertAccepted();

    $operation = Operation::query()->sole();
    expect($operation->input['route_convergence']['workloads'])->toBe([])
        ->and($operation->input['route_convergence']['certificates'][0]['scope'])->toBe('route-'.$route->id)
        ->and($operation->input['participant_node_ids'])->toBe([$node->id, 209])
        ->and($this->agents->calls)->toBe([]);
});

it('projects a failed Route recovery only from its bound native root', function (): void {
    [$route, , $node] = native_converge_admission_route('failed');
    $node->accessibleNodes()->attach($this->gateway);
    $this->postJson('/api/v1/routes/'.$route->id.'/converge')->assertAccepted();
    $operation = Operation::query()->sole();
    $this->withServerVariables(['REMOTE_ADDR' => $node->wireguard_ip]);
    $this->postJson('/api/v1/direct-operations/'.$operation->id.'/result', ['run_id' => 'a075eb23-7ac4-4487-80d1-57a038a92676',
        'status' => 'succeeded', 'started_at' => '2026-10-31T11:00:00.000Z', 'finished_at' => '2026-10-31T11:00:01.000Z',
        'result' => ['ready' => true, 'route_id' => $route->id]])->assertOk();

    expect($route->fresh()->status)->toBe(RouteStatus::Active)->and($route->fresh()->error_code)->toBeNull()
        ->and($operation->fresh()->status)->toBe(OperationStatus::Succeeded);
});

it('rejects retiring Route resurrection and a replacement in progress before native dispatch', function (): void {
    [$route] = native_converge_admission_route();
    $route->update(['status' => RouteStatus::Retiring]);
    $this->postJson('/api/v1/routes/'.$route->id.'/converge')->assertConflict()->assertJsonPath('error.code', 'route.removal_in_progress');
    $route->update(['status' => RouteStatus::Active]);
    app(RouteMutationState::class)->reserve($route, 'replacement-converge.private.test');
    $this->postJson('/api/v1/routes/'.$route->id.'/converge')->assertConflict()->assertJsonPath('error.code', 'route.domain_change_conflict');
    expect(Operation::query()->count())->toBe(0)->and($this->socket->sent)->toBe([]);
});

it('rejects foreign access and caller-provided mutation fields before dispatch', function (): void {
    [$route] = native_converge_admission_route();
    $this->postJson('/api/v1/routes/'.$route->id.'/converge', ['domain' => 'caller.example.test'])->assertUnprocessable();
    $foreign = Node::query()->create(['name' => 'foreign-converge', 'public_ssh_host' => '192.0.2.87', 'wireguard_ip' => '10.44.0.87', 'user' => 'orbit', 'platform' => 'linux', 'status' => 'active']);
    $this->withServerVariables(['REMOTE_ADDR' => $foreign->wireguard_ip]);
    $this->postJson('/api/v1/routes/'.$route->id.'/converge')->assertForbidden();
    expect(Operation::query()->count())->toBe(0)->and($this->socket->sent)->toBe([]);
});

it('refuses convergence while a failed target-set still owns cleanup', function (): void {
    [$route, $instance] = native_converge_admission_route('failed');
    $route->update(['target_set_intent' => ['targets' => [$instance->id], 'dispositions' => []],
        'target_set_step' => RouteTargetSetStep::DatabaseCommitted]);
    $before = $route->fresh()->getAttributes();

    $this->postJson('/api/v1/routes/'.$route->id.'/converge')->assertConflict()->assertJsonPath('error.code', 'route.target_set_conflict');

    expect($route->fresh()->getAttributes())->toBe($before)
        ->and($route->targets()->pluck('instance_id')->all())->toBe([$instance->id])
        ->and(Operation::query()->count())->toBe(0)
        ->and($this->socket->sent)->toBe([])
        ->and($this->agents->calls)->toBe([]);
});
