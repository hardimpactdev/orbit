<?php

declare(strict_types=1);

use App\Actions\Nodes\AdmitNodeRoleOperationAction;
use App\Actions\Routes\AdmitClusterRouterOperationAction;
use App\Domain\Clusters\ClusterRouterOperationLock;
use App\Domain\Clusters\ClusterState;
use App\Domain\Nodes\NodeRoleRemovalProjection;
use App\Domain\Nodes\NodeRoleValidationException;
use App\Domain\Nodes\RoleAssignmentException;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\OperationStatus;
use App\Domain\Routes\ClusterRouterTransition;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Swamp\SwampDispatch;
use App\Models\Cluster;
use App\Models\Node;
use App\Models\NodeRole;
use App\Models\Operation;
use App\Models\Project;
use App\Models\Route;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\SwampNodeFixture;

uses(LazilyRefreshDatabase::class);

/** @return object{started: list<int>} */
function staleRouterDispatch(): object
{
    app()->instance(SwampDispatch::class, $dispatch = new class
    {
        /** @var list<int> */
        public array $started = [];

        public function start(Operation $operation): void
        {
            $this->started[] = $operation->id;
        }
    });

    return $dispatch;
}

function staleRouterNode(Cluster $cluster, string $name, string $address, string $lan): Node
{
    return Node::withoutEvents(fn (): Node => Node::query()->create([
        'name' => $name, 'status' => 'active', 'platform' => 'linux', 'architecture' => 'x86_64', 'cluster_id' => $cluster->id,
        'wireguard_ip' => $address, 'public_ssh_host' => $address, 'lan_ip' => $lan, 'user' => 'orbit', 'wireguard_public_key' => $name.'-key',
        'ssh_host_fingerprint' => 'SHA256:'.$name, 'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => $name.'-public-key',
    ]));
}

/** The dev Cluster: Beast is its active Router, Shark keeps a router row that failed Router changes left. @return array{0:Node,1:NodeRole,2:NodeRole} */
function staleRouterCluster(string $failedStep = 'restore_dns-0', string $status = 'failed'): array
{
    SwampNodeFixture::ensure();
    $cluster = Cluster::query()->create(['name' => 'dev', 'tld' => 'test']);
    $cluster->forceFill(['state' => ClusterState::Active])->save();
    $beast = staleRouterNode($cluster, 'beast', '10.44.0.9', '192.168.6.9');
    $shark = staleRouterNode($cluster, 'shark', '10.44.0.13', '192.168.6.13');
    $active = NodeRole::query()->create(['node_id' => $beast->id, 'cluster_id' => $cluster->id, 'role' => RoleName::Router, 'status' => LifecycleStatus::Active]);
    $stale = NodeRole::query()->create(['node_id' => $shark->id, 'cluster_id' => $cluster->id, 'role' => RoleName::Router,
        'status' => $status, 'failed_step' => $failedStep, 'error_code' => 'cluster.router_failed']);

    return [$shark, $stale, $active];
}

function expectRouterProtected(Node $node, string $reason): void
{
    expect(fn () => app(AdmitNodeRoleOperationAction::class)->remove($node, RoleName::Router, force: true))
        ->toThrow(fn (ResourceOperationException $exception) => expect($exception->errorCode)->toBe('node_role.router_protected')
            ->and($exception->details['reason'])->toBe($reason)
            ->and($exception->getMessage())->toContain('cluster:router:set'));
}

it('admits the removal of a failed router row on a Node that is not the Cluster Router', function (): void {
    Http::preventStrayRequests();
    $dispatch = staleRouterDispatch();
    [$shark, $stale, $active] = staleRouterCluster();

    $operation = app(AdmitNodeRoleOperationAction::class)->remove($shark, RoleName::Router, force: true);

    expect($operation->kind)->toBe('node.role_remove')
        ->and($operation->status)->toBe(OperationStatus::Queued)
        ->and($operation->input['intent']['role'])->toBe('router')
        ->and($stale->refresh()->status)->toBe(LifecycleStatus::Removing)
        ->and($active->refresh()->status)->toBe(LifecycleStatus::Active)
        ->and($dispatch->started)->toBe([$operation->id]);
});

