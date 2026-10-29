<?php

declare(strict_types=1);

use App\Actions\Routes\AdmitRouteOperationAction;
use App\Data\Routes\UpdateRouteData;
use App\Domain\Nodes\RoleName;
use App\Domain\Routes\RouteMetricsRelevance;
use App\Domain\Routes\RouteParentExecution;
use App\Domain\Routes\RoutePublication;
use App\Models\Cluster;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Project;
use App\Models\Route;
use Tests\Support\RouteSwampGatewayFixture;

beforeEach(function (): void {
    bind_task_node_reachability();
    $this->node = Node::query()->create(['name' => 'route-metrics', 'wireguard_ip' => '10.44.0.5', 'public_ssh_host' => '192.0.2.5', 'platform' => 'linux', 'status' => 'active']);
    $this->cluster = Cluster::query()->create(['name' => 'Route Metrics', 'tld' => 'private.test', 'state' => 'active']);
    $this->node->update(['cluster_id' => $this->cluster->id]);
    $this->node->roles()->create(['role' => RoleName::Router, 'cluster_id' => $this->cluster->id, 'status' => 'active']);
    $this->ingress = Node::query()->create(['cluster_id' => $this->cluster->id, 'name' => 'route-metrics-ingress', 'public_ssh_host' => '192.0.2.6', 'wireguard_ip' => '10.44.0.6', 'platform' => 'linux', 'status' => 'active']);
    $this->ingress->roles()->create(['role' => RoleName::Ingress, 'cluster_id' => $this->cluster->id, 'status' => 'active']);
    $this->project = Project::query()->create(['name' => 'Route Metrics', 'slug' => 'route-metrics', 'repository_url' => 'https://example.test/metrics.git']);
    $this->instance = Instance::query()->create(['project_id' => $this->project->id, 'node_id' => $this->node->id, 'name' => 'metrics-proof', 'checkout_path' => '/srv/metrics-proof', 'status' => 'reserved']);
    $this->route = Route::query()->create(['project_id' => $this->project->id, 'cluster_id' => $this->cluster->id, 'domain' => 'example.private.test', 'provenance' => 'explicit', 'publication' => 'public', 'status' => 'pending']);
    $this->route->targets()->create(['instance_id' => $this->instance->id, 'position' => 0]);
    $this->route->update(['status' => 'active']);
});

it('skips unchanged public hosts for publication target and web-root changes', function (string $mode): void {
    expect(app(RouteMetricsRelevance::class)->changes($this->route, $mode, ['publication' => 'public']))->toBeFalse();
})->with(['publication', 'web_root', 'target_binding', 'state_only', 'targets', 'targets_recover']);

it('skips private host changes even when their Route becomes active or is withdrawn', function (string $mode): void {
    $this->route->update(['publication' => 'private']);
    expect(app(RouteMetricsRelevance::class)->changes($this->route, $mode, ['publication' => 'private']))->toBeFalse();
})->with(['publication', 'converge', 'withdraw']);

it('reconciles a public host addition removal or activation', function (string $mode, string $publication, string $status, array $plan): void {
    $this->route->update(['publication' => $publication, 'status' => $status]);
    expect(app(RouteMetricsRelevance::class)->changes($this->route, $mode, $plan))->toBeTrue();
})->with([
    ['publication', 'private', 'active', ['publication' => 'public', 'activate_public' => true]],
    ['publication', 'public', 'active', ['publication' => 'private']],
    ['publication', 'public', 'activating', ['publication' => 'public', 'activate_public' => true]],
    ['withdraw', 'public', 'active', []],
    ['converge', 'public', 'activating', []],
]);

it('skips a public intention that never enters the active Ingress host catalog', function (): void {
    $this->route->update(['status' => 'pending']);
    expect(app(RouteMetricsRelevance::class)->changes($this->route, 'publication', ['publication' => 'public']))->toBeFalse();
    $this->route->update(['status' => 'active', 'cluster_id' => null, 'node_id' => $this->node->id]);
    expect(app(RouteMetricsRelevance::class)->changes($this->route, 'withdraw', []))->toBeFalse();
});

