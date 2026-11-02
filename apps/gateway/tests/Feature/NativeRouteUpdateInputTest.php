<?php

declare(strict_types=1);

use App\Actions\Routes\RouteMutationState;
use App\Domain\Certificates\LeafCertificateSigner;
use App\Domain\Instances\ProductionPhpRuntimeIdentity;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Routes\NativeRouteNodeRender;
use App\Domain\Routes\NativeRouteUpdateInput;
use App\Domain\Routes\NativeRouteUpdateProjection;
use App\Domain\Routes\NativeRouteWorkloadPreparation;
use App\Domain\Routes\RouteReplacementStep;
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
    $this->manifest = $manifest = tempnam(sys_get_temp_dir(), 'route-snapshot-agent-');
    file_put_contents($manifest, json_encode(['commit' => str_repeat('b', 40), 'version' => '0.5.0', 'artifacts' => ['linux-x86_64' => ['sha256' => str_repeat('a', 64), 'url' => 'https://example.test/agent']]], JSON_THROW_ON_ERROR));
    app()->instance(AgentDelivery::class, new AgentDelivery($manifest, sys_get_temp_dir()));
    $this->mock(LeafCertificateSigner::class)->shouldReceive('rootCertificate')->andReturn('public-root');
    $this->mock(ManagedUserAccountResolver::class)->shouldNotReceive('resolve');
    $this->node = Node::query()->create(['ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'fixture-host-key', 'name' => 'snapshot-workload', 'user' => 'orbit', 'platform' => 'linux', 'architecture' => 'x86_64',
        'status' => 'active', 'public_ssh_host' => '192.0.2.31', 'wireguard_ip' => '10.44.0.31', 'tld' => 'old.test']);
    $this->node->roles()->create(['role' => 'app-dev', 'status' => 'active']);
    $this->gateway = Node::query()->create(['ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'fixture-host-key', 'name' => 'snapshot-gateway', 'user' => 'orbit', 'platform' => 'linux', 'status' => 'active',
        'public_ssh_host' => '192.0.2.14', 'wireguard_ip' => '10.44.0.14']);
    $this->gateway->roles()->create(['role' => 'gateway', 'status' => 'active']);
    $this->gateway->roles()->create(['role' => 'vpn', 'status' => 'active']);
    $project = Project::query()->create(['name' => 'Snapshot', 'slug' => 'snapshot', 'repository_url' => 'https://example.test/snapshot.git', 'root' => 'public']);
    $this->instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $this->node->id, 'name' => 'development',
        'checkout_path' => '/srv/snapshot', 'status' => 'active', 'provisioning_step' => 'active', 'source_is_laravel' => false]);
    $this->route = Route::query()->create(['project_id' => $project->id, 'node_id' => $this->node->id, 'generation_basis_node_id' => null,
        'domain' => 'development.snapshot.old.test', 'publication' => 'private', 'provenance' => 'explicit', 'status' => 'pending']);
    $this->route->targets()->create(['instance_id' => $this->instance->id, 'position' => 0]);
    $this->route->update(['status' => 'active', 'sites_published' => true]);
    $this->candidate = new NativeRouteNodeRender($this->node->id, '10.44.0.41', 'new.test');
    $this->capture = app(NativeRouteUpdateInput::class);
    $this->input = ['mode' => 'publication', 'domain' => $this->route->domain, 'publication' => 'private', 'web_root' => null];
});

afterEach(function (): void {
    unlink($this->manifest);
});

it('captures an unchanged update without host duties or private material', function (): void {
    $capture = $this->capture->capture($this->route->fresh(), $this->input);
    expect($capture['intent']['effectful'])->toBeFalse()
        ->and($capture['execution_secrets'])->toBe([])
        ->and($capture['certificates'])->toBe([])
        ->and($capture['participant_node_ids'])->toBe([209])
        ->and(Operation::query()->count())->toBe(0);
});

/** The whole Caddyfile a phase writes on one Node. */
function native_route_update_caddy(array $phase, int $nodeId): ?string
{
    return collect($phase['caddy'])->firstWhere('node_id', $nodeId)['content'] ?? null;
}

