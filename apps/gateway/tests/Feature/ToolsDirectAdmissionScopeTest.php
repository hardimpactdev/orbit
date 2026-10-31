<?php

declare(strict_types=1);

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
use Tests\Support\FakeRoutePublicationSocket;

it('stamps each converted Tool kind directly without a target hold', function (string $kind): void {
    scopedToolNode(209);
    $operation = scopedToolStore($kind, 209, ['manager' => 'apt']);

    expect($operation->state['tool_writer'])->toBe('direct');
    expect($operation->parent_operation_id)->toBeNull();
    expect($operation->lock_key)->toBeNull();
})->with(['tool.install', 'tool.update', 'tool.remove', 'tool.adopt', 'tool.manager_converge']);

it('keeps the other Tool kinds scoped to standalone Node 209', function (string $kind): void {
    scopedToolNode(209);
    $direct = app(DirectOperation::class);
    expect($direct->writer($kind, ['manager' => 'vp'], 209))->toBe('direct');
    expect(fn () => $direct->writer($kind, ['node_id' => 209], 210))->toThrow(ResourceOperationException::class);
    expect(fn () => $direct->writer($kind))->toThrow(ResourceOperationException::class);
    app(OperationIdempotency::class)->parent = new Operation()->forceFill(['id' => 1]);
    expect(fn () => $direct->writer($kind, ['parent_operation_id' => null], 209))->toThrow(ResourceOperationException::class);
    $this->assertDatabaseCount('operations', 0);
})->with(['tool.update', 'tool.remove', 'tool.adopt', 'tool.manager_converge']);

it('starts a standalone install on its admitted Node with that account and agent pin', function (int $id, string $user): void {
    $node = scopedToolNode($id);
    $node->update(['user' => $user]);
    $socket = new FakeRoutePublicationSocket;
    app()->instance(WebSocketClient::class, $socket);
    config(['swamp.run_token' => 'fixture-token']);

    $operation = app(StartToolOperationAction::class)->execute('install', $id, 'apt', 'hello', null);

    expect($operation->node_id)->toBe($id);
    expect($operation->target_id)->toBe($id);
    expect($operation->input['user'])->toBe($user);
    expect($operation->input['agent_sha256'])->toBe(app(AgentDelivery::class)->pin($node));
    expect($operation->parent_operation_id)->toBeNull();
    expect($socket->sent)->toHaveCount(1);
    expect($socket->sent[0]['payload']['workflowIdOrName'])->toBe('operation-tool-install-direct');
    expect($socket->sent[0]['payload']['inputs'])->toMatchArray(['node_id' => $id, 'kind' => 'tool.install']);
})->with([[14, 'orbit'], [9, 'nckrtl'], [209, 'orbit']]);

it('refuses a child install before recording or starting it', function (): void {
    scopedToolNode(14);
    $socket = scopedToolSocket();
    app(OperationIdempotency::class)->parent = new Operation()->forceFill(['id' => 1]);

    expect(fn () => app(StartToolOperationAction::class)->execute('install', 14, 'apt', 'hello', null))
        ->toThrow(ResourceOperationException::class);

    $this->assertDatabaseCount('operations', 0);
    expect($socket->sent)->toBeEmpty();
});

it('refuses a disabled direct writer before recording work', function (string $kind): void {
    scopedToolNode(209);
    config(['swamp.direct_writers' => [$kind => 'polled']]);
    expect(fn () => scopedToolStore($kind, 209, ['manager' => 'apt']))->toThrow(ResourceOperationException::class);
    $this->assertDatabaseCount('operations', 0);
})->with(['tool.install', 'tool.update', 'tool.remove', 'tool.adopt', 'tool.manager_converge']);

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
        ->toThrow(ResourceOperationException::class);

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
