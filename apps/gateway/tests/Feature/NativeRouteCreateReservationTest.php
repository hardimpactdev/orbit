<?php

declare(strict_types=1);

use App\Actions\Routes\CreateRouteAction;
use App\Data\Routes\CreateRouteData;
use App\Domain\Instances\InstanceState;
use App\Domain\Instances\ProductionWebRootManager;
use App\Domain\Nodes\RoleName;
use App\Domain\Processes\ProcessRuntimeLease;
use App\Domain\Routes\RoutePublication;
use App\Domain\Routes\RouteStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Cluster;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Process;
use App\Models\Project;
use App\Models\Route;

/** @return array{Instance,Node} */
function native_create_instance(bool $production = false): array
{
    $cluster = $production ? Cluster::query()->create(['name' => 'creation-cluster', 'state' => 'active']) : null;
    $node = Node::query()->create(['name' => 'creation-workload', 'public_ssh_host' => '192.0.2.31', 'wireguard_ip' => '10.44.0.31', 'cluster_id' => $cluster?->id,
        'user' => 'orbit', 'platform' => 'linux', 'architecture' => 'x86_64', 'status' => 'active']);
    $node->roles()->create(['role' => $production ? RoleName::AppProd : RoleName::AppDev, 'status' => 'active']);
    if ($cluster !== null) {
        $router = Node::query()->create(['name' => 'creation-router', 'public_ssh_host' => '192.0.2.32', 'wireguard_ip' => '10.44.0.32', 'cluster_id' => $cluster->id,
            'user' => 'orbit', 'platform' => 'linux', 'architecture' => 'x86_64', 'status' => 'active']);
        $router->roles()->create(['role' => RoleName::Router, 'cluster_id' => $cluster->id, 'status' => 'active']);
    }
    $project = Project::query()->create(['name' => 'Create', 'slug' => 'create', 'repository_url' => 'https://example.test/create.git', 'root' => 'public']);
    $instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $node->id, 'name' => 'create', 'checkout_path' => '/srv/create',
        'environment' => $production ? 'production' : 'development', 'production_home' => $production ? '/srv/create' : null,
        'production_user' => $production ? 'orbit-app-'.$project->id : null, 'source_is_laravel' => false, 'status' => InstanceState::Active]);

    return [$instance, $node];
}

it('reserves an unpublished pending Route and retries only matching immutable creation input', function (): void {
    [$instance] = native_create_instance();
    $action = app(CreateRouteAction::class);
    $request = new CreateRouteData('create.private.test', RoutePublication::Private, instanceId: $instance->id);

    $result = $action->reserveForRouteCreate($request);
    $retry = $action->reserveForRouteCreate($request);

    expect($result['created'])->toBeTrue()
        ->and($result['route']->status)->toBe(RouteStatus::Pending)
        ->and($result['route']->sites_published)->toBeFalse()
        ->and($result['route']->targets()->pluck('instance_id')->all())->toBe([$instance->id])
        ->and($retry['created'])->toBeFalse()
        ->and($retry['route']->id)->toBe($result['route']->id)
        ->and(Route::query()->count())->toBe(1)
        ->and(Operation::query()->count())->toBe(0);
    expect(fn () => $action->reserveForRouteCreate(new CreateRouteData('create.private.test', RoutePublication::Public, instanceId: $instance->id)))
        ->toThrow(fn (ResourceOperationException $error) => expect($error->errorCode)->toBe('route.retry_conflict'));
});

it('keeps production web root reservation in the database without host checks or runtime leases', function (): void {
    [$instance] = native_create_instance(true);
    $this->mock(ProductionWebRootManager::class)->shouldNotReceive('assertServable');
    app()->bind(ProcessRuntimeLease::class, static fn () => throw new RuntimeException('Reservation cannot acquire an execution lease.'));

    $result = app(CreateRouteAction::class)->reserveForRouteCreate(new CreateRouteData('docs.private.test', RoutePublication::Private,
        instanceId: $instance->id, webRoot: 'docs/public'));

    expect($result['route']->web_root)->toBe('docs/public')
        ->and($result['route']->status)->toBe(RouteStatus::Pending)
        ->and($result['route']->sites_published)->toBeFalse()
        ->and($instance->fresh()->status)->toBe(InstanceState::Active)
        ->and(Operation::query()->count())->toBe(0);
});

it('refuses failed and retiring retries without changing the existing Route', function (RouteStatus $status): void {
    [$instance] = native_create_instance();
    $action = app(CreateRouteAction::class);
    $request = new CreateRouteData('refused.private.test', RoutePublication::Private, instanceId: $instance->id);
    $route = $action->reserveForRouteCreate($request)['route'];
    $route->update(['status' => $status, 'failed_step' => $status === RouteStatus::Failed ? 'native-convergence' : null, 'error_code' => $status === RouteStatus::Failed ? 'route.convergence_failed' : null]);

    expect(fn () => $action->reserveForRouteCreate($request))
        ->toThrow(fn (ResourceOperationException $error) => expect($error->errorCode)->toBe('route.activation_unsupported'));
    expect($route->fresh()->status)->toBe($status)
        ->and($route->targets()->pluck('instance_id')->all())->toBe([$instance->id]);
})->with([RouteStatus::Failed, RouteStatus::Retiring]);

