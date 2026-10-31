<?php

declare(strict_types=1);

use App\Actions\Operations\StartOperationAction;
use App\Actions\Tools\ConvergeToolManagerAction;
use App\Actions\Tools\StartToolOperationAction;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\OperationIdempotency;
use App\Domain\Operations\OperationStore;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tools\ToolManagerName;
use App\Infrastructure\AgentCommands\AgentDelivery;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Models\Node;
use App\Models\Operation;
use App\Models\TaskVm;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeRoutePublicationSocket;

it('stamps only proven root APT intents on Node 209 as direct', function (string $kind): void {
    scopedToolNode(209);
    config(['swamp.direct_writers' => [$kind => 'direct']]);
    $operation = scopedToolStore($kind, 209, ['manager' => 'apt', 'tool_status' => 'installed', 'tool_id' => 7]);

    expect($operation->state['tool_writer'])->toBe('direct');
    expect($operation->node_id)->toBe(209);
    expect($operation->parent_operation_id)->toBeNull();
    expect($operation->lock_key)->toBeNull();
})->with(['tool.manager_converge']);

it('keeps missing or unsupported admitted scope values polled', function (string $kind, ?int $node, array $input): void {
    if ($node !== null) {
        scopedToolNode($node);
    }
    config(['swamp.direct_writers' => [$kind => 'direct']]);
    $operation = scopedToolStore($kind, $node, $input);

    expect($operation->state['tool_writer'])->toBe('polled');
    expect($operation->lock_key)->toBe('node_tools:209');
})->with([
    'foreign Node' => ['tool.manager_converge', 210, ['manager' => 'apt']],
    'missing Node' => ['tool.manager_converge', null, ['manager' => 'apt']],
    'foreign manager' => ['tool.manager_converge', 209, ['manager' => 'vp']],
    'missing manager' => ['tool.manager_converge', 209, []],
    'manager case' => ['tool.manager_converge', 209, ['manager' => 'APT']],
    'manager whitespace' => ['tool.manager_converge', 209, ['manager' => 'apt ']],
    'manager wrong type' => ['tool.manager_converge', 209, ['manager' => true]],

]);

it('refuses foreign missing or child targets for each converted Tool kind', function (string $kind): void {
    scopedToolNode(209);
    $direct = app(DirectOperation::class);
    expect($direct->writer($kind, ['manager' => 'vp'], 209))->toBe('direct');
    expect(fn () => $direct->writer($kind, ['node_id' => 209], 210))->toThrow(ResourceOperationException::class);
    expect(fn () => $direct->writer($kind))->toThrow(ResourceOperationException::class);
    app(OperationIdempotency::class)->parent = new Operation()->forceFill(['id' => 1]);
    expect(fn () => $direct->writer($kind, ['parent_operation_id' => null], 209))->toThrow(ResourceOperationException::class);
    $this->assertDatabaseCount('operations', 0);
})->with(['tool.install', 'tool.update', 'tool.remove', 'tool.adopt']);

it('takes parent identity from the child admission context before the row is stamped', function (string $kind): void {
    scopedToolNode(209);
    $parent = Operation::query()->create(['kind' => 'node.add', 'target_type' => 'node', 'target_id' => 209,
        'node_id' => 209, 'status' => 'running', 'input' => [], 'sequence' => 1]);
    $input = ['manager' => 'apt', 'tool_status' => 'installed', 'node_id' => 209, 'parent_operation_id' => null];
    $intent = ['key' => 'manager', 'kind' => $kind, 'node_id' => 209, 'input' => $input];
    config(['swamp.direct_writers' => [$kind => 'direct']]);
    $operation = app(OperationIdempotency::class)->duringChild($parent, $intent, fn (): Operation => scopedToolStore($kind, 209, $input));

    expect($operation->parent_operation_id)->toBe($parent->id);
    expect($operation->state['tool_writer'])->toBe('polled');
})->with(['tool.manager_converge']);

