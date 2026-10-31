<?php

declare(strict_types=1);

use App\Domain\Nodes\NodeRoleDirectInput;
use App\Domain\Nodes\NodeRoleIntent;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Nodes\NodeAgentHashSource;
use App\Models\Node;
use App\Models\NodeRole;
use App\Models\Operation;
use Tests\Support\Fleet\FleetFixtures;

function roleHashSourceOperation(string $kind = 'node.role_add'): Operation
{
    FleetFixtures::bind();
    $node = FleetFixtures::node('role-hash-owner', [RoleName::Database]);
    $node->forceFill(['agent_secret_hash' => str_repeat('e', 64)])->save();
    $assignment = $node->roles->sole();
    $snapshot = app(NodeRoleDirectInput::class)->capture($node, $assignment, false);

    return new Operation(['kind' => $kind, 'target_type' => 'node', 'target_id' => $node->id, 'node_id' => $node->id,
        'status' => 'running', 'parent_operation_id' => null,
        'input' => ['node_id' => $node->id, 'role' => 'database', '_role' => $snapshot,
            'secret_hash' => ['$secret' => 'node.agent_secret_hash', 'id' => $node->id]],
        'state' => ['node_role_writer' => 'direct', 'swamp_run_id' => 'a075eb23-7ac4-4487-80d1-57a038a92676',
            'role_intent' => NodeRoleIntent::capture($assignment, false)]]);
}

it('reads the existing hash privately for a bound role root but cannot store one', function (string $kind): void {
    $operation = roleHashSourceOperation($kind);
    $source = app(NodeAgentHashSource::class);
    expect($source->ownerId($operation))->toBe($operation->node_id);
    $secret = $source->resolve($operation, $operation->node_id, '', 'secret_hash');
    expect($secret->value)->toBe(str_repeat('e', 64))
        ->and(json_encode($secret))->not->toContain(str_repeat('e', 64));
    expect(fn () => $source->store($operation, $operation->node_id, str_repeat('f', 64)))
        ->toThrow(ResourceOperationException::class);
    expect(Node::query()->findOrFail($operation->node_id)->agent_secret_hash)->toBe(str_repeat('e', 64));
})->with(['node.role_add', 'node.role_remove']);

it('refuses foreign owners and undeclared parameters for role hash reads', function (): void {
    $operation = roleHashSourceOperation();
    $source = app(NodeAgentHashSource::class);
    expect(fn () => $source->resolve($operation, $operation->node_id + 1, '', 'secret_hash'))
        ->toThrow(ResourceOperationException::class);
    expect(fn () => $source->resolve($operation, $operation->node_id, '', 'worker_credentials'))
        ->toThrow(ResourceOperationException::class);
});

it('reuses canonical refusal for role owner drift before reading protected bytes', function (string $drift): void {
    $operation = roleHashSourceOperation();
    $input = $operation->input;
    $state = $operation->state;
    match ($drift) {
        'parent' => $operation->parent_operation_id = 1,
        'terminal' => $operation->status = 'succeeded',
        'polled' => $state['node_role_writer'] = 'polled',
        'pin' => $input['_role']['footprint']['agent_sha256'] = str_repeat('b', 64),
        'assignment' => NodeRole::query()->whereKey($input['_role']['assignment_id'])->delete(),
    };
    $operation->input = $input;
    $operation->state = $state;
    $source = app(NodeAgentHashSource::class);
    expect($source->ownerId($operation))->toBeNull();
    expect(fn () => $source->resolve($operation, $operation->node_id, '', 'secret_hash'))
        ->toThrow(ResourceOperationException::class);
})->with(['parent', 'terminal', 'polled', 'pin', 'assignment']);
