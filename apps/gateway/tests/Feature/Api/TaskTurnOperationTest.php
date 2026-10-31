<?php

declare(strict_types=1);

use App\Domain\Tasks\TaskExtensionState;
use App\Domain\Tasks\TaskTurnProjection;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\Processes\ProcessRunner;
use App\Models\AgentThread;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskVm;
use Tests\Support\AppDevFakeProcessRunner;
use Tests\Support\FakeRoutePublicationSocket;

/** @return array{Task, Task} */
function turn_operation_group(string $orchestration = 'external', int $nodeId = 91): array
{
    $node = test()->markAsGateway(Node::query()->forceCreate(['id' => $nodeId, 'name' => 'turn-node', 'platform' => 'linux', 'status' => 'active', 'wireguard_ip' => '10.44.0.91', 'public_ssh_host' => '192.0.2.91']));
    test()->withServerVariables(['REMOTE_ADDR' => $node->wireguard_ip]);
    app(TaskExtensionState::class)->enable();
    $project = Project::query()->create(['name' => 'Turn', 'slug' => 'turn', 'repository_url' => 'https://github.com/acme/turn.git']);
    $group = Task::topLevel()->create(['project_id' => $project->id, 'title' => 'Turn', 'brief' => 'Orchestrated.', 'status' => 'running', 'orchestration' => $orchestration, 'implementer_agent_driver' => 'pi', 'reviewer_agent_driver' => 'pi', 'implementer_model' => 'gpt-5.6-luna', 'reviewer_model' => 'gpt-5.6-luna']);
    $task = Task::query()->create(['parent_id' => $group->id, 'position' => 1, 'title' => 'One', 'brief' => 'One.', 'status' => 'running', 'deliverables' => [['id' => 'done', 'type' => 'review', 'description' => 'Done.']]]);
    $workspace = Instance::query()->create(['project_id' => $project->id, 'node_id' => $node->id, 'name' => 'task-'.$group->id, 'task_workspace_owner_id' => $group->id, 'checkout_path' => '/srv/turn', 'status' => 'source_resolved']);
    $group->taskable()->associate($workspace)->save();
    test_task_vm($group, $node);

    return [$group->refresh(), $task];
}

/** @return array{subtask: int, role: string, prompt: string, thread: string} */
function turn_operation_input(Task $task): array
{
    return ['subtask' => $task->id, 'role' => 'implementer', 'prompt' => 'Implement exactly this prompt.', 'thread' => 'new'];
}

beforeEach(function (): void {
    config(['swamp.run_token' => 'native-test', 'swamp.ca_path' => '/public/root.pem']);
    $this->processes = new AppDevFakeProcessRunner;
    app()->instance(ProcessRunner::class, $this->processes);
    $this->run = '21269a76-203b-400f-a5b9-2cc6e7d7e0ee';
    $this->socket = new FakeRoutePublicationSocket;
    $this->socket->reply = fn (array $message): array => [['type' => 'event', 'id' => $message['id'], 'event' => ['kind' => 'started', 'workflowName' => 'operation-task-turn', 'runId' => $this->run]]];
    app()->instance(WebSocketClient::class, $this->socket);
});

