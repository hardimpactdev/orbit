<?php

declare(strict_types=1);

use App\Data\Instances\InstanceData;
use App\Domain\Instances\InstanceState;
use App\Domain\Nodes\RoleName;
use App\Domain\Routes\RouteProvenance;
use App\Domain\Routes\RoutePublication;
use App\Domain\Routes\RouteStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Project;
use App\Models\Route;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Orbit\Sdk\Requests\Instances\ListInstancesRequest;
use Orbit\Sdk\Requests\Instances\ShowInstanceRequest;

beforeEach(function (): void {
    $this->node = Node::query()->create([
        'name' => 'app-dev',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'tld' => 'test',
        'public_ssh_host' => '192.0.2.10',
        'wireguard_ip' => '10.44.0.3',
        'user' => 'orbit',
        'settings' => ['apps' => ['path' => '/srv/orbit/apps']],
    ]);
    $this->node
        ->roles()
        ->create([
            'role' => RoleName::AppDev,
            'status' => LifecycleStatus::Active,
        ]);
    $operator = Node::query()->create([
        'name' => 'operator',
        'status' => LifecycleStatus::Active,
        'public_ssh_host' => '192.0.2.2',
        'wireguard_ip' => '10.44.0.2',
    ]);
    $this->markAsGateway($operator);
    $this->withServerVariables(['REMOTE_ADDR' => '10.44.0.2']);
    $this->orbitApp = Project::query()->create([
        'name' => 'Acme',
        'slug' => 'acme',
        'repository_url' => 'https://github.com/acme/site.git',
        'default_branch' => 'main',
        'root' => 'public',
    ]);
});

