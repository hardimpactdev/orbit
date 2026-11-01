<?php

declare(strict_types=1);

use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\Swamp\NativeStepFailure;
use App\Models\Activity;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Process;
use App\Models\Project;
use Illuminate\Support\Facades\DB;
use Tests\Support\FakeRoutePublicationSocket;

beforeEach(function (): void {
    $this->node = $this->markAsGateway(Node::query()->create(['name' => 'native-read-root', 'status' => 'active', 'platform' => 'linux', 'user' => 'orbit', 'wireguard_ip' => '10.44.0.9', 'public_ssh_host' => '192.0.2.9']));
    $project = Project::query()->create(['name' => 'Read', 'slug' => 'native-read', 'repository_url' => 'https://example.test/read.git']);
    $this->instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $this->node->id, 'name' => 'reserved', 'checkout_path' => '/srv/read', 'status' => 'reserved']);
    $this->runId = '8e983d54-e9fb-49c8-853e-02f466d1a840';
    $this->operation = Operation::query()->create(['kind' => 'instance.register', 'target_type' => 'instance', 'target_id' => $this->instance->id, 'node_id' => $this->node->id, 'status' => 'running', 'started_at' => now()->subSeconds(10), 'dispatched_at' => now()->subSeconds(10), 'lock_key' => 'native-read-fixture', 'state' => ['swamp_run_id' => $this->runId], 'input' => ['instance_id' => $this->instance->id]]);
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

it('enriches a previously projected native failure once with the first worker error and no invented stderr', function (): void {
    $error = "Timed out waiting for a worker matching target 'orbit-node-13' to become available";
    $this->operation->update(['status' => 'failed', 'error_code' => 'operation.native_failed',
        'finished_at' => now(), 'result' => ['error_code' => 'operation.native_failed']]);
    $finished = $this->operation->fresh()->finished_at->toISOString();
    $this->native['jobs'] = [
        ['name' => 'admission', 'steps' => [['name' => 'intent', 'status' => 'failed', 'error' => $error,
            'outputs' => ['stdout' => 'never-expose-private-stdout']]]],
        ['name' => 'cleanup', 'steps' => [['name' => 'cleanup', 'status' => 'failed', 'error' => 'Later cleanup error.']]],
    ];
    $alerts = Activity::query()->where('command', 'instance:failure-alert')->count();

    $this->getJson($this->url)->assertOk()->assertJsonPath('data.status', 'failed')
        ->assertJsonPath('data.failed_step', 'intent')->assertJsonPath('data.error.detail', $error)
        ->assertJsonPath('data.error.stderr_recorded', false);

    expect($this->operation->fresh()->finished_at->toISOString())->toBe($finished);
    expect($this->operation->fresh()->result)->toBe(['error_code' => 'operation.native_failed']);
    expect(Activity::query()->where('command', 'instance:failure-alert')->count())->toBe($alerts);
    $this->getJson($this->url)->assertOk()->assertJsonPath('data.failed_step', 'intent');
    expect($this->socket->sent)->toHaveCount(1);
    expect(json_encode($this->operation->fresh()->state))->not->toContain('never-expose-private-stdout');
});

it('records checked but unavailable diagnostics without reading history on every terminal GET', function (): void {
    $this->operation->update(['status' => 'failed', 'error_code' => 'operation.native_failed', 'finished_at' => now()]);

    $this->getJson($this->url)->assertOk()->assertJsonPath('data.error', null);
    $this->getJson($this->url)->assertOk()->assertJsonPath('data.error', null);

    expect($this->socket->sent)->toHaveCount(1);
    expect(array_key_exists('native_failure', $this->operation->fresh()->state))->toBeTrue();
});

it('ignores malformed native completion timestamps without failing the Operation read', function (string $completed): void {
    $this->native['completedAt'] = $completed;

    $this->getJson($this->url)->assertOk()->assertJsonPath('data.status', 'failed');
    expect($this->operation->fresh()->finished_at->diffInSeconds(now()))->toBeLessThan(2);
})->with(['not a timestamp', 'tomorrow', '2026-99-99T99:99:99Z']);

it('redacts the target Instance known values before storing or returning native diagnostics', function (): void {
    $secret = 's3cr3t-db-password-value';
    $this->instance->environmentValues()->create(['env_key' => 'DB_PASSWORD', 'env_value' => $secret]);
    $this->native['jobs'] = [['steps' => [['name' => 'setup', 'status' => 'failed', 'outputs' => ['stderr' => 'connecting with '.$secret.' failed']]]]];

    $response = $this->getJson($this->url)->assertOk()->assertJsonPath('data.error.detail', 'connecting with [REDACTED] failed');

    expect($response->getContent())->not->toContain($secret);
    expect(json_encode($this->operation->fresh()->state))->not->toContain($secret);
});

it('redacts a multiline private key before its identifying BEGIN line can be cut off', function (): void {
    $body = str_repeat("private-key-body-sentinel\n", 250);
    $this->native['jobs'] = [['steps' => [['name' => 'setup', 'status' => 'failed', 'outputs' => [
        'stderr' => "-----BEGIN PRIVATE KEY-----\n".$body."-----END PRIVATE KEY-----\nReadable failure.",
    ]]]]];

    $response = $this->getJson($this->url)->assertOk()->assertJsonPath('data.error.detail', "[REDACTED]\nReadable failure.");

    expect($response->getContent())->not->toContain('private-key-body-sentinel');
    expect(json_encode($this->operation->fresh()->state))->not->toContain('private-key-body-sentinel');
});

it('redacts known target values that earlier credential masking creates in the retained tail', function (): void {
    $this->instance->environmentValues()->create(['env_key' => 'DB_PASSWORD', 'env_value' => 'connection=[REDACTED]']);
    $this->native['jobs'] = [['steps' => [['name' => 'setup', 'status' => 'failed', 'outputs' => [
        'stderr' => 'connection=ghp_'.str_repeat('q', 24),
    ]]]]];

    $this->getJson($this->url)->assertOk()->assertJsonPath('data.error.detail', '[REDACTED]');
    expect(json_encode($this->operation->fresh()->state))->not->toContain('connection=[REDACTED]');
});

it('redacts the target Process known environment values through the same extractor', function (): void {
    $secret = 'private-process-credential-sentinel';
    $process = Process::query()->create(['owner_type' => Node::class, 'owner_id' => $this->node->id, 'name' => 'diagnostic',
        'runtime' => 'systemd', 'runtime_config' => ['environment' => ['DB_PASSWORD' => $secret]],
        'restart_policy' => 'always', 'desired_state' => 'running', 'status' => 'active']);
    $operation = new Operation(['target_type' => 'process', 'target_id' => $process->id]);

    $failure = app(NativeStepFailure::class)->fromRun(['status' => 'failed', 'jobs' => [['steps' => [
        ['name' => 'start', 'status' => 'failed', 'error' => 'failed with '.$secret],
    ]]]], $operation);

    expect($failure['detail'])->toBe('failed with [REDACTED]');
});

it('uses the verified native completion time when the failure is projected late', function (): void {
    $completed = now()->subSeconds(4)->toIso8601String();
    $this->native['completedAt'] = $completed;
    $this->native['jobs'] = [['name' => 'source', 'steps' => [['name' => 'inspect', 'status' => 'failed', 'error' => 'Cannot inspect source.']]]];

    $this->getJson($this->url)->assertOk()->assertJsonPath('data.finished_at', $completed)
        ->assertJsonPath('data.failed_step', 'inspect');

    expect($this->socket->sent)->toHaveCount(1);
});
