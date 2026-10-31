<?php

declare(strict_types=1);

use App\Domain\Certificates\LeafCertificateSigner;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Nodes\RoleName;
use App\Domain\Routes\NativeRouteMutationPlan;
use App\Domain\Routes\NativeRouteNodeMutationInput;
use App\Domain\Routes\NativeRouteNodeRender;
use App\Domain\Routes\RouteRenderContext;
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
    $manifest = tempnam(sys_get_temp_dir(), 'route-snapshot-agent-');
    file_put_contents($manifest, json_encode(['commit' => str_repeat('b', 40), 'version' => '0.5.0', 'artifacts' => ['linux-x86_64' => ['sha256' => str_repeat('a', 64), 'url' => 'https://example.test/agent']]], JSON_THROW_ON_ERROR));
    app()->instance(AgentDelivery::class, new AgentDelivery($manifest, sys_get_temp_dir()));
    $this->mock(LeafCertificateSigner::class)->shouldReceive('rootCertificate')->andReturn('public-root');
    $this->mock(ManagedUserAccountResolver::class)->shouldNotReceive('resolve');
    $this->node = Node::query()->create(['name' => 'snapshot-workload', 'user' => 'orbit', 'platform' => 'linux', 'architecture' => 'x86_64',
        'status' => 'active', 'public_ssh_host' => '192.0.2.31', 'wireguard_ip' => '10.44.0.31', 'tld' => 'old.test']);
    $this->node->roles()->create(['role' => 'app-dev', 'status' => 'active']);
    $this->gateway = Node::query()->create(['name' => 'snapshot-gateway', 'user' => 'orbit', 'platform' => 'linux', 'status' => 'active',
        'public_ssh_host' => '192.0.2.14', 'wireguard_ip' => '10.44.0.14']);
    $this->gateway->roles()->create(['role' => 'gateway', 'status' => 'active']);
    $this->gateway->roles()->create(['role' => 'vpn', 'status' => 'active']);
    $project = Project::query()->create(['name' => 'Snapshot', 'slug' => 'snapshot', 'repository_url' => 'https://example.test/snapshot.git', 'root' => 'public']);
    $this->instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $this->node->id, 'name' => 'development',
        'checkout_path' => '/srv/snapshot', 'status' => 'active', 'provisioning_step' => 'active', 'source_is_laravel' => false]);
    $this->route = Route::query()->create(['project_id' => $project->id, 'node_id' => $this->node->id, 'generation_basis_node_id' => $this->node->id,
        'domain' => 'development.snapshot.old.test', 'publication' => 'private', 'provenance' => 'generated', 'status' => 'pending']);
    $this->route->targets()->create(['instance_id' => $this->instance->id, 'position' => 0]);
    $this->route->update(['status' => 'active', 'sites_published' => true]);
    $this->candidate = new NativeRouteNodeRender($this->node->id, '10.44.0.41', 'new.test');
    $this->capture = app(NativeRouteNodeMutationInput::class);
});

it('reserves a canonical rename and captures both identities without changing the Node', function (): void {
    $stored = $this->node->fresh()->getAttributes();
    $input = $this->capture->capture($this->node, $this->candidate, null);
    $batch = $input['route_mutations']['batch'];
    expect($batch['changes'])->toHaveCount(1)
        ->and($batch['changes'][0]['route_id'])->toBe($this->route->id)
        ->and($batch['changes'][0]['result_route_id'])->not->toBe($this->route->id)
        ->and($input['route_mutations']['forward']['routes'][0]['domain'])->toBe('development.snapshot.new.test')
        ->and($input['route_mutations']['restore']['routes'][0]['domain'])->toBe('development.snapshot.old.test')
        ->and($this->node->fresh()->getAttributes())->toBe($stored)
        ->and($this->route->fresh()->domain)->toBe('development.snapshot.old.test')
        ->and(Operation::query()->count())->toBe(0)
        ->and(app(RouteRenderContext::class)->nodeId())->toBeNull();
    $node = collect($input['route_mutations']['forward']['publications'])->firstWhere('node_id', $this->node->id);
    expect($node['before']['caddy']['content'])->toContain('development.snapshot.old.test')->not->toContain('development.snapshot.new.test')
        ->and($node['after']['caddy']['content'])->toContain('development.snapshot.new.test')->not->toContain('development.snapshot.old.test')
        ->and($node['connection']['nodeHost'])->toBe('10.44.0.41');
    $restored = collect($input['route_mutations']['restore']['publications'])->firstWhere('node_id', $this->node->id);
    expect($restored['connection']['nodeHost'])->toBe('10.44.0.31')
        ->and($input['participant_node_ids'])->toContain($this->node->id, $this->gateway->id, 209)
        ->and($input['execution_secrets'])->toBe([]);
});

