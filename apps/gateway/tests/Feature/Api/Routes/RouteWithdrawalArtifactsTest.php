<?php

declare(strict_types=1);

use App\Domain\Instances\InstanceState;
use App\Domain\Nodes\ManagedUserAccount;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Nodes\RoleName;
use App\Domain\Routes\RoutePublication;
use App\Domain\Routes\RouteStatus;
use App\Domain\Routes\RouteWithdrawalArtifacts;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\AgentCommands\AgentDelivery;
use App\Models\Cluster;
use App\Models\Instance;
use App\Models\InstanceRemoval;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Project;
use App\Models\Route;
use Illuminate\Support\Str;
use Tests\Support\FakeAgentCommandRunner;

beforeEach(function (): void {
    $this->node = Node::query()->create(['name' => 'withdraw-dev', 'user' => 'nckrtl', 'platform' => 'linux', 'architecture' => 'x86_64', 'public_ssh_host' => '192.0.2.9', 'wireguard_ip' => '10.44.0.9', 'status' => 'active']);
    $this->node->roles()->create(['role' => RoleName::AppDev, 'status' => 'active']);
    $this->project = Project::query()->create(['name' => 'Withdrawal', 'slug' => 'withdrawal', 'repository_url' => 'https://example.test/withdrawal.git', 'root' => 'public']);
    $this->instance = Instance::query()->create(['project_id' => $this->project->id, 'node_id' => $this->node->id, 'name' => 'withdrawing', 'checkout_path' => '/srv/withdrawal/main', 'branch' => 'main', 'status' => InstanceState::SourceResolved, 'starting_commit' => str_repeat('a', 40), 'selected_php_version' => '8.5']);
    $removal = InstanceRemoval::query()->create(['id' => (string) Str::uuid(), 'requested_instance_id' => $this->instance->id, 'requested_name' => $this->instance->name, 'force' => false, 'inventory_digest' => str_repeat('a', 64), 'total' => 1, 'status' => 'removing', 'current_step' => 'source_preparation']);
    $removal->members()->create(['position' => 0, 'instance_id' => $this->instance->id, 'project_id' => $this->project->id, 'node_id' => $this->node->id, 'name' => $this->instance->name, 'environment' => 'development', 'runtime_published' => false, 'root' => 'public', 'branch' => 'main', 'starting_commit' => $this->instance->starting_commit, 'source_commit' => $this->instance->starting_commit, 'repository_identity' => $this->project->repository_identity, 'common_repository_path' => '/srv/withdrawal/main/.git', 'source_identity' => 'withdrawal-source', 'source_layout' => $this->instance->source_layout, 'checkout_path' => $this->instance->checkout_path, 'linked_worktree_paths' => [], 'source_digest' => str_repeat('a', 64)]);
    $this->createRoute = function (): Route {
        $this->route = Route::query()->create(['project_id' => $this->project->id, 'node_id' => $this->node->id, 'domain' => 'withdrawal.test', 'provenance' => 'explicit', 'publication' => RoutePublication::Private, 'status' => RouteStatus::Pending, 'sites_published' => false]);
        $this->route->targets()->create(['instance_id' => $this->instance->id, 'position' => 0]);
        $this->route->update(['status' => RouteStatus::Retiring, 'sites_published' => false]);

        return $this->route;
    };
    $this->agents = new FakeAgentCommandRunner;
    app()->instance(AgentCommandRunner::class, $this->agents);
    app()->instance(ManagedUserAccountResolver::class, new class implements ManagedUserAccountResolver
    {
        public function resolve(Node $node): ManagedUserAccount
        {
            throw new LogicException('Artifact rendering must not query a Node account.');
        }
    });
    $manifest = tempnam(sys_get_temp_dir(), 'withdraw-agent-');
    file_put_contents($manifest, json_encode(['commit' => str_repeat('b', 40), 'version' => '0.5.0', 'artifacts' => ['linux-x86_64' => ['sha256' => str_repeat('a', 64), 'url' => 'https://example.test/agent']]], JSON_THROW_ON_ERROR));
    app()->instance(AgentDelivery::class, new AgentDelivery($manifest, sys_get_temp_dir()));
    $this->render = function (): array {
        $this->instance->update(['status' => InstanceState::Removing]);

        return app(RouteWithdrawalArtifacts::class)->forDevelopmentRemoval($this->instance, collect([$this->route->fresh()]));
    };
});

