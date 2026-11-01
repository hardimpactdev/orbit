<?php

declare(strict_types=1);

use App\Domain\Certificates\LeafCertificateSigner;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Routes\NativeRouteConvergenceProjection;
use App\Domain\Routes\NativeRouteLifecycleInput;
use App\Domain\Routes\NativeRouteRemovalProjection;
use App\Domain\Routes\RouteEnvironmentMaterialSource;
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
    $this->manifest = tempnam(sys_get_temp_dir(), 'target-input-agent-');
    file_put_contents($this->manifest, json_encode(['commit' => str_repeat('b', 40), 'version' => '0.5.0', 'artifacts' => ['linux-x86_64' => ['sha256' => str_repeat('a', 64), 'url' => 'https://example.test/agent']]], JSON_THROW_ON_ERROR));
    app()->instance(AgentDelivery::class, new AgentDelivery($this->manifest, sys_get_temp_dir()));
    $this->mock(LeafCertificateSigner::class)->shouldReceive('rootCertificate')->andReturn('public-root');
    $this->mock(ManagedUserAccountResolver::class)->shouldNotReceive('resolve');
    $cluster = Cluster::query()->create(['name' => 'immutable-targets', 'state' => 'active', 'tld' => 'target.test']);
    $project = Project::query()->create(['name' => 'Targets', 'slug' => 'targets', 'repository_url' => 'https://example.test/targets.git', 'root' => 'public']);
    $this->nodes = [];
    foreach (['old' => 'app-prod', 'new' => 'app-prod', 'router' => 'router', 'gateway' => 'gateway'] as $name => $role) {
        $n = count($this->nodes) + 31;
        $node = Node::query()->create(['name' => 'target-'.$name, 'cluster_id' => $cluster->id, 'user' => 'orbit', 'platform' => 'linux', 'architecture' => 'x86_64', 'status' => 'active',
            'public_ssh_host' => '192.0.2.'.$n, 'wireguard_ip' => '10.44.0.'.$n, 'lan_ip' => '10.20.0.'.$n,
            'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'fixture-target-key']);
        $node->roles()->create(['role' => $role, 'status' => 'active', 'cluster_id' => $role === 'router' ? $cluster->id : null]);
        $this->nodes[$name] = $node;
    }
    $this->nodes['gateway']->roles()->create(['role' => 'vpn', 'status' => 'active']);
    $this->routes = [];
    foreach (['pool', 'source', 'destination'] as $name) {
        $this->routes[$name] = Route::query()->create(['project_id' => $project->id, 'cluster_id' => $cluster->id,
            'domain' => $name.'.target.test', 'publication' => 'private', 'provenance' => 'explicit', 'status' => 'pending']);
    }
    $this->instances = [];
    foreach (['old' => 'pool', 'new' => 'source'] as $name => $owner) {
        $home = '/home/targets-'.$name;
        $instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $this->nodes[$name]->id, 'name' => $name,
            'environment' => 'production', 'production_user' => 'targets-'.$name, 'production_home' => $home, 'checkout_path' => $home.'/releases/test',
            'status' => 'active', 'provisioning_step' => 'active', 'source_is_laravel' => false]);
        $this->routes[$owner]->targets()->create(['instance_id' => $instance->id, 'position' => 0]);
        $this->routes[$owner]->update(['status' => 'active', 'sites_published' => true]);
        $this->instances[$name] = $instance;
    }
    $this->proposal = ['mode' => 'targets', 'targets' => [$this->instances['new']->id],
        'dispositions' => [['instance_id' => $this->instances['old']->id, 'route_id' => $this->routes['destination']->id, 'remove' => false]]];
});

afterEach(function (): void {
    unlink($this->manifest);
});

it('captures actual before and immutable create after without early publication', function (): void {
    $route = $this->routes['pool'];
    $route->update(['status' => 'pending', 'sites_published' => false]);
    $capture = app(NativeRouteLifecycleInput::class)->capture($route->fresh(), ['mode' => 'converge']);
    $pub = collect($capture['intent']['route_mutations']['forward']['publications'])->firstWhere('node_id', $this->nodes['old']->id);
    expect($pub['before']['caddy']['content'])->not->toContain('pool.target.test')
        ->and($pub['after']['caddy']['content'])->toContain('pool.target.test')
        ->and($capture['intent']['route_mutations']['restore']['certificates'])->toBe([])
        ->and($capture['intent']['preparation']['probes'])->not->toBeEmpty()
        ->and($route->fresh()->sites_published)->toBeFalse()->and(Operation::query()->count())->toBe(0);
});

