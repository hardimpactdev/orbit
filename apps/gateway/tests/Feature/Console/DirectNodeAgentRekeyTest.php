<?php

declare(strict_types=1);

use App\Actions\Nodes\StartNodeAgentRekeyAction;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\Nodes\NodeLocks;
use App\Models\Node;
use App\Models\Operation;
use Illuminate\Support\Facades\DB;
use Tests\Support\FakeAgentCommandRunner;
use Tests\Support\FakeRoutePublicationSocket;
use Tests\Support\Fleet\FleetFixtures;

require_once __DIR__.'/../../Support/Fleet/NodeSecretOutput.php';

function directRekeySource($test): array
{
    [$node, $source, $path, $run] = nodeSecretOutput($test, true);
    $agents = new FakeAgentCommandRunner;
    $agents->answer('agent.configure', static function (): array {
        throw new RuntimeException('lost live configure response');
    });
    app()->instance(AgentCommandRunner::class, $agents);
    $test->postJson($path.'/run', $run)->assertConflict()->assertJsonPath('error.code', 'operation.execution_uncertain');
    $socket = new FakeRoutePublicationSocket;
    $runId = 'ff3b7992-7376-4ac7-a49f-ed45bedc8de5';
    $socket->reply = static fn (array $message): array => [['type' => 'event', 'id' => $message['id'], 'event' => [
        'kind' => 'started', 'workflowName' => 'operation-node-agent-rekey', 'runId' => $runId,
    ]]];
    app()->instance(WebSocketClient::class, $socket);
    config(['swamp.run_token' => 'fake-token']);

    return [$node, $source->fresh(), $socket, $runId];
}

it('admits one direct recovery without PHP execution or a new Gateway hold', function (): void {
    [$node, $source, $socket, $runId] = directRekeySource($this);
    $agents = new FakeAgentCommandRunner;
    app()->instance(AgentCommandRunner::class, $agents);
    $op = DB::transaction(fn () => app(StartNodeAgentRekeyAction::class)->execute($node->id, $source->id));
    $again = app(StartNodeAgentRekeyAction::class)->execute($node->id, $source->id);
    expect($again->id)->toBe($op->id);
    expect($agents->commands())->toBe([]);
    expect($socket->sent)->toHaveCount(1);
    expect($op->fresh()->state['node_agent_rekey_writer'])->toBe('direct');
    expect($op->fresh()->state['swamp_run_id'])->toBe($runId);
    expect($op->attempts)->toBe(0);
    expect($op->worker)->toBeNull();
    expect($op->lock_key)->toBeNull();
    expect($op->parent_operation_id)->toBeNull();
    expect($source->fresh()->lock_key)->toBe('node:'.$node->id);
    expect($op->input['_agent_rekey']['configuration'])->not->toHaveKeys(['secret_hash', 'worker_credentials']);
});

it('refuses an active legacy configure critical section before admitting recovery', function (): void {
    [$node, $source, $socket] = directRekeySource($this);
    $lock = app(NodeLocks::class)->lock('node-agent:id:'.$node->id, 420);
    $lock->get();
    try {
        expect(fn () => app(StartNodeAgentRekeyAction::class)->execute($node->id, $source->id))
            ->toThrow(ResourceOperationException::class);
        expect(Operation::query()->where('kind', 'node.agent_rekey')->exists())->toBeFalse();
        expect($socket->sent)->toBe([]);
    } finally {
        $lock->release();
    }
});

it('rejects a successful or foreign configure as a recovery source', function (): void {
    [$node, $source] = directRekeySource($this);
    $source->update(['status' => 'succeeded', 'lock_key' => null]);
    expect(fn () => app(StartNodeAgentRekeyAction::class)->execute($node->id, $source->id))->toThrow(ResourceOperationException::class);
    expect(fn () => app(StartNodeAgentRekeyAction::class)->execute($node->id + 1000, $source->id))->toThrow(ResourceOperationException::class);
    expect(Operation::query()->where('kind', 'node.agent_rekey')->exists())->toBeFalse();
});

it('stores a private output only for the exact bound Node and projects no hash', function (): void {
    [$node, $source, , $runId] = directRekeySource($this);
    $op = DB::transaction(fn () => app(StartNodeAgentRekeyAction::class)->execute($node->id, $source->id));
    $node->accessibleNodes()->syncWithoutDetaching(Node::query()->whereHas('roles', static fn ($q) => $q->where('role', 'gateway'))->pluck('id')->all());
    config(['operation_executor.worker_node_ids' => [$node->id]]);
    $this->withServerVariables(['REMOTE_ADDR' => $node->wireguard_ip]);
    $path = '/api/v1/direct-operations/'.$op->id;
    $hash = str_repeat('d', 64);
    $before = $source->state['executor_runs'];
    $this->postJson($path.'/secret-output', ['run_id' => $runId, 'outputs' => ['secret_hash' => $hash]])
        ->assertOk()->assertJsonPath('data.stored.secret_hash', ['$secret' => 'node.agent_secret_hash', 'id' => $node->id]);
    expect($node->fresh()->agent_secret_hash)->toBe($hash);
    expect(DB::table('nodes')->where('id', $node->id)->value('agent_secret_hash'))->not->toContain($hash);
    $this->postJson($path.'/result', ['run_id' => $runId, 'status' => 'succeeded', 'started_at' => '2026-10-31T03:00:00.000Z',
        'finished_at' => '2026-10-31T03:00:01.000Z', 'result' => ['restarted' => true, 'rekeyed' => true]])->assertOk();
    expect($source->fresh()->state['executor_runs'])->toBe($before);
    expect($source->fresh()->state['agent_secret_rekey']['recovery_operation_id'])->toBe($op->id);
    expect($source->fresh()->status->value)->toBe('running');
    expect(json_encode([$op->fresh()->toArray(), $op->steps()->get()->toArray()]))->not->toContain($hash);
    $this->postJson($path.'/secret-output', ['run_id' => $runId, 'outputs' => ['secret_hash' => $hash]])->assertConflict();
});

