<?php

declare(strict_types=1);

use App\Domain\Nodes\NodeLifecycleOperationExecution;
use App\Domain\Operations\OperationChildren;
use App\Domain\Operations\OperationKinds;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\Operation;
use Illuminate\Support\Facades\DB;
use Tests\Support\Fleet\FleetFixtures;

beforeEach(function (): void {
    $keys = Mockery::mock(SshKeyProvider::class);
    $keys->shouldReceive('publicKey')->andReturn('ssh-ed25519 fixture-key');
    $keys->shouldNotReceive('privateKeyPath');
    app()->instance(SshKeyProvider::class, $keys);
});

function nodeLifecycleParent(array $intent): Operation
{
    $definitions = OperationKinds::definitions();
    $definitions['fixture.node-parent'] = [...$definitions['node.add'], 'execution' => NodeLifecycleOperationExecution::class,
        'children' => [['kind' => $intent['kind'], 'node_from' => 'intent.node_ids']], 'compensations' => []];
    unset($definitions['fixture.node-parent']['compensations'], $definitions['fixture.node-parent']['cancellation_boundary']);
    app()->instance(OperationKinds::class, new OperationKinds(app(), $definitions));

    return Operation::query()->create(['kind' => 'fixture.node-parent', 'target_type' => 'task_vm', 'target_id' => 123,
        'node_id' => $intent['node_id'], 'input' => ['_node_plan' => [['type' => 'child', ...$intent, 'optional' => false]]],
        'state' => ['child_intents' => [$intent]], 'status' => 'running', 'sequence' => 1]);
}

it('admits a reserved enrollment Node as an exact stamped child and replays it', function (): void {
    $node = FleetFixtures::node('reserved-child');
    $intent = ['key' => 'node-enrollment', 'kind' => 'node.add', 'node_id' => $node->id,
        'input' => ['name' => $node->name, 'publicSshHost' => $node->public_ssh_host, 'expectedSshHostFingerprint' => $node->ssh_host_fingerprint]];
    $parent = nodeLifecycleParent($intent);
    $children = app(OperationChildren::class);
    $child = DB::transaction(fn () => $children->admit($parent, ['key' => $intent['key']]))['operation'];

    expect($child->kind)->toBe('node.add');
    expect($child->node_id)->toBe($node->id);
    expect($child->parent_operation_id)->toBe($parent->id);
    expect($child->child_admission['intent'])->toBe($intent);
    expect(DB::transaction(fn () => $children->admit($parent, ['key' => $intent['key']]))['operation']->id)->toBe($child->id);
    expect($node->refresh()->status->value)->toBe('active');
});

it('refuses a different enrollment name before reserving another Node', function (): void {
    $node = FleetFixtures::node('reserved-refusal');
    $parent = nodeLifecycleParent(['key' => 'node-enrollment', 'kind' => 'node.add', 'node_id' => $node->id,
        'input' => ['name' => 'unrecorded-target', 'publicSshHost' => '192.0.2.10']]);
    expect(fn () => DB::transaction(fn () => app(OperationChildren::class)->admit($parent, ['key' => 'node-enrollment'])))
        ->toThrow(fn (ResourceOperationException $error) => expect($error->errorCode)->toBe('operation.child_forbidden'));
    $this->assertDatabaseMissing('nodes', ['name' => 'unrecorded-target']);
});

it('admits removal through its owner without deleting the Node', function (): void {
    $caller = FleetFixtures::node('child-remove-caller');
    $node = FleetFixtures::node('child-remove-target');
    $intent = ['key' => 'node-removal', 'kind' => 'node.remove', 'node_id' => $node->id,
        'input' => ['caller_node_id' => $caller->id, 'offline' => true, 'force' => true, 'deleted' => true]];
    $parent = nodeLifecycleParent($intent);
    $child = DB::transaction(fn () => app(OperationChildren::class)->admit($parent, ['key' => 'node-removal']))['operation'];

    expect($child->child_admission['intent'])->toBe($intent);
    expect($child->kind)->toBe('node.remove');
    expect($child->parent_operation_id)->toBe($parent->id);
    $this->assertModelExists($node);
});