it('keeps the admitted writer through declaration changes and idempotent replay', function (string $writer): void {
    scopedToolNode(209);
    $kind = 'tool.manager_converge';
    $context = app(OperationIdempotency::class);
    $context->kind = $kind;
    $context->key = 'scope-replay';
    $context->fingerprint = 'admitted-intent';
    config(['swamp.direct_writers' => [$kind => $writer]]);
    $operation = scopedToolStore($kind, 209, ['manager' => 'apt']);
    if ($writer === 'direct') {
        $operation->update(['state' => [...$operation->state, 'swamp_run_id' => 'a075eb23-7ac4-4487-80d1-57a038a92676'], 'dispatched_at' => now()]);
    }
    config(['swamp.direct_writers' => [$kind => $writer === 'direct' ? 'polled' : 'direct']]);
    $definition = app(DirectOperation::class)->definition($kind);
    $definition['writer_scope']['node_id'] = 210;
    $reflection = new ReflectionClass(DirectOperation::class);
    $changed = $reflection->newInstanceWithoutConstructor();
    $reflection->getProperty('definitions')->setValue($changed, [$kind => $definition]);
    app()->instance(DirectOperation::class, $changed);

    $replay = app(StartOperationAction::class)->execute($kind, 'node_tools', 209, 209, ['manager' => 'apt'], 'operator', null);

    expect($replay->id)->toBe($operation->id);
    expect($replay->state['tool_writer'])->toBe($writer);
    expect(DirectOperation::owns($replay))->toBe($writer === 'direct');
    expect(Operation::query()->count())->toBe(1);
})->with(['direct', 'polled']);

it('admits the scoped manager through its owner with the pinned agent', function (): void {
    $node = scopedToolNode(209);
    $socket = scopedToolSocket();
    config(['swamp.direct_writers' => ['tool.manager_converge' => 'direct']]);
    $operation = app(ConvergeToolManagerAction::class)->execute($node, ToolManagerName::Apt);

    expect($operation->state['tool_writer'])->toBe('direct');
    expect($operation->input['agent_sha256'])->toBe(app(AgentDelivery::class)->pin($node));
    expect($socket->sent)->toHaveCount(1);
});

it('admits existing and new package ownership directly through the owning action', function (bool $existing): void {
    $node = scopedToolNode(209);
    $node->toolManagers()->create(['name' => 'apt', 'status' => 'active']);
    if ($existing) {
        $node->tools()->create(['tool_manager_id' => $node->toolManagers()->sole()->id, 'package' => 'gh', 'status' => 'installed', 'installed_version' => '2.46.0']);
    }
    $socket = scopedToolSocket();
    config(['swamp.direct_writers' => ['tool.adopt' => 'direct']]);
    $operation = app(StartToolOperationAction::class)->execute('adopt', $node->id, 'apt', 'gh', null);

    expect($operation->state['tool_writer'])->toBe('direct');
    expect($operation->input['agent_sha256'])->toBe(app(AgentDelivery::class)->pin($node));
    expect($socket->sent)->toHaveCount(1);
})->with(['existing installed package' => true, 'new ownership' => false]);

it('refuses prepared input for another writer before recording or starting an Operation', function (string $configured, string $prepared): void {
    scopedToolNode(209);
    $socket = scopedToolSocket();
    config(['swamp.direct_writers' => ['tool.manager_converge' => $configured]]);

    expect(fn () => app(StartOperationAction::class)->execute('tool.manager_converge', 'node_tools', 209, 209,
        ['manager' => 'apt'], 'operator', null, expectedWriter: $prepared))
        ->toThrow(LogicException::class, 'The prepared Operation input must match its admitted writer.');

    $this->assertDatabaseCount('operations', 0);
    expect($socket->sent)->toBeEmpty();
})->with([
    'direct input with polled admission' => ['polled', 'direct'],
    'polled input with direct admission' => ['direct', 'polled'],
]);