it('captures withdrawal without changing the actual serving catalog or associations', function (): void {
    $route = $this->routes['pool'];
    $capture = app(NativeRouteLifecycleInput::class)->capture($route, [], true);
    $pub = collect($capture['intent']['route_mutations']['forward']['publications'])->firstWhere('node_id', $this->nodes['old']->id);
    expect($pub['before']['caddy']['content'])->toContain('pool.target.test')
        ->and($pub['after']['caddy']['content'])->not->toContain('pool.target.test')
        ->and($capture['intent']['route_mutations']['forward']['cleanup'])->not->toBeEmpty()
        ->and($route->fresh()->status->value)->toBe('active')->and($route->fresh()->sites_published)->toBeTrue()
        ->and($route->targets()->count())->toBe(1)->and(Operation::query()->count())->toBe(0);
});

it('projects create serving state only at native terminal success', function (string $status): void {
    $route = $this->routes['pool'];
    $route->update(['status' => 'pending', 'sites_published' => false]);
    $capture = app(NativeRouteLifecycleInput::class)->capture($route->fresh(), ['mode' => 'converge']);
    $op = lifecycle_operation($route, $capture, 'route.create');
    lifecycle_project($op, NativeRouteConvergenceProjection::class, 'running');
    expect($route->fresh()->sites_published)->toBeFalse();
    lifecycle_project($op, NativeRouteConvergenceProjection::class, $status);
    expect($route->fresh()->sites_published)->toBe($status === 'succeeded')
        ->and($route->fresh()->status->value)->toBe($status === 'succeeded' ? 'active' : 'pending')
        ->and($route->targets()->count())->toBe(1);
})->with(['succeeded', 'failed', 'cancelled']);

it('deletes or withdraws only at native terminal success', function (string $kind, string $status): void {
    $route = $this->routes['pool'];
    $this->instances['old']->update(['status' => 'reserved']);
    $capture = app(NativeRouteLifecycleInput::class)->capture($route, [], true);
    $op = lifecycle_operation($route, $capture, $kind);
    lifecycle_project($op, NativeRouteRemovalProjection::class, 'running');
    expect($route->fresh()->sites_published)->toBeTrue();
    lifecycle_project($op, NativeRouteRemovalProjection::class, $status);
    if ($status === 'succeeded' && $kind === 'route.destroy') {
        expect($route->fresh())->toBeNull();
    } else {
        expect($route->fresh()->status->value)->toBe($status === 'succeeded' ? 'retiring' : ($status === 'failed' ? 'failed' : 'active'))
            ->and($route->fresh()->sites_published)->toBe($status !== 'succeeded')
            ->and($route->targets()->count())->toBe(1);
    }
})->with([['route.destroy', 'succeeded'], ['route.destroy', 'failed'], ['route.destroy', 'cancelled'], ['route.withdraw', 'succeeded']]);

it('does not require host inputs for an unchanged active create retry', function (): void {
    $this->nodes['old']->update(['ssh_host_key' => null]);
    $capture = app(NativeRouteLifecycleInput::class)->capture($this->routes['pool'], ['mode' => 'state_only']);
    expect($capture['intent']['effectful'])->toBeFalse()->and($capture['execution_secrets'])->toBe([])->and($capture['certificates'])->toBe([]);
});

function lifecycle_operation(Route $route, array $capture, string $kind): Operation
{
    return Operation::query()->create(['kind' => $kind, 'target_type' => 'route', 'target_id' => $route->id, 'node_id' => $route->targets()->first()?->instance->node_id ?? $route->node_id, 'status' => 'running',
        'input' => ['route_lifecycle' => $capture['intent'], 'execution_secrets' => $capture['execution_secrets'], 'route_removal' => ['routes' => [['route_id' => $route->id, 'domain' => $route->domain, 'replacement_of' => null]]]],
        'state' => ['swamp_run_id' => 'e81cd50a-ed9c-4c79-a15d-0c0c03936ce1']]);
}
function lifecycle_project(Operation $op, string $projection, string $status): Operation
{
    return app($projection)->apply($op, ['run_id' => $op->state['swamp_run_id'], 'status' => $status, 'started_at' => '2026-11-01T05:00:00Z',
        'finished_at' => $status === 'running' ? null : '2026-11-01T05:01:00Z', 'error_code' => $status === 'failed' ? 'route.lifecycle_failed' : null,
        'result' => ['ready' => $status === 'succeeded', 'restored' => $status === 'failed', 'migrated_node_ids' => []]]);
}

