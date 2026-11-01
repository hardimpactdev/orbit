<?php

declare(strict_types=1);

use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\Doctor\DoctorExecutionInput;
use App\Infrastructure\Swamp\SwampCommandStep;
use App\Jobs\Operations\ProjectSwampTerminal;
use App\Models\Node;
use App\Models\Operation;
use Tests\Support\FakeRoutePublicationSocket;

beforeEach(function (): void {
    $this->node = Node::query()->create(['name' => 'doctor-ssh', 'platform' => 'linux', 'status' => 'active', 'user' => 'orbit', 'wireguard_ip' => '10.44.0.9', 'public_ssh_host' => '192.0.2.9', 'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'fixture-public-key']);
    $this->operation = Operation::query()->create(['kind' => 'doctor.inspect', 'target_type' => 'node', 'target_id' => $this->node->id, 'node_id' => $this->node->id, 'status' => 'running', 'lock_key' => 'doctor-fixture', 'sequence' => 1,
        'state' => ['swamp_run_id' => '653aa66b-2104-49dc-9522-dea26f20fe7c'], 'input' => ['collect_problems' => false]]);
    $this->intent = ['nodes' => [['id' => $this->node->id, 'managed' => true, 'parked' => false, 'resources' => (object) []]], 'families' => ['node'], 'collect_problems' => false];
    $this->socket = new FakeRoutePublicationSocket;
    app()->instance(WebSocketClient::class, $this->socket);
    config(['swamp.address' => '192.0.2.209', 'swamp.ca_path' => '/public/ca.pem', 'swamp.run_token' => 'private-fixture', 'swamp.read_token' => 'read-fixture']);
});

it('exports public SSH placement without reading or copying private material', function (): void {
    $result = app(DoctorExecutionInput::class)->forIntent($this->intent);
    expect($result)->toHaveKeys(['intent'])->not->toHaveKey('execution_secrets');
    expect($result['intent']['nodes'][0]['ssh'])->toMatchArray(['host' => '10.44.0.9', 'user' => 'orbit', 'port' => 22, 'host_keys' => ['ssh-ed25519 fixture-public-key']]);
    expect($this->socket->sent)->toBe([]);
});

it('does not require SSH for parked and unmanaged Nodes', function (array $flags): void {
    $this->intent['nodes'][0] = [...$this->intent['nodes'][0], ...$flags];
    expect(app(DoctorExecutionInput::class)->forIntent($this->intent)['intent']['nodes'][0])->not->toHaveKey('ssh');
    expect($this->socket->sent)->toBe([]);
})->with([[['parked' => true]], [['managed' => false]]]);

it('projects actual Doctor failure and interruption through the shared fixed-selector job', function (string $status): void {
    $run = $this->operation->state['swamp_run_id'];
    $this->socket->reply = fn (array $request): array => [['type' => 'workflow.history.get', 'id' => $request['id'], 'payload' => ['data' => ['id' => $run, 'workflowName' => 'operation-doctor-inspect', 'status' => $status, 'inputs' => ['operation_id' => $this->operation->id, 'node_id' => $this->node->id, 'kind' => 'doctor.inspect'], 'startedAt' => '2026-11-01T01:00:00Z', 'completedAt' => '2026-11-01T01:01:00Z', 'jobs' => [['name' => 'report', 'steps' => [['name' => 'result', 'status' => 'skipped']]]]]]]];
    (new ProjectSwampTerminal($this->operation->id, $run, $status))->handle(app(SwampCommandStep::class));
    expect($this->operation->fresh()->status->value)->toBe($status)->and($this->operation->fresh()->error_code)->toBe('operation.native_failed')->and($this->operation->fresh()->lock_key)->toBeNull();
    expect($this->socket->sent)->toHaveCount(1);
})->with(['failed', 'interrupted']);

it('projects the matching native report through the shared terminal reader', function (): void {
    $run = $this->operation->state['swamp_run_id'];
    $report = ['nodes' => [['node_id' => $this->node->id, 'node_name' => $this->node->name, 'families' => [['family' => 'node', 'checked' => 1, 'issues' => [], 'observed_resources' => [$this->node->id], 'observed_checks' => [], 'observed_inventories' => []]]]]];
    $this->socket->reply = fn (array $request): array => [['type' => 'workflow.history.get', 'id' => $request['id'], 'payload' => ['data' => ['id' => $run, 'workflowName' => 'operation-doctor-inspect', 'status' => 'succeeded', 'inputs' => ['operation_id' => $this->operation->id, 'node_id' => $this->node->id, 'kind' => 'doctor.inspect'], 'startedAt' => '2026-11-01T01:00:00Z', 'completedAt' => '2026-11-01T01:01:00Z', 'jobs' => [['name' => 'report', 'steps' => [['name' => 'result', 'status' => 'succeeded', 'outputs' => ['exitCode' => 0, 'stdout' => json_encode(['report' => $report], JSON_THROW_ON_ERROR)]]]]]]]]];
    (new ProjectSwampTerminal($this->operation->id, $run, 'succeeded'))->handle(app(SwampCommandStep::class));
    expect($this->operation->fresh()->status->value)->toBe('succeeded')
        ->and($this->operation->fresh()->result['report']['healthy'])->toBeTrue()
        ->and($this->operation->fresh()->lock_key)->toBeNull()
        ->and($this->socket->sent)->toHaveCount(1);
});