it('reuses unfinished and successful attempts but admits an explicit retry after refusal or cancellation', function (): void {
    [$node, $source] = directRekeySource($this);
    $start = fn () => DB::transaction(fn () => app(StartNodeAgentRekeyAction::class)->execute($node->id, $source->id));
    $first = $start();
    expect($start()->id)->toBe($first->id);
    $first->update(['status' => 'failed', 'error_code' => 'agent.version_mismatch']);
    $second = $start();
    expect($second->id)->not->toBe($first->id);
    expect($start()->id)->toBe($second->id);
    $second->update(['status' => 'cancelled']);
    $third = $start();
    expect($third->id)->not->toBe($second->id);
    $third->update(['status' => 'succeeded', 'result' => ['rekeyed' => true, 'restarted' => true]]);
    expect($start()->id)->toBe($third->id);
    expect(Operation::query()->where('kind', 'node.agent_rekey')->count())->toBe(3);
});

it('accepts the operator command without claiming restored authentication', function (): void {
    [$node, $source] = directRekeySource($this);
    $this->artisan('orbit:node-agent-rekey', ['node' => (string) $node->id, 'operation' => (string) $source->id])
        ->expectsOutputToContain('Agent recovery Operation #')
        ->doesntExpectOutputToContain('Agent authentication restored')->assertSuccessful();
});

it('refuses wrong runs, undeclared outputs, private public results and source drift without storing', function (): void {
    [$node, $source, , $runId] = directRekeySource($this);
    $op = DB::transaction(fn () => app(StartNodeAgentRekeyAction::class)->execute($node->id, $source->id));
    $node->accessibleNodes()->syncWithoutDetaching(Node::query()->whereHas('roles', static fn ($q) => $q->where('role', 'gateway'))->pluck('id')->all());
    config(['operation_executor.worker_node_ids' => [$node->id]]);
    $this->withServerVariables(['REMOTE_ADDR' => $node->wireguard_ip]);
    $path = '/api/v1/direct-operations/'.$op->id;
    $hash = str_repeat('d', 64);
    $before = $node->agent_secret_hash;
    $this->postJson($path.'/secret-output', ['run_id' => '3a332a4f-0911-4e9d-9a18-09899b7b1864', 'outputs' => ['secret_hash' => $hash]])->assertConflict();
    $this->postJson($path.'/secret-output', ['run_id' => $runId, 'outputs' => ['worker_credentials' => $hash]])->assertUnprocessable();
    $this->postJson($path.'/secret-output', ['run_id' => $runId, 'outputs' => ['secret_hash' => 'invalid-hash']])->assertConflict();
    $this->postJson($path.'/result', ['run_id' => $runId, 'status' => 'succeeded', 'started_at' => '2026-10-31T03:00:00.000Z',
        'finished_at' => '2026-10-31T03:00:01.000Z', 'result' => ['rekeyed' => true, 'restarted' => true, 'secret_hash' => $hash]])->assertUnprocessable();
    $this->postJson($path.'/result', ['run_id' => $runId, 'status' => 'failed', 'started_at' => '2026-10-31T03:00:00.000Z',
        'finished_at' => '2026-10-31T03:00:01.000Z', 'result' => ['rekeyed' => false, 'error_code' => 'swamp.node_worker_not_ready'],
        'error_code' => 'swamp.node_worker_not_ready'])->assertUnprocessable();
    $state = $source->state;
    $state['executor_runs']['footprint-agent']['input']['configuration'] .= '# changed';
    $source->update(['state' => $state]);
    $this->postJson($path.'/secret-output', ['run_id' => $runId, 'outputs' => ['secret_hash' => $hash]])->assertUnprocessable();
    expect($node->fresh()->agent_secret_hash)->toBe($before);
    expect(json_encode($op->fresh()->toArray()))->not->toContain($hash);
});

it('keeps private output authority on the exact owning Node', function (): void {
    [$node, $source, , $runId] = directRekeySource($this);
    $op = DB::transaction(fn () => app(StartNodeAgentRekeyAction::class)->execute($node->id, $source->id));
    $foreign = FleetFixtures::node('foreign-direct-rekey-caller');
    $foreign->accessibleNodes()->syncWithoutDetaching(Node::query()->whereHas('roles', static fn ($q) => $q->where('role', 'gateway'))->pluck('id')->all());
    config(['operation_executor.worker_node_ids' => [$foreign->id], 'dependency_detection.services_node_id' => (string) $foreign->id]);
    $this->withServerVariables(['REMOTE_ADDR' => $foreign->wireguard_ip]);
    $before = $node->agent_secret_hash;
    $this->postJson('/api/v1/direct-operations/'.$op->id.'/secret-output', ['run_id' => $runId, 'outputs' => ['secret_hash' => str_repeat('d', 64)]])->assertForbidden();
    expect($node->fresh()->agent_secret_hash)->toBe($before);
});