it('renders withdrawal data without Node calls or child Operations and retains associations', function (): void {
    ($this->createRoute)();
    $result = ($this->render)();
    expect($result['schema'])->toBe('route.withdrawal.v1')->and($result['route_ids'])->toBe([$this->route->id])
        ->and($result['nodes'][0]['certificate_scopes'])->toBe(["app-instance-{$this->instance->id}"])
        ->and($result['nodes'][0]['lan_route_ids'])->toBe([$this->route->id])
        ->and($result['nodes'][0]['caddy']['content'])->not->toContain('withdrawal.test')
        ->and($this->route->targets()->count())->toBe(1)->and($this->route->fresh()->status)->toBe(RouteStatus::Retiring)
        ->and(Operation::query()->count())->toBe(0)->and($this->agents->calls)->toBe([]);
});

it('always supplies the empty PHP object and local cleanup when no Route exists', function (): void {
    $this->instance->update(['status' => InstanceState::Removing]);
    $result = app(RouteWithdrawalArtifacts::class)->forDevelopmentRemoval($this->instance, collect());
    expect($result['route_ids'])->toBe([])->and($result['php']['node_id'])->toBe($this->node->id)
        ->and(json_encode($result['php']['configurations']))->toBe('{}')
        ->and($result['nodes'][0]['certificate_scopes'])->toBe(["app-instance-{$this->instance->id}"])
        ->and($result['dns'])->toBe([])->and($this->agents->calls)->toBe([]);
});

it('captures current and transition Routers independently from the workload Node', function (): void {
    ($this->createRoute)();
    $routers = [];
    foreach (['current', 'previous'] as $index => $name) {
        $cluster = Cluster::query()->create(['name' => $name]);
        $router = Node::query()->create(['name' => $name, 'cluster_id' => $cluster->id, 'user' => 'orbit', 'platform' => 'linux', 'architecture' => 'x86_64', 'public_ssh_host' => '192.0.2.17', 'wireguard_ip' => '10.44.0.'.($index + 17), 'status' => 'active']);
        $router->roles()->create(['role' => RoleName::Router, 'status' => 'active', 'cluster_id' => $cluster->id]);
        $routers[] = [$cluster, $router];
    }
    $this->node->update(['cluster_id' => $routers[0][0]->id]);
    $this->route->update(['node_id' => null, 'cluster_id' => $routers[0][0]->id, 'transition_cluster_id' => $routers[1][0]->id, 'web_root' => 'apps/docs/public']);
    $result = ($this->render)();
    $map = collect($result['nodes'])->keyBy('node_id');
    expect($map->keys()->all())->toBe([$this->node->id, $routers[0][1]->id, $routers[1][1]->id])
        ->and($map[$this->node->id]['certificate_scopes'])->toContain("route-{$this->route->id}", "app-instance-{$this->instance->id}-hostname-change");
    foreach ($routers as [, $router]) {
        expect($map[$router->id]['certificate_scopes'])->toBe(["route-{$this->route->id}-router", "route-{$this->route->id}-router-hostname-change"]);
    }
    expect($this->agents->calls)->toBe([]);
});

it('publishes DNS only on active VPN owners and retains the admitted catalog digest', function (): void {
    ($this->createRoute)();
    $owners = [];
    foreach ([[RoleName::Vpn, 'active'], [RoleName::Gateway, 'active'], [RoleName::Vpn, 'removing']] as $index => [$role, $status]) {
        $node = Node::query()->create(['name' => 'dns-'.$index, 'user' => 'orbit', 'platform' => 'linux', 'architecture' => 'x86_64', 'public_ssh_host' => '192.0.2.'.($index + 20), 'wireguard_ip' => '10.44.0.'.($index + 20), 'status' => 'active']);
        $node->roles()->create(['role' => $role, 'status' => $status]);
        if ($role === RoleName::Vpn && $status === 'active') {
            $owners[] = $node->id;
        }
    }
    $result = ($this->render)();
    expect(array_column($result['dns'], 'node_id'))->toBe($owners)
        ->and($result['dns'][0]['digest'])->toBe(hash('sha256', $result['dns'][0]['catalog']))
        ->and($this->agents->calls)->toBe([]);
});