it('freezes a reserved rename without changing stored serving state', function (): void {
    $route = $this->route->fresh();
    $replacement = app(RouteMutationState::class)->reserve($route, 'new.snapshot.test');
    $capture = $this->capture->capture($route, [...$this->input, 'mode' => 'replace_domain', 'candidate_route_id' => $replacement->id]);
    $plan = $capture['intent']['plan'];
    expect($capture['intent']['effectful'])->toBeTrue()
        ->and($capture['intent']['result_route_id'])->toBe($replacement->id)
        ->and($route->fresh()->domain)->toBe('development.snapshot.old.test')
        ->and($replacement->fresh()->sites_published)->toBeFalse();
    // The publish step serves both names; the withdraw step leaves only the new one.
    expect(native_route_update_caddy($plan['publish'], $this->node->id))->toContain($route->domain, $replacement->domain)
        ->and(native_route_update_caddy($plan['retire'], $this->node->id))->toContain($replacement->domain)->not->toContain($route->domain)
        ->and(collect($plan['leaves'])->pluck('domain')->all())->toContain($replacement->domain)
        // The temporary leaf serves the new name before the cutover; the Instance's live leaf changes only after it.
        ->and(collect($plan['publish']['leaf_indexes'])->map(fn (int $i): string => $plan['leaves'][$i]['scope'])->all())->toBe(['app-instance-'.$this->instance->id.'-hostname-change'])
        ->and(collect($plan['retire']['leaf_indexes'])->map(fn (int $i): string => $plan['leaves'][$i]['scope'])->all())->toBe(['app-instance-'.$this->instance->id])
        ->and(collect($plan['probes'])->pluck('domain')->unique()->all())->toBe([$replacement->domain])
        ->and($plan['nodes'][(string) $this->node->id]['host_keys'])->toBe(['ssh-ed25519 fixture-host-key'])
        ->and($capture['participant_node_ids'])->toContain($this->node->id, $this->gateway->id, 209);
});

it('keeps pending replacements entirely in database intent', function (): void {
    $this->route->update(['status' => 'pending', 'sites_published' => false]);
    $route = $this->route->fresh();
    $replacement = app(RouteMutationState::class)->reserve($route, 'pending.snapshot.test');
    $capture = $this->capture->capture($route, [...$this->input, 'mode' => 'replace_pending', 'candidate_route_id' => $replacement->id]);
    expect($capture['intent']['effectful'])->toBeFalse()->and($capture['certificates'])->toBe([])
        ->and($route->fresh()->domain)->toBe('development.snapshot.old.test');
});

it('renders a prospective web root and its shared pools without early DB changes', function (): void {
    $plan = $this->capture->capture($this->route->fresh(), [...$this->input, 'mode' => 'web_root', 'web_root' => 'docs/public'])['intent']['plan'];
    expect(native_route_update_caddy($plan['retire'], $this->node->id))->toContain('/srv/snapshot/docs/public')
        ->and(native_route_update_caddy($plan['publish'], $this->node->id))->toContain('/srv/snapshot/docs/public')
        ->and($this->route->fresh()->web_root)->toBeNull();
});

it('keeps unrelated sites in each whole Caddyfile it writes', function (): void {
    $other = Route::query()->create(['kind' => 'custom_proxy', 'node_id' => $this->node->id,
        'domain' => 'unrelated.test', 'provenance' => 'explicit', 'publication' => 'private', 'status' => 'pending']);
    $other->customProxy()->create(['node_id' => $this->node->id, 'upstream' => 'http://127.0.0.1:8088']);
    $other->update(['status' => 'active', 'sites_published' => true]);
    $plan = $this->capture->capture($this->route->fresh(), [...$this->input, 'mode' => 'web_root', 'web_root' => 'docs/public'])['intent']['plan'];
    // A whole file replaces the Node's Caddyfile, so the other Route's site must stay in it.
    foreach (['publish', 'retire'] as $phase) {
        expect(native_route_update_caddy($plan[$phase], $this->node->id))->toContain('unrelated.test');
    }
});

