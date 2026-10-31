<?php

declare(strict_types=1);

use App\Domain\Clusters\ClusterState;
use App\Domain\Nodes\RoleName;
use App\Domain\Routes\RoutePublication;
use App\Domain\Routes\RouteStatus;
use App\Domain\Settings\SettingRepository;
use App\Domain\Settings\SettingScope;
use App\Domain\Settings\SettingScopeType;
use App\Infrastructure\Swamp\SwampCommandStep;
use App\Models\Cluster;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Project;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Http::preventStrayRequests();
    $this->gateway = $this->markAsGateway(Node::query()->create(['name' => 'gateway', 'status' => 'active', 'wireguard_ip' => '10.44.0.2', 'public_ssh_host' => '192.0.2.2']));
    $this->withServerVariables(['REMOTE_ADDR' => $this->gateway->wireguard_ip]);
    $this->cluster = Cluster::query()->create(['name' => 'edge', 'tld' => 'edge.test', 'state' => ClusterState::Active]);
    $this->router = instance_analytics_node('router', '10.44.0.20', $this->cluster, RoleName::Router);
    $this->ingress = instance_analytics_node('ingress', '10.44.0.30', $this->cluster, RoleName::Ingress);
    $this->vpn = instance_analytics_node('vpn', '10.44.0.1', null, RoleName::Vpn);
    $this->workload = instance_analytics_node('prod', '10.44.0.31', $this->cluster, RoleName::AppProd);
    $this->analytics = instance_analytics_node('analytics', '10.44.0.40', null, RoleName::Analytics);
    foreach ([$this->router, $this->ingress, $this->vpn] as $node) {
        $node->update(['ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'AAAAC3NzaC1lZDI1NTE5AAAAINqVuK+N4gL/sDvGNKnGrYhEtEFnZx9hvH+134PsQgND']);
    }
    $project = Project::query()->create(['name' => 'Shop', 'slug' => 'shop', 'repository_url' => 'https://example.test/shop.git', 'default_branch' => 'main', 'root' => 'public']);
    $this->instance = instance_analytics_instance($project, $this->workload, 'production');
    $this->appRoute = instance_analytics_app_route($this->instance, 'shop.example.com', RoutePublication::Public);
    $this->tracking = instance_analytics_tracking($this->instance);
    $this->socket = analytics_intent_socket();
    config(['swamp.read_token' => 'analytics-public-read-token']);
    $this->url = "/api/v1/instances/{$this->instance->id}/analytics";
    $this->scope = new SettingScope(SettingScopeType::Gateway);
    app(SettingRepository::class)->put($this->scope, 'analytics.site.'.$this->instance->id, '{"domain":"shop.example.com","timezone":"UTC"}');
});

function analyticsDisableTerminal(object $test, string $status = 'succeeded'): Operation
{
    $operation = Operation::query()->sole();
    $run = ['id' => $operation->state['swamp_run_id'], 'workflowName' => 'operation-analytics-disable-direct', 'status' => $status,
        'inputs' => ['operation_id' => $operation->id, 'kind' => $operation->kind, 'node_id' => $operation->node_id],
        'startedAt' => now()->toIso8601String(), 'completedAt' => now()->toIso8601String(),
        'jobs' => $status === 'succeeded' ? [['name' => 'finish', 'steps' => [['name' => 'result', 'outputs' => ['stdout' => $operation->input['native_disable']['result_json']]]]]] : []];
    $test->socket->reply = static fn (array $request): array => [['id' => $request['id'], 'type' => 'workflow.history.get', 'payload' => ['data' => $run]]];

    return app(SwampCommandStep::class)->projectTerminal($operation, $run, ['job' => 'finish', 'step' => 'result']);
}

