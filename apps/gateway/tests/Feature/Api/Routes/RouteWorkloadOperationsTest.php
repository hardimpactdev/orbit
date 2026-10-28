<?php

declare(strict_types=1);

use App\Domain\Instances\InstanceState;
use App\Domain\Nodes\ManagedUserAccount;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\OperationStatus;
use App\Domain\Routes\RoutePublication;
use App\Domain\Routes\RouteStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Models\Instance;
use App\Models\Node;
use App\Models\NodeRole;
use App\Models\Operation;
use App\Models\Project;
use App\Models\Route;
use Tests\Support\FakeAgentCommandRunner;

beforeEach(function (): void {
    $gateway = Node::query()->create(['name' => 'gateway', 'public_ssh_host' => '192.0.2.1', 'user' => 'orbit', 'wireguard_ip' => '10.44.0.1', 'platform' => 'linux', 'status' => LifecycleStatus::Active]);
    NodeRole::query()->create(['node_id' => $gateway->id, 'role' => RoleName::Gateway, 'status' => LifecycleStatus::Active]);
    $services = Node::query()->create(['name' => 'services', 'public_ssh_host' => '192.0.2.17', 'user' => 'orbit', 'wireguard_ip' => '10.44.0.17', 'platform' => 'linux', 'status' => LifecycleStatus::Active]);
    $services->accessibleNodes()->attach($gateway);
    config(['dependency_detection.services_node_id' => $services->id]);
    $this->withServerVariables(['REMOTE_ADDR' => $services->wireguard_ip]);
    $this->node = Node::query()->create(['name' => 'dev', 'public_ssh_host' => '192.0.2.3', 'user' => 'orbit', 'wireguard_ip' => '10.44.0.3', 'platform' => 'linux', 'status' => LifecycleStatus::Active]);
    $this->node->roles()->create(['role' => RoleName::AppDev, 'status' => LifecycleStatus::Active]);
    $project = Project::query()->create(['name' => 'Proof', 'slug' => 'proof', 'repository_url' => 'https://example.test/proof.git', 'root' => 'public']);
    $this->instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $this->node->id, 'name' => 'private', 'checkout_path' => '/srv/proof/private', 'branch' => 'main', 'starting_commit' => str_repeat('a', 40), 'source_is_laravel' => false, 'status' => InstanceState::SourceResolved]);
    $this->route = Route::query()->create(['project_id' => $project->id, 'node_id' => $this->node->id, 'domain' => 'private.test', 'provenance' => 'explicit', 'publication' => RoutePublication::Private, 'status' => RouteStatus::Pending]);
    $this->route->targets()->create(['instance_id' => $this->instance->id, 'position' => 0]);
    $this->route->publishSites();
    $this->operation = Operation::query()->create(['kind' => 'route.workload_prepare', 'target_type' => 'route_workload', 'target_id' => $this->route->id, 'node_id' => $this->node->id, 'status' => OperationStatus::Running, 'worker' => 'worker', 'sequence' => 1, 'input' => ['route_id' => $this->route->id, 'instance_id' => $this->instance->id]]);
    $this->path = '/api/v1/operation-executor/'.$this->operation->id;
    app()->instance(ManagedUserAccountResolver::class, new class implements ManagedUserAccountResolver
    {
        public function resolve(Node $node): ManagedUserAccount
        {
            return new ManagedUserAccount('orbit', 'orbit', '/home/orbit');
        }
    });
    $this->agents = new FakeAgentCommandRunner()->answer('instance.grant-development-caddy-access', ['unreadable' => []])
        ->answer('php.inspect-shared-pools', ['versions' => [], 'missing_pools' => []])->answer('php.converge-shared-pools', ['changed' => false, 'missing_pools' => []]);
    app()->instance(AgentCommandRunner::class, $this->agents);
    $this->runStep = function (string $key, ?array $override = null) {
        $descriptors = $this->postJson($this->path.'/heartbeat', ['worker' => 'worker'])->assertOk()->json('data.execution_context.descriptors');
        $descriptor = array_find($descriptors, static fn (array $descriptor): bool => $descriptor['key'] === $key);
        expect($descriptor)->not->toBeNull();

        return $this->postJson($this->path.'/run', ['worker' => 'worker', ...array_intersect_key($descriptor, array_flip(['key', 'transport', 'name', 'input'])), ...($override ?? [])]);
    };
});

