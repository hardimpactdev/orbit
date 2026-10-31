<?php

declare(strict_types=1);

use App\Domain\Analytics\AnalyticsIntentProjection;
use App\Domain\Analytics\AnalyticsStatsKeyStore;
use App\Domain\Clusters\ClusterState;
use App\Domain\Nodes\RoleName;
use App\Domain\Routes\RoutePublication;
use App\Domain\Routes\RouteStatus;
use App\Domain\Settings\SettingRepository;
use App\Domain\Settings\SettingScope;
use App\Domain\Settings\SettingScopeType;
use App\Domain\Settings\SettingValueProtection;
use App\Infrastructure\Swamp\SwampCommandStep;
use App\Models\Cluster;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Project;
use App\Models\Route;
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
    $this->socket = analytics_intent_socket();
    config(['swamp.read_token' => 'analytics-public-read-token']);
    $this->url = "/api/v1/instances/{$this->instance->id}/analytics";
    $this->scope = new SettingScope(SettingScopeType::Gateway);
    foreach (['analytics.management_email' => 'native@example.test', 'analytics.management_password' => 'private-management-sentinel'] as $key => $value) {
        app(SettingRepository::class)->put($this->scope, $key, $value, SettingValueProtection::Secret);
    }
    app(AnalyticsStatsKeyStore::class)->put('private-stats-sentinel');
});

function analyticsEnableTerminal(object $test, string $status = 'succeeded'): Operation
{
    $operation = Operation::query()->sole();
    $run = ['id' => $operation->state['swamp_run_id'], 'workflowName' => 'operation-analytics-enable-direct', 'status' => $status,
        'inputs' => ['operation_id' => $operation->id, 'kind' => $operation->kind, 'node_id' => $operation->node_id],
        'startedAt' => now()->toIso8601String(), 'completedAt' => now()->toIso8601String(),
        'jobs' => $status === 'succeeded' ? [['name' => 'finish', 'steps' => [['name' => 'result', 'outputs' => ['stdout' => $operation->input['native_enable']['result_json']]]]]] : []];
    $test->socket->reply = static fn (array $request): array => [['id' => $request['id'], 'type' => 'workflow.history.get', 'payload' => ['data' => $run]]];

    return app(SwampCommandStep::class)->projectTerminal($operation, $run, ['job' => 'finish', 'step' => 'result']);
}

it('captures prospective router ingress and DNS publication before dispatch with names-only secrets', function (): void {
    $this->postJson($this->url)->assertAccepted();
    $operation = Operation::query()->sole();
    $intent = $operation->input['native_enable'];
    $router = array_find($intent['router'], fn (array $row): bool => $row['node_id'] === $this->router->id);
    $ingressBefore = array_find($intent['router'], fn (array $row): bool => $row['node_id'] === $this->ingress->id);
    $ingressAfter = array_find($intent['ingress'], fn (array $row): bool => $row['node_id'] === $this->ingress->id);
    expect($router['before']['content'])->not->toContain('analytics.shop.example.com');
    expect($router['after']['content'])->toContain('analytics.shop.example.com');
    expect($ingressBefore['after']['content'])->toBe($ingressBefore['before']['content']);
    expect($ingressAfter['after']['content'])->toContain('analytics.shop.example.com');
    expect($intent['dns'][0]['after']['records'])->toContain('analytics.shop.example.com');
    expect($intent['probes'][0])->toMatchArray(['control_node_id' => 209, 'probe' => ['address' => $this->router->lan_ip, 'port' => 443, 'domain' => 'analytics.shop.example.com']]);
    expect($intent['certificates'][0]['leaf'])->not->toHaveKeys(['route_id', 'in_use']);
    expect($this->socket->sent)->toHaveCount(2);
    $copy = $this->socket->sent[0];
    expect($copy['type'])->toBe('vault.put');
    expect(json_decode($copy['payload']['value'], true))->toMatchArray(['password' => 'private-management-sentinel', 'apiKey' => 'private-stats-sentinel']);
    expect($copy['payload']['key'])->toMatch('/^analytics-plausible-[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/');
    expect($this->socket->sent[1]['payload']['inputs']['intent'])->toBe($intent);
    expect(array_keys($operation->input['execution_secrets']['plausible_credentials']))->toBe(['vault', 'key']);
    expect(json_encode($operation->toArray()))->not->toContain('private-management-sentinel', 'private-stats-sentinel', '$secret');
    expect(Route::query()->where('kind', 'analytics_tracking')->sole()->sites_published)->toBeFalse();
    Http::assertNothingSent();
});

it('projects success once through authoritative history after every native effect', function (): void {
    $this->postJson($this->url)->assertAccepted();
    analyticsEnableTerminal($this);
    analyticsEnableTerminal($this);
    $route = Route::query()->where('kind', 'analytics_tracking')->sole();
    expect($route->status)->toBe(RouteStatus::Active);
    expect($route->sites_published)->toBeTrue();
    expect($route->replacement_step?->value)->toBe('public-activated');
    expect(Operation::query()->sole()->result)->toMatchArray(['ready' => true, 'readable' => true, 'enabled' => true]);
    expect(app(SettingRepository::class)->get($this->scope, 'analytics.site.'.$this->instance->id))->toContain('shop.example.com');
    expect(array_count_values(array_column($this->socket->sent, 'type'))['workflow.history.get'])->toBe(1);
});

it('retains unpublished desired hosts and old binding on native failure or cancellation', function (string $status): void {
    app(SettingRepository::class)->put($this->scope, 'analytics.site.'.$this->instance->id, '{"domain":"old.example.com","timezone":"UTC"}');
    $this->postJson($this->url)->assertAccepted();
    analyticsEnableTerminal($this, $status);
    expect(Route::query()->where('kind', 'analytics_tracking')->sole()->sites_published)->toBeFalse();
    expect(app(SettingRepository::class)->get($this->scope, 'analytics.site.'.$this->instance->id))->toContain('old.example.com');
    expect(Operation::query()->sole()->status->value)->toBe($status);
})->with(['failed', 'cancelled']);

it('keeps captured native input immutable when live Route records change', function (): void {
    $this->postJson($this->url)->assertAccepted();
    $operation = Operation::query()->sole();
    $before = app(AnalyticsIntentProjection::class)->input($operation);
    $this->appRoute->update(['publication' => RoutePublication::Private]);
    expect(app(AnalyticsIntentProjection::class)->input($operation->fresh()))->toBe($before);
});

it('rolls back desired admission if its execution copy is refused', function (): void {
    $this->socket->reply = static fn (array $request): array => [['type' => 'error', 'id' => $request['id'], 'error' => ['code' => 'unauthorized']]];
    $this->postJson($this->url)->assertStatus(503)->assertJsonPath('error.code', 'swamp.vault_sync_unavailable');
    expect(Operation::query()->count())->toBe(0);
    expect(Route::query()->where('kind', 'analytics_tracking')->exists())->toBeFalse();
    expect(array_column($this->socket->sent, 'type'))->toBe(['vault.put']);
});

it('refuses missing management or site-goal credentials before any native run', function (bool $goals): void {
    if (! $goals) {
        app(SettingRepository::class)->delete($this->scope, 'analytics.management_password');
    }
    $this->postJson($this->url, $goals ? ['goals' => [['goal_type' => 'Goal.CustomEvent', 'goal' => ['event_name' => 'Signup']]]] : [])
        ->assertConflict()->assertJsonPath('error.code', $goals ? 'analytics.goals_credentials_missing' : 'analytics.credentials_missing');
    expect(Operation::query()->count())->toBe(0);
    expect($this->socket->sent)->toBe([]);
})->with([false, true]);
