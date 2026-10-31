<?php

declare(strict_types=1);

use App\Domain\Fleet\FleetReleaseProjection;
use App\Domain\Fleet\NodeCliState;
use App\Domain\Gateway\GatewayServingHost;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Models\FleetRollout;
use App\Models\Operation;
use Tests\Support\FakeAgentCommandRunner;
use Tests\Support\FakeRoutePublicationSocket;
use Tests\Support\Fleet\FleetFixtures;

beforeEach(function (): void {
    FleetFixtures::bind();
    $this->gateway = FleetFixtures::node('native-fleet-gateway', [RoleName::Gateway]);
    app(GatewayServingHost::class)->remember($this->gateway);
    $this->dev = FleetFixtures::node('native-fleet-dev', [RoleName::AppDev]);
    $this->prod = FleetFixtures::node('native-fleet-prod', [RoleName::AppProd]);
    $this->agents = new FakeAgentCommandRunner;
    app()->instance(AgentCommandRunner::class, $this->agents);
    $this->run = 'a075eb23-7ac4-4487-80d1-57a038a92676';
    $this->socket = new FakeRoutePublicationSocket;
    $this->socket->reply = fn (array $m): array => [['type' => 'event', 'id' => $m['id'], 'event' => [
        'kind' => 'started', 'workflowName' => $m['payload']['workflowIdOrName'], 'runId' => $this->run]]];
    app()->instance(WebSocketClient::class, $this->socket);
    config(['swamp.run_token' => 'fake.token']);
    $this->withServerVariables(['REMOTE_ADDR' => $this->gateway->wireguard_ip]);
});

it('admits the whole rollout into one native run without Node actions or children', function (): void {
    $id = $this->withHeader('Idempotency-Key', 'fleet-native-rollout')->postJson('/api/v1/fleet/rollout')->assertAccepted()->json('data.id');
    $operation = Operation::findOrFail($id);
    expect($operation->kind)->toBe('fleet.rollout')->and($operation->node_id)->toBe($this->gateway->id)
        ->and(array_column($operation->input['nodes'], 'node_id'))->toBe([$this->dev->id, $this->prod->id])
        ->and($operation->input['nodes'][0]['daytime'])->toBeTrue()
        ->and($operation->input['nodes'][1]['daytime'])->toBeFalse()
        ->and($this->socket->sent)->toHaveCount(1)->and(Operation::count())->toBe(1)
        ->and($this->agents->calls)->toBe([]);
    $this->postJson('/api/v1/fleet/rollout')->assertAccepted()->assertJsonPath('data.id', $id);
    expect($this->socket->sent)->toHaveCount(1);
});

it('admits a CLI subset and rejects an excluded Node before starting', function (): void {
    $id = $this->postJson('/api/v1/fleet/cli/converge', ['node_ids' => [$this->dev->id]])->assertAccepted()->json('data.id');
    $operation = Operation::findOrFail($id);
    expect(array_column($operation->input['nodes'], 'node_id'))->toBe([$this->dev->id])
        ->and($operation->input['nodes'][0]['agent'])->toBeNull()->and($this->agents->calls)->toBe([]);
    $this->postJson('/api/v1/fleet/cli/converge', ['node_ids' => [$this->gateway->id]])->assertUnprocessable();
});

it('records only admitted results and keeps later Nodes pending after a halt', function (): void {
    $id = $this->postJson('/api/v1/fleet/rollout')->assertAccepted()->json('data.id');
    $operation = Operation::findOrFail($id);
    $result = ['nodes' => [
        ['node_id' => $this->dev->id, 'outcome' => 'failed', 'error_code' => 'cli.checksum_mismatch', 'cli_version' => null, 'agent_version' => null, 'step' => 'cli', 'ok' => false],
        ['node_id' => $this->prod->id, 'outcome' => 'pending', 'error_code' => null, 'cli_version' => null, 'agent_version' => null, 'step' => 'admission', 'ok' => false],
    ], 'error_code' => 'cli.checksum_mismatch', 'rollout_status' => 'halted', 'halted_node_id' => $this->dev->id];
    $body = ['run_id' => $this->run, 'status' => 'failed', 'started_at' => now()->toIso8601String(), 'finished_at' => now()->toIso8601String(), 'error_code' => 'cli.checksum_mismatch', 'result' => $result];
    $invalid = $body;
    $invalid['result']['nodes'][0]['node_id'] = $this->gateway->id;
    expect(fn () => app(FleetReleaseProjection::class)->apply($operation, $invalid))->toThrow(ResourceOperationException::class);
    expect($operation->refresh()->status)->toBe(OperationStatus::Running);
    app(FleetReleaseProjection::class)->apply($operation, $body);
    $rollout = FleetRollout::findOrFail($operation->input['rollout_id']);
    expect($rollout->status->value)->toBe('halted')->and($rollout->halted_node_id)->toBe($this->dev->id)
        ->and($rollout->nodes()->where('node_id', $this->prod->id)->sole()->outcome->value)->toBe('pending');
});

it('projects foreign CLI state only for an admitted Node', function (): void {
    $id = $this->postJson('/api/v1/fleet/cli/converge', ['node_ids' => [$this->dev->id]])->assertAccepted()->json('data.id');
    $operation = Operation::findOrFail($id);
    app(FleetReleaseProjection::class)->apply($operation, ['run_id' => $this->run, 'status' => 'succeeded',
        'started_at' => now()->toIso8601String(), 'finished_at' => now()->toIso8601String(),
        'result' => ['nodes' => [['node_id' => $this->dev->id, 'outcome' => 'skipped', 'error_code' => 'cli.foreign_binary', 'cli_version' => null, 'agent_version' => null, 'step' => 'cli', 'ok' => true]]]]);
    expect(app(NodeCliState::class)->isForeign($this->dev->refresh()))->toBeTrue();
});
