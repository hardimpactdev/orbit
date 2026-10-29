<?php

declare(strict_types=1);

use App\Actions\Nodes\ProvisionNodeAction;
use App\Data\Nodes\ProvisionNodeData;
use App\Domain\Nodes\NodeLifecycleOperationExecution;
use App\Domain\Operations\OperationChildIntent;
use App\Domain\Operations\OperationChildren;
use App\Domain\Operations\OperationKinds;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\Operation;
use App\Models\TaskVm;
use Illuminate\Support\Facades\DB;
use Tests\Support\Fleet\FleetFixtures;

beforeEach(function (): void {
    $keys = Mockery::mock(SshKeyProvider::class);
    $keys->shouldReceive('publicKey')->andReturn('ssh-ed25519 fixture-key');
    $keys->shouldNotReceive('privateKeyPath');
    app()->instance(SshKeyProvider::class, $keys);
});

function nodeLifecycleParent(bool $removal = false, string $parentKind = ''): array
{
    $node = FleetFixtures::node('reserved-child');
    $node->update(['status' => 'provisioning']);
    $host = FleetFixtures::node('reserved-host');
    $vm = TaskVm::query()->create(['provider' => 'incus', 'name' => $node->name, 'node_id' => $node->id, 'host_node_id' => $host->id,
        'state' => 'provisioning', 'wireguard_ip' => $node->wireguard_ip, 'address' => $node->public_ssh_host]);
    $kind = $removal ? 'node.remove' : 'node.add';
    $parentKind = $parentKind !== '' ? $parentKind : ($removal ? 'task_vm.destroy' : 'task_vm.provision');
    $intent = ['key' => $removal ? 'node-removal' : 'node-enrollment', 'kind' => $kind, 'node_id' => $node->id,
        'input' => $removal ? ['caller_node_id' => $host->id, 'offline' => true, 'force' => true, 'deleted' => true]
            : ['name' => $node->name, 'publicSshHost' => $vm->address, 'roles' => ['app-dev'], 'user' => 'orbit', 'orbitUser' => 'orbit',
                'wireguardIp' => $vm->wireguard_ip, 'tld' => 'child.test', 'expectedSshHostFingerprint' => $node->ssh_host_fingerprint,
                'sshJumpNodeId' => $host->id, 'taskVmId' => $vm->id]];
    $definitions = OperationKinds::definitions();
    $definitions[$parentKind] = [...$definitions['node.add'], 'execution' => NodeLifecycleOperationExecution::class,
        'children' => [['kind' => $kind, 'node_from' => 'intent.node_ids']]];
    unset($definitions[$parentKind]['compensations'], $definitions[$parentKind]['cancellation_boundary']);
    app()->instance(OperationKinds::class, new OperationKinds(app(), $definitions));
    $parent = Operation::query()->create(['kind' => $parentKind, 'target_type' => 'task_vm', 'target_id' => $vm->id,
        'node_id' => $host->id, 'input' => ['_node_plan' => [['type' => 'child', ...$intent, 'optional' => false]]],
        'state' => ['child_intents' => [$intent]], 'status' => 'running', 'sequence' => 1]);
    nodeLifecycleEvidence($parent, $removal ? 'provider-delete' : 'prepare-enrollment',
        $removal ? 'task_vm.incus_delete' : 'task_vm.prepare_enrollment', $vm->id, $host->id,
        $removal ? ['ready' => true] : ['ready' => true, 'node_id' => $node->id, 'enrolled' => false]);

    return [$parent, $node, $vm, $intent];
}

function nodeLifecycleEvidence(Operation $parent, string $key, string $kind, int $target, int $node, array $result): Operation
{
    $intent = ['key' => $key, 'kind' => $kind, 'node_id' => $node, 'input' => []];
    $identity = ['parent_operation_id' => $parent->id, 'key' => $key, 'kind' => $kind, 'node_id' => $node,
        'target_type' => 'task_vm', 'target_id' => $target, 'input' => [], 'run_constraints' => []];
    $snapshot = ['intent' => $intent, 'admitted' => $identity];
    $child = new Operation;
    $child->forceFill(['kind' => $kind, 'node_id' => $node, 'target_type' => 'task_vm', 'target_id' => $target,
        'input' => [], 'parent_operation_id' => $parent->id, 'child_key' => $key, 'child_admission' => $snapshot,
        'child_fingerprint' => OperationChildIntent::fingerprint($snapshot), 'run_constraints' => [], 'result' => $result,
        'status' => 'succeeded', 'sequence' => 1])->save();

    return $child;
}

function admitNodeLifecycleChild(Operation $parent): Operation
{
    return DB::transaction(fn () => app(OperationChildren::class)->admit($parent, ['key' => $parent->state['child_intents'][0]['key']]))['operation'];
}