it('compares replacement domain and Ingress placement while preserving unchanged hosts', function (bool $rename, bool $move, bool $expected): void {
    $cluster = $this->cluster;
    if ($move) {
        $cluster = Cluster::query()->create(['name' => 'Moved Metrics', 'state' => 'active']);
        $other = Node::query()->create(['cluster_id' => $cluster->id, 'name' => 'other-ingress', 'public_ssh_host' => '192.0.2.7', 'platform' => 'linux', 'status' => 'active']);
        $other->roles()->create(['role' => RoleName::Ingress, 'cluster_id' => $cluster->id, 'status' => 'active']);
    }
    $candidate = ! $rename && ! $move ? $this->route : Route::query()->create(['project_id' => $this->project->id, 'cluster_id' => $cluster->id, 'domain' => $rename ? 'changed.private.test' : $this->route->domain, 'publication' => 'public', 'provenance' => 'explicit', 'status' => 'pending']);
    expect(app(RouteMetricsRelevance::class)->changes($this->route, 'replacement', ['candidate_route_id' => $candidate->id]))->toBe($expected);
})->with([[true, false, true], [true, true, true], [false, false, false]]);

it('preserves the source host when withdrawing an unactivated replacement', function (): void {
    $candidate = Route::query()->create(['project_id' => $this->project->id, 'cluster_id' => $this->cluster->id, 'domain' => 'candidate.private.test', 'publication' => 'public', 'provenance' => 'explicit', 'status' => 'pending']);
    expect(app(RouteMetricsRelevance::class)->changes($candidate, 'withdraw_replacement', ['candidate_route_id' => $candidate->id]))->toBeFalse();
});

it('finishes the live private no-op update shape without admitting Metrics or any Node effect', function (): void {
    $this->node->roles()->create(['role' => RoleName::Metrics, 'status' => 'active']);
    $this->route->update(['publication' => 'private']);
    $gateway = Node::query()->create(['name' => 'metrics-fixture-gateway', 'public_ssh_host' => '192.0.2.1', 'wireguard_ip' => '10.254.254.1', 'platform' => 'linux', 'status' => 'active']);
    $gateway->roles()->create(['role' => RoleName::Gateway, 'status' => 'active']);
    $fixture = new RouteSwampGatewayFixture($this);
    $operation = app(AdmitRouteOperationAction::class)->update($this->route, new UpdateRouteData(false, null, true, RoutePublication::Private));

    expect($operation->state['route_plan']['metrics'])->toBeFalse()
        ->and(app(RouteParentExecution::class)->sequence($operation))->not->toContain('metrics-reconciled');
    $fixture->run($operation);
    $finished = $operation->refresh();
    expect($finished->status->value)->toBe('succeeded')
        ->and($finished->children()->exists())->toBeFalse()
        ->and($this->route->fresh()->status->value)->toBe('active')
        ->and($this->route->fresh()->publication)->toBe(RoutePublication::Private);
});

it('records the owned Metrics child only for a publication host change', function (): void {
    $this->node->roles()->create(['role' => RoleName::Metrics, 'status' => 'active']);
    $operation = app(AdmitRouteOperationAction::class)->update($this->route, new UpdateRouteData(false, null, true, RoutePublication::Private));

    expect($operation->state['route_plan']['metrics'])->toBeTrue()
        ->and($operation->state['route_plan']['stages']['metrics-reconciled'])->toBe([
            ['key' => 'metrics-reconciled.fleet', 'kind' => 'metrics.reconcile', 'node_id' => $this->node->id, 'input' => []],
        ])
        ->and($operation->children()->exists())->toBeFalse();
});

it('skips Metrics on private Route destruction with an active Metrics assignment', function (): void {
    $this->node->roles()->create(['role' => RoleName::Metrics, 'status' => 'active']);
    $this->route->update(['publication' => 'private', 'web_root' => 'public']);
    $operation = app(AdmitRouteOperationAction::class)->destroy($this->route);

    expect($operation->state['route_plan']['metrics'])->toBeFalse()
        ->and($operation->state['route_plan']['stages'])->not->toHaveKey('metrics-reconciled')
        ->and($operation->children()->exists())->toBeFalse();
});
