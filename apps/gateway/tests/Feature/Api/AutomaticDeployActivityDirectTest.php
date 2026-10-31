<?php

declare(strict_types=1);

use App\Domain\Operations\OperationStatus;
use App\Domain\Releases\ReleaseAlertNotifier;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\Swamp\StartSwampRun;
use App\Models\Activity;
use App\Models\Node;
use App\Models\Operation;
use Tests\Support\FakeRoutePublicationSocket;
use Tests\Support\Fleet\RecordingReleaseAlertNotifier;

beforeEach(function (): void {
    $this->instance = dev935_instance('native-policy-activity');
    $this->instance->update(['auto_deploy' => true]);
    $this->caller = $this->markAsGateway(Node::query()->create(['name' => 'policy-caller',
        'platform' => 'linux', 'user' => 'orbit', 'status' => 'active',
        'public_ssh_host' => '192.0.2.118', 'wireguard_ip' => '10.44.0.118']));
    $this->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip]);
    $this->socket = new FakeRoutePublicationSocket;
    app()->instance(WebSocketClient::class, $this->socket);
    $this->notifier = new RecordingReleaseAlertNotifier;
    app()->instance(ReleaseAlertNotifier::class, $this->notifier);
    config(['swamp.run_token' => 'private.policy.fixture']);
    $this->path = '/api/v1/instances/'.$this->instance->id.'/auto-deploy/events';
    $this->payload = ['event' => 'activity', 'result' => 'started', 'commit' => str_repeat('a', 40)];
});

it('admits one keyed native Activity and projects its admitted record exactly once', function (): void {
    $id = $this->postJson($this->path, $this->payload, ['Idempotency-Key' => 'native.activity.1'])
        ->assertAccepted()->json('data.id');
    $this->postJson($this->path, $this->payload, ['Idempotency-Key' => 'native.activity.1'])
        ->assertAccepted()->assertJsonPath('data.id', $id);
    $operation = Operation::query()->findOrFail($id);
    app(StartSwampRun::class)->start($operation);
    expect($operation->node_id)->toBe($this->caller->id)
        ->and($this->socket->sent[0]['payload']['workflowIdOrName'])->toBe('operation-instance-policy-activity')
        ->and(Operation::query()->count())->toBe(1);
    $path = '/api/v1/direct-operations/'.$id;
    $this->getJson($path.'?run_id='.$operation->fresh()->state['swamp_run_id'])->assertOk()
        ->assertJsonPath('data.input.event', 'activity');
    $body = ['run_id' => $operation->fresh()->state['swamp_run_id'], 'status' => 'succeeded',
        'started_at' => '2026-10-31T08:00:00.000Z', 'finished_at' => '2026-10-31T08:00:00.000Z',
        'result' => ['program' => 'ignored native field']];
    $this->postJson($path.'/result', $body)->assertOk()->assertJsonPath('data.status', 'succeeded')
        ->assertJsonMissingPath('data.result.program');
    $this->postJson($path.'/result', $body)->assertOk();
    $activity = Activity::query()->where('description', 'auto_deploy.started')->sole();
    expect($activity->subject_id)->toBe($this->instance->id)
        ->and($operation->fresh()->result['activity_id'])->toBe($activity->id)
        ->and($this->notifier->alerts)->toBe([]);
});

it('refuses a foreign native root before it records Activity', function (): void {
    $id = $this->postJson($this->path, $this->payload, ['Idempotency-Key' => 'native.activity.2'])
        ->assertAccepted()->json('data.id');
    $operation = Operation::query()->findOrFail($id);
    app(StartSwampRun::class)->start($operation);
    $this->postJson('/api/v1/direct-operations/'.$id.'/result',
        ['run_id' => '414a4549-081f-45bf-9b76-1ace0e7b9ee5', 'status' => 'succeeded',
            'started_at' => '2026-10-31T08:00:00.000Z', 'finished_at' => '2026-10-31T08:00:00.000Z', 'result' => []])
        ->assertConflict()->assertJsonPath('error.code', 'operation.run_mismatch');
    expect(Activity::query()->where('description', 'auto_deploy.started')->count())->toBe(0)
        ->and($operation->fresh()->status)->toBe(OperationStatus::Running);
});

it('leaves unported release notifications unavailable without PHP notifier effects', function (): void {
    $this->postJson($this->path, ['event' => 'failed', 'deployment_id' => 1],
        ['Idempotency-Key' => 'native.activity.alert'])->assertServiceUnavailable()
        ->assertJsonPath('error.code', 'operation.kind_unavailable');
    expect(Operation::query()->count())->toBe(0)->and($this->notifier->alerts)->toBe([]);
});
