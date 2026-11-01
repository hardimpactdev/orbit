<?php

declare(strict_types=1);

use App\Actions\Routes\AdmitRouteOperationAction;
use App\Actions\Routes\RouteTargetState;
use App\Domain\Certificates\LeafCertificateSigner;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Routes\NativeRouteTargetInput;
use App\Domain\Routes\NativeRouteTargetIntent;
use App\Domain\Routes\NativeRouteTargetProjection;
use App\Domain\Shared\ResourceOperationException;
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

it('freezes prospective target sites and original restoration without changing associations or markers', function (): void {
    $capture = app(NativeRouteTargetInput::class)->capture($this->routes['pool']->fresh(), $this->proposal);
    $publication = collect($capture['intent']['route_mutations']['forward']['publications'])->firstWhere('node_id', $this->nodes['new']->id);

    expect($publication['before']['caddy']['content'])->toContain('source.target.test')->not->toContain('pool.target.test')
        ->and($publication['prepared']['caddy']['content'])->toContain('source.target.test', 'pool.target.test', '-hostname-change')
        ->and($publication['after']['caddy']['content'])->toContain('pool.target.test')->not->toContain('source.target.test', '-hostname-change')
        ->and($publication['host_keys'])->toBe(['ssh-ed25519 fixture-target-key'])
        ->and($publication['control_node_id'])->toBe(209)
        ->and($this->routes['pool']->targets()->pluck('instance_id')->all())->toBe([$this->instances['old']->id])
        ->and($this->routes['source']->targets()->pluck('instance_id')->all())->toBe([$this->instances['new']->id])
        ->and($this->routes['destination']->targets()->count())->toBe(0)
        ->and($this->routes['destination']->fresh()->sites_published)->toBeFalse()
        ->and(Operation::query()->count())->toBe(0);
});

it('freezes environment values in execution copies with the proposed and original domains', function (): void {
    foreach ($this->instances as $instance) {
        $instance->update(['source_is_laravel' => true]);
        $instance->environmentValues()->create(['env_key' => 'APP_KEY', 'env_value' => 'synthetic-target-private-key']);
        $instance->environmentValues()->create(['env_key' => 'APP_URL', 'env_value' => 'https://{{instance.domain}}']);
    }
    config(['swamp.address' => '192.0.2.209', 'swamp.ca_path' => '/public/root.pem', 'swamp.run_token' => 'synthetic-token']);
    $socket = new FakeRoutePublicationSocket;
    $socket->reply = static fn (array $request): array => [['type' => 'vault.put', 'id' => $request['id'], 'payload' => ['data' => [
        'vaultName' => $request['payload']['vaultName'], 'secretKey' => $request['payload']['key'], 'vaultType' => 'local_encryption']]]];
    app()->instance(WebSocketClient::class, $socket);

    $capture = app(NativeRouteTargetInput::class)->capture($this->routes['pool']->fresh(), $this->proposal);

    expect(json_encode($capture, JSON_THROW_ON_ERROR))->not->toContain('synthetic-target-private-key')
        ->and($capture['execution_secrets'])->toHaveCount(4);
    $values = collect($socket->sent)->pluck('payload.value');
    expect($values->filter(fn (string $value): bool => str_contains($value, 'pool.target.test')))->toHaveCount(2)
        ->and($values->filter(fn (string $value): bool => str_contains($value, 'source.target.test')))->toHaveCount(1)
        ->and($values->filter(fn (string $value): bool => str_contains($value, 'destination.target.test')))->toHaveCount(1);
});

function target_input_operation(array $capture, int $nodeId): Operation
{
    app(RouteTargetState::class)->reserve(Route::query()->findOrFail($capture['intent']['route_id']),
        NativeRouteTargetIntent::proposal($capture['intent']['proposal']));

    return Operation::query()->create(['kind' => 'route.target_set', 'target_type' => 'route', 'target_id' => $capture['intent']['route_id'],
        'node_id' => $nodeId, 'status' => 'running', 'sequence' => 1, 'state' => ['swamp_run_id' => '8374180c-85cc-4df3-9fbf-e538d43404c5'],
        'input' => ['route_target' => $capture['intent'], 'execution_secrets' => $capture['execution_secrets']]]);
}

function target_input_project(Operation $operation, string $status, array $result): Operation
{
    return app(NativeRouteTargetProjection::class)->apply($operation, [
        'run_id' => $operation->state['swamp_run_id'], 'status' => $status,
        'started_at' => '2026-11-01T04:00:00Z', 'finished_at' => $status === 'running' ? null : '2026-11-01T04:01:00Z',
        'error_code' => $status === 'failed' ? 'route.target_set_failed' : null, 'result' => $result]);
}