it('refuses still-published or pending Routes before producing cleanup artifacts', function (array $attributes): void {
    ($this->createRoute)();
    $this->route->update($attributes);
    expect($this->render)->toThrow(ResourceOperationException::class)->and($this->agents->calls)->toBe([]);
})->with([[['sites_published' => true]], [['status' => RouteStatus::Pending]]]);

it('refuses another target or a detached target rather than guessing ownership', function (bool $detach): void {
    ($this->createRoute)();
    $this->route->targets()->delete();
    if (! $detach) {
        $other = Instance::query()->create(['project_id' => $this->project->id, 'node_id' => $this->node->id, 'name' => 'other', 'checkout_path' => '/srv/withdrawal/other', 'branch' => 'main', 'starting_commit' => str_repeat('a', 40), 'status' => InstanceState::SourceResolved]);
        $this->route->targets()->create(['instance_id' => $other->id, 'position' => 0]);
    }
    expect($this->render)->toThrow(ResourceOperationException::class)->and($this->agents->calls)->toBe([]);
})->with([true, false]);

it('keeps another application in the whole Node render and defers account lookup to Swamp', function (): void {
    ($this->createRoute)();
    $other = Instance::query()->create(['project_id' => $this->project->id, 'node_id' => $this->node->id, 'name' => 'survivor', 'checkout_path' => '/srv/withdrawal/survivor', 'branch' => 'main', 'starting_commit' => str_repeat('a', 40), 'status' => InstanceState::SourceResolved, 'selected_php_version' => '8.5', 'source_is_laravel' => true]);
    $route = Route::query()->create(['project_id' => $this->project->id, 'node_id' => $this->node->id, 'domain' => 'survivor.test', 'provenance' => 'explicit', 'publication' => RoutePublication::Private, 'status' => RouteStatus::Pending]);
    $route->targets()->create(['instance_id' => $other->id, 'position' => 0]);
    $route->update(['status' => RouteStatus::Active]);
    $result = ($this->render)();
    expect($result['nodes'][0]['caddy']['content'])->toContain('survivor.test')->not->toContain('withdrawal.test')
        ->and($result['php']['configurations']->{'8.5'})->toContain('/srv/withdrawal/survivor', RouteWithdrawalArtifacts::User, RouteWithdrawalArtifacts::Group)
        ->not->toContain('/srv/withdrawal/main')->and($this->agents->calls)->toBe([]);
});

it('keeps public Route removal outside the development private withdrawal contract', function (): void {
    ($this->createRoute)();
    $cluster = Cluster::query()->create(['name' => 'public-scope']);
    $this->node->update(['cluster_id' => $cluster->id]);
    $this->route->update(['node_id' => null, 'cluster_id' => $cluster->id, 'publication' => RoutePublication::Public]);
    expect($this->render)->toThrow(ResourceOperationException::class)->and($this->agents->calls)->toBe([]);
});

it('requires additional application Routes in the captured withdrawal set', function (): void {
    ($this->createRoute)();
    $extra = Route::query()->create(['project_id' => $this->project->id, 'node_id' => $this->node->id, 'domain' => 'docs.withdrawal.test', 'web_root' => 'apps/docs/public', 'provenance' => 'explicit', 'publication' => RoutePublication::Private, 'status' => RouteStatus::Pending]);
    $extra->targets()->create(['instance_id' => $this->instance->id, 'position' => 0]);
    $extra->update(['status' => RouteStatus::Retiring, 'sites_published' => false]);
    expect($this->render)->toThrow(ResourceOperationException::class);
    $result = app(RouteWithdrawalArtifacts::class)->forDevelopmentRemoval($this->instance, collect([$this->route->fresh(), $extra->fresh()]));
    expect($result['route_ids'])->toBe([$this->route->id, $extra->id])
        ->and($result['nodes'][0]['certificate_scopes'])->toContain("route-{$extra->id}")
        ->and($this->agents->calls)->toBe([]);
});