it('bounds Instance response relationship queries for one and several visible rows', function (): void {
    $secondVisibleNode = Node::query()->create([
        'name' => 'second-visible-node',
        'status' => LifecycleStatus::Active,
        'public_ssh_host' => '192.0.2.20',
        'wireguard_ip' => '10.44.0.20',
    ]);
    $inaccessibleNode = Node::query()->create([
        'name' => 'inaccessible-node',
        'status' => LifecycleStatus::Active,
        'public_ssh_host' => '192.0.2.21',
        'wireguard_ip' => '10.44.0.21',
    ]);
    $consumer = Node::query()->create([
        'name' => 'direct-consumer',
        'status' => LifecycleStatus::Active,
        'public_ssh_host' => '192.0.2.22',
        'wireguard_ip' => '10.44.0.22',
    ]);
    $consumer->accessibleNodes()->attach([$this->node->id, $secondVisibleNode->id]);

    $first = Instance::query()->create([
        'project_id' => $this->orbitApp->id,
        'node_id' => $this->node->id,
        'name' => 'first',
        'checkout_path' => '/srv/orbit/apps/acme/first',
    ]);
    $firstRoute = Route::query()->create([
        'project_id' => $this->orbitApp->id,
        'node_id' => $this->node->id,
        'generation_basis_node_id' => $this->node->id,
        'domain' => 'first.acme.test',
        'provenance' => RouteProvenance::Generated,
        'publication' => RoutePublication::Private,
    ]);
    $firstRoute->targets()->create(['instance_id' => $first->id, 'position' => 0]);
    $firstRoute->update(['status' => RouteStatus::Active]);
    $first->update(['status' => InstanceState::Active]);

    /** @var list<string> $relationshipQueries */
    $relationshipQueries = [];
    DB::listen(static function (QueryExecuted $query) use (&$relationshipQueries): void {
        $sql = str_replace(['"', '`'], '', mb_strtolower($query->sql));
        $relationship = match (true) {
            str_contains($sql, ' from projects ') => 'apps',
            str_contains($sql, ' from routes ') => 'routes',
            str_contains($sql, ' from route_targets ') => 'targets',
            default => null,
        };

        if ($relationship !== null) {
            $relationshipQueries[] = $relationship;
        }
    });

    $this
        ->withServerVariables(['REMOTE_ADDR' => $consumer->wireguard_ip])
        ->getJson('/api/v1/instances')
        ->assertOk()
        ->assertJsonPath('data.*.id', [$first->id])
        ->assertJsonPath('data.0.effective_root', 'public')
        ->assertJsonPath('data.0.route.target.instance_id', $first->id);
    $oneRowQueryCounts = array_count_values($relationshipQueries);

    $second = Instance::query()->create([
        'project_id' => $this->orbitApp->id,
        'node_id' => $secondVisibleNode->id,
        'name' => 'second',
        'checkout_path' => '/srv/orbit/apps/acme/second',
    ]);
    $secondRoute = Route::query()->create([
        'project_id' => $this->orbitApp->id,
        'node_id' => $secondVisibleNode->id,
        'generation_basis_node_id' => $secondVisibleNode->id,
        'domain' => 'second.acme.test',
        'provenance' => RouteProvenance::Generated,
        'publication' => RoutePublication::Private,
    ]);
    $secondRoute->targets()->create(['instance_id' => $second->id, 'position' => 0]);
    $secondRoute->update(['status' => RouteStatus::Active]);
    $second->update(['status' => InstanceState::Active]);
    $unroutedApp = Project::query()->create([
        'name' => 'Unrouted',
        'slug' => 'unrouted',
        'repository_url' => 'https://example.test/unrouted.git',
        'root' => 'web',
    ]);
    $unrouted = Instance::query()->create([
        'project_id' => $unroutedApp->id,
        'node_id' => $this->node->id,
        'name' => 'reserved',
        'checkout_path' => '/srv/orbit/apps/unrouted/reserved',
    ]);
    $inaccessibleApp = Project::query()->create([
        'name' => 'Inaccessible',
        'slug' => 'inaccessible',
        'repository_url' => 'https://example.test/inaccessible.git',
        'root' => 'public',
    ]);
    $inaccessible = Instance::query()->create([
        'project_id' => $inaccessibleApp->id,
        'node_id' => $inaccessibleNode->id,
        'name' => 'hidden',
        'checkout_path' => '/srv/orbit/apps/inaccessible/hidden',
    ]);
    $inaccessibleRoute = Route::query()->create([
        'project_id' => $inaccessibleApp->id,
        'node_id' => $inaccessibleNode->id,
        'generation_basis_node_id' => $inaccessibleNode->id,
        'domain' => 'hidden.inaccessible.test',
        'provenance' => RouteProvenance::Generated,
        'publication' => RoutePublication::Private,
    ]);
    $inaccessibleRoute->targets()->create(['instance_id' => $inaccessible->id, 'position' => 0]);
    $inaccessibleRoute->update(['status' => RouteStatus::Active]);
    $inaccessible->update(['status' => InstanceState::Active]);
    $relationshipQueries = [];

    $this
        ->withServerVariables(['REMOTE_ADDR' => $consumer->wireguard_ip])
        ->getJson('/api/v1/instances')
        ->assertOk()
        ->assertJsonPath('data.*.id', [$first->id, $unrouted->id, $second->id])
        ->assertJsonPath('data.*.project_id', [$this->orbitApp->id, $unroutedApp->id, $this->orbitApp->id])
        ->assertJsonPath('data.1.effective_root', 'web')
        ->assertJsonPath('data.1.route', null)
        ->assertJsonPath('data.0.route.target.instance_id', $first->id)
        ->assertJsonPath('data.2.route.target.instance_id', $second->id);

    expect($oneRowQueryCounts)
        ->toBe(['apps' => 1, 'routes' => 1, 'targets' => 1])
        ->and(array_count_values($relationshipQueries))
        ->toBe($oneRowQueryCounts);
});

