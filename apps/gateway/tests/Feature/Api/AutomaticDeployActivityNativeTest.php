<?php

declare(strict_types=1);

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\OperationStatus;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\Swamp\StartSwampRun;
use App\Infrastructure\Swamp\SwampCommandStep;
use App\Models\Activity;
use App\Models\Node;
use App\Models\Operation;
use Tests\Support\FakeRoutePublicationSocket;

beforeEach(function (): void {
    $this->instance = dev935_instance('native-policy-activity');
    $this->gateway = $this->markAsGateway(Node::query()->create(['name' => 'native-policy-gateway',
        'public_ssh_host' => '192.0.2.116', 'wireguard_ip' => '10.44.0.116', 'user' => 'orbit', 'platform' => 'linux', 'status' => 'active']));
    $this->withServerVariables(['REMOTE_ADDR' => $this->gateway->wireguard_ip]);
    $this->url = '/api/v1/instances/'.$this->instance->id.'/auto-deploy/events';
});

it('admits an existing event key and passes its public intent to one native run', function (): void {
    $socket = new FakeRoutePublicationSocket;
    app()->instance(WebSocketClient::class, $socket);
    config(['swamp.run_token' => 'fixture-policy-token']);
    $body = ['event' => 'activity', 'result' => 'commit_failed', 'commit' => str_repeat('b', 40)];
    $id = $this->withHeader('Idempotency-Key', 'dev.policy.native.1')->postJson($this->url, $body)
        ->assertAccepted()->assertJsonPath('data.kind', 'instance.policy_event')->json('data.id');
    $this->withHeader('Idempotency-Key', 'dev.policy.native.1')->postJson($this->url, $body)
        ->assertAccepted()->assertJsonPath('data.id', $id);
    $op = Operation::query()->findOrFail($id);
    app(StartSwampRun::class)->start($op);
    expect($socket->sent)->toHaveCount(1)
        ->and($socket->sent[0]['payload']['workflowIdOrName'])->toBe('operation-instance-policy-activity')
        ->and($socket->sent[0]['payload']['inputs']['intent'])->toMatchArray([
            ...$body, 'instance_id' => $this->instance->id, 'caller_node_id' => $this->gateway->id,
        ])
        ->and(Activity::query()->where('description', 'auto_deploy.commit_failed')->count())->toBe(0)
        ->and(Operation::query()->count())->toBe(1);
});

it('records the terminal Activity once and preserves the deployment hold', function (): void {
    $hold = ['result' => 'commit_failed', 'commit' => str_repeat('b', 40)];
    $this->instance->update(['auto_deploy_state' => $hold]);
    $op = Operation::query()->create(['kind' => 'instance.policy_event', 'target_type' => 'instance_policy',
        'target_id' => $this->instance->id, 'node_id' => $this->gateway->id, 'status' => OperationStatus::Running,
        'input' => ['event' => 'activity', ...$hold],
        'state' => ['swamp_run_id' => 'a075eb23-7ac4-4487-80d1-57a038a92676']]);
    $runId = $op->state['swamp_run_id'];
    $history = ['id' => $runId, 'workflowName' => 'operation-instance-policy-activity', 'status' => 'succeeded',
        'inputs' => ['operation_id' => $op->id, 'node_id' => $op->node_id, 'kind' => $op->kind],
        'startedAt' => '2026-10-31T19:00:00Z', 'completedAt' => '2026-10-31T19:00:01Z',
        'jobs' => [['name' => 'record', 'steps' => [['name' => 'record', 'outputs' => ['stdout' => '{"recorded":true}']]]]]];
    $socket = new FakeRoutePublicationSocket;
    $socket->reply = static fn (array $request): array => [['type' => 'workflow.history.get', 'id' => $request['id'], 'payload' => ['data' => $history]]];
    app()->instance(WebSocketClient::class, $socket);
    config(['swamp.run_token' => 'fixture-policy-token', 'swamp.read_token' => 'fixture-policy-read']);
    $terminal = app(SwampCommandStep::class);
    $selector = app(DirectOperation::class)->definition($op->kind)['terminal_result'];
    $first = $terminal->projectTerminal($op, ['id' => $runId, 'status' => 'succeeded'], $selector);
    $again = $terminal->projectTerminal($op, ['id' => $runId, 'status' => 'succeeded'], $selector);
    expect($first->status)->toBe(OperationStatus::Succeeded)
        ->and($again->result)->toBe($first->result)
        ->and($socket->sent)->toHaveCount(1)
        ->and(Activity::query()->where('description', 'auto_deploy.commit_failed')->count())->toBe(1)
        ->and($this->instance->fresh()->auto_deploy_state)->toBe($hold)
        ->and(Operation::query()->count())->toBe(1);
});

it('records no Activity for a failed or cancelled native root', function (string $status): void {
    $op = Operation::query()->create(['kind' => 'instance.policy_event', 'target_type' => 'instance_policy',
        'target_id' => $this->instance->id, 'node_id' => $this->gateway->id, 'status' => OperationStatus::Running,
        'input' => ['event' => 'activity', 'result' => 'started'],
        'state' => ['swamp_run_id' => 'a075eb23-7ac4-4487-80d1-57a038a92676']]);
    app(DirectOperation::class)->projection($op)->apply($op, ['run_id' => $op->state['swamp_run_id'],
        'status' => $status, 'result' => [], 'started_at' => '2026-10-31T19:00:00Z', 'finished_at' => '2026-10-31T19:00:01Z',
        'error_code' => $status === 'failed' ? 'operation.native_failed' : null]);
    expect(Activity::query()->where('description', 'auto_deploy.started')->count())->toBe(0)
        ->and($op->fresh()->status->value)->toBe($status);
})->with(['failed', 'cancelled']);

it('refuses unported notification events before creating an Operation', function (): void {
    $this->withHeader('Idempotency-Key', 'event.notification')->postJson($this->url, [
        'event' => 'stalled', 'result' => 'busy', 'commit' => str_repeat('b', 40), 'since' => '2026-10-31T19:00:00Z',
    ])->assertServiceUnavailable()->assertJsonPath('error.code', 'operation.kind_unavailable');
    expect(Operation::query()->count())->toBe(0);
});

it('requires Gateway authority for policy Activity admission', function (): void {
    $this->withServerVariables(['REMOTE_ADDR' => $this->instance->node->wireguard_ip]);
    $this->withHeader('Idempotency-Key', 'event.foreign')->postJson($this->url, [
        'event' => 'activity', 'result' => 'busy',
    ])->assertForbidden()->assertJsonPath('error.code', 'node_access.required');
    expect(Operation::query()->count())->toBe(0);
});