it('commits the proposal only from terminal success and accepts identical delivery', function (): void {
    $capture = app(NativeRouteTargetInput::class)->capture($this->routes['pool']->fresh(), $this->proposal);
    $operation = target_input_operation($capture, $this->nodes['old']->id);
    target_input_project($operation, 'running', ['checkpoint' => 'database-committed']);
    expect($this->routes['pool']->targets()->pluck('instance_id')->all())->toBe([$this->instances['old']->id]);

    $result = ['ready' => true, 'restored' => false, 'selected' => 'after', 'route_id' => $this->routes['pool']->id, 'result_route_id' => $this->routes['pool']->id, 'migrated_node_ids' => []];
    $done = target_input_project($operation, 'succeeded', $result);
    target_input_project($done, 'succeeded', $result);

    expect($done->fresh()->status->value)->toBe('succeeded')
        ->and($this->routes['pool']->targets()->pluck('instance_id')->all())->toBe([$this->instances['new']->id])
        ->and($this->routes['source']->targets()->count())->toBe(0)
        ->and($this->routes['destination']->targets()->pluck('instance_id')->all())->toBe([$this->instances['old']->id])
        ->and($this->routes['destination']->fresh()->sites_published)->toBeTrue()
        ->and($done->fresh()->state)->not->toHaveKeys(['native_target_preparations', 'native_target_rollback', 'steps']);
});

it('never commits a failed or cancelled proposal even after proved restoration', function (string $status, bool $restored): void {
    $capture = app(NativeRouteTargetInput::class)->capture($this->routes['pool']->fresh(), $this->proposal);
    $operation = target_input_operation($capture, $this->nodes['old']->id);

    $done = target_input_project($operation, $status, ['ready' => false, 'restored' => $restored, 'selected' => $restored ? 'before' : 'unknown']);

    expect($done->status->value)->toBe($status)
        ->and($this->routes['pool']->targets()->pluck('instance_id')->all())->toBe([$this->instances['old']->id])
        ->and($this->routes['source']->targets()->pluck('instance_id')->all())->toBe([$this->instances['new']->id])
        ->and($this->routes['destination']->targets()->count())->toBe(0)
        ->and($this->routes['destination']->fresh()->sites_published)->toBeFalse();
})->with([['failed', true], ['failed', false], ['cancelled', false]]);

it('captures a completed no-op without effects or private-material bindings', function (): void {
    $capture = app(NativeRouteTargetInput::class)->capture($this->routes['pool']->fresh(), ['mode' => 'state_only', 'targets' => [$this->instances['old']->id], 'dispositions' => []]);

    expect($capture['intent']['effectful'])->toBeFalse()->and($capture['intent']['workloads'])->toBe([])
        ->and($capture['certificates'])->toBe([])->and($capture['execution_secrets'])->toBe([])
        ->and($capture['intent'])->not->toHaveKeys(['environment_fields', 'private_bindings', 'route_mutations']);
});

it('keeps co-located Router pools unchanged until workload TLS preparation finishes', function (): void {
    $this->nodes['router']->roles()->where('role', 'router')->delete();
    $this->nodes['new']->roles()->create(['role' => 'router', 'status' => 'active', 'cluster_id' => $this->nodes['new']->cluster_id]);

    $capture = app(NativeRouteTargetInput::class)->capture($this->routes['pool']->fresh(), $this->proposal);
    $publication = collect($capture['intent']['route_mutations']['forward']['publications'])->firstWhere('node_id', $this->nodes['new']->id);
    $preparation = collect($capture['intent']['preparation']['publications'])->firstWhere('node_id', $this->nodes['new']->id);
    $pool = static fn (array $caddy): string => collect($caddy['blocks'])->first(static fn (array $block): bool => str_contains($block['block'], 'pool.target.test'))['block'];

    expect($pool($preparation['after']))->toBe($pool($publication['before']['caddy']))
        ->and($pool($publication['prepared']['caddy']))->not->toBe($pool($publication['before']['caddy']))
        ->and($capture['intent']['preparation']['probes'])->toHaveCount(1)
        ->and($capture['intent']['preparation']['probes'][0]['probe']['domain'])->toBe('destination.target.test')
        ->and($capture['intent']['preparation']['probes'][0]['host_keys'])->toBe(['ssh-ed25519 fixture-target-key']);
});

