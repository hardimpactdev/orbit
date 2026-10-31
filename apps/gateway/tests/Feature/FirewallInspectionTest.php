<?php

declare(strict_types=1);

use App\Domain\Nodes\RoleName;
use App\Domain\Operations\OperationStatus;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Models\Node;
use App\Models\Operation;
use Tests\Support\FakeAgentCommandRunner;
use Tests\Support\FakeRoutePublicationSocket;

beforeEach(function (): void {
    $gateway = Node::query()->create(['name' => 'inspection-gateway', 'public_ssh_host' => '192.0.2.1', 'user' => 'orbit', 'platform' => 'linux', 'wireguard_ip' => '10.44.0.1', 'status' => 'active']);
    $gateway->roles()->create(['role' => RoleName::Gateway, 'status' => 'active']);
    $this->node = Node::query()->create(['name' => 'inspection-node', 'public_ssh_host' => '192.0.2.9', 'user' => 'orbit', 'platform' => 'linux', 'architecture' => 'x86_64', 'wireguard_ip' => '10.44.0.9', 'status' => 'active']);
    $this->node->accessibleNodes()->attach($gateway);
    $this->withServerVariables(['REMOTE_ADDR' => $this->node->wireguard_ip]);
    $this->agents = new FakeAgentCommandRunner;
    app()->instance(AgentCommandRunner::class, $this->agents);
    $this->socket = new FakeRoutePublicationSocket;
    app()->instance(WebSocketClient::class, $this->socket);
    $this->runId = 'ac4b0f57-04bf-4705-b01d-d6e671c119cf';
    $this->shape = ['comment' => 'unmanaged-web', 'action' => 'allow', 'direction' => 'in', 'source' => '192.0.2.5', 'destination' => 'any',
        'port' => '443', 'protocol' => 'tcp', 'in_interface' => null, 'out_interface' => null, 'family' => 'v4'];
});

/** @param array<string,mixed> $result */
function firewall_inspection_operation(Node $node, string $runId, array $result = [], string $status = 'running'): Operation
{
    return Operation::query()->create(['kind' => 'firewall.inspect', 'target_type' => 'node_firewall', 'target_id' => $node->id,
        'node_id' => $node->id, 'status' => $status, 'input' => ['node_id' => $node->id], 'result' => $result,
        'state' => ['swamp_run_id' => $runId]]);
}

it('starts one placed native inspection without reading the agent or changing named rules', function (): void {
    config(['swamp.run_token' => 'inspection-fixture-token']);
    $this->socket->reply = fn (array $request): array => [['type' => 'event', 'id' => $request['id'], 'event' => ['kind' => 'started', 'workflowName' => 'operation-firewall-inspect', 'runId' => $this->runId]]];

    $response = $this->postJson('/api/v1/nodes/'.$this->node->id.'/firewall-inspection');

    $response->assertAccepted()->assertJsonPath('data.kind', 'firewall.inspect');
    $operation = Operation::query()->findOrFail($response->json('data.id'));
    expect($operation->input)->toBe(['node_id' => $this->node->id, 'caller_node_id' => $this->node->id])
        ->and($operation->state['swamp_run_id'])->toBe($this->runId)
        ->and($this->agents->calls)->toBe([])
        ->and($this->socket->sent)->toHaveCount(1)
        ->and($this->node->firewallRules()->count())->toBe(0);
});

it('projects only typed public snapshot fields and reads them without queuing work', function (): void {
    $operation = firewall_inspection_operation($this->node, $this->runId);
    $path = '/api/v1/direct-operations/'.$operation->id;
    $body = ['run_id' => $this->runId, 'status' => 'succeeded', 'started_at' => '2026-10-31T10:00:00.000Z', 'finished_at' => '2026-10-31T10:00:01.000Z',
        'result' => ['backend' => 'active', 'observed_at' => '2026-10-31T10:00:01.000Z', 'live' => [[...$this->shape, 'private' => 'sentinel-private']], 'password' => 'sentinel-private']];
    $this->postJson($path.'/result', $body)->assertOk()->assertJsonMissing(['password' => 'sentinel-private']);
    $this->postJson($path.'/result', $body)->assertOk();

    $this->getJson('/api/v1/nodes/'.$this->node->id.'/live-firewall-rules')->assertOk()
        ->assertJsonPath('data.backend_status', 'active')->assertJsonPath('data.observed_at', '2026-10-31T10:00:01.000Z')
        ->assertJsonPath('data.live.0.comment', 'unmanaged-web')->assertJsonPath('data.live.0.match', 'unmanaged');

    expect($operation->fresh()->status)->toBe(OperationStatus::Succeeded)
        ->and($operation->fresh()->result['live'])->toBe([$this->shape])
        ->and($this->agents->calls)->toBe([])->and($this->socket->sent)->toBe([]);
});