it('captures withdrawal deltas and dispatches full public intent without vault or host calls', function (): void {
    $this->deleteJson($this->url)->assertAccepted();
    $operation = Operation::query()->sole();
    $intent = $operation->input['native_disable'];
    $router = array_find($intent['caddy'], fn (array $row): bool => $row['node_id'] === $this->router->id);
    expect($router['before']['content'])->toContain('analytics.shop.example.com');
    expect($router['after']['content'])->not->toContain('analytics.shop.example.com');
    expect($router['after']['content'])->toContain('shop.example.com');
    expect($intent['dns'])->toHaveCount(1);
    expect($intent['dns'][0]['before']['records'])->toContain('analytics.shop.example.com');
    expect($intent['dns'][0]['after']['records'])->not->toContain('analytics.shop.example.com');
    expect($intent['cleanup'])->toHaveCount(1);
    expect($intent['cleanup'][0]['leaf']['scope'])->toBe('route-'.$this->tracking->id.'-router');
    expect(array_keys($intent['cleanup'][0]['leaf']))->toBe(['node_id', 'user', 'scope', 'agent_sha256', 'in_use']);
    expect($intent['cleanup'][0]['control_node_id'])->toBe(209);
    expect($this->socket->sent)->toHaveCount(1);
    expect($this->socket->sent[0]['payload']['inputs']['intent'])->toBe($intent);
    expect($this->socket->sent[0]['payload']['inputs']['execution_secrets'])->toEqual((object) []);
    expect($this->tracking->fresh()->status)->toBe(RouteStatus::Retiring);
    Http::assertNothingSent();
});

it('projects successful detachment once and preserves a later tracking host', function (): void {
    $this->deleteJson($this->url)->assertAccepted();
    $later = instance_analytics_tracking($this->instance, 'later.shop.example.com');
    analyticsDisableTerminal($this);
    analyticsDisableTerminal($this);
    expect($this->tracking->fresh())->toBeNull();
    expect($later->fresh()->sites_published)->toBeTrue();
    expect($this->appRoute->fresh())->not->toBeNull();
    expect(app(SettingRepository::class)->get($this->scope, 'analytics.site.'.$this->instance->id))->toBeNull();
    expect(Operation::query()->sole()->result)->toBe(['ready' => true, 'enabled' => false, 'detached' => true, 'domain' => 'shop.example.com', 'timezone' => 'UTC']);
    expect(array_count_values(array_column($this->socket->sent, 'type'))['workflow.history.get'])->toBe(1);
});

it('retains tracking records and the provider binding on failure or cancellation', function (string $status): void {
    $this->deleteJson($this->url)->assertAccepted();
    analyticsDisableTerminal($this, $status);
    expect($this->tracking->fresh())->not->toBeNull();
    expect(app(SettingRepository::class)->get($this->scope, 'analytics.site.'.$this->instance->id))->not->toBeNull();
    expect(Operation::query()->sole()->status->value)->toBe($status);
})->with(['failed', 'cancelled']);

it('detaches an empty tracking set without needing SSH targets or an analytics role', function (): void {
    $this->tracking->delete();
    $this->analytics->roles()->delete();
    foreach ([$this->router, $this->ingress, $this->vpn] as $node) {
        $node->update(['ssh_host_key_type' => null, 'ssh_host_key' => null]);
    }
    $this->deleteJson($this->url)->assertAccepted();
    expect(Operation::query()->sole()->input['native_disable'])->toMatchArray(['caddy' => [], 'dns' => [], 'cleanup' => []]);
    analyticsDisableTerminal($this);
    expect(Operation::query()->sole()->status->value)->toBe('succeeded');
});

it('rolls back desired withdrawal when an admitted SSH target is incomplete', function (): void {
    $this->router->update(['ssh_host_key' => null]);
    $this->deleteJson($this->url)->assertStatus(503)->assertJsonPath('error.code', 'swamp.ssh_target_unavailable');
    expect($this->tracking->fresh()->status)->toBe(RouteStatus::Active);
    expect($this->tracking->fresh()->sites_published)->toBeTrue();
    expect(Operation::query()->count())->toBe(0);
    expect($this->socket->sent)->toBe([]);
});
