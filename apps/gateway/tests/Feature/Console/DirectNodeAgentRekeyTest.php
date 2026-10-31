<?php

declare(strict_types=1);

use App\Domain\Nodes\RoleName;
use App\Domain\Operations\DirectOperation;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\Nodes\NodeAgentHashSource;
use App\Models\Node;
use App\Models\Operation;
use Tests\Support\FakeAgentCommandRunner;
use Tests\Support\FakeRoutePublicationSocket;
use Tests\Support\Fleet\FleetFixtures;

function directRekeySource(): array
{
    FleetFixtures::bind();
    $agents = Mockery::mock(AgentCommandRunner::class);
    $agents->shouldNotReceive('run', 'install');
    app()->instance(AgentCommandRunner::class, $agents);
    $gateway = FleetFixtures::node('rekey-gateway', [RoleName::Gateway]);
    $node = FleetFixtures::node('rekey-owner');
    $node->accessibleNodes()->attach($gateway);
    $node->forceFill(['agent_secret_hash' => str_repeat('a', 64)])->save();
    config(['swamp_workers.nodes' => [$node->id], 'operation_executor.worker_node_ids' => [$node->id], 'swamp.run_token' => 'fake-token']);
    $source = Operation::query()->create(['kind' => 'node.converge', 'target_type' => 'node', 'target_id' => $node->id,
        'node_id' => $node->id, 'status' => 'running', 'sequence' => 1,
        'state' => ['swamp_run_id' => '3a332a4f-0911-4e9d-9a18-09899b7b1864'],
        'input' => ['_converge' => ['schema_version' => 1, 'node_id' => $node->id, 'agent_sha256' => str_repeat('c', 64),
            'artifacts' => [['name' => 'agent', 'required' => true]], 'payloads' => ['agent' => [
                'user' => $node->user, 'apps_root' => null, 'url' => 'https://artifacts.example/agent',
                'configuration' => "[swamp_worker]\nnode_id = {$node->id}\nuser = ".json_encode($node->user, JSON_THROW_ON_ERROR)."\n",
                'certificate' => 'public-ca', 'unit' => 'admitted-unit', 'checkouts' => [],
            ]]]]]);
    $socket = new FakeRoutePublicationSocket;
    $runId = 'ff3b7992-7376-4ac7-a49f-ed45bedc8de5';
    $socket->reply = static fn (array $message): array => [['type' => 'event', 'id' => $message['id'], 'event' => [
        'kind' => 'started', 'workflowName' => 'operation-node-agent-rekey', 'runId' => $runId,
    ]]];
    app()->instance(WebSocketClient::class, $socket);

    return [$node, $source, $socket, $runId];
}

it('records an idempotent native intent without PHP execution holds or children', function (): void {
    [$node, $source, $socket, $runId] = directRekeySource();
    $agents = new FakeAgentCommandRunner;
    app()->instance(AgentCommandRunner::class, $agents);
    for ($i = 0; $i < 2; $i++) {
        $this->artisan('orbit:node-agent-rekey', ['node' => $node->id, 'operation' => $source->id])
            ->expectsOutputToContain('Agent recovery Operation #')->doesntExpectOutputToContain('authentication restored')->assertSuccessful();
    }
    $op = Operation::query()->where('kind', 'node.agent_rekey')->sole();
    expect(DirectOperation::owns($op))->toBeTrue()->and($socket->sent)->toHaveCount(1)
        ->and($op->state['swamp_run_id'])->toBe($runId)->and($agents->commands())->toBe([])
        ->and($op->attempts)->toBe(0)->and($op->worker)->toBeNull()->and($op->lock_key)->toBeNull()
        ->and($op->input['_agent_rekey']['configure_run_id'])->toBe($source->state['swamp_run_id'])
        ->and($op->input['_agent_rekey']['configuration'])->not->toHaveKeys(['secret_hash', 'worker_credentials']);
});

it('refuses an ineligible immutable native source before admission', function (string $case): void {
    [$node, $source, $socket] = directRekeySource();
    $state = $source->state;
    $input = $source->input;
    match ($case) {
        'finished' => $source->status = 'succeeded',
        'queued' => $source->status = 'queued',
        'foreign' => $source->target_id = $node->id + 1,
        'foreign_root' => $source->node_id = null,
        'kind' => $source->kind = 'node.settings',
        'unbound' => $state = [],
        'invalid_run' => $state['swamp_run_id'] = 'not-a-run',
        'foreign_snapshot' => $input['_converge']['node_id'] = $node->id + 1,
        'invalid_pin' => $input['_converge']['agent_sha256'] = 'not-a-pin',
        'no_agent' => $input['_converge']['artifacts'] = [],
        'not_required' => $input['_converge']['artifacts'][0]['required'] = false,
        'foreign_user' => $input['_converge']['payloads']['agent']['user'] = 'foreign',
        'credentials' => $input['_converge']['payloads']['agent']['worker_credentials'] = null,
        'workerless' => config(['swamp_workers.nodes' => []]),
    };
    $source->forceFill(['state' => $state, 'input' => $input])->save();
    $this->artisan('orbit:node-agent-rekey', ['node' => $node->id, 'operation' => $source->id])->assertFailed();
    expect(Operation::query()->where('kind', 'node.agent_rekey')->exists())->toBeFalse()->and($socket->sent)->toBe([]);
})->with(['finished', 'queued', 'foreign', 'foreign_root', 'kind', 'unbound', 'invalid_run', 'foreign_snapshot', 'invalid_pin', 'no_agent', 'not_required', 'foreign_user', 'credentials', 'workerless']);

