<?php

declare(strict_types=1);

use App\Domain\Tasks\TaskExtensionState;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskWorkspaceLeaseRecord;
use Tests\Support\FakeRoutePublicationSocket;

/** @return array{Task, Task} */
function script_operation_group(string $orchestration = 'external'): array
{
    $node = test()->markAsGateway(Node::query()->create(['name' => 'script-node', 'platform' => 'linux', 'status' => 'active', 'wireguard_ip' => '10.44.0.91', 'public_ssh_host' => '192.0.2.91']));
    test()->withServerVariables(['REMOTE_ADDR' => $node->wireguard_ip]);
    app(TaskExtensionState::class)->enable();
    $project = Project::query()->create(['name' => 'Script', 'slug' => 'script', 'repository_url' => 'https://github.com/acme/turn.git']);
    $group = Task::topLevel()->create(['project_id' => $project->id, 'title' => 'Script', 'brief' => 'Orchestrated.', 'status' => 'running', 'orchestration' => $orchestration, 'implementer_agent_driver' => 'pi', 'reviewer_agent_driver' => 'pi', 'implementer_model' => 'gpt-5.6-luna', 'reviewer_model' => 'gpt-5.6-luna']);
    $task = Task::query()->create(['parent_id' => $group->id, 'position' => 1, 'title' => 'One', 'brief' => 'One.', 'status' => 'running', 'deliverables' => [['id' => 'done', 'type' => 'review', 'description' => 'Done.']]]);
    $workspace = Instance::query()->create(['project_id' => $project->id, 'node_id' => $node->id, 'name' => 'task-'.$group->id, 'task_workspace_owner_id' => $group->id, 'checkout_path' => '/srv/script', 'status' => 'source_resolved']);
    $group->taskable()->associate($workspace)->save();
    test_task_vm($group, $node);

    return [$group->refresh(), $task];
}

beforeEach(function (): void {
    config(['swamp.run_token' => 'native-test']);
    $this->run = '849dd6d8-a55d-4318-b18b-3afaf96a52c4';
    $this->socket = new FakeRoutePublicationSocket;
    $this->socket->reply = fn (array $message): array => [['type' => 'event', 'id' => $message['id'], 'event' => ['kind' => 'started', 'workflowName' => 'operation-task-script', 'runId' => $this->run]]];
    app()->instance(WebSocketClient::class, $this->socket);
});

it('admits the exact VM and literal arguments without a workspace lease', function (): void {
    [$group, $task] = script_operation_group();
    $body = ['subtask' => $task->id, 'script' => 'scripts/dependencies', 'args' => ['routine', '$(touch nope); literal', ' spaces ', '']];
    $url = '/api/v1/task-groups/'.$group->id.'/script';

    $first = $this->withHeader('Idempotency-Key', 'native-script')->postJson($url, $body)->assertAccepted();
    $id = $first->json('data.id');
    $operation = Operation::findOrFail($id);
    expect($operation->node_id)->toBe($group->taskable->node_id)
        ->and($operation->input['checkout_path'])->toBe('/srv/script')
        ->and($operation->input['args'])->toBe($body['args'])
        ->and($operation->state['swamp_run_id'])->toBe($this->run)
        ->and(TaskWorkspaceLeaseRecord::query()->count())->toBe(0);
    $group->update(['status' => 'completed']);
    $this->postJson($url, $body)->assertAccepted()->assertJsonPath('data.id', $id);
    expect(count($this->socket->sent))->toBe(1);
});

it('records a nonzero script result without changing board state', function (): void {
    [$group, $task] = script_operation_group();
    $id = $this->withHeader('Idempotency-Key', 'native-result')->postJson('/api/v1/task-groups/'.$group->id.'/script', ['subtask' => $task->id, 'script' => 'scripts/dependencies', 'args' => []])->assertAccepted()->json('data.id');
    $result = ['outcome' => 'failed', 'exit_code' => 23, 'output' => 'Script failed', 'output_truncated' => false, 'changed' => true, 'head' => str_repeat('a', 40), 'tree' => str_repeat('b', 40)];
    $body = ['run_id' => $this->run, 'status' => 'succeeded', 'started_at' => '2026-10-31T08:00:00.000Z', 'finished_at' => '2026-10-31T08:01:00.000Z', 'result' => $result, 'error_code' => null];

    $this->postJson('/api/v1/direct-operations/'.$id.'/result', $body)->assertOk()->assertJsonPath('data.result', $result);
    $this->postJson('/api/v1/direct-operations/'.$id.'/result', $body)->assertOk();
    expect($group->fresh()->status->value)->toBe('running');
});

it('refuses an unowned workspace before starting a native run', function (): void {
    [$group, $task] = script_operation_group();
    Instance::query()->whereKey($group->taskable_id)->update(['task_workspace_owner_id' => null]);
    $this->withHeader('Idempotency-Key', 'wrong-owner')->postJson('/api/v1/task-groups/'.$group->id.'/script', ['subtask' => $task->id, 'script' => 'scripts/dependencies', 'args' => []])
        ->assertConflict()->assertJsonPath('error.code', 'tasks.no_workspace');
    expect($this->socket->sent)->toBe([]);
});

it('refuses unsafe or nonliteral script inputs', function (array $override): void {
    [$group, $task] = script_operation_group();
    $body = array_replace(['subtask' => $task->id, 'script' => 'scripts/dependencies', 'args' => []], $override);
    $this->withHeader('Idempotency-Key', 'invalid')->postJson('/api/v1/task-groups/'.$group->id.'/script', $body)->assertUnprocessable();
    expect($this->socket->sent)->toBe([]);
})->with([[['script' => '../run']], [['script' => 'scripts/./run']], [['args' => [7]]], [['command' => 'echo nope']], [['args' => ["\0"]]]]);

it('requires an operation key before admission', function (): void {
    [$group, $task] = script_operation_group();
    $this->postJson('/api/v1/task-groups/'.$group->id.'/script', ['subtask' => $task->id, 'script' => 'scripts/dependencies', 'args' => []])->assertUnprocessable();
    expect($this->socket->sent)->toBe([]);
});

it('refuses a later child before starting a native run', function (): void {
    [$group, $task] = script_operation_group();
    $later = Task::query()->create(['parent_id' => $group->id, 'position' => 2, 'title' => 'Later', 'brief' => 'Later.', 'status' => 'todo']);
    $this->withHeader('Idempotency-Key', 'later')->postJson('/api/v1/task-groups/'.$group->id.'/script', ['subtask' => $later->id, 'script' => 'scripts/dependencies', 'args' => []])
        ->assertConflict()->assertJsonPath('error.code', 'tasks.subtask_not_current');
    expect($this->socket->sent)->toBe([]);
});
