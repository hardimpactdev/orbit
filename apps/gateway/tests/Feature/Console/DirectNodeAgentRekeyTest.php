<?php

declare(strict_types=1);

use App\Domain\Nodes\RoleName;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\OperationAttemptLease;
use App\Domain\Operations\OperationChildIntent;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\Nodes\NodeLocks;
use App\Models\Node;
use App\Models\Operation;
use Illuminate\Support\Facades\DB;
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
        'node_id' => $node->id, 'input' => [], 'status' => 'running', 'lock_key' => 'node:'.$node->id, 'attempts' => 1, 'sequence' => 1,
        'state' => ['executor_runs' => ['footprint-agent' => ['transport' => 'agent', 'name' => 'agent.configure', 'started' => true, 'result' => null,
            'input' => ['configuration' => "\n[swamp_worker]\nnode_id = {$node->id}\nuser = ".json_encode($node->user, JSON_THROW_ON_ERROR)."\n",
                'certificate' => 'public-ca', 'unit' => 'admitted-unit', 'checkouts' => [], 'binary_changed' => false,
                'secret_hash' => ['$secret' => 'node.agent_secret_hash', 'id' => $node->id],
                'worker_credentials' => ['$secret' => 'node.worker_credentials', 'id' => $node->id]]]]]]);
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
        ->and($op->attempts)->toBe(0)->and($op->worker)->toBeNull()->and($op->lock_key)->toBeNull()->and($op->parent_operation_id)->toBeNull()
        ->and($source->fresh()->lock_key)->toBe('node:'.$node->id)
        ->and($op->input['_agent_rekey']['configuration'])->not->toHaveKeys(['secret_hash', 'worker_credentials']);
});

it('refuses an ineligible source before admitting a recovery', function (string $case): void {
    [$node, $source, $socket] = directRekeySource();
    $state = $source->state;
    match ($case) {
        'finished' => $source->status = 'succeeded',
        'unowned' => $source->lock_key = null,
        'foreign' => $source->target_id = $node->id + 1,
        'parent' => $source->parent_operation_id = $source->id,
        'kind' => $source->kind = 'node.settings',
        'not_started' => $state['executor_runs']['footprint-agent']['started'] = false,
        'proven' => $state['executor_runs']['footprint-agent']['result'] = ['restarted' => true],
        'workerless' => config(['swamp_workers.nodes' => []]),
    };
    $source->state = $state;
    $source->save();
    $this->artisan('orbit:node-agent-rekey', ['node' => $node->id, 'operation' => $source->id])->assertFailed();
    expect(Operation::query()->where('kind', 'node.agent_rekey')->exists())->toBeFalse()->and($socket->sent)->toBe([]);
})->with(['finished', 'unowned', 'foreign', 'parent', 'kind', 'not_started', 'proven', 'workerless']);

it('refuses a still-active legacy configure section without acquiring a Gateway hold', function (): void {
    [$node, $source] = directRekeySource();
    $lock = app(NodeLocks::class)->lock('node-agent:id:'.$node->id, 420);
    $lock->get();
    try {
        $this->artisan('orbit:node-agent-rekey', ['node' => $node->id, 'operation' => $source->id])->assertFailed();
        expect(Operation::query()->where('kind', 'node.agent_rekey')->exists())->toBeFalse();
    } finally {
        $lock->release();
    }
});

it('refuses a still-running source attempt even when its legacy agent lock is free', function (): void {
    [$node, $source, $socket] = directRekeySource();
    expect(app(NodeLocks::class)->lock('node-agent:id:'.$node->id, 420)->isLocked())->toBeFalse();
    $held = app(OperationAttemptLease::class)->hold($source->id, function () use ($node, $source, $socket): void {
        $this->artisan('orbit:node-agent-rekey', ['node' => $node->id, 'operation' => $source->id])->assertFailed();
        expect(Operation::query()->where('kind', 'node.agent_rekey')->exists())->toBeFalse()->and($socket->sent)->toBe([]);
    });
    expect($held)->toBeTrue();
    $this->artisan('orbit:node-agent-rekey', ['node' => $node->id, 'operation' => $source->id])->assertSuccessful();
});

it('rechecks the source attempt exclusion before accepting a recovered private hash', function (): void {
    [$node, $source, , $runId] = directRekeySource();
    $this->artisan('orbit:node-agent-rekey', ['node' => $node->id, 'operation' => $source->id])->assertSuccessful();
    $op = Operation::query()->where('kind', 'node.agent_rekey')->sole();
    $this->withServerVariables(['REMOTE_ADDR' => $node->wireguard_ip]);
    $before = $node->agent_secret_hash;
    app(OperationAttemptLease::class)->hold($source->id, function () use ($op, $runId): void {
        $this->postJson('/api/v1/direct-operations/'.$op->id.'/secret-output',
            ['run_id' => $runId, 'outputs' => ['secret_hash' => str_repeat('d', 64)]])->assertConflict();
    });
    expect($node->fresh()->agent_secret_hash)->toBe($before);
});

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

