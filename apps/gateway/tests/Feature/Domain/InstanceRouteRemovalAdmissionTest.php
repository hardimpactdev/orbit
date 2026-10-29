<?php

declare(strict_types=1);

use App\Domain\Instances\InstanceRouteRemovalAdmission;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\OperationChildren;
use App\Domain\Operations\OperationKinds;
use App\Domain\Routes\CaddyOperationCompletion;
use App\Domain\Routes\RouteParentExecution;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;
use App\Models\Node;
use App\Models\NodeRole;
use App\Models\Operation;
use App\Models\Project;
use App\Models\Route;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    $node = Node::query()->create(['name' => 'dev', 'status' => 'active', 'platform' => 'linux', 'user' => 'orbit',
        'public_ssh_host' => '192.0.2.9', 'wireguard_ip' => '10.44.0.9']);
    NodeRole::query()->create(['node_id' => $node->id, 'role' => RoleName::AppDev, 'status' => 'active']);
    $project = Project::query()->create(['name' => 'Removal proof', 'slug' => 'removal-proof',
        'repository_url' => 'https://example.test/removal-proof.git', 'root' => 'public', 'default_branch' => 'main']);
    $this->instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $node->id,
        'name' => 'detached', 'status' => 'active', 'checkout_path' => '/srv/apps/removal-proof/detached', 'source_layout' => 'checkout']);
    $route = Route::query()->create(['project_id' => $project->id, 'node_id' => $node->id, 'domain' => 'proof.test',
        'publication' => 'private', 'provenance' => 'explicit', 'status' => 'pending']);
    $state = ['route_plan' => ['route_id' => $route->id, 'target_instance_ids' => [],
        'previous_target_instance_ids' => [$this->instance->id], 'removed_instance_ids' => [$this->instance->id],
        'placements' => [['instance_id' => $this->instance->id, 'node_id' => $node->id, 'production' => false]],
        'detached_instances' => [['instance_id' => $this->instance->id, 'node_id' => $node->id, 'checkout_path' => $this->instance->checkout_path]]],
        'route_active_stage' => 'removal', 'route_completed_stages' => [], 'executor_runs' => [], 'steps' => []];
    foreach (['database-committed', 'environment-synchronized', 'target-live-certificates', 'target-live-caddy',
        'router-published', 'vacated-published', 'target-staging-cleanup'] as $phase) {
        $key = 'stage-commit.'.$phase;
        $state['route_completed_stages'][$phase] = true;
        $state['executor_runs'][$key] = ['transport' => 'gateway', 'name' => 'route.parent', 'step' => 'stage.'.$phase,
            'input' => ['action' => 'commit', 'stage' => $phase],
            'result' => ['ready' => true, 'stage' => $phase]];
        $state['steps']['stage.'.$phase] = ['ready' => true, 'stage' => $phase];
    }
    $this->parent = Operation::query()->create(['kind' => 'route.target_set', 'target_type' => 'route', 'target_id' => $route->id,
        'node_id' => $node->id, 'status' => 'running', 'sequence' => 1, 'input' => ['detached_instances' => $state['route_plan']['detached_instances']], 'state' => $state]);
    foreach (array_keys($state['steps']) as $position => $step) {
        $this->parent->steps()->create(['name' => $step, 'position' => $position, 'sequence' => 1,
            'status' => 'succeeded', 'resumable' => true]);
    }
    $this->intent = ['key' => 'removal.'.$this->instance->id, 'kind' => 'instance.destroy', 'node_id' => $node->id,
        'input' => ['instance_id' => $this->instance->id, 'force' => true]];
});

it('accepts only the stored removed placement after every publication commit is proven', function (): void {
    $instance = app(InstanceRouteRemovalAdmission::class)->instance($this->parent, $this->intent);
    expect($instance->id)->toBe($this->instance->id)->and($instance->node_id)->toBe($this->intent['node_id'])
        ->and($instance->routes()->exists())->toBeFalse()->and($instance->status->value)->toBe('active');
});

it('rejects changed child identity or caller policy before removal admission', function (string $change): void {
    $intent = $this->intent;
    match ($change) {
        'force' => $intent['input']['force'] = false,
        'extra' => $intent['input']['detached'] = true,
        'node' => $intent['node_id']++,
        'key' => $intent['key'] = 'remove-something-else',
        'kind' => $intent['kind'] = 'instance.create',
    };
    expect(fn () => app(InstanceRouteRemovalAdmission::class)->instance($this->parent, $intent))
        ->toThrow(ResourceOperationException::class);
    expect($this->instance->refresh()->status->value)->toBe('active');
})->with(['force', 'extra', 'node', 'key', 'kind']);

