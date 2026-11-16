<?php

declare(strict_types=1);

use App\Actions\Nodes\AdmitNodeRoleOperationAction;
use App\Domain\Clusters\ClusterState;
use App\Domain\Nodes\NodeRoleValidationException;
use App\Domain\Nodes\RoleAssignmentException;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Swamp\SwampDispatch;
use App\Models\Cluster;
use App\Models\Node;
use App\Models\NodeRole;
use App\Models\Operation;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
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