it('withdraws the Router sites, certificates and LAN rules on that Node only', function (): void {
    Http::preventStrayRequests();
    staleRouterDispatch();
    [$shark, , $active] = staleRouterCluster();
    $project = Project::query()->create(['name' => 'bench', 'slug' => 'bench', 'repository_url' => 'https://github.com/laravel/laravel.git', 'default_branch' => 'main', 'root' => 'public']);
    $route = Route::query()->create(['kind' => 'app', 'project_id' => $project->id, 'cluster_id' => $shark->cluster_id, 'provenance' => 'explicit',
        'publication' => 'private', 'domain' => 'kept.bench.test', 'status' => 'pending']);
    $prod = Cluster::query()->create(['name' => 'prod', 'tld' => 'prod']);
    $candidate = staleRouterNode($prod, 'candidate', '10.44.0.20', '192.168.7.20');
    NodeRole::query()->create(['node_id' => $candidate->id, 'cluster_id' => $prod->id, 'role' => RoleName::Router, 'status' => 'failed', 'failed_step' => 'cleanup']);
    $dnsBefore = app(ClusterRouterTransition::class)->dnsRouters();

    $intent = app(AdmitNodeRoleOperationAction::class)->remove($shark, RoleName::Router, force: true)->input['intent'];
    $hosts = static fn (array $actions): array => array_values(array_unique(array_map(static fn (array $action): string => $action['ssh']['target']['host'], $actions)));
    $comments = array_map(static fn (array $action): string => $action['input']['argv'][array_key_last($action['input']['argv'])], $intent['firewall']);

    expect($comments)->toBe(['orbit:router-lan-https:'.$active->node_id, 'orbit:router-lan-dns-udp:'.$active->node_id, 'orbit:router-lan-dns-tcp:'.$active->node_id])
        ->and($intent['firewall'][0]['input']['argv'])->toBe(['ufw', '--force', 'delete', 'allow', 'in', 'proto', 'tcp', 'from', '192.168.6.9', 'to', '192.168.6.13', 'port', '443',
            'comment', 'orbit:router-lan-https:'.$active->node_id])
        ->and($intent['commands'])->toHaveCount(1)
        ->and($intent['commands'][0]['command'])->toBe("timeout 60 rm -rf -- /etc/caddy/orbit-certificates/route-{$route->id}-router /etc/caddy/orbit-certificates/route-{$route->id}-router-hostname-change")
        ->and($hosts([...$intent['firewall'], ...$intent['commands']]))->toBe(['10.44.0.13'])
        ->and(array_column($intent['publications'], 'node_id'))->toBe([$shark->id])
        ->and(array_column(array_column($intent['publications'], 'after'), 'dns'))->toBe([null])
        ->and($intent['stop_units'])->toBe([])
        ->and($intent['remove_units'])->toBe([])
        ->and($intent['stop_containers'])->toBe([])
        ->and($intent['files'])->toBe([])
        ->and($intent['directories'])->toBe([])
        ->and($intent['certificates'])->toBe([])
        ->and($dnsBefore)->toBe([$prod->id => $candidate->id])
        ->and(app(ClusterRouterTransition::class)->dnsRouters())->toBe($dnsBefore)
        ->and(array_keys(app(ClusterRouterTransition::class)->secondRouters()))->not->toContain($shark->cluster_id);
});

it('deletes the router row once its removal run succeeds', function (): void {
    Http::preventStrayRequests();
    staleRouterDispatch();
    [$shark, $stale, $active] = staleRouterCluster();
    $operation = app(AdmitNodeRoleOperationAction::class)->remove($shark, RoleName::Router, force: true);
    $operation->forceFill(['status' => 'running', 'state' => ['swamp_run_id' => 'fixture-run']])->save();

    app(NodeRoleRemovalProjection::class)->apply($operation, ['run_id' => 'fixture-run', 'status' => 'succeeded',
        'started_at' => now()->toIso8601String(), 'finished_at' => now()->toIso8601String(),
        'result' => ['steps' => ['Confirm role removal' => ['status' => 'succeeded']], 'summary' => ['failed_steps' => []]]]);

    expect(NodeRole::query()->whereKey($stale->id)->exists())->toBeFalse()
        ->and($active->refresh()->status)->toBe(LifecycleStatus::Active)
        ->and(Cluster::query()->whereKey($shark->cluster_id)->exists())->toBeTrue();
});

it('refuses the active Router of the Cluster', function (): void {
    Http::preventStrayRequests();
    staleRouterDispatch();
    [, , $active] = staleRouterCluster();

    expectRouterProtected($active->node, 'active_router');
    expect($active->refresh()->status)->toBe(LifecycleStatus::Active)
        ->and(Operation::query()->count())->toBe(0);
});

