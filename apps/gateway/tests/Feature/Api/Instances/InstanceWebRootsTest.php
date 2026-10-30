<?php

declare(strict_types=1);

use App\Actions\Operations\StartOperationAction;
use App\Domain\Operations\DirectOperation;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\AgentCommands\AgentDelivery;
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
    $this->operation->update(['state' => ['instance_writer' => 'direct', 'swamp_run_id' => $this->body['run_id']]]);
    $this->node->update(['architecture' => 'x86_64']);
    $this->manifest = tempnam(sys_get_temp_dir(), 'web-roots-agent-');
    file_put_contents($this->manifest, json_encode(['commit' => str_repeat('b', 40), 'version' => '0.5.0', 'artifacts' => ['linux-x86_64' => ['sha256' => str_repeat('a', 64)]]], JSON_THROW_ON_ERROR));
    app()->instance(AgentDelivery::class, new AgentDelivery($this->manifest, sys_get_temp_dir()));
    $this->result = ['instance_id' => $this->instance->id, 'confirmed' => true, 'no_op' => false];
    $this->finish = [...$this->body, 'status' => 'succeeded', 'started_at' => '2026-10-30T12:00:00.000Z', 'finished_at' => '2026-10-30T12:00:01.000Z', 'result' => $this->result];
    $this->agents = new FakeAgentCommandRunner()->answer('instance.prepare-served-web-roots', [
        'name' => '20261030120000-canary', 'path' => '/home/deployment-api/releases/20261030120000-canary', 'commit' => str_repeat('a', 40)]);
    app()->instance(AgentCommandRunner::class, $this->agents);
});

afterEach(function (): void {
    unlink($this->manifest);
});

it('returns only admitted Node input and refuses a Gateway execution relay', function (): void {
    $response = $this->getJson($this->path.'?run_id='.$this->body['run_id'])->assertOk()
        ->assertJsonPath('data.input.instance_id', $this->instance->id)
        ->assertJsonPath('data.input.agent_sha256', str_repeat('a', 64))
        ->assertJsonPath('data.input.agent_input.instance', $this->instance->id)
        ->assertJsonPath('data.input.agent_input.home', '/home/deployment-api')
        ->assertJsonPath('data.input.agent_input.served', [['web_root' => 'other/public', 'directory' => 'other']]);
    expect($response->json('data.input.agent_input'))->not->toHaveKeys(['command', 'env', 'content', 'socket', 'worker']);
    $this->postJson($this->path.'/primitive', $this->body)->assertUnprocessable()
        ->assertJsonPath('error.code', 'operation.execution_forbidden');
    expect($this->agents->calls)->toBe([]);
});

it('refuses changed Route ownership or Instance placement before returning local input', function (): void {
    $this->parent->update(['input' => ['route_id' => $this->route->id, 'target_instance_ids' => []]]);
    $this->getJson($this->path.'?run_id='.$this->body['run_id'])->assertUnprocessable();
    $this->parent->update(['input' => ['route_id' => $this->route->id, 'target_instance_ids' => [$this->instance->id]]]);
    $this->operation->update(['node_id' => $this->worker->id]);
    $this->getJson($this->path.'?run_id='.$this->body['run_id'])->assertUnprocessable();
    expect($this->agents->calls)->toBe([]);
});

