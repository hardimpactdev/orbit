<?php

declare(strict_types=1);

use App\Domain\Operations\OperationIdempotency;
use App\Domain\Projects\ScheduledProjectMaintenance;
use App\Domain\Tasks\TaskExtensionState;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Project;
use App\Models\Setting;
use Illuminate\Support\Str;
use Tests\Support\FakeRoutePublicationSocket;

beforeEach(function (): void {
    $this->markAsGateway(Node::query()->create(['name' => 'schedule-gateway', 'public_ssh_host' => '192.0.2.2', 'platform' => 'linux', 'wireguard_ip' => '10.44.0.2', 'status' => 'active']));
    Node::query()->forceCreate(['id' => 209, 'name' => 'swamp', 'public_ssh_host' => '192.0.2.209', 'platform' => 'linux', 'wireguard_ip' => '10.44.0.15', 'status' => 'active']);
    app(TaskExtensionState::class)->enable();
    $this->project = Project::query()->create(['name' => 'Schedule Project', 'slug' => 'schedule-project', 'source_access' => 'github_app', 'repository_url' => 'https://github.com/example/project.git', 'default_branch' => 'main', 'task_check' => 'composer check']);
    config(['swamp.address' => '192.0.2.209', 'swamp.ca_path' => '/fixture/ca.pem', 'swamp.run_token' => 'fixture.run']);
    $this->socket = new FakeRoutePublicationSocket;
    $this->socket->reply = static function (array $sent): array {
        if ($sent['type'] === 'workflow.trigger.set') {
            return [['type' => $sent['type'], 'id' => $sent['id'], 'payload' => ['data' => ['workflowName' => $sent['payload']['workflowName'], 'entry' => ['schedule' => $sent['payload']['schedule'], 'inputs' => json_decode(json_encode($sent['payload']['inputs'], JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR)]]]]];
        }

        return [['type' => 'event', 'id' => $sent['id'], 'event' => ['kind' => 'started', 'workflowName' => $sent['payload']['workflowIdOrName'], 'runId' => (string) Str::uuid()]]];
    };
    app()->instance(WebSocketClient::class, $this->socket);
    $this->schedule = app(ScheduledProjectMaintenance::class);
    $this->state = static fn (): array => json_decode(Setting::query()->where('key', ScheduledProjectMaintenance::StateKey)->firstOrFail()->value, true, 512, JSON_THROW_ON_ERROR);
    $this->schedule->activate();
    $inputs = ($this->state)()['inputs'];
    $this->run = ['id' => (string) Str::uuid(), 'startedAt' => now()->toISOString(), 'completedAt' => now()->toISOString(), 'status' => 'succeeded', 'inputs' => $inputs];
    $this->result = ['revision' => $inputs['revision'], 'snapshot_hash' => $inputs['snapshot_hash'], 'requests' => [['project_id' => $this->project->id, 'mode' => 'routine', 'key' => $this->project->id.'-routine-2026-10-26']]];
});

it('publishes full public schedule inputs without admitting an Operation at activation', function (): void {
    expect($this->socket->sent)->toHaveCount(1)
        ->and($this->socket->sent[0]['type'])->toBe('workflow.trigger.set')
        ->and($this->socket->sent[0]['payload']['workflowName'])->toBe('maintenance-projects')
        ->and($this->run['inputs']['projects'][0]['id'])->toBe($this->project->id)
        ->and(Operation::query()->count())->toBe(0)
        ->and(($this->state)()['published'])->toBeTrue();
});

it('admits the normal native Project Operation only on a terminal selection and makes restart replay a noop', function (): void {
    $this->schedule->applyScheduled(ScheduledProjectMaintenance::Workflow, $this->run, $this->result);
    app()->forgetInstance(ScheduledProjectMaintenance::class);
    app(ScheduledProjectMaintenance::class)->applyScheduled(ScheduledProjectMaintenance::Workflow, $this->run, $this->result);
    $operation = Operation::query()->sole();
    expect($operation->kind)->toBe('project.routine')->and($operation->target_id)->toBe($this->project->id)
        ->and($operation->input['project']['repository'])->toBe($this->project->repository_url)
        ->and($operation->state['swamp_run_id'])->toBeString()
        ->and(($this->state)()['admissions'][$this->project->id.':routine']['operation_id'])->toBe($operation->id)
        ->and(app(OperationIdempotency::class)->kind)->toBeNull();
    $starts = array_filter($this->socket->sent, static fn (array $sent): bool => $sent['type'] === 'workflow.run');
    expect($starts)->toHaveCount(1);
});

it('rejects stale, altered and failed selections without a downstream admission', function (string $cause): void {
    if ($cause === 'changed intent') {
        $this->project->update(['task_check' => 'new check']);
    } elseif ($cause === 'altered input') {
        $this->run['inputs']['projects'][0]['repository'] = 'https://github.com/other/repo.git';
    } elseif ($cause === 'wrong revision') {
        $this->result['revision']++;
    } else {
        $this->run['status'] = 'failed';
        $this->result = [];
    }
    $this->schedule->applyScheduled(ScheduledProjectMaintenance::Workflow, $this->run, $this->result);
    expect(Operation::query()->count())->toBe(0);
})->with(['changed intent', 'altered input', 'wrong revision', 'native failure']);

it('keeps failed schedule publication unaccepted and never starts a downstream operation', function (): void {
    $this->socket->reply = static fn (): array => [];
    $this->project->update(['task_check' => 'unpublished check']);
    expect(($this->state)()['published'])->toBeFalse();
    $this->schedule->applyScheduled(ScheduledProjectMaintenance::Workflow, $this->run, $this->result);
    expect(Operation::query()->count())->toBe(0);
});