it('refuses a candidate whose failed step is replacement progress', function (string $step): void {
    Http::preventStrayRequests();
    staleRouterDispatch();
    [$shark, $stale] = staleRouterCluster($step);

    expectRouterProtected($shark, 'replacement_in_progress');
    expect($stale->refresh()->status)->toBe(LifecycleStatus::Failed)
        ->and(Operation::query()->count())->toBe(0);
})->with(['dns-publication', 'rollback:router-caddy', 'cleanup']);

it('refuses while another Router row of the Cluster is in a replacement', function (): void {
    Http::preventStrayRequests();
    staleRouterDispatch();
    [$shark, $stale, $active] = staleRouterCluster();
    $active->update(['failed_step' => 'database']);

    expectRouterProtected($shark, 'replacement_in_progress');
    expect($stale->refresh()->status)->toBe(LifecycleStatus::Failed);
});

it('refuses while a Router Operation of the Cluster is open', function (string $kind, string $status): void {
    Http::preventStrayRequests();
    staleRouterDispatch();
    [$shark, $stale] = staleRouterCluster(status: 'provisioning');
    Operation::query()->create(['kind' => $kind, 'target_type' => 'cluster_router', 'target_id' => $shark->cluster_id,
        'node_id' => $shark->id, 'status' => $status, 'input' => []]);

    expectRouterProtected($shark, 'router_operation_open');
    expect($stale->refresh()->status)->toBe(LifecycleStatus::Provisioning);
})->with([['cluster.router_set', 'queued'], ['cluster.router_set', 'running'], ['cluster.router_unset', 'running']]);

it('admits a provisioning router row once no Router Operation of the Cluster is open', function (): void {
    Http::preventStrayRequests();
    staleRouterDispatch();
    [$shark, $stale] = staleRouterCluster(status: 'provisioning');
    Operation::query()->create(['kind' => 'cluster.router_set', 'target_type' => 'cluster_router', 'target_id' => $shark->cluster_id,
        'node_id' => $shark->id, 'status' => 'failed', 'input' => []]);

    app(AdmitNodeRoleOperationAction::class)->remove($shark, RoleName::Router, force: true);

    expect($stale->refresh()->status)->toBe(LifecycleStatus::Removing);
});

it('keeps router protected from add and relocate', function (): void {
    Http::preventStrayRequests();
    staleRouterDispatch();
    [$shark] = staleRouterCluster();
    $other = staleRouterNode($shark->cluster, 'spare', '10.44.0.14', '192.168.6.14');

    expect(fn () => app(AdmitNodeRoleOperationAction::class)->add($other, RoleName::Router))
        ->toThrow(RoleAssignmentException::class, 'protected from generic mutation')
        ->and(fn () => app(AdmitNodeRoleOperationAction::class)->relocate($other, RoleName::Router, force: true, from: $shark))
        ->toThrow(NodeRoleValidationException::class, 'cannot be relocated');
    expect(Operation::query()->count())->toBe(0);
});

it('keeps database and app-dev removals as before', function (RoleName $role): void {
    Http::preventStrayRequests();
    $dispatch = staleRouterDispatch();
    [$shark] = staleRouterCluster();
    $assignment = $shark->roles()->create(['role' => $role, 'status' => 'failed', 'failed_step' => 'converge:Apply role prerequisites',
        'error_code' => 'node_role.convergence_failed']);

    expect(fn () => app(AdmitNodeRoleOperationAction::class)->remove($shark, $role))->toThrow(NodeRoleValidationException::class);
    $operation = app(AdmitNodeRoleOperationAction::class)->remove($shark->refresh(), $role, force: true);

    expect($operation->input['intent']['role'])->toBe($role->value)
        ->and($assignment->refresh()->status)->toBe(LifecycleStatus::Removing)
        ->and($dispatch->started)->toBe([$operation->id]);
})->with([RoleName::Database, RoleName::AppDev]);