it('reserves private proxy intent without publishing it or acquiring a Process runtime lease', function (bool $processBound): void {
    [, $node] = native_create_instance();
    app()->bind(ProcessRuntimeLease::class, static fn () => throw new RuntimeException('Reservation cannot acquire an execution lease.'));
    $process = $processBound ? Process::query()->create(['owner_type' => Node::class, 'owner_id' => $node->id, 'name' => 'creation-local-http',
        'runtime' => 'docker', 'runtime_config' => ['ports' => ['127.0.0.1:8787:8080/tcp']], 'restart_policy' => 'unless-stopped', 'desired_state' => 'running', 'status' => 'active']) : null;
    $request = new CreateRouteData('proxy.private.test', RoutePublication::Private, nodeId: $node->id,
        upstream: $processBound ? null : 'http://127.0.0.1:8787', processId: $process?->id);

    $result = app(CreateRouteAction::class)->reserveForRouteCreate($request);
    $retry = app(CreateRouteAction::class)->reserveForRouteCreate($request);

    expect($result['route']->customProxy->upstream)->toBe('http://127.0.0.1:8787')
        ->and($result['route']->status)->toBe(RouteStatus::Pending)
        ->and($result['route']->sites_published)->toBeFalse()
        ->and($retry['created'])->toBeFalse();
})->with(['literal upstream' => false, 'Process listener' => true]);

it('records a public Process listener and rejects drift without acquiring its runtime lease', function (): void {
    [, $node] = native_create_instance();
    $cluster = Cluster::query()->create(['name' => 'creation-public', 'state' => 'active']);
    $ingress = Node::query()->create(['name' => 'creation-ingress', 'public_ssh_host' => '192.0.2.33', 'wireguard_ip' => '10.44.0.33', 'cluster_id' => $cluster->id,
        'user' => 'orbit', 'platform' => 'linux', 'architecture' => 'x86_64', 'status' => 'active']);
    $ingress->roles()->create(['role' => RoleName::Ingress, 'cluster_id' => $cluster->id, 'status' => 'active']);
    $process = Process::query()->create(['owner_type' => Node::class, 'owner_id' => $node->id, 'name' => 'creation-http',
        'runtime' => 'docker', 'runtime_config' => ['ports' => [$node->wireguard_ip.':8787:8080/tcp']], 'restart_policy' => 'unless-stopped', 'desired_state' => 'running', 'status' => 'active']);
    app()->bind(ProcessRuntimeLease::class, static fn () => throw new RuntimeException('Reservation cannot acquire an execution lease.'));
    $request = new CreateRouteData('public.example.test', RoutePublication::Public, processId: $process->id, ingressNodeId: $ingress->id);

    $result = app(CreateRouteAction::class)->reserveForRouteCreate($request);

    expect($result['route']->cluster_id)->toBe($cluster->id)
        ->and($result['route']->customProxy->process_id)->toBe($process->id)
        ->and($result['route']->customProxy->upstream)->toBe('http://'.$node->wireguard_ip.':8787')
        ->and($result['route']->status)->toBe(RouteStatus::Pending)
        ->and($result['route']->sites_published)->toBeFalse();
    $process->update(['runtime_config' => ['ports' => [$node->wireguard_ip.':8788:8080/tcp']]]);
    expect(fn () => app(CreateRouteAction::class)->reserveForRouteCreate($request))
        ->toThrow(fn (ResourceOperationException $error) => expect($error->errorCode)->toBe('route.retry_conflict'));
    expect($result['route']->fresh()->customProxy->upstream)->toBe('http://'.$node->wireguard_ip.':8787');
});

it('keeps provisioning Route creation pure and refuses a different immutable retry domain', function (): void {
    [$instance] = native_create_instance();
    $instance->update(['status' => InstanceState::Reserved]);
    $action = app(CreateRouteAction::class);

    $route = $action->ensureForInstance($instance, 'provisioning.private.test');
    $retry = $action->ensureForInstance($instance, 'provisioning.private.test');

    expect($route->status)->toBe(RouteStatus::Pending)
        ->and($route->sites_published)->toBeFalse()
        ->and($retry->id)->toBe($route->id)
        ->and($instance->fresh()->status)->toBe(InstanceState::Reserved);
    expect(fn () => $action->ensureForInstance($instance, 'different.private.test'))
        ->toThrow(fn (ResourceOperationException $error) => expect($error->errorCode)->toBe('route.retry_conflict'));
});
