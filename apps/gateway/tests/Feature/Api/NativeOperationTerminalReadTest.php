<?php

declare(strict_types=1);

use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Project;
use Illuminate\Support\Facades\DB;
use Tests\Support\FakeRoutePublicationSocket;

beforeEach(function (): void {
    $this->node = $this->markAsGateway(Node::query()->create(['name' => 'native-read-root', 'status' => 'active', 'platform' => 'linux', 'user' => 'orbit', 'wireguard_ip' => '10.44.0.9', 'public_ssh_host' => '192.0.2.9']));
    $project = Project::query()->create(['name' => 'Read', 'slug' => 'native-read', 'repository_url' => 'https://example.test/read.git']);
    $this->instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $this->node->id, 'name' => 'reserved', 'checkout_path' => '/srv/read', 'status' => 'reserved']);
    $this->runId = '8e983d54-e9fb-49c8-853e-02f466d1a840';
    $this->operation = Operation::query()->create(['kind' => 'instance.register', 'target_type' => 'instance', 'target_id' => $this->instance->id, 'node_id' => $this->node->id, 'status' => 'running', 'started_at' => now()->subMinutes(10), 'dispatched_at' => now()->subMinutes(10), 'lock_key' => 'native-read-fixture', 'state' => ['swamp_run_id' => $this->runId], 'input' => ['instance_id' => $this->instance->id]]);
    $this->native = ['id' => $this->runId, 'workflowName' => 'operation-instance-register', 'status' => 'failed', 'inputs' => ['operation_id' => $this->operation->id, 'kind' => 'instance.register', 'node_id' => $this->node->id]];
    $this->socket = new FakeRoutePublicationSocket;
    $this->socket->reply = fn (array $request): array => [['id' => $request['id'], 'type' => 'workflow.history.get', 'payload' => ['data' => $this->native]]];
    app()->instance(WebSocketClient::class, $this->socket);
    $this->mock(AgentCommandRunner::class)->shouldNotReceive('run');
    config(['swamp.read_token' => 'native-read-fixture', 'swamp.address' => '10.44.0.209', 'swamp.ca_path' => '/public/test-ca']);
    $this->withServerVariables(['REMOTE_ADDR' => $this->node->wireguard_ip]);
    $this->url = '/api/v1/operations/'.$this->operation->id;
});

it('settles the exact failed run when registration and its reporter were both source-refused', function (): void {
    $level = DB::transactionLevel();
    $this->socket->onConnect = function () use ($level): void {
        expect(DB::transactionLevel())->toBe($level);
    };
    $this->native['jobs'] = [['name' => 'register', 'status' => 'failed', 'error' => 'private diagnostic'], ['name' => 'native-failure', 'status' => 'failed']];
    $this->getJson($this->url)->assertOk()->assertJsonPath('data.status', 'failed')->assertJsonPath('data.error_code', 'operation.native_failed');
    expect($this->operation->fresh()->lock_key)->toBeNull()->and($this->operation->fresh()->finished_at)->not->toBeNull()->and($this->operation->fresh()->state['swamp_run_id'])->toBe($this->runId)->and($this->instance->fresh()->status->value)->toBe('reserved');
    expect(json_encode($this->operation->fresh()->result))->not->toContain('private diagnostic');
    expect($this->socket->sent)->toHaveCount(1)->and($this->socket->sent[0]['type'])->toBe('workflow.history.get')->and($this->socket->sent[0]['payload'])->toBe(['workflowIdOrName' => $this->runId]);
    $this->getJson($this->url)->assertOk()->assertJsonPath('data.status', 'failed');
    expect($this->socket->sent)->toHaveCount(1);
});

it('recovers interrupted native history after a serve restart without rerunning registration', function (): void {
    $this->native['status'] = 'interrupted';
    $this->operation->update(['cancel_requested_at' => now()]);
    $this->getJson($this->url)->assertOk()->assertJsonPath('data.status', 'interrupted')->assertJsonPath('data.error_code', 'operation.native_failed');
    expect($this->operation->fresh()->lock_key)->toBeNull()->and($this->socket->sent)->toHaveCount(1);
});

it('keeps a serve outage active then settles its exact interrupted history on the next read', function (): void {
    $this->socket->onConnect = static function (): void {
        throw new RuntimeException('not_found is only diagnostic text');
    };
    $this->getJson($this->url)->assertOk()->assertJsonPath('data.status', 'running');
    expect($this->operation->fresh()->lock_key)->toBe('native-read-fixture');
    $this->socket->onConnect = null;
    $this->native['status'] = 'interrupted';
    $this->getJson($this->url)->assertOk()->assertJsonPath('data.status', 'interrupted')->assertJsonPath('data.error_code', 'operation.native_failed');
    expect($this->socket->sent)->toHaveCount(1);
});