it('refuses a Router set or unset of the Cluster while the stale removal runs', function (): void {
    Http::preventStrayRequests();
    staleRouterDispatch();
    [$shark, $stale, $active] = staleRouterCluster();
    app(AdmitNodeRoleOperationAction::class)->remove($shark, RoleName::Router, force: true);
    $cluster = $shark->cluster;
    $conflict = static fn (ResourceOperationException $exception) => expect($exception->errorCode)->toBe('cluster.router_transition_conflict');

    expect(fn () => app(AdmitClusterRouterOperationAction::class)->set($cluster, $shark))->toThrow($conflict)
        ->and(fn () => app(AdmitClusterRouterOperationAction::class)->set($cluster, $active->node))->toThrow($conflict)
        ->and(fn () => app(AdmitClusterRouterOperationAction::class)->unset($cluster))->toThrow($conflict);
    expect($stale->refresh()->status)->toBe(LifecycleStatus::Removing)
        ->and(Operation::query()->where('kind', 'like', 'cluster.router_%')->count())->toBe(0);
});

it('checks the router row under its Cluster Router lock', function (): void {
    Http::preventStrayRequests();
    staleRouterDispatch();
    [$shark, $stale] = staleRouterCluster();
    app()->instance(ClusterRouterOperationLock::class, $lock = new class implements ClusterRouterOperationLock
    {
        /** @var list<int> */
        public array $clusters = [];

        public bool $busy = true;

        public function run(int $clusterId, Closure $operation): mixed
        {
            $this->clusters[] = $clusterId;
            if ($this->busy) {
                throw new ResourceOperationException('cluster.router_busy', 'busy', 409);
            }

            return $operation();
        }
    });

    expect(fn () => app(AdmitNodeRoleOperationAction::class)->remove($shark, RoleName::Router, force: true))
        ->toThrow(fn (ResourceOperationException $exception) => expect($exception->errorCode)->toBe('cluster.router_busy'));
    expect($stale->refresh()->status)->toBe(LifecycleStatus::Failed)
        ->and(Operation::query()->count())->toBe(0);

    $lock->busy = false;
    app(AdmitNodeRoleOperationAction::class)->remove($shark, RoleName::Router, force: true);
    expect($lock->clusters)->toBe([$shark->cluster_id, $shark->cluster_id])
        ->and($stale->refresh()->status)->toBe(LifecycleStatus::Removing);
});

it('keeps a router row whose removal run failed, and admits the rerun', function (): void {
    Http::preventStrayRequests();
    staleRouterDispatch();
    [$shark, $stale] = staleRouterCluster();
    $operation = app(AdmitNodeRoleOperationAction::class)->remove($shark, RoleName::Router, force: true);
    $operation->forceFill(['status' => 'running', 'state' => ['swamp_run_id' => 'fixture-run']])->save();

    app(NodeRoleRemovalProjection::class)->apply($operation, ['run_id' => 'fixture-run', 'status' => 'failed',
        'started_at' => now()->toIso8601String(), 'finished_at' => now()->toIso8601String(),
        'result' => ['error_code' => 'route.withdraw_failed', 'failed_step' => 'Remove role data',
            'steps' => ['Remove role data' => ['status' => 'failed']], 'summary' => ['failed_steps' => ['Remove role data']]]]);

    expect($stale->refresh()->status)->toBe(LifecycleStatus::Failed)
        ->and($stale->failed_step)->toBe('remove:Remove role data');
    $rerun = app(AdmitNodeRoleOperationAction::class)->remove($shark->refresh(), RoleName::Router, force: true);
    expect($rerun->id)->not->toBe($operation->id)
        ->and($stale->refresh()->status)->toBe(LifecycleStatus::Removing);
});

it('admits the stale removal and refuses the active Router over the API', function (): void {
    Queue::fake();
    Http::preventStrayRequests();
    staleRouterDispatch();
    [$shark, $stale, $active] = staleRouterCluster();
    $caller = Node::withoutEvents(fn (): Node => Node::query()->create(['name' => 'operator', 'status' => 'active', 'platform' => 'linux',
        'wireguard_ip' => '10.44.0.50', 'public_ssh_host' => '10.44.0.50', 'user' => 'orbit']));
    $caller->accessibleNodes()->attach([$shark->id, $active->node_id]);
    $this->withServerVariables(['REMOTE_ADDR' => $caller->wireguard_ip]);

    $this->deleteJson('/api/v1/nodes/'.$active->node_id.'/roles/router', ['force' => true])
        ->assertStatus(409)->assertJsonPath('error.code', 'node_role.router_protected');
    $this->deleteJson('/api/v1/nodes/'.$shark->id.'/roles/router', ['force' => true])
        ->assertStatus(202)->assertJsonPath('data.kind', 'node.role_remove');

    expect($stale->refresh()->status)->toBe(LifecycleStatus::Removing)
        ->and($active->refresh()->status)->toBe(LifecycleStatus::Active);
});
