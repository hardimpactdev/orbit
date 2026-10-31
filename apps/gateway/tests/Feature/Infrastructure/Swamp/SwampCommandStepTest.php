<?php

declare(strict_types=1);

use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\Swamp\SwampCommandStep;
use App\Models\Node;
use App\Models\Operation;
use Tests\Support\FakeRoutePublicationSocket;

beforeEach(function (): void {
    $this->node = Node::query()->create(['name' => 'command-target', 'user' => 'prior', 'platform' => 'linux', 'public_ssh_host' => '192.0.2.209']);
    $this->runId = '2a9c5e84-746a-487b-8621-4f6e1bb7d356';
    $this->operation = Operation::query()->create([
        'kind' => 'node.bootstrap_probe', 'target_type' => 'node', 'target_id' => $this->node->id, 'node_id' => $this->node->id,
        'input' => [], 'state' => ['swamp_run_id' => $this->runId], 'status' => OperationStatus::Running,
        'started_at' => '2026-10-31T12:00:00Z', 'sequence' => 1,
    ]);
    $this->history = ['id' => $this->runId, 'workflowName' => 'operation-node-bootstrap-probe', 'status' => 'succeeded',
        'inputs' => ['operation_id' => $this->operation->id, 'node_id' => $this->node->id, 'kind' => 'node.bootstrap_probe'],
        'startedAt' => '2026-10-31T12:00:00Z', 'completedAt' => '2026-10-31T12:00:01Z',
        'jobs' => [['name' => 'bootstrap', 'steps' => [
            ['name' => 'scan-host-key', 'outputs' => ['stdout' => '{"architecture":"discard"}']],
            ['name' => 'probe-host', 'outputs' => ['exitCode' => 0, 'stdout' => '{"probed":true,"architecture":"x86_64","public_key":"public-fixture"}']],
        ]]],
    ];
    $this->socket = new FakeRoutePublicationSocket;
    $this->socket->reply = fn (array $request): array => [['type' => 'workflow.history.get', 'id' => $request['id'], 'payload' => ['data' => $this->history]]];
    app()->instance(WebSocketClient::class, $this->socket);
    config(['swamp.run_token' => 'synthetic-gateway-token', 'swamp.ca_path' => '/public/ca.pem']);
    $this->step = app(SwampCommandStep::class);
    $this->selector = ['job' => 'bootstrap', 'step' => 'probe-host'];
});

it('reads history once after a native terminal event and projects only the fixed step stdout', function (): void {
    $terminal = ['id' => $this->runId, 'status' => 'succeeded'];
    $result = $this->step->projectTerminal($this->operation, $terminal, $this->selector);

    expect($result->status)->toBe(OperationStatus::Succeeded);
    expect($result->result)->toBe(['probed' => true, 'architecture' => 'x86_64', 'public_key' => 'public-fixture']);
    expect($this->node->fresh()->architecture)->toBe('x86_64')->and($this->node->fresh()->user)->toBe('prior');
    expect($this->socket->sent)->toHaveCount(1);
    expect($this->socket->sent[0])->toMatchArray(['type' => 'workflow.history.get', 'payload' => ['workflowIdOrName' => $this->runId]]);
    expect($this->socket->closed)->toBeTrue();
    $this->step->projectTerminal($result, $terminal, $this->selector);
    expect($this->socket->sent)->toHaveCount(1);
});

it('projects native failure cancellation and interruption when the selected step was skipped', function (string $native, OperationStatus $expected, ?string $code): void {
    $this->history['status'] = $native;
    $this->history['jobs'][0]['steps'][1] = ['name' => 'probe-host', 'status' => 'skipped'];
    $result = $this->step->projectTerminal($this->operation, ['id' => $this->runId, 'status' => $native], $this->selector);

    expect($result->status)->toBe($expected)->and($result->error_code)->toBe($code)->and($result->result)->toBe([]);
    expect($this->socket->sent)->toHaveCount(1);
    expect($this->node->fresh()->ssh_host_key)->toBeNull();
})->with([
    ['failed', OperationStatus::Failed, 'operation.native_failed'],
    ['cancelled', OperationStatus::Cancelled, null],
    ['interrupted', OperationStatus::Failed, 'operation.native_failed'],
]);

it('preserves a failed script public error from its stdout', function (): void {
    $this->history['status'] = 'failed';
    $this->history['jobs'][0]['steps'][1]['outputs']['stdout'] = '{"error_code":"node.platform_mismatch"}';
    $result = $this->step->projectTerminal($this->operation, ['id' => $this->runId, 'status' => 'failed'], $this->selector);
    expect($result->status)->toBe(OperationStatus::Failed)->and($result->error_code)->toBe('node.platform_mismatch');
});

it('refuses a nonterminal or foreign native event without a history request', function (array $terminal): void {
    expect(fn () => $this->step->projectTerminal($this->operation, $terminal, $this->selector))->toThrow(ResourceOperationException::class);
    expect($this->socket->sent)->toBeEmpty();
    expect($this->operation->fresh()->status)->toBe(OperationStatus::Running);
})->with([
    [['id' => 'foreign-run', 'status' => 'succeeded']],
    [['id' => '2a9c5e84-746a-487b-8621-4f6e1bb7d356', 'status' => 'running']],
]);

it('refuses mismatched history or unavailable public JSON without projecting', function (string $change): void {
    match ($change) {
        'run' => $this->history['id'] = 'foreign-run',
        'workflow' => $this->history['workflowName'] = 'unrelated',
        'owner' => $this->history['inputs']['node_id'] = $this->node->id + 1,
        'status' => $this->history['status'] = 'running',
        'missing' => $this->history['jobs'][0]['steps'][1]['outputs'] = [],
        'array' => $this->history['jobs'][0]['steps'][1]['outputs']['stdout'] = '[]',
        'json' => $this->history['jobs'][0]['steps'][1]['outputs']['stdout'] = 'synthetic-private-diagnostic',
    };
    try {
        $this->step->projectTerminal($this->operation, ['id' => $this->runId, 'status' => 'succeeded'], $this->selector);
        test()->fail('An unavailable native result must not project.');
    } catch (ResourceOperationException $error) {
        expect($error->errorCode)->toBe('swamp.terminal_result_unavailable');
        expect((string) $error)->not->toContain('synthetic-private-diagnostic');
        expect($error->getPrevious())->toBeNull();
    }
    expect($this->socket->sent)->toHaveCount(1);
    expect($this->operation->fresh()->status)->toBe(OperationStatus::Running);
    expect($this->node->fresh()->ssh_host_key)->toBeNull();
})->with(['run', 'workflow', 'owner', 'status', 'missing', 'array', 'json']);