it('admits one native Pi session without sending a Pi or agent command', function (): void {
    [$group, $task] = turn_operation_group();
    $body = turn_operation_input($task);
    $url = '/api/v1/task-groups/'.$group->id.'/turn';
    $first = $this->withHeader('Idempotency-Key', 'native-turn')->postJson($url, $body)->assertAccepted();
    $id = $first->json('data.id');
    $operation = Operation::findOrFail($id);
    expect($operation->node_id)->toBe($group->taskable->node_id)
        ->and($operation->input['checkout_path'])->toBe('/srv/turn')
        ->and($operation->input['model'])->toBe('orbit-task-vm/gpt-5.6-luna')
        ->and($operation->state['swamp_run_id'])->toBe($this->run)
        ->and(AgentThread::query()->count())->toBe(1);
    $input = app(TaskTurnProjection::class)->input($operation);
    expect($input['intent']['prompt'])->toBe($body['prompt'])
        ->and($input['execution_secrets']['pi_token']['vault'])->toBe('orbit-control-inputs')
        ->and($input['execution_secrets']['pi_token']['key'])->toBe('task-vm-pi-token-'.TaskVm::forGroup($group)->id)
        ->and(json_encode($input))->not->toContain(TEST_TASK_VM_PI_TOKEN)
        ->and($input['intent'])->not->toHaveKey('execution_secrets');
    expect($this->socket->sent[0]['payload']['inputs'])->toBe([...$input,
        'operation_id' => $operation->id, 'kind' => 'tasks.turn', 'node_id' => $operation->node_id]);
    $this->postJson($url, $body)->assertAccepted()->assertJsonPath('data.id', $id);
    expect(AgentThread::query()->count())->toBe(1)->and(count($this->socket->sent))->toBe(1);
});

it('records the native result in the existing board thread', function (): void {
    [$group, $task] = turn_operation_group();
    $id = $this->withHeader('Idempotency-Key', 'native-result')->postJson('/api/v1/task-groups/'.$group->id.'/turn', turn_operation_input($task))->assertAccepted()->json('data.id');
    $operation = Operation::findOrFail($id);
    $result = ['thread' => $operation->input['thread_id'], 'state' => 'done', 'receipt' => ['outcome' => 'ready_for_review', 'summary' => 'Updated the task'], 'metrics' => ['tokens' => 42, 'input_tokens' => 30, 'output_tokens' => 12]];
    $body = ['run_id' => $this->run, 'status' => 'succeeded', 'started_at' => '2026-10-31T08:00:00.000Z', 'finished_at' => '2026-10-31T08:01:00.000Z', 'result' => $result, 'error_code' => null];
    $this->postJson('/api/v1/direct-operations/'.$id.'/result', $body)->assertOk()->assertJsonPath('data.result', $result);
    expect(AgentThread::findOrFail($operation->input['thread_id'])->state->value)->toBe('done')
        ->and(AgentThread::findOrFail($operation->input['thread_id'])->tokens)->toBe(42);
    $this->postJson('/api/v1/direct-operations/'.$id.'/result', $body)->assertOk();
});

it('refuses an unowned workspace before creating a session', function (): void {
    [$group, $task] = turn_operation_group();
    Instance::query()->whereKey($group->taskable_id)->update(['task_workspace_owner_id' => null]);
    $this->withHeader('Idempotency-Key', 'wrong-owner')->postJson('/api/v1/task-groups/'.$group->id.'/turn', turn_operation_input($task))
        ->assertConflict()->assertJsonPath('error.code', 'tasks.no_workspace');
    expect(AgentThread::query()->count())->toBe(0)->and($this->socket->sent)->toBe([]);
});

it('refuses a failed execution-copy push before starting a native turn', function (): void {
    [$group, $task] = turn_operation_group();
    $this->processes->fail = true;
    $this->withHeader('Idempotency-Key', 'turn-vault-failed')->postJson('/api/v1/task-groups/'.$group->id.'/turn', turn_operation_input($task))
        ->assertServiceUnavailable()->assertJsonPath('error.code', 'swamp.vault_sync_unavailable');
    expect($this->socket->sent)->toBeEmpty()->and(AgentThread::query()->count())->toBe(0);
});

it('refuses native built-in work on an unresolved proxy identity', function (int $nodeId): void {
    [$group, $task] = turn_operation_group(nodeId: $nodeId);
    $this->withHeader('Idempotency-Key', 'turn-proxy')->postJson('/api/v1/task-groups/'.$group->id.'/turn', turn_operation_input($task))
        ->assertServiceUnavailable()->assertJsonPath('error.code', 'operation.kind_unavailable');
    expect($this->socket->sent)->toBeEmpty()->and($this->processes->invocations)->toBeEmpty();
})->with([1, 18]);
