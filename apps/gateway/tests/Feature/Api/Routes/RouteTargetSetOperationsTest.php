<?php

declare(strict_types=1);

use App\Actions\Routes\AdmitRouteOperationAction;
use App\Data\Routes\RouteTargetDispositionData;
use App\Data\Routes\SetRouteTargetsData;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Cluster;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Project;
use App\Models\Route;
use Tests\Support\RouteSwampGatewayFixture;

beforeEach(function (): void {
    $gateway = $this->markAsGateway(Node::query()->create(['name' => 'target-gateway', 'wireguard_ip' => '10.44.0.1', 'status' => 'active', 'public_ssh_host' => '192.0.2.1']));
    $this->withServerVariables(['REMOTE_ADDR' => $gateway->wireguard_ip]);
    $cluster = Cluster::query()->create(['name' => 'target-pool', 'state' => 'active']);
    $router = Node::query()->create(['architecture' => 'x86_64', 'name' => 'target-router', 'cluster_id' => $cluster->id, 'wireguard_ip' => '10.44.0.2', 'status' => 'active', 'public_ssh_host' => '192.0.2.2']);
    $router->roles()->create(['role' => RoleName::Router, 'cluster_id' => $cluster->id, 'status' => 'active']);
    $project = Project::query()->create(['name' => 'Pool', 'slug' => 'pool', 'repository_url' => 'https://example.test/pool.git', 'root' => 'public']);
    $instances = [];
    foreach ([3, 4] as $i) {
        $node = Node::query()->create(['name' => 'target-node-'.$i, 'cluster_id' => $cluster->id, 'wireguard_ip' => '10.44.0.'.$i, 'status' => 'active', 'public_ssh_host' => '192.0.2.'.$i, 'platform' => 'linux']);
        $node->roles()->create(['role' => RoleName::AppProd, 'status' => 'active']);
        $instances[] = Instance::query()->create(['project_id' => $project->id, 'node_id' => $node->id, 'name' => 'prod-'.$i, 'environment' => 'production', 'branch' => 'main', 'checkout_path' => '/srv/pool/'.$i, 'production_home' => '/srv/pool/'.$i, 'production_user' => 'orbit-pool', 'source_is_laravel' => false, 'status' => 'active']);
    }
    [$this->first, $this->second] = $instances;
    $this->route = Route::query()->create(['project_id' => $project->id, 'cluster_id' => $cluster->id, 'domain' => 'pool.private.test', 'provenance' => 'explicit', 'publication' => 'private', 'status' => 'pending']);
    $this->sibling = Route::query()->create(['project_id' => $project->id, 'cluster_id' => $cluster->id, 'domain' => 'sibling.private.test', 'provenance' => 'explicit', 'publication' => 'private', 'status' => 'pending']);
    $this->route->targets()->create(['instance_id' => $this->first->id, 'position' => 0]);
    $this->sibling->targets()->create(['instance_id' => $this->second->id, 'position' => 0]);
    $this->route->update(['status' => 'active', 'sites_published' => true]);
    $this->sibling->update(['status' => 'active', 'sites_published' => true]);
    $this->swamp = new RouteSwampGatewayFixture($this);
    $this->proposal = new SetRouteTargetsData([$this->first->id, $this->second->id], []);
    $this->admit = fn (): Operation => app(AdmitRouteOperationAction::class)->setTargets($this->route->refresh(), $this->proposal);
});

it('prepares workloads before publishing a production target set and does not report a partial set', function (): void {
    $observed = false;
    $this->swamp->foreign->observe = function (Operation $child) use (&$observed): void {
        if ($child->kind === 'route.workload_verify') {
            $observed = true;
            expect($this->route->targets()->pluck('instance_id')->all())->toBe([$this->first->id]);
        }
    };
    $op = ($this->admit)();
    $this->swamp->run($op);
    expect($observed)->toBeTrue()->and($op->refresh()->status->value)->toBe('succeeded')
        ->and($this->route->targets()->orderBy('position')->pluck('instance_id')->all())->toBe([$this->first->id, $this->second->id])
        ->and($this->route->refresh()->target_set_intent)->toBeNull()
        ->and($op->state['route_completed_stages'])->toMatchArray(['target-workload-verified' => true, 'environment-synchronized' => true, 'router-published' => true]);
});

it('restores preparations when a target-set change fails before database commit', function (): void {
    $this->swamp->foreign->failKind = 'route.workload_verify';
    $op = ($this->admit)();
    $this->swamp->run($op);
    expect($op->refresh()->status->value)->toBe('failed')
        ->and($this->route->targets()->pluck('instance_id')->all())->toBe([$this->first->id])
        ->and($this->sibling->targets()->pluck('instance_id')->all())->toBe([$this->second->id])
        ->and($op->state['route_completed_stages'])->toMatchArray(['rollback-preparations-caddy' => true, 'rollback-preparations-certificates' => true, 'rollback-preparations-record' => true]);
});

it('retains committed target-set progress and resumes the recorded intent', function (): void {
    $op = ($this->admit)();
    $key = $op->state['route_plan']['stages']['router-published'][0]['key'];
    $this->swamp->foreign->failures[$key] = 1;
    $this->swamp->run($op);
    expect($op->refresh()->status->value)->toBe('failed')
        ->and($this->route->targets()->orderBy('position')->pluck('instance_id')->all())->toBe([$this->first->id, $this->second->id]);
    $count = count($this->swamp->foreign->calls);
    $retry = ($this->admit)();
    $this->swamp->run($retry);
    expect($retry->refresh()->status->value)->toBe('succeeded')->and($this->route->refresh()->target_set_intent)->toBeNull()
        ->and(array_column(array_slice($this->swamp->foreign->calls, $count), 'kind'))->not->toContain('instance.prepare_php_runtime');
});

it('refuses a competing target-set intent and treats an identical completed change as a no-op', function (): void {
    $op = ($this->admit)();
    expect(fn () => app(AdmitRouteOperationAction::class)->setTargets($this->route->refresh(), new SetRouteTargetsData([$this->first->id], [])))
        ->toThrow(ResourceOperationException::class);
    $this->swamp->run($op);
    $count = count($this->swamp->foreign->calls);
    $noop = ($this->admit)();
    $this->swamp->run($noop);
    expect($noop->refresh()->status->value)->toBe('succeeded')->and(count($this->swamp->foreign->calls))->toBe($count);
});

it('invokes authorized App instance removal after the replacement pool is recorded', function (): void {
    $this->proposal = new SetRouteTargetsData([$this->second->id], [new RouteTargetDispositionData($this->first->id, remove: true)]);
    $observed = false;
    $this->swamp->foreign->observe = function (Operation $child) use (&$observed): void {
        if ($child->kind === 'instance.destroy') {
            $observed = true;
            expect($child->input)->toBe(['instance_id' => $this->first->id, 'force' => true])
                ->and($child->node_id)->toBe($this->first->node_id)
                ->and($this->route->targets()->orderBy('position')->pluck('instance_id')->all())->toBe([$this->second->id, $this->first->id]);
            $parent = $child->parent;
            expect($parent->state['route_completed_stages']['router-published'])->toBeTrue()
                ->and($parent->state['route_plan']['removed_instance_ids'])->toBe([$this->first->id])
                ->and($parent->state['route_plan']['detached_instances'][0])->toMatchArray(['instance_id' => $this->first->id, 'node_id' => $this->first->node_id, 'checkout_path' => $this->first->checkout_path]);
        }
    };
    $op = ($this->admit)();
    $this->swamp->run($op);
    expect($observed)->toBeTrue()->and($op->refresh()->status->value)->toBe('succeeded');
});
