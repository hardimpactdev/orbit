<?php

declare(strict_types=1);

use App\Domain\Nodes\RoleName;
use App\Domain\Operations\OperationKinds;
use App\Domain\Routes\AnalyticsRouteParentExecution;
use App\Domain\Routes\CaddyOperationCompletion;
use App\Domain\Routes\RouteKind;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\Caddy\Build\NodeCaddyfileRenderer;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Project;
use App\Models\Route;
use Tests\Support\FakeAgentCommandRunner;

it('admits only the open Route phase, yields to its stamped child and finishes only after canonical proof', function (): void {
    $gateway = Node::query()->create(['name' => 'gateway-child', 'public_ssh_host' => '192.0.2.1', 'user' => 'orbit', 'wireguard_ip' => '10.44.0.1', 'platform' => 'linux', 'status' => LifecycleStatus::Active]);
    $gateway->roles()->create(['role' => RoleName::Gateway, 'status' => LifecycleStatus::Active]);
    $services = Node::query()->create(['name' => 'services-child', 'public_ssh_host' => '192.0.2.17', 'user' => 'orbit', 'wireguard_ip' => '10.44.0.17', 'platform' => 'linux', 'status' => LifecycleStatus::Active]);
    $services->accessibleNodes()->attach($gateway);
    config(['dependency_detection.services_node_id' => $services->id]);
    $this->withServerVariables(['REMOTE_ADDR' => $services->wireguard_ip]);
    $node = Node::query()->create(['name' => 'dev-child', 'public_ssh_host' => '192.0.2.3', 'user' => 'orbit', 'wireguard_ip' => '10.44.0.3', 'platform' => 'linux', 'status' => LifecycleStatus::Active]);
    $project = Project::query()->create(['name' => 'Child', 'slug' => 'child', 'repository_url' => 'https://example.test/child.git', 'root' => 'public']);
    $instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $node->id, 'name' => 'private', 'checkout_path' => '/srv/child/private', 'branch' => 'main', 'starting_commit' => str_repeat('a', 40), 'status' => 'source_resolved']);
    $route = Route::query()->create(['kind' => RouteKind::AnalyticsTracking, 'node_id' => $node->id, 'domain' => 'tracking.private.test', 'provenance' => 'explicit', 'publication' => 'private', 'status' => 'pending']);
    $route->analyticsTracking()->create(['instance_id' => $instance->id]);

    // Exercise B now. Production registration stays gated on C's bounded child-phase list.
    $definitions = OperationKinds::definitions();
    $definitions['route.analytics_host_withdraw'] = [
        'executor' => 'swamp', 'completion' => CaddyOperationCompletion::class, 'execution' => AnalyticsRouteParentExecution::class,
        'runs' => [['transport' => 'gateway', 'name' => 'route.analytics-parent', 'resumable' => true, 'input_fields' => [], 'input_values' => []]],
        'children' => [['kind' => 'route.caddy_converge', 'node_from' => 'intent.node_ids']],
        'completion_contract' => ['step' => 'route-proof', 'transport' => 'gateway', 'name' => 'route.analytics-parent', 'result' => ['ready' => true]],
        'result_fields' => ['prepared', 'ready', 'opened', 'wait_seconds', 'stage', 'route_id', 'error_code'],
    ];
    app()->instance(OperationKinds::class, new OperationKinds(app(), $definitions));
    app()->instance(NodeCaddyfileRenderer::class, new NodeCaddyfileRenderer([]));
    $agents = new FakeAgentCommandRunner()->answer('caddy.publish', ['result' => 'published']);
    app()->instance(AgentCommandRunner::class, $agents);
    $parent = Operation::query()->create(['kind' => 'route.analytics_host_withdraw', 'target_type' => 'route', 'target_id' => $route->id,
        'node_id' => $node->id, 'input' => ['route_id' => $route->id], 'status' => 'queued', 'sequence' => 1]);
    $path = '/api/v1/operation-executor/'.$parent->id;
    $this->postJson('/api/v1/operation-executor/claim', ['kind' => $parent->kind, 'worker' => 'parent-one'])->assertOk()->assertJsonPath('data.id', $parent->id);
    $run = function (string $key, array $input, string $worker = 'parent-one') use ($path) {
        return $this->postJson($path.'/run', ['worker' => $worker, 'key' => $key, 'transport' => 'gateway', 'name' => 'route.analytics-parent', 'input' => $input]);
    };
    $run('parent-prepare', ['action' => 'prepare'])->assertOk();
    $key = 'host-withdraw.node-'.$node->id;
    $this->postJson($path.'/children', ['worker' => 'parent-one', 'key' => $key])->assertUnprocessable()->assertJsonPath('error.code', 'operation.child_forbidden');
    $run('stage-open.await-dns-expiry', ['action' => 'open', 'stage' => 'await-dns-expiry'])->assertOk();
    $run('stage-commit.await-dns-expiry', ['action' => 'commit', 'stage' => 'await-dns-expiry'])->assertOk();
    $run('stage-open.host-withdraw', ['action' => 'open', 'stage' => 'host-withdraw'])->assertOk();
    $this->postJson($path.'/children', ['worker' => 'parent-one', 'key' => $key, 'node_id' => $services->id])->assertConflict()->assertJsonPath('error.code', 'operation.idempotency_conflict');
    $child = $this->postJson($path.'/children', ['worker' => 'parent-one', 'key' => $key])->assertAccepted()->json('data');
    $this->postJson($path.'/children', ['worker' => 'parent-one', 'key' => $key])->assertOk()->assertJsonPath('data.id', $child['id']);
    $run('stage-commit.host-withdraw', ['action' => 'commit', 'stage' => 'host-withdraw'])->assertConflict()->assertJsonPath('error.code', 'operation.child_unproven');
    $this->postJson($path.'/wait', ['worker' => 'parent-one', 'keys' => [$key]])->assertOk()->assertJsonPath('data.status', 'waiting');
    $this->postJson('/api/v1/operation-executor/claim', ['kind' => $parent->kind, 'worker' => 'parent-two', 'operation_id' => $parent->id])->assertOk()->assertJsonPath('data', null);
    $this->postJson('/api/v1/operation-executor/claim', ['kind' => $child['kind'], 'worker' => 'child', 'operation_id' => $child['id']])->assertOk()->assertJsonPath('data.id', $child['id']);
    $childPath = '/api/v1/operation-executor/'.$child['id'];
    $this->postJson($childPath.'/run', ['worker' => 'child', 'key' => 'prepare', 'transport' => 'gateway', 'name' => 'route.caddy-prepare', 'input' => $child['input']])->assertOk();
    $descriptor = $this->postJson($childPath.'/heartbeat', ['worker' => 'child'])->assertOk()->json('data.execution_context.descriptors.1');
    $result = $this->postJson($childPath.'/run', ['worker' => 'child', ...array_intersect_key($descriptor, array_flip(['key', 'transport', 'name', 'input']))])->assertOk()->json('data');
    $this->postJson($childPath.'/step', ['worker' => 'child', 'key' => 'publish-proof', 'name' => 'caddy-publish', 'status' => 'succeeded', 'result' => $result])->assertOk();
    $this->postJson($childPath.'/complete', ['worker' => 'child', 'status' => 'succeeded', 'result' => $result])->assertOk();
    $this->postJson('/api/v1/operation-executor/claim', ['kind' => $parent->kind, 'worker' => 'parent-two', 'operation_id' => $parent->id])->assertOk()->assertJsonPath('data.id', $parent->id);
    $run('stage-commit.host-withdraw', ['action' => 'commit', 'stage' => 'host-withdraw'], 'parent-two')->assertOk();
    $run('stage-open.host-certificate-remove', ['action' => 'open', 'stage' => 'host-certificate-remove'], 'parent-two')->assertOk();
    $run('stage-commit.host-certificate-remove', ['action' => 'commit', 'stage' => 'host-certificate-remove'], 'parent-two')->assertOk();
    $proof = $run('route-proof', ['action' => 'prove'], 'parent-two')->assertOk()->json('data');
    $this->postJson($path.'/step', ['worker' => 'parent-two', 'key' => 'final-proof', 'name' => 'route-proof', 'status' => 'succeeded', 'result' => $proof])->assertOk();
    $this->postJson($path.'/complete', ['worker' => 'parent-two', 'status' => 'succeeded', 'result' => [...$proof, 'route_id' => $route->id + 1]])->assertConflict()->assertJsonPath('error.code', 'operation.completion_unproven');
    $this->postJson($path.'/complete', ['worker' => 'parent-two', 'status' => 'succeeded', 'result' => $proof])->assertOk()->assertJsonPath('data.status', 'succeeded');
    expect($agents->calls)->toHaveCount(1)->and($agents->calls[0]['node'])->toBe('dev-child')
        ->and(Operation::query()->findOrFail($child['id'])->parent_operation_id)->toBe($parent->id);
});
