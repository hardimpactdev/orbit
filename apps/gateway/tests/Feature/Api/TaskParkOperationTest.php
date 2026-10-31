<?php

declare(strict_types=1);

use App\Domain\Instances\InstanceState;
use App\Domain\Operations\OperationKinds;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tasks\AssistanceKind;
use App\Domain\Tasks\TaskAssistance;
use App\Domain\Tasks\TaskExecutionLock;
use App\Domain\Tasks\TaskExtensionState;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskOrchestration;
use App\Domain\Tasks\TaskStatus;
use App\Domain\TaskVms\TaskVmProvider;
use App\Domain\TaskVms\TaskVmSettings;
use App\Domain\TaskVms\TaskVmState;
use App\Domain\TaskVms\TaskVmUpCloud;
use App\Domain\TaskVms\VmObservation;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\TaskVms\UpCloudApi;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskVm;
use App\Models\TaskWorkspaceLeaseRecord;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Support\FakeRoutePublicationSocket;
use Tests\Support\RecordingTaskVmProvider;
use Tests\Support\TaskVmSettingsBuilder;

beforeEach(function (): void {
    enable_operation_queue();
    app(TaskExtensionState::class)->enable();
    $node = $this->markAsGateway(Node::query()->create(['name' => 'park-gateway', 'status' => LifecycleStatus::Active, 'platform' => 'linux', 'architecture' => 'x86_64', 'wireguard_ip' => '10.44.0.1', 'public_ssh_host' => '192.0.2.1', 'user' => 'orbit']));
    $this->withServerVariables(['REMOTE_ADDR' => $node->wireguard_ip]);
    $project = Project::query()->create(['name' => 'Park', 'slug' => 'park', 'repository_url' => 'git@github.com:acme/park.git']);
    $this->group = Task::topLevel()->create(['project_id' => $project->id, 'title' => 'Batch', 'brief' => 'Batch', 'orchestration' => TaskOrchestration::External,
        'status' => TaskGroupStatus::Running, 'pr_url' => 'https://github.com/acme/park/pull/1', 'watched_pr_state' => 'open']);
    $this->child = Task::query()->create(['parent_id' => $this->group->id, 'position' => 1, 'title' => 'Update', 'brief' => 'Update', 'status' => TaskStatus::Completed]);
    $instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $node->id, 'name' => 'park-workspace', 'task_workspace_owner_id' => $this->group->id, 'branch_override' => 'task-'.$this->group->id, 'source_layout' => 'checkout', 'checkout_path' => '/srv/park', 'status' => InstanceState::SourceResolved]);
    $this->group->taskable()->associate($instance);
    $this->group->save();
    $this->vm = TaskVm::query()->create(['group_id' => $this->group->id, 'node_id' => $node->id, 'host_node_id' => $node->id, 'provider' => 'incus', 'name' => 'tvm-park', 'wireguard_ip' => '10.44.0.2', 'state' => TaskVmState::Ready]);
    $this->provider = new RecordingTaskVmProvider;
    $this->provider->observations = [new VmObservation(true, null), new VmObservation(false, null)];
    app()->instance(TaskVmProvider::class, $this->provider);
    TaskVmSettingsBuilder::bind(['hosts' => [TaskVmSettingsBuilder::host($node->id)]]);
    config(['swamp.run_token' => 'fixture.fake', 'dependency_detection.services_node_id' => $node->id]);
    $this->vm->update(['name' => 'tvm-'.$this->vm->id]);
    $this->run = '3fc3ad54-423a-4b4b-8187-99514098426b';
    $socket = new FakeRoutePublicationSocket;
    $socket->reply = fn (array $message): array => [['type' => 'event', 'id' => $message['id'], 'event' => ['kind' => 'started', 'workflowName' => 'operation-task-park', 'runId' => $this->run]]];
    app()->instance(WebSocketClient::class, $socket);
    $this->url = '/api/v1/task-groups/'.$this->group->id.'/park';
});

function parkResult(int $operationId, string $status = 'succeeded', ?array $result = null): TestResponse
{
    $result ??= ['group_id' => test()->group->id, 'task_vm_id' => test()->vm->id, 'instance_id' => test()->group->taskable_id,
        'status' => 'waiting_for_review', 'power' => 'stopped'];

    return test()->postJson('/api/v1/direct-operations/'.$operationId.'/result', ['run_id' => test()->run, 'status' => $status,
        'started_at' => '2026-10-31T00:00:00.000Z', 'finished_at' => $status === 'running' ? null : '2026-10-31T00:00:01.000Z',
        'result' => $result, 'error_code' => $status === 'failed' ? $result['error_code'] : null]);
}