it('stores only an exact Node bound private hash and leaves the original receipt untouched', function (): void {
    [$node, $source, , $runId] = directRekeySource();
    $this->artisan('orbit:node-agent-rekey', ['node' => $node->id, 'operation' => $source->id])->assertSuccessful();
    $op = Operation::query()->where('kind', 'node.agent_rekey')->sole();
    $this->withServerVariables(['REMOTE_ADDR' => $node->wireguard_ip]);
    $path = '/api/v1/direct-operations/'.$op->id;
    $hash = str_repeat('d', 64);
    $before = $source->state;
    $this->postJson($path.'/secret-output', ['run_id' => $runId, 'outputs' => ['secret_hash' => $hash]])->assertOk();
    expect($node->fresh()->agent_secret_hash)->toBe($hash)
        ->and(DB::table('nodes')->where('id', $node->id)->value('agent_secret_hash'))->not->toContain($hash);
    $this->postJson($path.'/result', ['run_id' => $runId, 'status' => 'succeeded', 'started_at' => '2026-10-31T03:00:00.000Z',
        'finished_at' => '2026-10-31T03:00:01.000Z', 'result' => ['restarted' => true, 'rekeyed' => true]])->assertOk();
    expect($source->fresh()->state)->toBe($before)->and($source->fresh()->status->value)->toBe('running')
        ->and(json_encode([$op->fresh()->toArray(), $op->steps()->get()->toArray()]))->not->toContain($hash);
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
    $this->postJson($path.'/result', ['run_id' => $runId, 'status' => 'succeeded', 'started_at' => '2026-10-31T03:00:00.000Z',
        'finished_at' => '2026-10-31T03:00:01.000Z', 'result' => ['rekeyed' => true, 'restarted' => true, 'secret_hash' => $hash]])->assertUnprocessable();
    foreach (['agent.configure_failed', 'swamp.node_worker_not_ready', 'swamp.node_worker_memory_low'] as $code) {
        $this->postJson($path.'/result', ['run_id' => $runId, 'status' => 'failed', 'started_at' => '2026-10-31T03:00:00.000Z',
            'finished_at' => '2026-10-31T03:00:01.000Z', 'result' => ['rekeyed' => false, 'error_code' => $code],
            'error_code' => $code])->assertUnprocessable();
    }
    $state = $source->state;
    $state['executor_runs']['footprint-agent']['input']['configuration'] .= '# changed';
    $source->update(['state' => $state]);
    $this->getJson($path.'?run_id='.$runId)->assertUnprocessable();
    $this->postJson($path.'/secret-output', ['run_id' => $runId, 'outputs' => ['secret_hash' => $hash]])->assertConflict();
    expect($node->fresh()->agent_secret_hash)->toBe($before)->and(json_encode($op->fresh()->toArray()))->not->toContain($hash);
});

it('preserves read-only legacy parent ownership without creating recovery children', function (bool $tamper): void {
    [$node, $source] = directRekeySource();
    $source->update(['lock_key' => null]);
    $intent = ['key' => 'enrollment-footprint', 'kind' => 'node.converge', 'node_id' => $node->id,
        'input' => ['force_artifacts' => false, 'first_visit' => false]];
    $parent = Operation::query()->create(['kind' => 'node.add', 'target_type' => 'node', 'target_id' => $node->id,
        'node_id' => $node->id, 'input' => [], 'state' => ['child_intents' => [$intent]],
        'status' => 'running', 'lock_key' => 'node:'.$node->id, 'sequence' => 1, 'attempts' => 1]);
    $snapshot = ['intent' => $intent, 'admitted' => ['parent_operation_id' => $parent->id, 'key' => $intent['key'],
        'kind' => 'node.converge', 'node_id' => $node->id, 'target_type' => 'node', 'target_id' => $node->id,
        'input' => $source->input, 'run_constraints' => []]];
    $source->forceFill(['parent_operation_id' => $parent->id, 'child_key' => $intent['key'],
        'child_admission' => $snapshot, 'child_fingerprint' => OperationChildIntent::fingerprint($snapshot)])->save();
    if ($tamper) {
        $source->forceFill(['child_key' => 'foreign-footprint'])->save();
    } else {
        app(OperationAttemptLease::class)->hold($parent->id, function () use ($node, $source): void {
            $this->artisan('orbit:node-agent-rekey', ['node' => $node->id, 'operation' => $source->id])->assertFailed();
            expect(Operation::query()->where('kind', 'node.agent_rekey')->exists())->toBeFalse();
        });
    }
    $this->artisan('orbit:node-agent-rekey', ['node' => $node->id, 'operation' => $source->id])->assertExitCode($tamper ? 1 : 0);
    $recovery = Operation::query()->where('kind', 'node.agent_rekey')->first();
    expect($recovery !== null)->toBe(! $tamper)
        ->and($parent->children()->count())->toBe(1)->and($parent->fresh()->lock_key)->toBe('node:'.$node->id);
    if ($recovery !== null) {
        expect($recovery->parent_operation_id)->toBeNull()->and($recovery->lock_key)->toBeNull();
    }
})->with([false, true]);

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
