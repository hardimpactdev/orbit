<?php

declare(strict_types=1);

use App\Domain\Operations\DirectOperation;
use App\Domain\Tasks\TaskExtensionState;
use App\Domain\Tasks\TaskTurnProjection;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\Swamp\SwampCommandStep;
use App\Models\AgentThread;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskVm;
use Tests\Support\FakeRoutePublicationSocket;

/** @return array{Task, Task} */
function turn_operation_group(string $orchestration = 'external'): array
{
    $node = test()->markAsGateway(Node::query()->create(['name' => 'turn-node', 'platform' => 'linux', 'status' => 'active', 'wireguard_ip' => '10.44.0.91', 'public_ssh_host' => '192.0.2.91', 'user' => 'orbit', 'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'AAAAC3NzaC1lZDI1NTE5AAAAINqVuK+N4gL/sDvGNKnGrYhEtEFnZx9hvH+134PsQgND']));
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
    config(['swamp.run_token' => 'native-test', 'swamp.ca_path' => '/public/root.pem', 'swamp.address' => '192.0.2.209']);
    $this->run = '21269a76-203b-400f-a5b9-2cc6e7d7e0ee';
    $this->socket = new FakeRoutePublicationSocket;
    $this->socket->reply = fn (array $message): array => $message['type'] === 'vault.put' ? [['type' => 'vault.put', 'id' => $message['id'], 'payload' => ['data' => ['vaultName' => $message['payload']['vaultName'], 'secretKey' => $message['payload']['key'], 'vaultType' => 'local_encryption']]]] : [['type' => 'event', 'id' => $message['id'], 'event' => ['kind' => 'started', 'workflowName' => 'operation-task-turn', 'runId' => $this->run]]];
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
        ->and($input['intent']['ssh_execution']['target']['host'])->toBe('10.44.0.91')
        ->and($input['execution_secrets']['pi_token'])->toBe(['vault' => 'orbit-control-inputs', 'key' => 'task-vm-pi-token-'.TaskVm::forGroup($group)->id])
        ->and(json_encode($input))->not->toContain(TEST_TASK_VM_PI_TOKEN)
        ->and($this->socket->sent[1]['payload']['inputs'])->toBe([...$input, 'operation_id' => $operation->id, 'kind' => 'tasks.turn', 'node_id' => $operation->node_id]);
    $this->postJson($url, $body)->assertAccepted()->assertJsonPath('data.id', $id);
    expect(AgentThread::query()->count())->toBe(1)->and(count($this->socket->sent))->toBe(2);
});

it('records the producer evidence and native failure in the existing board thread', function (?string $failure): void {
    [$group, $task] = turn_operation_group();
    $id = $this->withHeader('Idempotency-Key', 'native-result')->postJson('/api/v1/task-groups/'.$group->id.'/turn', turn_operation_input($task))->assertAccepted()->json('data.id');
    $operation = Operation::findOrFail($id);
    $result = match ($failure) {
        'producer' => [],
        'refusal' => ['thread' => $operation->input['thread_id'], 'state' => 'failed', 'error_code' => 'tasks.analysis_prompt_too_large', 'error' => 'The complete prompt exceeds the limit.'],
        default => ['thread' => $operation->input['thread_id'], 'state' => 'done', 'receipt' => ['outcome' => 'ready_for_review', 'summary' => str_repeat('Updated the task ', 400)], 'metrics' => ['tokens' => 42, 'input_tokens' => 30, 'output_tokens' => 12]],
    };
    $status = $failure === null ? 'succeeded' : 'failed';
    $steps = [
        ['name' => 'shape_result', 'status' => $failure === 'producer' ? 'failed' : 'succeeded', 'outputs' => $failure === 'producer' ? [] : ['result_json' => json_encode($result, JSON_THROW_ON_ERROR)]],
        ['name' => 'delete_local_artifacts', 'status' => $failure === 'cleanup' ? 'failed' : 'succeeded'],
        ['name' => 'outcome', 'status' => in_array($failure, ['producer', 'cleanup'], true) ? 'skipped' : $status],
    ];
    config(['swamp.read_token' => 'native-reader']);
    $this->socket->reply = fn (array $message): array => [['type' => 'workflow.history.get', 'id' => $message['id'], 'payload' => ['data' => [
        'id' => $this->run, 'workflowName' => 'operation-task-turn', 'status' => $status,
        'inputs' => ['operation_id' => $id, 'node_id' => $operation->node_id, 'kind' => 'tasks.turn'],
        'startedAt' => '2026-10-31T08:00:00.000Z', 'completedAt' => '2026-10-31T08:01:00.000Z',
        'jobs' => [['name' => 'turn', 'steps' => $steps]],
    ]]]];
    $projection = app(SwampCommandStep::class);
    $selector = app(DirectOperation::class)->definition('tasks.turn')['terminal_result'];

    $projection->projectTerminal($operation, ['id' => $this->run, 'status' => $status], $selector);
    $projection->projectTerminal($operation, ['id' => $this->run, 'status' => $status], $selector);

    expect($operation->fresh()->result)->toBe($result)
        ->and($operation->fresh()->status->value)->toBe($status)
        ->and($operation->fresh()->error_code)->toBe($failure === null ? null : ($result['error_code'] ?? 'operation.native_failed'));
    $thread = AgentThread::findOrFail($operation->input['thread_id']);
    expect($thread->state->value)->toBe($result['state'] ?? 'failed')
        ->and($thread->error)->toBe($result['error'] ?? null)
        ->and($thread->tokens)->toBe($result['metrics']['tokens'] ?? null);
    expect(array_column($this->socket->sent, 'type'))->toBe(['vault.put', 'workflow.run', 'workflow.history.get']);
    expect($group->fresh()->status->value)->toBe('running');
})->with([null, 'refusal', 'producer', 'cleanup']);

it('refuses an unowned workspace before creating a session', function (): void {
    [$group, $task] = turn_operation_group();
    Instance::query()->whereKey($group->taskable_id)->update(['task_workspace_owner_id' => null]);
    $this->withHeader('Idempotency-Key', 'wrong-owner')->postJson('/api/v1/task-groups/'.$group->id.'/turn', turn_operation_input($task))
        ->assertConflict()->assertJsonPath('error.code', 'tasks.no_workspace');
    expect(AgentThread::query()->count())->toBe(0)->and($this->socket->sent)->toBe([]);
});

it('refuses a missing VM credential before creating a thread or starting Swamp', function (): void {
    [$group, $task] = turn_operation_group();
    TaskVm::forGroup($group)->update(['pi_token' => '']);

    $this->postJson('/api/v1/task-groups/'.$group->id.'/turn', turn_operation_input($task))
        ->assertConflict()->assertJsonPath('error.code', 'tasks.pi_credential_unavailable');

    expect(AgentThread::query()->count())->toBe(0)->and($this->socket->sent)->toBe([]);
});
