<?php

declare(strict_types=1);

use App\Actions\Operations\StartOperationAction;
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
        'status' => 'queued', 'sequence' => 1, 'input' => ['instance_id' => $this->instance->id], 'state' => ['instance_writer' => 'direct']]);
    $this->operation->forceFill(['parent_operation_id' => $this->parent->id])->save();
    $this->path = '/api/v1/direct-operations/'.$this->operation->id;
    $this->body = ['run_id' => 'a075eb23-7ac4-4487-80d1-57a038a92676'];
    $this->result = ['instance_id' => $this->instance->id, 'confirmed' => true, 'no_op' => false];
    $this->finish = [...$this->body, 'status' => 'succeeded', 'started_at' => '2026-10-30T12:00:00.000Z', 'finished_at' => '2026-10-30T12:00:01.000Z', 'result' => $this->result];
    $this->agents = new FakeAgentCommandRunner()->answer('instance.prepare-served-web-roots', [
        'name' => '20261030120000-canary', 'path' => '/home/deployment-api/releases/20261030120000-canary', 'commit' => str_repeat('a', 40)]);
    app()->instance(AgentCommandRunner::class, $this->agents);
});

it('prepares only the admitted Instance on its stored Node without execution receipts', function (): void {

    $this->getJson($this->path.'?run_id='.$this->body['run_id'])->assertOk()->assertJsonPath('data.input', ['instance_id' => $this->instance->id]);
    $this->postJson($this->path.'/primitive', [...$this->body, 'instance_id' => 999])->assertOk()->assertJsonPath('data', $this->result);
    expect($this->agents->calls)->toHaveCount(1);
    $call = $this->agents->calls[0];
    expect($call)->toMatchArray(['command' => 'instance.prepare-served-web-roots', 'node' => $this->node->name]);
    expect($call['input']['instance'])->toBe($this->instance->id);
    expect($call['input']['served'])->toContain(['web_root' => 'other/public', 'directory' => 'other']);
    expect($call['input'])->not->toHaveKeys(['effect_key', 'effect_metadata', 'worker', 'env', 'content']);
    expect($this->operation->fresh()->state)->toBe(['instance_writer' => 'direct']);
});

it('refuses changed Route ownership or Instance placement before the Node primitive', function (): void {
    $this->parent->update(['input' => ['route_id' => $this->route->id, 'target_instance_ids' => []]]);
    $this->postJson($this->path.'/primitive', $this->body)->assertUnprocessable();
    $this->parent->update(['input' => ['route_id' => $this->route->id, 'target_instance_ids' => [$this->instance->id]]]);
    $this->operation->update(['node_id' => $this->worker->id]);
    $this->postJson($this->path.'/primitive', $this->body)->assertUnprocessable();
    expect($this->agents->calls)->toBe([]);
});

it('requires executor identity and refuses caller-selected commands and targets', function (): void {
    foreach (['node_id' => $this->worker->id, 'input' => ['env' => 'private'], 'command' => 'arbitrary'] as $key => $value) {
        $this->postJson($this->path.'/primitive', [...$this->body, $key => $value])->assertUnprocessable();
    }
    $this->withServerVariables(['REMOTE_ADDR' => $this->node->wireguard_ip]);
    $this->postJson($this->path.'/primitive', $this->body)->assertForbidden();
    expect($this->agents->calls)->toBe([]);
});

it('projects bounded results and replays terminal status without a claim or proof receipt', function (): void {
    $this->postJson($this->path.'/result', [...$this->body, 'status' => 'running', 'started_at' => $this->finish['started_at']])->assertOk();
    $finish = [...$this->finish, 'result' => [...$this->result, 'password' => 'private-test-password']];
    $this->postJson($this->path.'/result', $finish)->assertOk()->assertJsonPath('data.result', $this->result);
    $sequence = $this->operation->fresh()->sequence;
    $this->postJson($this->path.'/result', $finish)->assertOk();
    $operation = $this->operation->fresh();
    expect($operation->sequence)->toBe($sequence);
    expect($operation->attempts)->toBe(0);
    expect($operation->worker)->toBeNull();
    expect($operation->state)->toHaveKeys(['instance_writer', 'swamp_run_id', 'steps'])->not->toHaveKeys(['executor_runs', 'executor_lease_node_id']);
    expect(json_encode($operation->toArray()))->not->toContain('private-test-password');
    $this->postJson($this->path.'/primitive', $this->body)->assertConflict();
    expect($this->agents->calls)->toBe([]);
});