it('allows only the matching Node worker to read and project the bound run', function (): void {
    config(['operation_executor.worker_node_ids' => [$this->node->id]]);
    $gateway = Node::query()->whereHas('roles', fn ($query) => $query->where('role', 'gateway'))->firstOrFail();
    $this->node->accessibleNodes()->attach($gateway);
    $this->withServerVariables(['REMOTE_ADDR' => $this->node->wireguard_ip]);
    $this->getJson($this->path.'?run_id='.$this->body['run_id'])->assertOk();
    $this->postJson($this->path.'/result', $this->finish)->assertOk();
    $this->operation->update(['node_id' => $this->worker->id]);
    $this->getJson($this->path.'?run_id='.$this->body['run_id'])->assertForbidden();
    $this->postJson($this->path.'/result', $this->finish)->assertForbidden();
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

it('accepts only a matching bounded local failure', function (): void {
    $failed = [...$this->finish, 'status' => 'failed', 'error_code' => 'route.web_root_release_missing', 'result' => ['error_code' => 'instance.release_invalid']];
    $this->postJson($this->path.'/result', $failed)->assertUnprocessable();
    $this->postJson($this->path.'/result', [...$failed, 'result' => ['error_code' => 'route.web_root_release_missing']])->assertOk();
});

it('stamps the per-kind writer and starts only its declared workflow with the shared run token', function (): void {
    $socket = new FakeRoutePublicationSocket;
    $socket->reply = function (array $request): array {
        $operation = Operation::query()->findOrFail($request['payload']['inputs']['operation_id']);
        $operation->forceFill(['parent_operation_id' => $this->parent->id])->save();
        $path = '/api/v1/direct-operations/'.$operation->id;
        $this->getJson($path.'?run_id='.$this->body['run_id'])->assertConflict()->assertJsonPath('error.code', 'operation.run_unbound');
        $this->postJson($path.'/result', $this->finish)->assertConflict()->assertJsonPath('error.code', 'operation.run_unbound');
        expect($operation->fresh()->state)->not->toHaveKey('swamp_run_id');

        return [['type' => 'event', 'id' => $request['id'], 'event' => [
            'kind' => 'started', 'workflowName' => 'operation-instance-web-roots', 'runId' => $this->body['run_id']]]];
    };
    app()->instance(WebSocketClient::class, $socket);
    $operation = app(StartOperationAction::class)->execute('instance.prepare_served_web_roots', 'instance', $this->instance->id, $this->node->id, ['instance_id' => $this->instance->id], 'test', null);
    expect($socket->sent)->toHaveCount(1);
    expect($socket->sent[0]['payload'])->toBe(['workflowIdOrName' => 'operation-instance-web-roots', 'inputs' => [
        'operation_id' => $operation->id, 'kind' => $operation->kind, 'node_id' => $this->node->id]]);
    expect($socket->endpoint->bearerToken)->toBe('private.fixture');
    expect($operation->fresh()->state['instance_writer'])->toBe('direct');
    expect($operation->fresh()->state['swamp_run_id'])->toBe($this->body['run_id']);
    $this->getJson('/api/v1/direct-operations/'.$operation->id.'?run_id='.$this->body['run_id'])->assertOk();
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
    $this->getJson($this->path.'?run_id='.$this->body['run_id'])->assertOk()->assertJsonPath('data.input', ['instance_id' => $this->instance->id, 'agent_sha256' => null, 'agent_input' => null]);
    expect($this->agents->calls)->toBe([]);
});

it('projects cancellation without a Node call or an execution receipt', function (): void {
    $cancelled = [...$this->finish, 'status' => 'cancelled', 'result' => []];
    $this->postJson($this->path.'/result', $cancelled)->assertOk()->assertJsonPath('data.status', 'cancelled');
    $this->postJson($this->path.'/primitive', $this->body)->assertConflict();
    expect($this->agents->calls)->toBe([]);
});

it('refuses worker read and projection before the Gateway start response binds the run', function (): void {
    $this->operation->update(['state' => ['instance_writer' => 'direct']]);
    $this->getJson($this->path.'?run_id='.$this->body['run_id'])->assertConflict()->assertJsonPath('error.code', 'operation.run_unbound');
    $this->postJson($this->path.'/result', $this->finish)->assertConflict()->assertJsonPath('error.code', 'operation.run_unbound');
    expect($this->operation->fresh()->state)->not->toHaveKey('swamp_run_id');
    expect($this->operation->fresh()->status->value)->toBe('queued');
});
