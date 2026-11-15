<?php

declare(strict_types=1);

use App\Actions\Certificates\SignDirectCertificatesAction;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use App\Models\NodeRole;
use App\Models\Operation;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

const SIGN_RUN = 'ea55ab37-bcbe-4459-81dc-cbb397e7d870';

function signNode(string $name, string $status, string $address): Node
{
    return Node::withoutEvents(fn (): Node => Node::query()->create(['name' => $name, 'status' => $status, 'platform' => 'linux', 'architecture' => 'x86_64',
        'public_ssh_host' => $address, 'wireguard_ip' => $address, 'user' => 'orbit']));
}

/** A Node enrollment that admitted the leaves of its Router Node and of the Node it adds. */
function signEnrollment(Node $host, Node $router, Node $added): Operation
{
    $leaf = static fn (Node $node, string $scope): array => ['node_id' => $node->id, 'scope' => $scope, 'domain' => 'node-1.create-bench.test', 'root_hash' => str_repeat('a', 64)];

    return Operation::query()->create(['kind' => 'node.add', 'target_type' => 'node', 'target_id' => $added->id, 'node_id' => $host->id,
        'input' => ['_node_enrollment' => ['certificates' => [$leaf($router, 'route-1-router'), $leaf($added, 'app-instance-1')]]],
        'status' => OperationStatus::Running, 'state' => ['swamp_run_id' => SIGN_RUN], 'sequence' => 1, 'attempts' => 1]);
}

/** An old task provision that admitted the leaves of its Router Node and of the guest of its removed task VM. */
function signProvision(Node $host, Node $router, Node $guest): Operation
{
    $leaf = static fn (Node $node, string $scope): array => ['node_id' => $node->id, 'scope' => $scope, 'domain' => 'task-1.create-bench.test', 'root_hash' => str_repeat('a', 64)];

    return Operation::query()->create(['kind' => 'tasks.provision', 'target_type' => 'task_group', 'target_id' => 1, 'node_id' => $host->id,
        'input' => ['guest_node_id' => $guest->id, 'route' => ['route_lifecycle' => ['preparation' => ['certificates' => [$leaf($router, 'route-1-router'), $leaf($guest, 'app-instance-1')]]]]],
        'status' => OperationStatus::Running, 'state' => ['swamp_run_id' => SIGN_RUN], 'sequence' => 1, 'attempts' => 1]);
}

function signLeaves(Operation $operation, Node $caller): array
{
    if (NodeRole::query()->where('role', RoleName::Swamp)->doesntExist()) {
        NodeRole::query()->create(['node_id' => signNode('swamp', 'active', '10.44.0.15')->id, 'role' => RoleName::Swamp, 'status' => LifecycleStatus::Active]);
    }
    $authorize = new ReflectionMethod(SignDirectCertificatesAction::class, 'authorize');

    return $authorize->invoke(app(SignDirectCertificatesAction::class), $operation, $caller->id, SIGN_RUN, false);
}

it('admits the leaf of the Node that a node.add enrolls while it is provisioning, and the same leaves once it is active', function (): void {
    [$host, $router, $added] = [signNode('shark', 'active', '10.44.0.11'), signNode('beast', 'active', '10.44.0.7'), signNode('new-node', 'provisioning', '10.44.0.30')];
    $operation = signEnrollment($host, $router, $added);

    $before = signLeaves($operation, $host);
    $added->forceFill(['status' => 'active'])->save();
    $after = signLeaves($operation, $host);

    expect(array_keys($before))->toBe([$router->id.':route-1-router', $added->id.':app-instance-1'])
        ->and($after)->toBe($before);
});

it('still refuses a leaf for a Node that is neither active nor the Node its own node.add enrolls', function (): void {
    [$host, $router, $added, $other] = [signNode('shark', 'active', '10.44.0.11'), signNode('beast', 'provisioning', '10.44.0.7'), signNode('new-node', 'provisioning', '10.44.0.30'), signNode('other', 'provisioning', '10.44.0.31')];
    $operation = signEnrollment($host, $router, $added);

    expect(array_keys(signLeaves($operation, $host)))->toBe([$added->id.':app-instance-1'])
        ->and(SignDirectCertificatesAction::leafStatuses($operation, $other->id))->toBe(['active'])
        ->and(SignDirectCertificatesAction::leafStatuses($operation, $added->id))->toBe(['active', 'provisioning']);
});

it('signs no leaf for an old task provision, whether its guest is provisioning or active', function (): void {
    [$host, $router, $guest] = [signNode('shark', 'active', '10.44.0.11'), signNode('beast', 'active', '10.44.0.7'), signNode('tvm-1', 'provisioning', '10.44.0.200')];
    $operation = signProvision($host, $router, $guest);

    expect(SignDirectCertificatesAction::leafStatuses($operation, $guest->id))->toBe(['active'])
        ->and(fn () => signLeaves($operation, $host))->toThrow(ResourceOperationException::class, 'This Operation has no admitted leaf signing intent.');
    $guest->forceFill(['status' => 'active'])->save();
    expect(fn () => signLeaves($operation, $host))->toThrow(ResourceOperationException::class, 'This Operation has no admitted leaf signing intent.');
});