it('allows an explicit new request after a terminal refusal without PHP retry sequencing', function (): void {
    [$node, $source] = directRekeySource();
    $input = ['node' => $node->id, 'operation' => $source->id];
    $this->artisan('orbit:node-agent-rekey', $input)->assertSuccessful();
    $first = Operation::query()->where('kind', 'node.agent_rekey')->sole();
    $first->update(['status' => 'failed', 'error_code' => 'agent.version_mismatch']);
    $this->artisan('orbit:node-agent-rekey', $input)->assertSuccessful();
    expect(Operation::query()->where('kind', 'node.agent_rekey')->count())->toBe(1);
    $this->artisan('orbit:node-agent-rekey', [...$input, '--attempt' => '2'])->assertSuccessful();
    expect(Operation::query()->where('kind', 'node.agent_rekey')->count())->toBe(2);
});

it('retains exact private output custody if its source finishes during native recovery', function (): void {
    [$node, $source, , $runId] = directRekeySource();
    $this->artisan('orbit:node-agent-rekey', ['node' => $node->id, 'operation' => $source->id])->assertSuccessful();
    $op = Operation::query()->where('kind', 'node.agent_rekey')->sole();
    $this->withServerVariables(['REMOTE_ADDR' => $node->wireguard_ip]);
    $path = '/api/v1/direct-operations/'.$op->id;
    $hash = str_repeat('d', 64);
    $before = $source->state;
    $source->update(['status' => 'failed', 'error_code' => 'agent.secret_mismatch']);
    $this->postJson($path.'/secret-output', ['run_id' => $runId, 'outputs' => ['secret_hash' => $hash]])->assertOk();
    expect($node->fresh()->agent_secret_hash)->toBe($hash);
    $this->postJson($path.'/result', ['run_id' => $runId, 'status' => 'succeeded', 'started_at' => '2026-10-31T03:00:00.000Z',
        'finished_at' => '2026-10-31T03:00:01.000Z', 'result' => ['restarted' => true, 'rekeyed' => true]])->assertOk();
    expect($source->fresh()->state)->toBe($before)->and($source->fresh()->status->value)->toBe('failed')
        ->and(json_encode($op->fresh()->toArray()))->not->toContain($hash);
    $this->postJson($path.'/secret-output', ['run_id' => $runId, 'outputs' => ['secret_hash' => $hash]])->assertConflict();
});

it('refuses foreign callers wrong runs undeclared outputs source drift and unsafe public results', function (): void {
    [$node, $source, , $runId] = directRekeySource();
    $this->artisan('orbit:node-agent-rekey', ['node' => $node->id, 'operation' => $source->id])->assertSuccessful();
    $op = Operation::query()->where('kind', 'node.agent_rekey')->sole();
    $path = '/api/v1/direct-operations/'.$op->id;
    $hash = str_repeat('d', 64);
    $before = $node->agent_secret_hash;
    $foreign = FleetFixtures::node('foreign-direct-rekey-caller');
    $foreign->accessibleNodes()->syncWithoutDetaching(Node::query()->whereHas('roles', static fn ($q) => $q->where('role', 'gateway'))->pluck('id')->all());
    config(['operation_executor.worker_node_ids' => [$foreign->id, $node->id], 'dependency_detection.services_node_id' => (string) $foreign->id]);
    $this->withServerVariables(['REMOTE_ADDR' => $foreign->wireguard_ip]);
    $this->postJson($path.'/secret-output', ['run_id' => $runId, 'outputs' => ['secret_hash' => $hash]])->assertForbidden();
    $this->withServerVariables(['REMOTE_ADDR' => $node->wireguard_ip]);
    $this->postJson($path.'/secret-output', ['run_id' => '3a332a4f-0911-4e9d-9a18-09899b7b1864', 'outputs' => ['secret_hash' => $hash]])->assertConflict();
    $this->postJson($path.'/secret-output', ['run_id' => $runId, 'outputs' => ['worker_credentials' => $hash]])->assertUnprocessable();
    $this->postJson($path.'/secret-output', ['run_id' => $runId, 'outputs' => ['secret_hash' => 'malformed-private-hash']])->assertConflict();
    $input = $source->input;
    $input['_converge']['payloads']['agent']['configuration'] .= '# changed';
    $source->update(['input' => $input]);
    $this->getJson($path.'?run_id='.$runId)->assertUnprocessable();
    $this->postJson($path.'/secret-output', ['run_id' => $runId, 'outputs' => ['secret_hash' => $hash]])->assertConflict();
    expect($node->fresh()->agent_secret_hash)->toBe($before)->and(json_encode($op->fresh()->toArray()))->not->toContain($hash);
});