function native_route_update_operation(array $capture, int $nodeId): Operation
{
    return Operation::query()->create(['kind' => 'route.update', 'target_type' => 'route', 'target_id' => $capture['intent']['route_id'],
        'node_id' => $nodeId, 'status' => 'running', 'sequence' => 1,
        'state' => ['swamp_run_id' => '8374180c-85cc-4df3-9fbf-e538d43404c5'],
        'input' => ['route_update' => $capture['intent'], 'execution_secrets' => $capture['execution_secrets']]]);
}

function native_route_update_finish(Operation $operation, bool $success, ?string $failedStep = null): Operation
{
    return app(NativeRouteUpdateProjection::class)->apply($operation, [
        'run_id' => $operation->state['swamp_run_id'], 'status' => $success ? 'succeeded' : 'failed',
        'started_at' => '2026-10-31T21:00:00Z', 'finished_at' => '2026-10-31T21:01:00Z',
        'error_code' => $success ? null : 'operation.native_failed', 'result' => [], 'failure' => $failedStep === null ? null : ['failed_step' => $failedStep]]);
}

it('commits the replacement only from the shared terminal result and accepts replay', function (): void {
    $route = $this->route->fresh();
    $candidate = app(RouteMutationState::class)->reserve($route, 'new.snapshot.test');
    $capture = $this->capture->capture($route, [...$this->input, 'mode' => 'replace_domain', 'candidate_route_id' => $candidate->id]);
    $operation = native_route_update_operation($capture, $this->node->id);
    $done = native_route_update_finish($operation, true);
    expect($done->status->value)->toBe('succeeded')->and($done->result)->toBe(['ready' => true, 'route_id' => $candidate->id])
        ->and($route->fresh())->toBeNull()
        ->and($candidate->fresh()->status->value)->toBe('active')->and($candidate->fresh()->replaces_route_id)->toBeNull();
    expect(native_route_update_finish($done, true)->result)->toBe(['ready' => true, 'route_id' => $candidate->id]);
});

it('keeps the old Route serving and marks the replacement failed when a step before the cutover fails', function (): void {
    $route = $this->route->fresh();
    $candidate = app(RouteMutationState::class)->reserve($route, 'new.snapshot.test');
    $capture = $this->capture->capture($route, [...$this->input, 'mode' => 'replace_domain', 'candidate_route_id' => $candidate->id]);
    $done = native_route_update_finish(native_route_update_operation($capture, $this->node->id), false, 'Probe the site over TLS-0');
    expect($done->status->value)->toBe('failed')->and($done->error_code)->toBe('route.probe_failed')
        ->and($route->fresh()->status->value)->toBe('active')->and($route->fresh()->replaced_by_route_id)->toBe($candidate->id)
        ->and($candidate->fresh()->status->value)->toBe('failed')->and($candidate->fresh()->failed_step)->toBe('Probe the site over TLS')
        ->and($candidate->fresh()->sites_published)->toBeFalse();
});

it('selects the new name when a step after the cutover fails, and keeps the old Route retiring', function (): void {
    $route = $this->route->fresh();
    $candidate = app(RouteMutationState::class)->reserve($route, 'new.snapshot.test');
    $capture = $this->capture->capture($route, [...$this->input, 'mode' => 'replace_domain', 'candidate_route_id' => $candidate->id]);
    $done = native_route_update_finish(native_route_update_operation($capture, $this->node->id), false, 'Withdraw the old site-0');
    expect($done->error_code)->toBe('route.withdraw_failed')
        ->and($candidate->fresh()->status->value)->toBe('active')->and($candidate->fresh()->sites_published)->toBeTrue()
        ->and($route->fresh()->status->value)->toBe('retiring')
        ->and($route->fresh()->failed_step)->toBe('Withdraw the old site');
});

it('projects a pending replacement without marking sites active', function (): void {
    $this->route->update(['status' => 'pending', 'sites_published' => false]);
    $route = $this->route->fresh();
    $candidate = app(RouteMutationState::class)->reserve($route, 'pending.snapshot.test');
    $capture = $this->capture->capture($route, [...$this->input, 'mode' => 'replace_pending', 'candidate_route_id' => $candidate->id]);
    native_route_update_finish(native_route_update_operation($capture, $this->node->id), true);
    expect($route->fresh())->toBeNull()->and($candidate->fresh()->status->value)->toBe('pending')
        ->and($candidate->fresh()->sites_published)->toBeFalse()->and($candidate->fresh()->replaces_route_id)->toBeNull();
});

