<?php

declare(strict_types=1);

use App\Actions\Operations\StartOperationAction;
use App\Domain\Instances\Environment\InstanceServedWebRootsExecution;
use App\Domain\Instances\Environment\InstanceServedWebRootsInput;
use App\Domain\Operations\DirectOperation;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Route;
use Tests\Support\FakeAgentCommandRunner;
use Tests\Support\FakeRoutePublicationSocket;

beforeEach(function (): void {
    [$gateway, $this->node, , $this->instance] = deployment_api_fixture();
    $this->worker = Node::query()->create(['name' => 'services', 'public_ssh_host' => '192.0.2.17', 'user' => 'orbit',
        'platform' => 'linux', 'wireguard_ip' => '10.44.0.17', 'status' => 'active']);
    $this->worker->accessibleNodes()->attach($gateway);
    config(['dependency_detection.services_node_id' => $this->worker->id,
        'swamp.direct_writers' => ['instance.prepare_served_web_roots' => 'direct'], 'swamp.run_token' => 'private.fixture']);
    $this->withServerVariables(['REMOTE_ADDR' => $this->worker->wireguard_ip]);
    $this->route = Route::query()->create(['project_id' => $this->instance->project_id, 'node_id' => $this->node->id,
        'domain' => 'canary.example.test', 'web_root' => 'other/public', 'provenance' => 'explicit', 'publication' => 'public', 'status' => 'pending']);
    $this->parent = Operation::query()->create(['kind' => 'route.create', 'target_type' => 'route', 'target_id' => $this->route->id,
        'node_id' => $this->node->id, 'status' => 'running', 'sequence' => 1,
        'input' => ['route_id' => $this->route->id, 'target_instance_ids' => [$this->instance->id]]]);
    $this->operation = Operation::query()->create(['kind' => 'instance.prepare_served_web_roots', 'target_type' => 'instance',
        'target_id' => $this->instance->id, 'node_id' => $this->node->id, 'parent_operation_id' => $this->parent->id,
        'status' => 'queued', 'sequence' => 1, 'input' => ['instance_id' => $this->instance->id]]);
    $this->operation->forceFill(['parent_operation_id' => $this->parent->id])->save();
    $this->socket = new FakeRoutePublicationSocket;
    $this->socket->reply = static fn (array $request): array => [['type' => 'event', 'id' => $request['id'], 'event' => [
        'kind' => 'started', 'workflowName' => 'operation-instance-web-roots', 'runId' => 'a075eb23-7ac4-4487-80d1-57a038a92676']]];
    app()->instance(WebSocketClient::class, $this->socket);
    $this->agents = new FakeAgentCommandRunner;
    app()->instance(AgentCommandRunner::class, $this->agents);
});

it('keeps one web-root dispatch when a stale direct-writer setting remains', function (): void {
    $operation = app(StartOperationAction::class)->execute('instance.prepare_served_web_roots', 'instance', $this->instance->id, $this->node->id, ['instance_id' => $this->instance->id], 'test', null);
    expect($this->socket->sent)->toBe([])
        ->and(DirectOperation::owns($operation))->toBeFalse()
        ->and($operation->state)->not->toHaveKeys(['instance_writer', 'swamp_run_id'])
        ->and($operation->lock_key)->not->toBeNull()
        ->and($this->agents->calls)->toBe([]);
});

it('retains admitted Route ownership and the current native preparation input', function (): void {
    $context = app(InstanceServedWebRootsExecution::class)->context($this->operation);
    expect($context['descriptors'][0]['name'])->toBe('instance.prepare-served-web-roots')
        ->and($context['descriptors'][0]['input'])->toMatchArray([
            'instance' => $this->instance->id, 'home' => '/home/deployment-api',
            'served' => [['web_root' => 'other/public', 'directory' => 'other']],
        ])
        ->and($context['descriptors'][1]['name'])->toBe('instance.web-roots-receipt')
        ->and($this->agents->calls)->toBe([]);
});

it('refuses changed Route ownership or Instance placement before preparation', function (string $change): void {
    if ($change === 'parent') {
        $this->parent->update(['input' => ['route_id' => $this->route->id, 'target_instance_ids' => []]]);
    } else {
        $this->operation->update(['node_id' => $this->worker->id]);
    }
    expect(fn () => app(InstanceServedWebRootsInput::class)->forOperation($this->operation))->toThrow(ResourceOperationException::class)
        ->and($this->agents->calls)->toBe([]);
})->with(['parent', 'node']);

it('keeps primary-root preparation a no-op without a Node call', function (): void {
    $this->route->update(['web_root' => 'public']);
    $execution = app(InstanceServedWebRootsExecution::class);
    expect($execution->context($this->operation)['descriptors'])->toHaveCount(1)
        ->and($execution->result($this->operation))->toBe(['instance_id' => $this->instance->id, 'confirmed' => true, 'no_op' => true])
        ->and($this->agents->calls)->toBe([]);
});