it('grants only the stored checkout and proves pool convergence before completion', function (): void {
    ($this->runStep)('workload-prepare')->assertOk()->assertJsonPath('data.prepared', true);
    ($this->runStep)('workload-source-access')->assertOk();
    ($this->runStep)('workload-php-inspect')->assertOk()->assertJsonPath('data.inspected', true);
    ($this->runStep)('workload-proof')->assertConflict()->assertJsonPath('error.code', 'operation.completion_unproven');
    ($this->runStep)('workload-php-pools')->assertOk();
    $result = ($this->runStep)('workload-proof')->assertOk()->json('data');
    $this->postJson($this->path.'/step', ['worker' => 'worker', 'key' => 'proof', 'name' => 'workload-proof', 'status' => 'succeeded', 'result' => $result])->assertOk();
    $this->postJson($this->path.'/complete', ['worker' => 'worker', 'status' => 'succeeded', 'result' => $result])->assertOk();
    expect($this->agents->calls)->toHaveCount(3)
        ->and($this->agents->calls[0]['input']['sites'][0]['checkout'])->toBe('/srv/proof/private')
        ->and((array) $this->agents->calls[2]['input']['configurations'])->toBe([]);
});

it('rejects a changed checkout and a different transport Node before Node work', function (): void {
    ($this->runStep)('workload-prepare')->assertOk();
    ($this->runStep)('workload-source-access', ['input' => ['user' => 'orbit', 'sites' => [['checkout' => '/srv/foreign', 'relative_root' => 'public', 'application' => '/srv/foreign']]]])->assertUnprocessable()->assertJsonPath('error.code', 'operation.execution_forbidden');
    ($this->runStep)('workload-source-access', ['node_id' => $this->node->id + 1])->assertUnprocessable();
    expect($this->agents->calls)->toBe([]);
});

it('refuses activation when a selected shared pool still has a missing directory', function (): void {
    $this->agents->answer('php.converge-shared-pools', ['changed' => true, 'missing_pools' => [['pool' => 'proof', 'version' => '8.5', 'directory' => '/srv/proof/private', 'installed' => false]]]);
    ($this->runStep)('workload-prepare')->assertOk();
    ($this->runStep)('workload-source-access')->assertOk();
    ($this->runStep)('workload-php-inspect')->assertOk();
    ($this->runStep)('workload-php-pools')->assertOk()->assertJsonPath('data.error_code', 'app-dev.php_pool_directory_missing');
    ($this->runStep)('workload-proof')->assertConflict()->assertJsonPath('error.code', 'operation.completion_unproven');
});

it('refuses a stale stored-site render before any Node mutation', function (): void {
    ($this->runStep)('workload-prepare')->assertOk();
    $this->route->update(['sites_published' => false]);
    ($this->runStep)('workload-source-access')->assertOk()->assertJsonPath('data.error_code', 'route.plan_stale');
    expect($this->agents->calls)->toBe([]);
});

it('withdraws only the recorded Route LAN rule and requires its cleanup receipt', function (): void {
    $this->route->update(['sites_published' => false]);
    $this->operation->update(['kind' => 'route.lan_remove', 'target_type' => 'route_effect', 'input' => ['route_id' => $this->route->id]]);
    $this->agents->answer('firewall.route-lan', ['matches' => true]);
    ($this->runStep)('node-effect-prepare')->assertOk();
    ($this->runStep)('node-effect-proof')->assertConflict()->assertJsonPath('error.code', 'operation.completion_unproven');
    ($this->runStep)('node-effect')->assertOk()->assertJsonPath('data.ready', true);
    ($this->runStep)('node-effect-proof')->assertOk();
    expect($this->agents->calls)->toHaveCount(1)->and($this->agents->calls[0]['input'])
        ->toMatchArray(['operation' => 'remove', 'route_id' => $this->route->id, 'effect_key' => 'op-'.$this->operation->id.'.node-effect']);
});

it('does not withdraw firewall or pools while the Route still publishes sites', function (): void {
    $this->operation->update(['kind' => 'route.lan_remove', 'target_type' => 'route_effect', 'input' => ['route_id' => $this->route->id]]);
    ($this->runStep)('node-effect-prepare')->assertUnprocessable()->assertJsonPath('error.code', 'operation.execution_forbidden');
    expect($this->agents->calls)->toBe([]);
});