it('loads missing response relations for a single Instance DTO caller', function (): void {
    $instance = Instance::query()->create([
        'project_id' => $this->orbitApp->id,
        'node_id' => $this->node->id,
        'name' => 'single',
        'checkout_path' => '/srv/orbit/apps/acme/single',
    ]);
    $route = Route::query()->create([
        'project_id' => $this->orbitApp->id,
        'node_id' => $this->node->id,
        'generation_basis_node_id' => $this->node->id,
        'domain' => 'single.acme.test',
        'provenance' => RouteProvenance::Generated,
        'publication' => RoutePublication::Private,
    ]);
    $route->targets()->create(['instance_id' => $instance->id, 'position' => 0]);
    $route->update(['status' => RouteStatus::Active]);
    $instance->update(['status' => InstanceState::Active]);
    $instance = Instance::query()->findOrFail($instance->id);
    $instance->preventsLazyLoading = true;

    expect($instance->relationLoaded('project'))
        ->toBeFalse()
        ->and($instance->relationLoaded('routes'))
        ->toBeFalse();

    $data = InstanceData::fromModel($instance);
    $loadedRoute = $instance->routes->first();

    expect($data->effectiveRoot)
        ->toBe('public')
        ->and($data->route?->target?->instanceId)
        ->toBe($instance->id)
        ->and($instance->relationLoaded('project'))
        ->toBeTrue()
        ->and($instance->relationLoaded('routes'))
        ->toBeTrue()
        ->and($loadedRoute)
        ->toBeInstanceOf(Route::class)
        ->and($loadedRoute?->relationLoaded('targets'))
        ->toBeTrue();
});

it('returns the list and show contracts from stored Instance state without executing lifecycle work', function (): void {
    $instance = Instance::query()->create(['project_id' => $this->orbitApp->id, 'node_id' => $this->node->id,
        'name' => 'dev', 'checkout_path' => '/srv/orbit/apps/acme/dev', 'status' => 'active',
        'branch' => 'dev', 'starting_commit' => str_repeat('a', 40), 'vite_port' => 5173, 'ssr_port' => 13714]);
    $route = Route::query()->create(['project_id' => $this->orbitApp->id, 'node_id' => $this->node->id,
        'generation_basis_node_id' => $this->node->id, 'domain' => 'dev.acme.test', 'provenance' => 'generated', 'publication' => 'private', 'status' => 'pending']);
    $route->targets()->create(['instance_id' => $instance->id, 'position' => 0]);
    $route->update(['status' => 'active']);
    record_fixture($this->getJson('/api/v1/instances')->assertOk()->assertJsonCount(1, 'data'), 'instances/instance-list/default', ListInstancesRequest::class, 'GET /api/v1/instances');
    record_fixture($this->getJson('/api/v1/instances/'.$instance->id)->assertOk()->assertJsonPath('data.name', 'dev'), 'instances/instance-show/default', ShowInstanceRequest::class, 'GET /api/v1/instances/{instance}');
});

it('updates the production deployment branch without changing deploy steps', function (): void {
    [$caller, , , $instance] = deployment_api_fixture();
    store_deploy_steps($instance, [
        ['name' => 'migrate', 'phase' => 'before_activation', 'command' => 'migrate', 'timeout_seconds' => 30],
    ]);

    $this
        ->withServerVariables(['REMOTE_ADDR' => $caller->wireguard_ip])
        ->patchJson("/api/v1/instances/{$instance->id}", ['branch' => 'release/next'])
        ->assertOk()
        ->assertJsonPath('data.deploy_steps.0.name', 'migrate');

    expect($instance->fresh()->deployment_branch)->toBe('release/next')
        ->and(normalized_deploy_steps($instance->fresh()))->toHaveCount(1);

    $instance->node->roles()->delete();
    $instance->node->roles()->create(['role' => RoleName::AppDev, 'status' => LifecycleStatus::Active]);

    $this
        ->withServerVariables(['REMOTE_ADDR' => $caller->wireguard_ip])
        ->patchJson("/api/v1/instances/{$instance->id}", ['branch' => 'other'])
        ->assertConflict()
        ->assertJsonPath('error.code', 'deployment_config.unavailable');
});