it('accepts a bounded pre-invoke refusal without storing a private hash', function (): void {
    [$node, $source, , $runId] = directRekeySource();
    $this->artisan('orbit:node-agent-rekey', ['node' => $node->id, 'operation' => $source->id])->assertSuccessful();
    $op = Operation::query()->where('kind', 'node.agent_rekey')->sole();
    $before = $node->agent_secret_hash;
    $this->withServerVariables(['REMOTE_ADDR' => $node->wireguard_ip]);
    $this->postJson('/api/v1/direct-operations/'.$op->id.'/result', ['run_id' => $runId, 'status' => 'failed',
        'started_at' => '2026-10-31T03:00:00.000Z', 'finished_at' => '2026-10-31T03:00:01.000Z',
        'result' => ['rekeyed' => false, 'error_code' => 'node.agent_rekey_unavailable'], 'error_code' => 'node.agent_rekey_unavailable'])->assertOk();
    expect($op->fresh()->status->value)->toBe('failed')->and($node->fresh()->agent_secret_hash)->toBe($before);
});

it('preserves immutable custody across database JSON object key ordering', function (): void {
    [$node, $source] = directRekeySource();
    $this->artisan('orbit:node-agent-rekey', ['node' => $node->id, 'operation' => $source->id])->assertSuccessful();
    $op = Operation::query()->where('kind', 'node.agent_rekey')->sole();
    $input = $op->input;
    $input['_agent_rekey'] = array_reverse($input['_agent_rekey'], true);
    $input['_agent_rekey']['configuration'] = array_reverse($input['_agent_rekey']['configuration'], true);
    $op->update(['input' => $input]);
    app(NodeAgentHashSource::class)->store($op, $node->id, str_repeat('d', 64));
    expect($node->fresh()->agent_secret_hash)->toBe(str_repeat('d', 64));
});

it('allows explicit repair after its source finishes and protected output storage is lost', function (): void {
    [$node, $source, $socket] = directRekeySource();
    $request = ['node' => $node->id, 'operation' => $source->id];
    $this->artisan('orbit:node-agent-rekey', $request)->assertSuccessful();
    $previous = Operation::query()->where('kind', 'node.agent_rekey')->sole();
    $source->update(['status' => 'failed', 'error_code' => 'agent.secret_mismatch']);
    $previous->update(['status' => 'failed', 'error_code' => 'operation.native_outcome_unknown', 'result' => ['error_code' => 'operation.native_outcome_unknown']]);
    $this->artisan('orbit:node-agent-rekey', [...$request, '--attempt' => 2])->assertSuccessful();
    expect(Operation::query()->where('kind', 'node.agent_rekey')->count())->toBe(2)->and($socket->sent)->toHaveCount(2);
});

it('refuses a finished-source retry without exact unknown recovery custody', function (string $case): void {
    [$node, $source] = directRekeySource();
    $request = ['node' => $node->id, 'operation' => $source->id];
    $this->artisan('orbit:node-agent-rekey', $request)->assertSuccessful();
    $previous = Operation::query()->where('kind', 'node.agent_rekey')->sole();
    $source->update(['status' => 'failed', 'error_code' => 'agent.secret_mismatch']);
    $state = $previous->state;
    $input = $previous->input;
    $status = 'failed';
    $error = 'operation.native_outcome_unknown';
    match ($case) {
        'unbound' => $state = [],
        'foreign_snapshot' => $input['_agent_rekey']['agent_sha256'] = str_repeat('b', 64),
        'succeeded' => [$status, $error] = ['succeeded', null],
        'running' => [$status, $error] = ['running', null],
        'pre_effect_refusal' => $error = 'agent.version_mismatch',
    };
    $previous->update(['status' => $status, 'state' => $state, 'input' => $input, 'error_code' => $error]);
    $this->artisan('orbit:node-agent-rekey', [...$request, '--attempt' => 2])->assertFailed();
    expect(Operation::query()->where('kind', 'node.agent_rekey')->count())->toBe(1);
})->with(['unbound', 'foreign_snapshot', 'succeeded', 'running', 'pre_effect_refusal']);