it('rejects missing publication proof or a changed admitted pool', function (string $change): void {
    $state = $this->parent->state;
    match ($change) {
        'stage' => $state['route_active_stage'] = 'router-published',
        'removed' => $state['route_plan']['removed_instance_ids'] = [],
        'previous' => $state['route_plan']['previous_target_instance_ids'] = [],
        'retained' => $state['route_plan']['target_instance_ids'] = [$this->instance->id],
        'placement' => $state['route_plan']['placements'][0]['node_id']++,
        'production' => $state['route_plan']['placements'][0]['production'] = true,
        'snapshot-missing' => $state['route_plan']['detached_instances'] = [],
        'snapshot-node' => $state['route_plan']['detached_instances'][0]['node_id']++,
        'snapshot-path' => $state['route_plan']['detached_instances'][0]['checkout_path'] = '/srv/apps/other/checkout',
        'snapshot-duplicate' => $state['route_plan']['detached_instances'][] = $state['route_plan']['detached_instances'][0],
        'route' => $state['route_plan']['route_id']++,
        'commit' => $state['route_completed_stages']['target-staging-cleanup'] = false,
        'receipt' => $state['executor_runs'] = [],
        'result' => $state['steps']['stage.target-staging-cleanup'] = ['ready' => true],
        'receipt-stage' => $state['executor_runs']['stage-commit.target-staging-cleanup']['result']['stage'] = 'router-published',
        'receipt-step' => $state['executor_runs']['stage-commit.target-staging-cleanup']['step'] = 'stage.router-published',
        'receipt-input' => $state['executor_runs']['stage-commit.target-staging-cleanup']['input']['action'] = 'open',
        'failed' => $state['executor_runs']['stage-commit.target-staging-cleanup']['result']['error_code'] = 'route.failed',
        'step' => $this->parent->steps()->where('name', 'stage.target-staging-cleanup')->delete(),
    };
    $this->parent->update(['state' => $state]);
    expect(fn () => app(InstanceRouteRemovalAdmission::class)->instance($this->parent, $this->intent))
        ->toThrow(ResourceOperationException::class);
    expect($this->instance->refresh()->status->value)->toBe('active');
})->with(['stage', 'removed', 'previous', 'retained', 'placement', 'production', 'snapshot-missing', 'snapshot-node', 'snapshot-path', 'snapshot-duplicate', 'route', 'commit', 'receipt', 'result', 'receipt-stage', 'receipt-step', 'receipt-input', 'failed', 'step']);

it('requires the exact stamped successful Caddy child named in the publication plan', function (): void {
    app()->instance(OperationKinds::class, new OperationKinds(app(), [
        ...OperationKinds::definitions(),
        'route.target_set' => ['executor' => 'swamp', 'execution' => RouteParentExecution::class,
            'completion' => CaddyOperationCompletion::class,
            'runs' => [['transport' => 'gateway', 'name' => 'route.parent']],
            'result_fields' => ['ready', 'stage'],
            'completion_contract' => ['step' => 'route-proof', 'transport' => 'gateway', 'name' => 'route.parent', 'result' => ['ready' => true]],
            'children' => [['kind' => 'route.caddy_converge', 'node_from' => 'intent.node_ids']]],
    ]));
    $state = $this->parent->state;
    $state['route_plan']['node_ids'] = [$this->instance->node_id];
    $state['route_plan']['stages']['target-live-caddy'] = [['key' => 'target-live-caddy.'.$this->instance->id,
        'kind' => 'route.caddy_converge', 'node_id' => $this->instance->node_id, 'input' => ['reason' => 'route.target_set']]];
    $this->parent->update(['state' => $state]);
    expect(fn () => app(InstanceRouteRemovalAdmission::class)->instance($this->parent, $this->intent))
        ->toThrow(ResourceOperationException::class);

    $this->parent->update(['state' => [...$state, 'route_active_stage' => 'target-live-caddy']]);
    $child = DB::transaction(fn (): Operation => app(OperationChildren::class)->admit($this->parent, ['key' => 'target-live-caddy.'.$this->instance->id])['operation']);
    $this->parent->update(['state' => $state]);
    $child->update(['status' => 'succeeded', 'result' => ['ready' => false]]);
    expect(fn () => app(InstanceRouteRemovalAdmission::class)->instance($this->parent, $this->intent))
        ->toThrow(ResourceOperationException::class);
    $child->update(['result' => ['ready' => true]]);
    expect(app(InstanceRouteRemovalAdmission::class)->instance($this->parent, $this->intent)->id)->toBe($this->instance->id);
    $child->forceFill(['child_fingerprint' => str_repeat('f', 64)])->save();
    expect(fn () => app(InstanceRouteRemovalAdmission::class)->instance($this->parent, $this->intent))
        ->toThrow(ResourceOperationException::class);
});