it('keeps recorded domains and captures address changes even without Route mutation rows', function (): void {
    $input = $this->capture->capture($this->node, new NativeRouteNodeRender($this->node->id, '10.44.0.41', 'old.test'), null);
    expect($input['route_mutations']['batch']['changes'])->toBe([])
        ->and($input['route_mutations']['forward']['routes'][0]['domain'])->toBe('development.snapshot.old.test');
    $publication = collect($input['route_mutations']['forward']['publications'])->firstWhere('node_id', $this->gateway->id);
    expect($publication['after']['dns']['records'])->toContain('host-record=development.snapshot.old.test,10.44.0.41')
        ->and($publication['before']['dns']['records'])->toContain('host-record=development.snapshot.old.test,10.44.0.31');
});

it('refuses discovery inside a prospective context or for a different actual Node', function (): void {
    expect(fn () => app(RouteRenderContext::class)->render($this->candidate, fn () => $this->capture->capture($this->node, $this->candidate, null)))
        ->toThrow(ResourceOperationException::class);
    expect(fn () => $this->capture->capture($this->gateway, $this->candidate, null))->toThrow(ResourceOperationException::class);
    expect(Route::query()->count())->toBe(1)->and(Operation::query()->count())->toBe(0);
});

it('keeps private environment values solely in shared execution copies for both directions', function (): void {
    $this->instance->update(['source_is_laravel' => true]);
    $this->instance->environmentValues()->create(['env_key' => 'APP_KEY', 'env_value' => 'synthetic-private-key']);
    $this->instance->environmentValues()->create(['env_key' => 'APP_URL', 'env_value' => 'https://{{instance.domain}}']);
    config(['swamp.address' => '192.0.2.209', 'swamp.ca_path' => '/public/root.pem', 'swamp.run_token' => 'synthetic-token']);
    $socket = new FakeRoutePublicationSocket;
    $socket->reply = static fn (array $request): array => [['type' => 'vault.put', 'id' => $request['id'], 'payload' => ['data' => [
        'vaultName' => $request['payload']['vaultName'], 'secretKey' => $request['payload']['key'], 'vaultType' => 'local_encryption']]]];
    app()->instance(WebSocketClient::class, $socket);
    app()->forgetInstance(NativeRouteNodeMutationInput::class);
    $input = app(NativeRouteNodeMutationInput::class)->capture($this->node, $this->candidate, null);
    expect($input['execution_secrets'])->toHaveCount(2)
        ->and(json_encode($input, JSON_THROW_ON_ERROR))->not->toContain('synthetic-private-key');
    $values = array_column(array_column($socket->sent, 'payload'), 'value');
    expect($values)->toHaveCount(2)->and($values[0])->toContain('synthetic-private-key', 'development.snapshot.new.test')
        ->and($values[1])->toContain('synthetic-private-key', 'development.snapshot.old.test');
});

it('applies forward Route changes only from the final parent projection', function (): void {
    $input = $this->capture->capture($this->node, $this->candidate, null);
    $resultId = $input['route_mutations']['batch']['changes'][0]['result_route_id'];
    expect($this->route->fresh()->status->value)->toBe('active');
    $this->node->update(['tld' => 'new.test', 'wireguard_ip' => '10.44.0.41']);
    app(NativeRouteMutationPlan::class)->applyTerminal($input['route_mutations'], ['ready' => true, 'restored' => false]);
    expect(Route::query()->find($this->route->id))->toBeNull()
        ->and(Route::query()->findOrFail($resultId)->status->value)->toBe('active')
        ->and(Route::query()->findOrFail($resultId)->domain)->toBe('development.snapshot.new.test')
        ->and(Operation::query()->count())->toBe(0);
});

it('records complete native restoration without committing the candidate Node', function (): void {
    $input = $this->capture->capture($this->node, $this->candidate, null);
    app(NativeRouteMutationPlan::class)->applyTerminal($input['route_mutations'], ['ready' => true, 'restored' => true]);
    expect($this->node->fresh()->tld)->toBe('old.test')->and($this->route->fresh()->domain)->toBe('development.snapshot.old.test')
        ->and($this->route->fresh()->replaced_by_route_id)->toBeNull()->and(Route::query()->count())->toBe(1);
});

it('selects the prospective web-root URL while retaining the prior restoration URL', function (): void {
    $this->route->update(['web_root' => 'docs/public']);
    $this->instance->update(['selected_php_version' => '8.5']);
    config(['swamp.address' => '192.0.2.209', 'swamp.ca_path' => '/public/root.pem', 'swamp.run_token' => 'synthetic-token']);
    $socket = new FakeRoutePublicationSocket;
    $socket->reply = static fn (array $request): array => [['type' => 'vault.put', 'id' => $request['id'], 'payload' => ['data' => [
        'vaultName' => $request['payload']['vaultName'], 'secretKey' => $request['payload']['key'], 'vaultType' => 'local_encryption']]]];
    app()->instance(WebSocketClient::class, $socket);
    app()->forgetInstance(NativeRouteNodeMutationInput::class);
    $input = app(NativeRouteNodeMutationInput::class)->capture($this->node, $this->candidate, null);
    expect($input['route_mutations']['forward']['url_writes'][0]['input']['url'])->toBe('https://development.snapshot.new.test')
        ->and($input['route_mutations']['restore']['url_writes'][0]['input']['url'])->toBe('https://development.snapshot.old.test');
});