it('rejects false success another Instance another run and conflicting terminal results', function (): void {
    foreach ([[], [...$this->result, 'confirmed' => false], [...$this->result, 'confirmed' => 1], [...$this->result, 'no_op' => 'true'], [...$this->result, 'instance_id' => (string) $this->instance->id], [...$this->result, 'instance_id' => 999], [...$this->result, 'error_code' => 'instance.release_invalid']] as $result) {
        $this->postJson($this->path.'/result', [...$this->finish, 'result' => $result])->assertUnprocessable();
    }
    $this->postJson($this->path.'/result', $this->finish)->assertOk();
    $this->postJson($this->path.'/result', [...$this->finish, 'result' => [...$this->result, 'no_op' => true]])->assertConflict();
    $this->getJson($this->path.'?run_id=fabd4059-fb6c-49d8-940e-01ae58aa3f38')->assertConflict();
});

it('maps a missing selected release and accepts only a matching bounded failure', function (): void {
    $this->agents->refuse('instance.prepare-served-web-roots', 'instance.release_missing');
    $this->postJson($this->path.'/primitive', $this->body)->assertOk()->assertJsonPath('data.error_code', 'route.web_root_release_missing');
    $failed = [...$this->finish, 'status' => 'failed', 'error_code' => 'route.web_root_release_missing', 'result' => ['error_code' => 'instance.release_invalid']];
    $this->postJson($this->path.'/result', $failed)->assertUnprocessable();
    $this->postJson($this->path.'/result', [...$failed, 'result' => ['error_code' => 'route.web_root_release_missing']])->assertOk();
});

it('stamps the per-kind writer and starts only its declared workflow with the shared run token', function (): void {
    $socket = new FakeRoutePublicationSocket;
    $socket->reply = static fn (array $request): array => [['type' => 'event', 'id' => $request['id'], 'event' => [
        'kind' => 'started', 'workflowName' => 'operation-instance-web-roots', 'runId' => 'a075eb23-7ac4-4487-80d1-57a038a92676']]];
    app()->instance(WebSocketClient::class, $socket);
    $operation = app(StartOperationAction::class)->execute('instance.prepare_served_web_roots', 'instance', $this->instance->id, $this->node->id, ['instance_id' => $this->instance->id], 'test', null);
    expect($socket->sent)->toHaveCount(1);
    expect($socket->sent[0]['payload'])->toBe(['workflowIdOrName' => 'operation-instance-web-roots', 'inputs' => [
        'operation_id' => $operation->id, 'kind' => $operation->kind, 'node_id' => $this->node->id]]);
    expect($socket->endpoint->bearerToken)->toBe('private.fixture');
    expect($operation->fresh()->state['instance_writer'])->toBe('direct');
    expect($operation->fresh()->lock_key)->toBeNull();
    config(['swamp.direct_writers' => []]);
    expect(DirectOperation::owns($operation->fresh()))->toBeTrue();
    $this->postJson('/api/v1/operation-executor/claim', ['kind' => $operation->kind, 'worker' => 'previous', 'operation_id' => $operation->id])->assertOk()->assertJsonPath('data', null);
});

it('keeps unstamped and polled intents on the previous Swamp path', function (): void {
    $this->operation->update(['state' => null]);
    $this->postJson($this->path.'/primitive', $this->body)->assertUnprocessable();
    config(['swamp.direct_writers' => []]);
    $polled = app(StartOperationAction::class)->execute('instance.prepare_served_web_roots', 'instance', $this->instance->id, $this->node->id, ['instance_id' => $this->instance->id], 'test', null);
    expect($polled->state['instance_writer'])->toBe('polled');
    expect($polled->lock_key)->not->toBeNull();
    config(['swamp.direct_writers' => ['instance.prepare_served_web_roots' => 'php']]);
    expect(fn () => app(StartOperationAction::class)->execute('instance.prepare_served_web_roots', 'instance', 999, $this->node->id, [], 'test', null))->toThrow(ResourceOperationException::class);
    expect($this->agents->calls)->toBe([]);
});

it('returns a no-op for the primary root without a Node mutation', function (): void {
    $this->route->update(['web_root' => 'public']);
    $this->postJson($this->path.'/primitive', $this->body)->assertOk()->assertJsonPath('data', [...$this->result, 'no_op' => true]);
    expect($this->agents->calls)->toBe([]);
});

it('projects cancellation without a Node call or an execution receipt', function (): void {
    $cancelled = [...$this->finish, 'status' => 'cancelled', 'result' => []];
    $this->postJson($this->path.'/result', $cancelled)->assertOk()->assertJsonPath('data.status', 'cancelled');
    $this->postJson($this->path.'/primitive', $this->body)->assertConflict();
    expect($this->agents->calls)->toBe([]);
});
