<?php

declare(strict_types=1);

use App\Domain\Operations\DirectOperation;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Models\Operation;
use Tests\Support\FakeRoutePublicationSocket;
use Tests\Support\Fleet\FleetFixtures;

beforeEach(function (): void {
    FleetFixtures::bind();
    $this->node = FleetFixtures::node('rekey-owner');
    $this->node->forceFill(['agent_secret_hash' => str_repeat('a', 64)])->save();
    $agents = Mockery::mock(AgentCommandRunner::class);
    $agents->shouldNotReceive('run', 'install');
    app()->instance(AgentCommandRunner::class, $agents);
    $this->socket = new FakeRoutePublicationSocket;
    $this->socket->reply = fn (array $m): array => [['type' => 'event', 'id' => $m['id'], 'event' => ['kind' => 'started',
        'workflowName' => $m['payload']['workflowIdOrName'], 'runId' => '6a1f0e9c-3b2d-4c5e-8f7a-1b2c3d4e5f60']]];
    app()->instance(WebSocketClient::class, $this->socket);
    config(['swamp.run_token' => 'fixture']);
});

it('starts a rekey with only the SSH target, under the converge lock, and replays the same attempt', function (): void {
    $this->artisan('orbit:node-agent-rekey', ['node' => $this->node->id])->assertSuccessful();
    $operation = Operation::query()->where('kind', 'node.agent_rekey')->sole();
    expect($operation->lock_key)->toBe('node-converge:node:'.$this->node->id)
        ->and($operation->input['_agent_rekey'])->toHaveKeys(['node_id', 'ssh'])
        ->and(json_encode($operation->input))->not->toContain(str_repeat('a', 64))
        ->and($this->socket->sent[0]['payload']['inputs']['intent']['ssh']['target']['host'])->toBe($this->node->wireguard_ip)
        ->and(app(DirectOperation::class)->definition('node.agent_rekey')['terminal_result'])->toBe(['job' => 'rekey', 'step' => 'Check the Node agent']);
    $this->artisan('orbit:node-agent-rekey', ['node' => $this->node->id, '--attempt' => '1'])->assertSuccessful();
    expect(Operation::query()->where('kind', 'node.agent_rekey')->count())->toBe(1)
        ->and($this->node->fresh()->agent_secret_hash)->toBe(str_repeat('a', 64));
});

it('refuses while a converge holds the Node and for an unmanaged Node', function (): void {
    Operation::query()->create(['kind' => 'node.converge', 'target_type' => 'node', 'target_id' => $this->node->id, 'node_id' => $this->node->id,
        'status' => 'running', 'input' => [], 'state' => [], 'lock_key' => 'node-converge:node:'.$this->node->id]);
    $this->artisan('orbit:node-agent-rekey', ['node' => $this->node->id, '--attempt' => '2'])->assertFailed();
    $this->node->update(['status' => 'removing']);
    $this->artisan('orbit:node-agent-rekey', ['node' => $this->node->id, '--attempt' => '3'])->assertFailed();
    expect(Operation::query()->where('kind', 'node.agent_rekey')->exists())->toBeFalse();
});