it('refuses when manager input preparation changes the selected writer', function (): void {
    $node = scopedToolNode(209);
    $action = app(ConvergeToolManagerAction::class);
    $delivery = app(AgentDelivery::class);
    $socket = scopedToolSocket();
    config(['swamp.direct_writers' => ['tool.manager_converge' => 'direct']]);
    app()->bind(AgentDelivery::class, function () use ($delivery): AgentDelivery {
        config(['swamp.direct_writers' => ['tool.manager_converge' => 'polled']]);

        return $delivery;
    });

    expect(fn () => $action->execute($node, ToolManagerName::Apt))
        ->toThrow(LogicException::class, 'The prepared Operation input must match its admitted writer.');

    $this->assertDatabaseCount('operations', 0);
    expect($socket->sent)->toBeEmpty();
});

function scopedToolNode(int $id): Node
{
    return Node::query()->forceCreate(['id' => $id, 'name' => 'scope-'.$id, 'public_ssh_host' => '192.0.2.9',
        'user' => 'orbit', 'platform' => 'linux', 'architecture' => 'x86_64', 'wireguard_ip' => '10.44.0.9',
        'ssh_host_fingerprint' => 'SHA256:'.str_repeat('a', 43), 'status' => 'active']);
}

function scopedToolStore(string $kind, ?int $nodeId, array $input): Operation
{
    return app(OperationStore::class)->create($kind, 'node_tools', 209, $nodeId, $input, 'operator', null);
}

function scopedToolSocket(): FakeRoutePublicationSocket
{
    $socket = new FakeRoutePublicationSocket;
    $socket->reply = static fn (array $request): array => [['type' => 'event', 'id' => $request['id'], 'event' => [
        'kind' => 'started', 'workflowName' => 'operation-tool-direct', 'runId' => 'a075eb23-7ac4-4487-80d1-57a038a92676']]];
    app()->instance(WebSocketClient::class, $socket);
    config(['swamp.run_token' => 'fixture-token']);

    return $socket;
}

it('admits manager readiness for a workerless Task VM Node during enrollment', function (string $manager, bool $child): void {
    Queue::fake();
    $host = scopedToolNode(9);
    $host->update(['wireguard_ip' => '10.44.0.90']);
    $node = scopedToolNode(212);
    $node->update(['status' => 'provisioning']);
    $vm = TaskVm::query()->create([
        'host_node_id' => $host->id, 'node_id' => $node->id, 'provider' => 'incus',
        'name' => 'tvm-pending', 'state' => 'provisioning', 'wireguard_ip' => '10.44.0.212',
    ]);
    $vm->update(['name' => 'tvm-'.$vm->id]);
    $node->update(['name' => $vm->name]);
    $socket = scopedToolSocket();
    config(['swamp.direct_writers' => ['tool.manager_converge' => 'direct']]);
    $admit = fn (): Operation => app(ConvergeToolManagerAction::class)->execute($node, ToolManagerName::from($manager), true);
    if ($child) {
        $parent = Operation::query()->create(['kind' => 'node.add', 'target_type' => 'node', 'target_id' => $node->id,
            'node_id' => $node->id, 'status' => 'running', 'input' => [], 'sequence' => 1]);
        $intent = ['key' => 'manager-'.$manager, 'kind' => 'tool.manager_converge', 'node_id' => $node->id,
            'input' => ['manager' => $manager, 'image_provides_manager' => true]];
        $operation = app(OperationIdempotency::class)->duringChild($parent, $intent, $admit);
        expect($operation->parent_operation_id)->toBe($parent->id);
    } else {
        $operation = $admit();
        expect($operation->parent_operation_id)->toBeNull();
    }

    expect($operation->kind)->toBe('tool.manager_converge')
        ->and($operation->node_id)->toBe($node->id)
        ->and($operation->state['tool_writer'])->toBe('polled')
        ->and($operation->input['manager'])->toBe($manager)
        ->and($operation->input)->not->toHaveKeys(['agent_sha256', 'materialize_command'])
        ->and($node->fresh()->status->value)->toBe('provisioning')
        ->and($socket->sent)->toBeEmpty();
})->with(['apt', 'vp', 'composer'])->with([false, true]);
