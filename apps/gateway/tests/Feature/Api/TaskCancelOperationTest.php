<?php

declare(strict_types=1);

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\TaskCancelProjection;
use App\Domain\Tasks\TaskExtensionState;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskStatus;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Project;
use App\Models\Task;
use Tests\Support\FakeRoutePublicationSocket;

beforeEach(function (): void {
    enable_operation_queue();
    app(TaskExtensionState::class)->enable();
    $node = $this->markAsGateway(Node::query()->forceCreate(['id' => 209, 'name' => 'cancel-gateway', 'status' => LifecycleStatus::Active, 'platform' => 'linux', 'architecture' => 'x86_64', 'wireguard_ip' => '10.44.0.1', 'public_ssh_host' => '192.0.2.1', 'user' => 'orbit']));
    $this->withServerVariables(['REMOTE_ADDR' => $node->wireguard_ip]);
    $project = Project::query()->create(['name' => 'Cancel', 'slug' => 'cancel', 'repository_url' => 'git@github.com:acme/cancel.git']);
    $this->group = Task::topLevel()->create(['project_id' => $project->id, 'title' => 'Cancel', 'brief' => 'Cancel', 'orchestration' => 'external', 'status' => TaskGroupStatus::Todo]);
    $this->child = Task::query()->create(['parent_id' => $this->group->id, 'title' => 'Child', 'brief' => 'Child', 'position' => 1, 'status' => TaskStatus::Todo]);
    config(['swamp.run_token' => 'fixture.fake', 'swamp.address' => '192.0.2.209', 'swamp.ca_path' => '/public/ca.pem']);
    $socket = new FakeRoutePublicationSocket;
    $socket->reply = static fn (array $message): array => [['type' => 'event', 'id' => $message['id'], 'event' => ['kind' => 'started', 'workflowName' => 'operation-task-cancel', 'runId' => '8ba8f6c9-0a5e-44da-9710-4bde40935894']]];
    app()->instance(WebSocketClient::class, $socket);
    $this->url = '/api/v1/task-groups/'.$this->group->id.'/cancel';
});

it('admits keyed native cancellation without prematurely ending a board group', function (): void {
    $id = $this->withHeader('Idempotency-Key', 'cancel.fixture')->postJson($this->url)->assertAccepted()->json('data.id');
    $operation = Operation::query()->findOrFail($id);
    expect($operation->kind)->toBe('tasks.cancel')->and($operation->node_id)->toBe(209)
        ->and($operation->input['teardown'])->toBe([])
        ->and($this->group->fresh()->status)->toBe(TaskGroupStatus::Todo)
        ->and(app(DirectOperation::class)->definition('tasks.cancel')['terminal_result'])->toBe(['job' => 'cancel', 'step' => 'result']);
    $this->postJson($this->url)->assertAccepted()->assertJsonPath('data.id', $id);
    $operation->update(['status' => OperationStatus::Running]);
    app(TaskCancelProjection::class)->apply($operation, ['run_id' => '8ba8f6c9-0a5e-44da-9710-4bde40935894', 'started_at' => now()->toISOString(), 'finished_at' => now()->toISOString(), 'status' => 'succeeded', 'result' => ['group_id' => $this->group->id, 'instance_id' => null, 'cleanup_complete' => true], 'error_code' => null]);
    expect($this->group->fresh()->status)->toBe(TaskGroupStatus::Cancelled)->and($this->child->fresh()->status)->toBe(TaskStatus::Cancelled);
});

it('does not cancel an existing cancellation when a different key retries', function (): void {
    $id = $this->withHeader('Idempotency-Key', 'cancel.first')->postJson($this->url)->assertAccepted()->json('data.id');
    $this->withHeader('Idempotency-Key', 'cancel.second')->postJson($this->url)->assertConflict()->assertJsonPath('error.code', 'operation.target_busy');
    expect(Operation::query()->findOrFail($id)->cancel_requested_at)->toBeNull();
});

it('retains board ownership on native failure and rejects forged cleanup receipts', function (): void {
    $id = $this->withHeader('Idempotency-Key', 'cancel.failure')->postJson($this->url)->assertAccepted()->json('data.id');
    $operation = Operation::query()->findOrFail($id);
    expect(fn () => app(TaskCancelProjection::class)->apply($operation, ['run_id' => '8ba8f6c9-0a5e-44da-9710-4bde40935894', 'started_at' => now()->toISOString(), 'finished_at' => now()->toISOString(), 'status' => 'succeeded', 'result' => ['group_id' => $this->group->id, 'instance_id' => null, 'cleanup_complete' => false], 'error_code' => null]))->toThrow(ResourceOperationException::class);
    app(TaskCancelProjection::class)->apply($operation->fresh(), ['run_id' => '8ba8f6c9-0a5e-44da-9710-4bde40935894', 'started_at' => now()->toISOString(), 'finished_at' => now()->toISOString(), 'status' => 'failed', 'result' => [], 'error_code' => 'tasks.cleanup_failed']);
    expect($this->group->fresh()->status)->toBe(TaskGroupStatus::Todo)->and($this->child->fresh()->status)->toBe(TaskStatus::Todo);
});

it('requires a key and refuses cancellation of completed groups', function (): void {
    $this->postJson($this->url)->assertUnprocessable();
    $this->group->update(['status' => TaskGroupStatus::Completed]);
    $this->withHeader('Idempotency-Key', 'cancel.closed')->postJson($this->url)->assertConflict()->assertJsonPath('error.code', 'tasks.not_cancellable');
});