it('admits one exact Node root and projects stopped proof without PHP execution', function (): void {
    $id = $this->withHeader('Idempotency-Key', 'review.park.1')->postJson($this->url)->assertAccepted()->json('data.id');
    $operation = Operation::query()->findOrFail($id);
    expect($operation->state['task_park_writer'])->toBe('direct')->and($operation->node_id)->toBe($this->vm->host_node_id)
        ->and($operation->lock_key)->toBeNull()->and($operation->worker)->toBeNull()->and($operation->children()->count())->toBe(0)
        ->and($this->vm->fresh()->state)->toBe(TaskVmState::Parked)->and($this->vm->fresh()->stop_confirmed_at)->toBeNull()
        ->and($this->group->fresh()->status)->toBe(TaskGroupStatus::Running)->and($this->provider->calls)->toBe([]);
    $this->getJson('/api/v1/direct-operations/'.$id.'?run_id='.$this->run)->assertOk()->assertJsonPath('data.input.eligible', true);
    parkResult($id)->assertOk();
    expect($operation->fresh()->status)->toBe(OperationStatus::Succeeded)->and($this->vm->fresh()->stop_confirmed_at)->not->toBeNull()
        ->and($this->group->fresh()->status)->toBe(TaskGroupStatus::WaitingForReview)
        ->and(Instance::query()->find($this->group->taskable_id))->not->toBeNull()->and($this->provider->calls)->toBe([]);
    $this->postJson($this->url)->assertAccepted()->assertJsonPath('data.id', $id);
    foreach (['task_vm.park', 'task_vm.confirm_stopped'] as $kind) {
        expect(app(OperationKinds::class)->definition($kind))->toBeNull();
    }
});

it('reports changed eligibility without an effect or a false board transition', function (): void {
    $id = $this->withHeader('Idempotency-Key', 'review.changed')->postJson($this->url)->assertAccepted()->json('data.id');
    $this->child->update(['status' => TaskStatus::Running]);
    $this->getJson('/api/v1/direct-operations/'.$id.'?run_id='.$this->run)->assertOk()->assertJsonPath('data.input.eligible', false);
    parkResult($id, 'failed', ['error_code' => 'tasks.park_refused'])->assertOk();
    expect($this->group->fresh()->status)->toBe(TaskGroupStatus::Running)->and($this->vm->fresh()->stop_confirmed_at)->toBeNull()
        ->and($this->vm->fresh()->error_code)->toBe('tasks.park_refused')->and($this->provider->calls)->toBe([]);
});

it('refuses foreign stopped proof and a late running result without changing park rows', function (): void {
    $id = $this->withHeader('Idempotency-Key', 'review.proof')->postJson($this->url)->assertAccepted()->json('data.id');
    parkResult($id, result: ['group_id' => $this->group->id, 'task_vm_id' => $this->vm->id + 1, 'instance_id' => $this->group->taskable_id,
        'status' => 'waiting_for_review', 'power' => 'stopped'])->assertUnprocessable();
    expect($this->vm->fresh()->stop_confirmed_at)->toBeNull();
    parkResult($id)->assertOk();
    $before = $this->vm->fresh()->getRawOriginal();
    parkResult($id, 'running', [])->assertConflict();
    expect($this->vm->fresh()->getRawOriginal())->toBe($before);
});

it('refuses a workspace owner change before private material or result projection', function (): void {
    $id = $this->withHeader('Idempotency-Key', 'review.owner')->postJson($this->url)->assertAccepted()->json('data.id');
    Instance::query()->whereKey($this->group->taskable_id)->update(['task_workspace_owner_id' => null]);
    $this->getJson('/api/v1/direct-operations/'.$id.'?run_id='.$this->run)->assertUnprocessable();
    parkResult($id)->assertUnprocessable();
    expect($this->group->fresh()->status)->toBe(TaskGroupStatus::Running)->and($this->vm->fresh()->stop_confirmed_at)->toBeNull();
});