it('reports an unavailable snapshot when no native inspection has completed', function (): void {
    $this->getJson('/api/v1/nodes/'.$this->node->id.'/live-firewall-rules')->assertOk()
        ->assertJsonPath('data', ['backend_status' => 'unreachable', 'observed_at' => null, 'live' => [], 'missing' => []]);
    expect($this->agents->calls)->toBe([])->and($this->socket->sent)->toBe([])->and(Operation::query()->count())->toBe(0);
});

it('does not hide a newer failed inspection behind an older successful snapshot', function (): void {
    firewall_inspection_operation($this->node, $this->runId, ['backend' => 'active', 'observed_at' => '2026-10-31T10:00:01.000Z', 'live' => [$this->shape]], 'succeeded');
    firewall_inspection_operation($this->node, $this->runId, [], 'failed');

    $this->getJson('/api/v1/nodes/'.$this->node->id.'/live-firewall-rules')->assertOk()
        ->assertJsonPath('data.backend_status', 'unreachable')->assertJsonPath('data.observed_at', null)->assertJsonPath('data.live', []);
});

it('keeps backend state and observation time for an inactive native snapshot', function (): void {
    firewall_inspection_operation($this->node, $this->runId, ['backend' => 'inactive', 'observed_at' => '2026-10-31T10:00:01.000Z', 'live' => []], 'succeeded');

    $this->getJson('/api/v1/nodes/'.$this->node->id.'/live-firewall-rules')->assertOk()
        ->assertJsonPath('data.backend_status', 'inactive')->assertJsonPath('data.observed_at', '2026-10-31T10:00:01.000Z')->assertJsonPath('data.live', []);
});

it('denies foreign inspection admission and root result publication', function (): void {
    $operation = firewall_inspection_operation($this->node, $this->runId);
    $foreign = Node::query()->create(['name' => 'inspection-foreign', 'public_ssh_host' => '192.0.2.10', 'user' => 'orbit', 'platform' => 'linux', 'wireguard_ip' => '10.44.0.10', 'status' => 'active']);
    $this->withServerVariables(['REMOTE_ADDR' => $foreign->wireguard_ip]);

    $this->postJson('/api/v1/nodes/'.$this->node->id.'/firewall-inspection')->assertForbidden();
    $this->postJson('/api/v1/direct-operations/'.$operation->id.'/result', ['run_id' => $this->runId, 'status' => 'succeeded', 'started_at' => '2026-10-31T10:00:00.000Z', 'finished_at' => '2026-10-31T10:00:01.000Z', 'result' => []])->assertForbidden();
    expect($this->socket->sent)->toBe([])->and($this->agents->calls)->toBe([]);
});

it('preserves the explicit native size refusal without recording a partial snapshot', function (): void {
    $operation = firewall_inspection_operation($this->node, $this->runId);
    $this->postJson('/api/v1/direct-operations/'.$operation->id.'/result', [
        'run_id' => $this->runId, 'status' => 'failed', 'started_at' => '2026-10-31T10:00:00.000Z', 'finished_at' => '2026-10-31T10:00:01.000Z',
        'error_code' => 'firewall.inspection_too_large', 'result' => ['error_code' => 'firewall.inspection_too_large'],
    ])->assertOk();
    expect($operation->fresh()->error_code)->toBe('firewall.inspection_too_large')
        ->and($operation->fresh()->result)->toBe(['error_code' => 'firewall.inspection_too_large']);
});