it('captures public address-only changes while preserving literal public hostnames', function (): void {
    $this->route->update(['publication' => 'public']);
    $input = $this->capture->capture($this->node, $this->candidate, null);
    expect($input['route_mutations']['batch']['changes'])->toBe([])
        ->and($input['route_mutations']['forward']['routes'][0]['domain'])->toBe('development.snapshot.old.test');
    $publication = collect($input['route_mutations']['forward']['publications'])->firstWhere('node_id', $this->node->id);
    expect($publication)->not->toBeNull()
        ->and($publication['connection']['nodeHost'])->toBe('10.44.0.41')
        ->and($input['route_mutations']['restore']['routes'][0]['domain'])->toBe('development.snapshot.old.test');
});

it('preserves literal tracking sites while rendering an address-only change', function (): void {
    $this->gateway->roles()->create(['role' => 'analytics', 'status' => 'active']);
    $tracking = instance_analytics_tracking($this->instance, 'tracker.literal.test');
    $input = $this->capture->capture($this->node, new NativeRouteNodeRender($this->node->id, '10.44.0.41', 'old.test'), null);
    $publication = collect($input['route_mutations']['forward']['publications'])->firstWhere('node_id', $this->node->id);
    expect($publication['before']['caddy']['content'])->toContain('tracker.literal.test')
        ->and($publication['after']['caddy']['content'])->toContain('tracker.literal.test')
        ->and($tracking->fresh()->domain)->toBe('tracker.literal.test')
        ->and($input['route_mutations']['analytics_changes'])->toBe([]);
});

it('captures tracking placement through its owner and applies it only at terminal', function (): void {
    $this->gateway->roles()->create(['role' => 'analytics', 'status' => 'active']);
    $tracking = instance_analytics_tracking($this->instance, 'tracker.literal.test');
    $cluster = Cluster::query()->create(['name' => 'snapshot-cluster', 'tld' => 'cluster.test', 'state' => 'active']);
    $router = instance_analytics_node('snapshot-router', '10.44.0.51', $cluster, RoleName::Router);
    $input = $this->capture->capture($this->node, $this->candidate, $cluster->id);
    expect($input['route_mutations']['analytics_changes'])->toHaveCount(1)
        ->and($tracking->fresh()->node_id)->toBe($this->node->id)
        ->and($input['route_mutations']['forward']['routes'][1]['domain'])->toBe('tracker.literal.test');
    $publication = collect($input['route_mutations']['forward']['publications'])->firstWhere('node_id', $router->id);
    expect($publication['after']['caddy']['content'])->toContain('tracker.literal.test');
    app(NativeRouteMutationPlan::class)->applyTerminal($input['route_mutations'], ['ready' => true, 'restored' => false]);
    expect($tracking->fresh()->cluster_id)->toBe($cluster->id)->and($tracking->fresh()->node_id)->toBeNull()
        ->and($tracking->fresh()->domain)->toBe('tracker.literal.test');
});

it('publishes an address change on a Node that serves only a custom proxy', function (): void {
    $node = instance_analytics_node('snapshot-proxy', '10.44.0.61', null, RoleName::AppDev);
    $proxy = Route::query()->create(['kind' => 'custom_proxy', 'node_id' => $node->id, 'domain' => 'proxy.literal.test',
        'provenance' => 'explicit', 'publication' => 'private', 'status' => 'pending']);
    $proxy->customProxy()->create(['node_id' => $node->id, 'upstream' => 'http://127.0.0.1:8787']);
    $proxy->update(['status' => 'active', 'sites_published' => true]);
    $input = $this->capture->capture($node, new NativeRouteNodeRender($node->id, '10.44.0.62', 'proxy.test'), null);
    $publication = collect($input['route_mutations']['forward']['publications'])->firstWhere('node_id', $node->id);
    expect($input['route_mutations']['batch']['changes'])->toBe([])
        ->and($publication['serves_routes'])->toBeTrue()
        ->and($publication['before']['caddy']['content'])->toContain('10.44.0.61', 'proxy.literal.test')
        ->and($publication['after']['caddy']['content'])->toContain('10.44.0.62', 'proxy.literal.test')
        ->and($node->fresh()->wireguard_ip)->toBe('10.44.0.61');
});