it('does not invent a failure from native cancellation', function (): void {
    $this->native['status'] = 'cancelled';
    $this->getJson($this->url)->assertOk()->assertJsonPath('data.status', 'running')->assertJsonPath('data.error_code', null);
});

it('does not invent failure or domain success from native success', function (): void {
    $this->native['status'] = 'succeeded';
    $this->getJson($this->url)->assertOk()->assertJsonPath('data.status', 'running')->assertJsonPath('data.error_code', null);
    expect($this->instance->fresh()->status->value)->toBe('reserved')->and($this->instance->fresh()->registration_completed_at)->toBeNull();
});

it('preserves active and mismatched native history', function (string $change): void {
    match ($change) {
        'run' => $this->native['id'] = 'different-run',
        'workflow' => $this->native['workflowName'] = 'operation-instance-clone',
        'operation' => $this->native['inputs']['operation_id'] = $this->operation->id + 1,
        'kind' => $this->native['inputs']['kind'] = 'instance.clone',
        'node' => $this->native['inputs']['node_id'] = $this->node->id + 1,
        'missing input' => $this->native['inputs'] = null,
        default => $this->native['status'] = $change,
    };
    $this->getJson($this->url)->assertOk()->assertJsonPath('data.status', 'running')->assertJsonPath('data.error_code', null);
    expect($this->operation->fresh()->lock_key)->toBe('native-read-fixture')->and($this->operation->fresh()->finished_at)->toBeNull();
})->with(['run', 'workflow', 'operation', 'kind', 'node', 'missing input', 'running', 'waiting', 'queued', 'suspended', 'unknown']);

it('never treats absent native history as failure even for an old binding', function (): void {
    $this->socket->reply = static fn (array $request): array => [['id' => $request['id'], 'type' => 'error', 'error' => ['code' => 'not_found']]];
    $this->operation->update(['dispatched_at' => now()]);
    $this->getJson($this->url)->assertOk()->assertJsonPath('data.status', 'running');
    $this->operation->update(['dispatched_at' => now()->subMinutes(2)]);
    $this->getJson($this->url)->assertOk()->assertJsonPath('data.status', 'running')->assertJsonPath('data.error_code', null);
});

it('does not treat authorization refusal as missing history', function (): void {
    $this->socket->reply = static fn (array $request): array => [['id' => $request['id'], 'type' => 'error', 'error' => ['code' => 'forbidden', 'message' => 'not_found']]];
    $this->getJson($this->url)->assertOk()->assertJsonPath('data.status', 'running');
});

it('preserves a concurrent family completion', function (): void {
    $this->socket->onConnect = function (): void {
        $this->operation->update(['status' => 'succeeded', 'result' => ['registered' => true], 'finished_at' => now(), 'lock_key' => null]);
    };
    $this->getJson($this->url)->assertOk()->assertJsonPath('data.status', 'succeeded')->assertJsonPath('data.result.registered', true);
});

it('does not settle a superseded run kind or Node binding from old history', function (string $field): void {
    $other = Node::query()->create(['name' => 'other-native-root', 'status' => 'active', 'platform' => 'linux', 'user' => 'orbit', 'wireguard_ip' => '10.44.0.18', 'public_ssh_host' => '192.0.2.18']);
    $this->socket->onConnect = function () use ($field, $other): void {
        $this->operation->update(match ($field) {
            'run' => ['state' => ['swamp_run_id' => 'e58fe178-0374-4f5e-a934-b985d34b9538']],
            'kind' => ['kind' => 'instance.rename'],
            'node' => ['node_id' => $other->id],
        });
    };
    $this->getJson($this->url)->assertOk()->assertJsonPath('data.status', 'running');
})->with(['run', 'kind', 'node']);

it('authorizes before native history is read', function (): void {
    $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.222'])->getJson($this->url)->assertForbidden();
    expect($this->socket->sent)->toBe([]);
});

it('does not read history for unbound or already terminal Operations', function (bool $terminal): void {
    $this->operation->update($terminal ? ['status' => 'failed', 'error_code' => 'original.failure'] : ['state' => []]);
    $this->getJson($this->url)->assertOk();
    expect($this->socket->sent)->toBe([]);
})->with([false, true]);

it('does not accept worker assertions through the retired failure callback', function (): void {
    $this->postJson('/api/v1/direct-operations/'.$this->operation->id.'/failure', ['run_id' => $this->runId])->assertNotFound();
    expect($this->operation->fresh()->status->value)->toBe('running')
        ->and($this->operation->fresh()->lock_key)->toBe('native-read-fixture')
        ->and($this->socket->sent)->toBe([]);
});