it('grants Caddy the new development web root in the publish step, checkout deny first', function (): void {
    $plan = $this->capture->capture($this->route->fresh(), [...$this->input, 'mode' => 'web_root', 'web_root' => 'docs/public'])['intent']['plan'];
    $argv = array_column($plan['publish']['acl'], 'argv');
    expect($argv[0])->toContain('d:u:caddy:---')
        ->and(end($argv))->toContain('u:caddy:r-X')
        ->and(collect($argv)->flatten()->filter(fn ($part) => is_string($part) && str_ends_with($part, '/docs/public'))->isNotEmpty())->toBeTrue()
        ->and($plan['publish']['links'])->toBe([]);
});

it('captures dedicated production pools for union, selected and restoration without installing a runtime', function (): void {
    $this->node->roles()->delete();
    $this->node->roles()->create(['role' => 'app-prod', 'status' => 'active']);
    $user = 'orbit-app-'.$this->instance->project_id;
    $this->instance->update(['environment' => 'production', 'production_user' => $user, 'production_home' => '/home/'.$user,
        'checkout_path' => '/home/'.$user.'/current']);
    $instance = $this->instance->fresh();
    $identity = ProductionPhpRuntimeIdentity::forProvisioning($instance, '8.5');
    $instance->update(['selected_php_version' => '8.5', ...$identity->attributes()]);
    $extra = Route::query()->create(['project_id' => $instance->project_id, 'node_id' => $instance->node_id,
        'domain' => 'docs.snapshot.test', 'web_root' => 'docs/public', 'provenance' => 'explicit', 'publication' => 'private', 'status' => 'pending']);
    $extra->targets()->create(['instance_id' => $instance->id, 'position' => 0]);
    $extra->update(['status' => 'active', 'sites_published' => true]);
    $after = clone $extra;
    $after->web_root = 'admin/public';
    $capture = app(NativeRouteWorkloadPreparation::class)->capture($instance, $extra, $after);
    $oldSuffix = substr(hash('sha256', 'docs'), 0, 8);
    $newSuffix = substr(hash('sha256', 'admin'), 0, 8);
    expect($capture['prepare']['runtime']['pool'])->toContain('orbit-'.$user.'-'.$oldSuffix, 'orbit-'.$user.'-'.$newSuffix)
        ->and($capture['selected']['runtime']['pool'])->toContain('orbit-'.$user.'-'.$newSuffix)->not->toContain('orbit-'.$user.'-'.$oldSuffix)
        ->and($capture['restore']['runtime']['pool'])->toContain('orbit-'.$user.'-'.$oldSuffix)->not->toContain('orbit-'.$user.'-'.$newSuffix)
        ->and($extra->fresh()->web_root)->toBe('docs/public');
});

