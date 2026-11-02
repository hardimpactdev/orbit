<?php

declare(strict_types=1);

use App\Domain\Operations\DirectOperation;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Models\Operation;
use Tests\Support\FakeRoutePublicationSocket;
use Tests\Support\Fleet\FleetFixtures;
use Tests\Support\Schedules\NativeScheduleReplies;

beforeEach(function (): void {
    FleetFixtures::bind();
    $this->node = FleetFixtures::node('rekey-owner');
    $this->node->forceFill(['agent_secret_hash' => str_repeat('a', 64)])->save();
    $agents = Mockery::mock(AgentCommandRunner::class);
    $agents->shouldNotReceive('run', 'install');
    app()->instance(AgentCommandRunner::class, $agents);
    $this->socket = new FakeRoutePublicationSocket;
    $this->socket->reply = fn (array $m): array => NativeScheduleReplies::for($m) ?? [['type' => 'event', 'id' => $m['id'], 'event' => ['kind' => 'started',
        'workflowName' => $m['payload']['workflowIdOrName'], 'runId' => '6a1f0e9c-3b2d-4c5e-8f7a-1b2c3d4e5f60']]];
    app()->instance(WebSocketClient::class, $this->socket);
    config(['swamp.run_token' => 'fixture']);
});

it('passes the new key only through one vault copy, records its verifier and replays the same attempt', function (): void {
    $this->artisan('orbit:node-agent-rekey', ['node' => $this->node->id])->assertSuccessful();
    $operation = Operation::query()->where('kind', 'node.agent_rekey')->sole();
    [$put, $start] = $this->socket->sent;
    $key = $put['payload']['value'];
    $inputs = $start['payload']['inputs'];
    expect($put['type'])->toBe('vault.put')
        ->and($put['payload']['key'])->toBe('node-agent-rekey-'.$this->node->id.'-1')
        ->and($key)->toMatch('/\A[a-f0-9]{64}\z/')
        ->and($operation->lock_key)->toBe('node-converge:node:'.$this->node->id)
        ->and($inputs['intent']['verifier_hash'])->toBe(hash('sha256', $key))
        ->and($inputs['intent'])->not->toHaveKey('agent_key')
        ->and($inputs['execution_secrets'])->toBe(['agent_key' => ['vault' => 'orbit-control-inputs', 'key' => 'node-agent-rekey-'.$this->node->id.'-1']])
        ->and($inputs['intent']['ssh']['target']['host'])->toBe($this->node->wireguard_ip)
        ->and(json_encode([$operation->input, $inputs]))->not->toContain($key)
        ->and(app(DirectOperation::class)->definition('node.agent_rekey')['terminal_result'])->toBe(['job' => 'rekey', 'step' => 'Prove the new key', 'native' => true]);
    $this->artisan('orbit:node-agent-rekey', ['node' => $this->node->id, '--attempt' => '1'])->assertSuccessful();
    expect(Operation::query()->where('kind', 'node.agent_rekey')->count())->toBe(1)
        ->and($this->socket->sent)->toHaveCount(2)
        ->and($this->node->fresh()->agent_secret_hash)->toBe(str_repeat('a', 64));
});

it('refuses while a converge holds the Node, deletes the unused copy, and refuses an unmanaged Node', function (): void {
    Operation::query()->create(['kind' => 'node.converge', 'target_type' => 'node', 'target_id' => $this->node->id, 'node_id' => $this->node->id,
        'status' => 'running', 'input' => [], 'state' => [], 'lock_key' => 'node-converge:node:'.$this->node->id]);
    $this->artisan('orbit:node-agent-rekey', ['node' => $this->node->id, '--attempt' => '2'])->assertFailed();
    expect(array_column($this->socket->sent, 'type'))->toBe(['vault.put', 'vault.delete'])
        ->and($this->socket->sent[1]['payload']['key'])->toBe('node-agent-rekey-'.$this->node->id.'-2');
    $this->node->update(['status' => 'removing']);
    $this->artisan('orbit:node-agent-rekey', ['node' => $this->node->id, '--attempt' => '3'])->assertFailed();
    expect(Operation::query()->where('kind', 'node.agent_rekey')->exists())->toBeFalse()
        ->and($this->socket->sent)->toHaveCount(2);
});