it('captures configured Laravel material for an unpublished initial Route', function (): void {
    $route = $this->routes['pool'];
    $route->update(['status' => 'pending', 'sites_published' => false]);
    $instance = $this->instances['old'];
    $instance->update(['source_is_laravel' => true]);
    $instance->environmentValues()->create(['env_key' => 'APP_URL', 'env_value' => 'https://{{instance.domain}}']);
    $socket = new FakeRoutePublicationSocket;
    $socket->reply = static fn (array $request): array => [['type' => 'vault.put', 'id' => $request['id'], 'payload' => ['data' => ['vaultName' => $request['payload']['vaultName'], 'secretKey' => $request['payload']['key'], 'vaultType' => 'local_encryption']]]];
    app()->instance(WebSocketClient::class, $socket);
    config(['swamp.address' => '192.0.2.209', 'swamp.run_token' => 'fixture-token', 'swamp.ca_path' => '/public/root.pem']);
    $capture = app(NativeRouteLifecycleInput::class)->capture($route->fresh(), ['mode' => 'converge']);
    expect($capture['execution_secrets'])->not->toBeEmpty()
        ->and($capture['intent']['route_mutations']['restore']['certificates'])->toBe([])
        ->and($route->fresh()->sites_published)->toBeFalse();
});

it('captures inactive Laravel removal without rewriting its default environment', function (): void {
    $this->instances['old']->update(['source_is_laravel' => true, 'status' => 'reserved']);
    $capture = app(NativeRouteLifecycleInput::class)->capture($this->routes['pool'], [], true);
    expect($capture['execution_secrets'])->toBe([])
        ->and($capture['intent']['route_mutations']['forward']['url_writes'])->toBe([])
        ->and($this->routes['pool']->fresh()->sites_published)->toBeTrue();
});

it('does not expose a Route creation private-material callback', function (): void {
    $source = app(RouteEnvironmentMaterialSource::class);
    $operation = new Operation(['kind' => 'route.create', 'target_type' => 'route', 'target_id' => $this->routes['pool']->id]);
    expect($source->ownerId($operation))->toBeNull()->and($source->targetTypes())->not->toContain('route');
});

it('captures public creation with automatic HTTPS and immutable Process checks', function (bool $process): void {
    $ingress = $this->nodes['new'];
    $ingress->roles()->create(['role' => 'ingress', 'status' => 'active', 'cluster_id' => $ingress->cluster_id]);
    $route = $this->routes['pool'];
    if ($process) {
        $owner = $this->nodes['old'];
        $owner->update(['lan_ip' => null]);
        $service = Process::query()->create(['owner_type' => Node::class, 'owner_id' => $owner->id, 'name' => 'public-service',
            'runtime' => 'docker', 'working_directory' => '/app', 'runtime_config' => ['image' => 'service:latest', 'command' => ['service'], 'environment' => [], 'ports' => [$owner->wireguard_ip.':8095:80/tcp'], 'volumes' => []],
            'restart_policy' => 'unless-stopped', 'desired_state' => 'running', 'status' => 'active']);
        $route = Route::query()->create(['kind' => 'custom_proxy', 'cluster_id' => $ingress->cluster_id, 'domain' => 'process.example.test',
            'publication' => 'public', 'provenance' => 'explicit', 'status' => 'pending']);
        $route->customProxy()->create(['node_id' => $owner->id, 'process_id' => $service->id, 'upstream' => 'http://'.$owner->wireguard_ip.':8095']);
    }
    $route->update(['status' => 'pending', 'sites_published' => false, 'publication' => 'public']);
    $capture = app(NativeRouteLifecycleInput::class)->capture($route->fresh(), []);
    $forward = $capture['intent']['route_mutations']['forward'];
    $publication = collect($forward['publications'])->firstWhere('node_id', $ingress->id);
    expect($capture['intent']['public_activated'])->toBeTrue()
        ->and($publication['before']['caddy']['content'])->not->toContain($route->domain)
        ->and($publication['after']['caddy']['content'])->toContain($route->domain)->not->toContain('/orbit-certificates/route-'.$route->id.'-ingress/')
        ->and($forward['ingress'][0]['rules'])->toBe([['port' => 80, 'ensure' => 'present'], ['port' => 443, 'ensure' => 'present']])
        ->and($capture['intent']['verifications'][0]['probe']['domain'])->toBe($route->domain)
        ->and($route->fresh()->sites_published)->toBeFalse();
    if ($process) {
        $checks = $capture['intent']['preparation']['upstream_firewalls'];
        expect($checks)->toHaveCount(1)->and($checks[0]['node_id'])->toBe($owner->id)
            ->and(array_column($checks[0]['expected'], 'comment'))->toContain('orbit:wireguard-members')
            ->and($capture['intent']['preparation']['probes'][0])->toMatchArray(['protocol' => 'http', 'node_id' => $ingress->id]);
    }
})->with([false, true]);
