<?php

declare(strict_types=1);

use App\Domain\Instances\Deployment\AutomaticDeployFireProjection;
use App\Domain\Operations\DirectOperation;
use App\Domain\Releases\ReleaseAlertKind;
use App\Domain\Releases\ReleaseAlertNotifier;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\Swamp\SwampCommandStep;
use App\Models\Activity;
use App\Models\InstanceDeployment;
use App\Models\Operation;
use Tests\Support\FakeRoutePublicationSocket;
use Tests\Support\Fleet\RecordingReleaseAlertNotifier;

beforeEach(function (): void {
    $this->instance = dev935_instance('fire-projection');
    $this->instance->project->update(['repository_url' => 'https://github.com/example/fire-projection.git']);
    $this->notifier = new RecordingReleaseAlertNotifier;
    app()->instance(ReleaseAlertNotifier::class, $this->notifier);
    $this->sha = str_repeat('b', 40);
    $this->run = ['id' => 'a075eb23-7ac4-4487-80d1-57a038a92676', 'status' => 'failed', 'startedAt' => '2026-11-02T08:00:30Z'];
});

function fire_event(int $instance, string $key, array $body): array
{
    return ['instance' => $instance, 'key' => $key, 'body' => $body];
}

it('declares the automatic-deploy fire as a scheduled result with no Operation kind', function (): void {
    expect(app(DirectOperation::class)->scheduledResultDefinitions()[AutomaticDeployFireProjection::Workflow])
        ->toMatchArray(['workflow' => 'automatic-production-deploy', 'terminal_result' => ['job' => 'policy', 'step' => 'failures', 'native' => true,
            'captures' => ['fire_json' => ['job' => 'policy', 'step' => 'tick', 'field' => 'fire_json']]]])
        ->and(app(DirectOperation::class)->definition('instance.policy_event'))->toBeNull();
});

it('records each event of a fire once, as Activity or a release alert, even when the fire failed', function (): void {
    $deployment = InstanceDeployment::query()->create(['instance_id' => $this->instance->id, 'kind' => 'deploy', 'commit' => $this->sha,
        'started_at' => now(), 'finished_at' => now(), 'status' => 'failed', 'failed_step' => 'migrate', 'error_code' => 'deployment.step_failed']);
    $result = ['events' => [
        fire_event($this->instance->id, 'policy.1.activity.1', ['event' => 'activity', 'result' => 'failed', 'commit' => $this->sha, 'error_code' => 'deployment.automatic_failed']),
        fire_event($this->instance->id, 'policy.1.failed', ['event' => 'failed', 'commit' => $this->sha, 'deployment_id' => $deployment->id]),
        fire_event($this->instance->id, 'policy.1.stalled', ['event' => 'stalled', 'commit' => $this->sha, 'since' => '2026-11-02T07:00:00Z', 'result' => 'gate_pending']),
    ], 'failures' => [['instance' => 99, 'error_code' => 'gateway.read_unavailable', 'message' => 'x']]];

    $projection = app(AutomaticDeployFireProjection::class);
    $projection->applyScheduled(AutomaticDeployFireProjection::Workflow, $this->run, ['fire_json' => json_encode($result, JSON_THROW_ON_ERROR)]);
    $projection->applyScheduled(AutomaticDeployFireProjection::Workflow, $this->run, ['fire_json' => json_encode($result, JSON_THROW_ON_ERROR)]);

    expect(Activity::query()->where('description', 'auto_deploy.failed')->count())->toBe(1)
        ->and(array_map(static fn ($alert) => $alert->kind, $this->notifier->alerts))
        ->toBe([ReleaseAlertKind::InstanceDeployFailed, ReleaseAlertKind::InstanceDeployStalled])
        ->and(Operation::query()->count())->toBe(0);
});

it('skips an invalid event or a foreign deployment and still records the others', function (): void {
    $other = dev935_instance('fire-projection-other');
    $foreign = InstanceDeployment::query()->create(['instance_id' => $other->id, 'kind' => 'deploy', 'commit' => $this->sha,
        'started_at' => now(), 'finished_at' => now(), 'status' => 'failed']);
    app(AutomaticDeployFireProjection::class)->applyScheduled(AutomaticDeployFireProjection::Workflow, $this->run, ['fire_json' => json_encode(['events' => [
        fire_event($this->instance->id, 'policy.1.bad', ['event' => 'stalled', 'commit' => 'main']),
        fire_event($this->instance->id, 'policy.1.foreign', ['event' => 'failed', 'commit' => $this->sha, 'deployment_id' => $foreign->id]),
        fire_event($this->instance->id, 'policy.1.activity.2', ['event' => 'activity', 'result' => 'started', 'commit' => $this->sha]),
    ]], JSON_THROW_ON_ERROR)]);

    expect(Activity::query()->where('description', 'auto_deploy.started')->count())->toBe(1)
        ->and($this->notifier->alerts)->toBe([]);
});

it('reads the complete producer receipt from native history when the fire succeeds or fails', function (string $status): void {
    $run = [...$this->run, 'status' => $status, 'workflowName' => 'automatic-production-deploy', 'workflowId' => AutomaticDeployFireProjection::Workflow, 'completedAt' => '2026-11-02T08:00:31Z',
        'jobs' => [['name' => 'policy', 'steps' => [
            ['name' => 'tick', 'status' => 'succeeded', 'outputs' => ['fire_json' => json_encode(['events' => [
                fire_event($this->instance->id, 'large.1', ['event' => 'activity', 'result' => 'started', 'message' => str_repeat('x', 3500)]),
                fire_event($this->instance->id, 'large.2', ['event' => 'activity', 'result' => 'busy', 'message' => str_repeat('y', 3500)]),
            ], 'failures' => []], JSON_THROW_ON_ERROR)]],
            ['name' => 'failures', 'status' => $status],
        ]]]];
    $socket = new FakeRoutePublicationSocket;
    $socket->reply = static fn (array $request): array => [['type' => 'workflow.history.get', 'id' => $request['id'], 'payload' => ['data' => $run]]];
    app()->instance(WebSocketClient::class, $socket);
    config(['swamp.read_token' => 'native-reader', 'swamp.address' => '192.0.2.209', 'swamp.ca_path' => '/public/ca.pem']);
    expect(app(SwampCommandStep::class)->projectScheduledTerminal(AutomaticDeployFireProjection::Workflow,
        ['id' => $run['id'], 'status' => $status]))->toBeTrue();
    expect(Activity::query()->whereIn('description', ['auto_deploy.started', 'auto_deploy.busy'])->count())->toBe(2)
        ->and(array_column($socket->sent, 'type'))->toBe(['workflow.history.get'])
        ->and(Operation::query()->count())->toBe(0);
})->with(['succeeded', 'failed']);