function native_route_update_public_fixture(object $test): Node
{
    $test->node->roles()->delete();
    $test->node->roles()->create(['role' => 'app-prod', 'status' => 'active']);
    $test->instance->update(['environment' => 'production', 'production_user' => 'orbit-app-'.$test->instance->project_id,
        'production_home' => '/home/orbit-app-'.$test->instance->project_id, 'checkout_path' => '/home/orbit-app-'.$test->instance->project_id.'/current']);
    $cluster = Cluster::query()->create(['name' => 'publication-cluster', 'state' => 'active', 'tld' => 'cluster.test']);
    $test->node->update(['cluster_id' => $cluster->id]);
    foreach (['router' => 51, 'ingress' => 52] as $role => $address) {
        $node = Node::query()->create(['name' => 'publication-'.$role, 'user' => 'orbit', 'platform' => 'linux', 'architecture' => 'x86_64', 'status' => 'active',
            'cluster_id' => $cluster->id, 'public_ssh_host' => '192.0.2.'.$address, 'wireguard_ip' => '10.44.0.'.$address,
            'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'fixture-host-key']);
        $node->roles()->create(['role' => $role, 'cluster_id' => $cluster->id, 'status' => 'active']);
    }
    $test->route->update(['node_id' => null, 'cluster_id' => $cluster->id]);

    return $node;
}

it('opens both public ingress ports for the first published public Route', function (): void {
    $ingress = native_route_update_public_fixture($this);
    $plan = $this->capture->capture($this->route->fresh(), [...$this->input, 'publication' => 'public'])['intent']['plan'];
    expect($plan['ingress_ports'])->toBe([['node_id' => $ingress->id, 'port' => 80, 'ensure' => 'present'], ['node_id' => $ingress->id, 'port' => 443, 'ensure' => 'present']])
        ->and($plan['edge_nodes'])->toBe([$ingress->id])
        ->and(collect($plan['probes'])->pluck('node_id')->all())->not->toContain($ingress->id)
        ->and($this->route->fresh()->publication->value)->toBe('private');
});

it('closes the last public ingress ports', function (): void {
    $ingress = native_route_update_public_fixture($this);
    $this->route->update(['publication' => 'public', 'replacement_step' => RouteReplacementStep::IngressFirewall]);
    $plan = $this->capture->capture($this->route->fresh(), $this->input)['intent']['plan'];
    expect($plan['ingress_ports'])->toBe([['node_id' => $ingress->id, 'port' => 80, 'ensure' => 'absent'], ['node_id' => $ingress->id, 'port' => 443, 'ensure' => 'absent']]);
});

it('keeps shared ingress ports when another published public Route survives', function (): void {
    native_route_update_public_fixture($this);
    $this->route->update(['publication' => 'public', 'replacement_step' => RouteReplacementStep::IngressFirewall]);
    $other = Route::query()->create(['project_id' => $this->instance->project_id, 'cluster_id' => $this->route->fresh()->cluster_id,
        'domain' => 'surviving-public.test', 'web_root' => 'docs/public', 'provenance' => 'explicit', 'publication' => 'public', 'status' => 'pending']);
    $other->targets()->create(['instance_id' => $this->instance->id, 'position' => 0]);
    $other->update(['status' => 'active', 'sites_published' => true, 'replacement_step' => RouteReplacementStep::IngressFirewall]);
    $plan = $this->capture->capture($this->route->fresh(), $this->input)['intent']['plan'];
    expect(collect($plan['ingress_ports'])->where('ensure', 'absent')->all())->toBe([]);
});

it('admits the complete immutable web-root update once through the HTTP boundary', function (): void {
    $this->route->update(['web_root' => 'old/public']);
    $socket = new FakeRoutePublicationSocket;
    app()->instance(WebSocketClient::class, $socket);
    config(['swamp.run_token' => 'synthetic-token']);
    $this->node->accessibleNodes()->attach($this->gateway);
    config(['dependency_detection.services_node_id' => $this->gateway->id]);
    $this->withServerVariables(['REMOTE_ADDR' => $this->node->wireguard_ip]);

    $this->patchJson('/api/v1/routes/'.$this->route->id, ['web_root' => 'docs/public'])->assertAccepted();

    $operation = Operation::query()->sole();
    expect($operation->kind)->toBe('route.update')
        ->and($operation->input['route_update']['plan']['publish']['acl'])->not->toBeEmpty()
        ->and($operation->input)->not->toHaveKeys(['private_bindings', 'environment_fields'])
        ->and($this->route->fresh()->web_root)->toBe('old/public')
        ->and($socket->sent)->toHaveCount(1)
        ->and($socket->sent[0]['payload']['workflowIdOrName'])->toBe('operation-route-update')
        ->and($socket->sent[0]['payload']['inputs']['intent'])->toBe($operation->input['route_update']['plan'])
        ->and($socket->sent[0]['payload']['inputs']['execution_secrets'])->toEqual((object) []);
});

it('keeps renamed URL environment values in execution copies and out of captured intent', function (): void {
    $this->instance->update(['source_is_laravel' => true]);
    $this->instance->environmentValues()->create(['env_key' => 'APP_KEY', 'env_value' => 'synthetic-update-private-key']);
    $this->instance->environmentValues()->create(['env_key' => 'APP_URL', 'env_value' => 'https://{{instance.domain}}']);
    config(['swamp.address' => '192.0.2.209', 'swamp.ca_path' => '/public/root.pem', 'swamp.run_token' => 'synthetic-token']);
    $socket = new FakeRoutePublicationSocket;
    $socket->reply = static fn (array $request): array => [['type' => 'vault.put', 'id' => $request['id'], 'payload' => ['data' => [
        'vaultName' => $request['payload']['vaultName'], 'secretKey' => $request['payload']['key'], 'vaultType' => 'local_encryption']]]];
    app()->instance(WebSocketClient::class, $socket);
    $route = $this->route->fresh();
    $candidate = app(RouteMutationState::class)->reserve($route, 'private-copy.snapshot.test');

    $capture = app(NativeRouteUpdateInput::class)->capture($route, [...$this->input, 'mode' => 'replace_domain', 'candidate_route_id' => $candidate->id]);

    // No rollback: only the new side is staged.
    expect($capture['execution_secrets'])->toHaveCount(1)
        ->and(json_encode($capture, JSON_THROW_ON_ERROR))->not->toContain('synthetic-update-private-key');
    $values = array_column(array_column($socket->sent, 'payload'), 'value');
    expect($values)->toHaveCount(1)
        ->and($values[0])->toContain('synthetic-update-private-key', 'private-copy.snapshot.test');
});

it('refuses public development targets before reserving an Operation or changing the Route', function (): void {
    native_route_update_public_fixture($this);
    $this->node->roles()->delete();
    $this->node->roles()->create(['role' => 'app-dev', 'status' => 'active']);
    $this->instance->update(['environment' => 'development']);
    $socket = new FakeRoutePublicationSocket;
    app()->instance(WebSocketClient::class, $socket);
    $this->withServerVariables(['REMOTE_ADDR' => $this->gateway->wireguard_ip]);

    $this->patchJson('/api/v1/routes/'.$this->route->id, ['publication' => 'public'])->assertStatus(409)
        ->assertJsonPath('error.code', 'route.reconciliation_required');

    expect(Operation::query()->count())->toBe(0)
        ->and($this->route->fresh()->publication->value)->toBe('private')
        ->and($socket->sent)->toBe([]);
});

it('admits public production publication with captured ingress duties and no early mutation', function (): void {
    $ingress = native_route_update_public_fixture($this);
    $socket = new FakeRoutePublicationSocket;
    app()->instance(WebSocketClient::class, $socket);
    config(['swamp.run_token' => 'synthetic-token']);
    $this->withServerVariables(['REMOTE_ADDR' => $this->gateway->wireguard_ip]);

    $this->patchJson('/api/v1/routes/'.$this->route->id, ['publication' => 'public'])->assertAccepted();

    $operation = Operation::query()->sole();
    expect($operation->input['route_update']['plan']['edge_nodes'])->toBe([$ingress->id])
        ->and($this->route->fresh()->publication->value)->toBe('private')
        ->and($socket->sent)->toHaveCount(1);
});

it('ends a pending Route domain change at once with no run, and settles the replacement (R1)', function (): void {
    $this->route->update(['status' => 'pending', 'sites_published' => false]);
    $socket = new FakeRoutePublicationSocket;
    app()->instance(WebSocketClient::class, $socket);
    $this->node->accessibleNodes()->attach($this->gateway);
    $this->withServerVariables(['REMOTE_ADDR' => $this->node->wireguard_ip]);

    $this->patchJson('/api/v1/routes/'.$this->route->id, ['domain' => 'renamed.snapshot.test'])->assertAccepted()
        ->assertJsonPath('data.status', 'succeeded');

    $replacement = Route::query()->where('domain', 'renamed.snapshot.test')->sole();
    expect($socket->sent)->toBe([])
        ->and($this->route->fresh())->toBeNull()
        ->and($replacement->status->value)->toBe('pending')->and($replacement->replaces_route_id)->toBeNull()
        ->and(Operation::query()->sole()->result)->toBe(['ready' => true, 'route_id' => $replacement->id]);
});