it('admits and replays only the provisioning VM reserved Node with serialized role names', function (string $parentKind, string $status): void {
    [$parent, $node, $vm, $intent] = nodeLifecycleParent(parentKind: $parentKind);
    $node->update(['status' => $status]);
    $child = admitNodeLifecycleChild($parent);

    expect($child->kind)->toBe('node.add');
    expect($child->node_id)->toBe($node->id);
    expect($child->parent_operation_id)->toBe($parent->id);
    expect($child->child_admission['intent'])->toBe($intent);
    expect($child->state['enrollment_intent']['roles'])->toBe(['app-dev']);
    expect(admitNodeLifecycleChild($parent)->id)->toBe($child->id);
    expect($vm->refresh()->node_id)->toBe($node->id);
    expect($node->refresh()->status->value)->toBe($status);
})->with([['task_vm.provision', 'provisioning'], ['task_vm.enroll', 'provisioning'], ['task_vm.enroll', 'failed']]);

it('refuses undeclared ownership and foreign enrollment identity before recording a Node operation', function (string $case): void {
    [$parent, $node, $vm, $intent] = nodeLifecycleParent(parentKind: $case === 'parent kind' ? 'fixture.node-parent' : 'task_vm.provision');
    match ($case) {
        'parent kind' => null,
        'target type' => $parent->update(['target_type' => 'node']),
        'vm relation' => $vm->update(['node_id' => null]),
        'fleet Node' => $node->update(['status' => 'active']),
        'vm name' => $vm->update(['name' => 'different-vm']),
        'vm address' => $vm->update(['address' => '192.0.2.99']),
        'vm tunnel' => $vm->update(['wireguard_ip' => '10.44.0.199']),
        'taskVmId' => $intent['input']['taskVmId'] = $vm->id + 1,
        'roles' => $intent['input']['roles'] = ['unknown-role'],
        'foreign role' => $intent['input']['roles'] = ['gateway'],
        'name' => $intent['input']['name'] = 'unrecorded-target',
        'preparation' => $parent->children()->where('child_key', 'prepare-enrollment')->delete(),
    };
    $parent->update(['input' => ['_node_plan' => [['type' => 'child', ...$intent, 'optional' => false]]], 'state' => ['child_intents' => [$intent]]]);

    expect(fn () => admitNodeLifecycleChild($parent))->toThrow(ResourceOperationException::class);
    expect(Operation::query()->where('kind', 'node.add')->count())->toBe(0);
    expect($vm->fresh()->node_id)->toBe($case === 'vm relation' ? null : $node->id);
    $this->assertDatabaseMissing('nodes', ['name' => 'unrecorded-target']);
})->with(['parent kind', 'target type', 'vm relation', 'fleet Node', 'vm name', 'vm address', 'vm tunnel', 'taskVmId', 'roles', 'foreign role', 'name', 'preparation']);

it('refuses root enrollment from attaching a task VM to an existing fleet Node', function (): void {
    [$parent, $node, $vm] = nodeLifecycleParent();
    $node->update(['status' => 'active']);
    expect(fn () => app(ProvisionNodeAction::class)->start(new ProvisionNodeData(name: $node->name, publicSshHost: $node->public_ssh_host,
        expectedSshHostFingerprint: $node->ssh_host_fingerprint, taskVmId: $vm->id)))
        ->toThrow(fn (ResourceOperationException $error) => expect($error->errorCode)->toBe('operation.child_forbidden'));
    expect(Operation::query()->where('kind', 'node.add')->count())->toBe(0);
});

it('admits removal only after stamped VM deletion proof without deleting the Node at admission', function (): void {
    [$parent, $node, , $intent] = nodeLifecycleParent(true);
    $child = admitNodeLifecycleChild($parent);

    expect($child->child_admission['intent'])->toBe($intent);
    expect($child->kind)->toBe('node.remove');
    expect($child->parent_operation_id)->toBe($parent->id);
    expect($child->input['deleted'])->toBeTrue();
    $this->assertModelExists($node);
});

it('refuses unproven deletion and foreign VM ownership before skipping reachability', function (string $case): void {
    [$parent, $node, $vm] = nodeLifecycleParent(true, $case === 'parent kind' ? 'fixture.node-parent' : 'task_vm.destroy');
    $deletion = $parent->children()->where('child_key', 'provider-delete')->sole();
    match ($case) {
        'parent kind' => null,
        'vm relation' => $vm->update(['node_id' => null]),
        'missing' => $deletion->delete(),
        'failed' => $deletion->update(['status' => 'failed']),
        'forged' => $deletion->update(['target_id' => $vm->id + 1]),
        'unready' => $deletion->update(['result' => ['ready' => false]]),
        'wrong provider' => [$deletion->delete(), nodeLifecycleEvidence($parent, 'provider-delete', 'task_vm.upcloud_delete', $vm->id, $parent->node_id, ['ready' => true])],
        'wrong VM' => [$deletion->delete(), nodeLifecycleEvidence($parent, 'provider-delete', 'task_vm.incus_delete', $vm->id + 1, $parent->node_id, ['ready' => true])],
    };

    expect(fn () => admitNodeLifecycleChild($parent))->toThrow(ResourceOperationException::class);
    expect(Operation::query()->where('kind', 'node.remove')->count())->toBe(0);
    $this->assertModelExists($node);
})->with(['parent kind', 'vm relation', 'missing', 'failed', 'forged', 'unready', 'wrong provider', 'wrong VM']);
