<?php

declare(strict_types=1);

use App\Actions\Operations\ExpireOperationAction;
use App\Domain\Clusters\ClusterState;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\OperationStatus;
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
        // The end check: the first Node's cfgmgmt check that the tracking hosts are gone.
        'jobs' => [['name' => 'analytics', 'steps' => [['name' => 'Check the hosts are gone-0', 'status' => $status === 'succeeded' ? 'succeeded' : 'skipped']]]]];
    $test->socket->reply = static fn (array $request): array => [['id' => $request['id'], 'type' => 'workflow.history.get', 'payload' => ['data' => $run]]];

    return app(SwampCommandStep::class)->projectTerminal($operation, $run, app(DirectOperation::class)->definition('analytics.disable')['terminal_result']);
}

it('captures withdrawal deltas and dispatches full public intent without vault or host calls', function (): void {
    $this->deleteJson($this->url)->assertAccepted();
    $operation = Operation::query()->sole();
    $intent = $operation->input['native_disable'];
    // The withdraw step writes the Router's whole Caddyfile without the tracking host, then removes its scope.
    $router = array_find($intent['retire']['caddy'], fn (array $row): bool => $row['node_id'] === $this->router->id);
    expect($router['content'])->not->toContain('analytics.shop.example.com');
    expect($router['content'])->toContain('shop.example.com');
    expect($intent['dns'])->toHaveCount(1);
    expect($intent['dns'][0]['records'])->not->toContain('analytics.shop.example.com');
    expect($intent['cleanup'])->toHaveCount(1);
    expect($intent['cleanup'][0]['leaf'])->toBe(['user' => $this->router->user, 'scope' => 'route-'.$this->tracking->id.'-router']);
    expect($intent['check_nodes'])->toContain($this->router->id);
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

it('projects a run that met its budget although the slow start reply arrived after the admission deadline', function (): void {
    $this->deleteJson($this->url)->assertAccepted();
    $operation = Operation::query()->sole();
    $runId = $operation->state['swamp_run_id'];
    // Serve replied with the run id only after the 600-second admission deadline (ops 7148, 7409).
    $operation->update(['state' => array_diff_key($operation->state, ['swamp_run_id' => true])]);
    $this->travel(600)->seconds();
    $expired = app(ExpireOperationAction::class)->execute($operation->fresh());
    expect($expired->error_code)->toBe('operation.timed_out');
    expect($this->tracking->fresh())->not->toBeNull();
    $expired->update(['state' => [...$expired->state, 'swamp_run_id' => $runId]]);
    $this->travel(60)->seconds();

    analyticsDisableTerminal($this);

    $projected = Operation::query()->sole();
    expect($projected->status)->toBe(OperationStatus::Succeeded);
    expect($projected->error_code)->toBeNull();
    expect($projected->state)->not->toHaveKeys(['native_timeout', 'native_timeout_restore']);
    expect($projected->cancel_requested_at)->toBeNull();
    expect($this->tracking->fresh())->toBeNull();
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
    // Nothing to withdraw: the Operation has already succeeded and no run starts (R1).
    $this->deleteJson($this->url)->assertAccepted();
    expect(Operation::query()->sole()->status->value)->toBe('succeeded')
        ->and($this->socket->sent)->toBe([])
        ->and(app(SettingRepository::class)->get($this->scope, 'analytics.site.'.$this->instance->id))->toBeNull();
});

it('rolls back desired withdrawal when an admitted SSH target is incomplete', function (): void {
    $this->router->update(['ssh_host_key' => null]);
    $this->deleteJson($this->url)->assertStatus(503)->assertJsonPath('error.code', 'swamp.ssh_target_unavailable');
    expect($this->tracking->fresh()->status)->toBe(RouteStatus::Active);
    expect($this->tracking->fresh()->sites_published)->toBeTrue();
    expect(Operation::query()->count())->toBe(0);
    expect($this->socket->sent)->toBe([]);
});