it('keeps UpCloud credentials behind the exact same-root private callback', function (): void {
    $this->vm->update(['provider' => 'upcloud', 'host_node_id' => null, 'provider_id' => '8330af24-e530-48a0-810c-803f101c70ec']);
    TaskVmSettingsBuilder::bind(['upcloud' => new TaskVmUpCloud(false, [], '/fixture/upcloud', 1, 'fi-hel1', '1xCPU-2GB', 20, '00000000-0000-4000-8000-000000000001')]);
    app()->instance(UpCloudApi::class, new UpCloudApi(app(TaskVmSettings::class), 'ucat_fixture_PRIVATE'));
    $id = $this->withHeader('Idempotency-Key', 'review.private')->postJson($this->url)->assertAccepted()->json('data.id');
    $this->getJson('/api/v1/direct-operations/'.$id.'?run_id='.$this->run)->assertOk()->assertJsonMissing(['upcloud_token' => 'ucat_fixture_PRIVATE']);
    $this->getJson('/api/v1/direct-operations/'.$id.'/private-material?run_id='.$this->run)->assertOk()->assertJsonPath('data.upcloud_token', 'ucat_fixture_PRIVATE');
    $this->getJson('/api/v1/direct-operations/'.$id.'/private-material?run_id=920c3e86-2e22-46ce-9716-91aa0c3eec46')->assertConflict();
    Instance::query()->whereKey($this->group->taskable_id)->update(['task_workspace_owner_id' => null]);
    $this->getJson('/api/v1/direct-operations/'.$id.'/private-material?run_id='.$this->run)->assertUnprocessable();
});

it('refuses unsafe review parking without changing status or touching the provider', function (string $reason): void {
    match ($reason) {
        'child' => $this->child->update(['status' => TaskStatus::Running]),
        'preview' => $this->group->update(['preview' => true]),
        'assistance' => TaskAssistance::apply($this->group, AssistanceKind::Direction, 'Review', 'Review'),
        'child assistance' => TaskAssistance::apply($this->child, AssistanceKind::RemovalFailure, reason: 'Cleanup needs help'),
        'workspace owner' => Instance::query()->whereKey($this->group->taskable_id)->update(['task_workspace_owner_id' => null]),
        'no PR' => $this->group->update(['pr_url' => null]),
        'closed PR' => $this->group->update(['watched_pr_state' => 'closed']),
        'lease' => TaskWorkspaceLeaseRecord::query()->create(['group_id' => $this->group->id, 'instance_id' => $this->group->taskable_id, 'active_instance_id' => $this->group->taskable_id, 'kind' => 'check', 'owner' => 'other', 'status' => 'held', 'acquisition_sequence' => 1]),
    };
    $this->withHeader('Idempotency-Key', 'review.refuse')->postJson($this->url)->assertConflict()->assertJsonPath('error.code', 'tasks.park_refused');
    expect($this->group->fresh()->status)->toBe(TaskGroupStatus::Running)->and($this->provider->calls)->toBe([]);
})->with(['child', 'preview', 'assistance', 'child assistance', 'workspace owner', 'no PR', 'closed PR', 'lease']);

it('requires an empty body and key, authenticates the peer and refuses another open group intent', function (): void {
    $this->postJson($this->url)->assertUnprocessable();
    $this->withHeader('Idempotency-Key', 'review.body')->postJson($this->url, ['command' => 'stop'])->assertUnprocessable();
    $this->withHeader('Idempotency-Key', 'review.busy')->postJson($this->url)->assertAccepted();
    $this->withHeader('Idempotency-Key', 'other.review')->postJson($this->url)->assertConflict()->assertJsonPath('error.code', 'operation.target_busy');
    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.15'])->postJson($this->url)->assertForbidden();
});

it('commits admission and projection before releasing the shared group execution lock', function (): void {
    $baseline = DB::transactionLevel();
    $lock = new class implements TaskExecutionLock
    {
        public array $levels = [];

        public function synchronized(int $groupId, Closure $operation, bool $wait = true): mixed
        {
            $before = DB::transactionLevel();
            $result = $operation();
            $this->levels[] = [$groupId, $before, DB::transactionLevel()];

            return $result;
        }
    };
    app()->instance(TaskExecutionLock::class, $lock);
    $id = $this->withHeader('Idempotency-Key', 'review.serialized')->postJson($this->url)->assertAccepted()->json('data.id');
    parkResult($id)->assertOk();
    expect($lock->levels)->toBe([[$this->group->id, $baseline, $baseline], [$this->group->id, $baseline, $baseline]]);
});