it('refuses a missing target pin before publishing execution copies or changing associations', function (): void {
    $this->nodes['new']->update(['ssh_host_key' => null]);
    $socket = new FakeRoutePublicationSocket;
    app()->instance(WebSocketClient::class, $socket);

    expect(fn () => app(NativeRouteTargetInput::class)->capture($this->routes['pool']->fresh(), $this->proposal))
        ->toThrow(ResourceOperationException::class);
    expect($socket->sent)->toBe([])
        ->and($this->routes['pool']->targets()->pluck('instance_id')->all())->toBe([$this->instances['old']->id]);
});

it('keeps configured environment material for both sides of an unbound production target', function (): void {
    $node = Node::query()->create(['name' => 'target-unbound', 'cluster_id' => $this->nodes['new']->cluster_id, 'user' => 'orbit', 'platform' => 'linux', 'architecture' => 'x86_64', 'status' => 'active',
        'public_ssh_host' => '192.0.2.49', 'wireguard_ip' => '10.44.0.49', 'lan_ip' => '10.20.0.49', 'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'fixture-target-key']);
    $node->roles()->create(['role' => 'app-prod', 'status' => 'active']);
    $instance = Instance::query()->create(['project_id' => $this->instances['new']->project_id, 'node_id' => $node->id, 'name' => 'unbound',
        'production_user' => 'targets-unbound', 'production_home' => '/home/targets-unbound', 'checkout_path' => '/home/targets-unbound/releases/test',
        'status' => 'active', 'provisioning_step' => 'active', 'source_is_laravel' => true]);
    $this->proposal['targets'] = [$instance->id];
    $instance->environmentValues()->create(['env_key' => 'APP_KEY', 'env_value' => 'synthetic-unbound-private-key']);
    $instance->environmentValues()->create(['env_key' => 'APP_URL', 'env_value' => 'https://unbound.target.test']);
    $socket = new FakeRoutePublicationSocket;
    $socket->reply = static fn (array $request): array => [['type' => 'vault.put', 'id' => $request['id'], 'payload' => ['data' => [
        'vaultName' => $request['payload']['vaultName'], 'secretKey' => $request['payload']['key'], 'vaultType' => 'local_encryption']]]];
    app()->instance(WebSocketClient::class, $socket);
    config(['swamp.address' => '192.0.2.209', 'swamp.ca_path' => '/public/root.pem', 'swamp.run_token' => 'synthetic-token']);

    $capture = app(NativeRouteTargetInput::class)->capture($this->routes['pool']->fresh(), $this->proposal);

    expect($capture['execution_secrets'])->toHaveCount(2)
        ->and($capture['intent']['route_mutations']['restore']['url_writes'][0]['instance_id'])->toBe($instance->id)
        ->and(json_encode($capture, JSON_THROW_ON_ERROR))->not->toContain('synthetic-unbound-private-key');
    expect(collect($socket->sent)->pluck('payload.value')->all())->each->toContain('unbound.target.test', 'synthetic-unbound-private-key');
});

it('publishes a pending pool only after terminal success', function (string $status): void {
    $route = $this->routes['pool'];
    $route->update(['status' => 'pending', 'sites_published' => false]);
    $capture = app(NativeRouteTargetInput::class)->capture($route->fresh(), $this->proposal);
    $operation = target_input_operation($capture, $this->nodes['old']->id);
    $router = collect($capture['intent']['route_mutations']['forward']['publications'])->firstWhere('node_id', $this->nodes['router']->id);
    expect($router['before']['caddy']['content'])->not->toContain('pool.target.test')
        ->and($router['after']['caddy']['content'])->toContain('pool.target.test')
        ->and($route->fresh()->status->value)->toBe('pending')->and($route->fresh()->sites_published)->toBeFalse();

    target_input_project($operation, $status, ['ready' => $status === 'succeeded', 'restored' => $status === 'failed']);

    expect($route->fresh()->status->value)->toBe($status === 'succeeded' ? 'active' : 'pending')
        ->and($route->fresh()->sites_published)->toBe($status === 'succeeded');
})->with(['succeeded', 'failed', 'cancelled']);

it('refuses adoption of an already committed target transition before a new admission', function (): void {
    $route = $this->routes['pool'];
    $proposal = NativeRouteTargetIntent::proposal($this->proposal);
    app(RouteTargetState::class)->reserve($route, $proposal);
    $route->update(['target_set_step' => 'database-committed']);
    $socket = new FakeRoutePublicationSocket;
    app()->instance(WebSocketClient::class, $socket);

    expect(fn () => app(AdmitRouteOperationAction::class)->setTargets($route->fresh(), $proposal))
        ->toThrow(ResourceOperationException::class, 'The committed target transition must finish through its owning Operation.');
    expect($socket->sent)->toBe([])->and(Operation::query()->count())->toBe(0)
        ->and($route->targets()->pluck('instance_id')->all())->toBe([$this->instances['old']->id]);
});
