<?php

declare(strict_types=1);

use App\Domain\Tasks\TaskExtensionState;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\Swamp\SwampCommandStep;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskCheck;
use App\Models\TaskWorkspaceLeaseRecord;
use Tests\Support\FakeRoutePublicationSocket;

/** @return array{Task, Task} */
function native_analysis_group(string $orchestration = 'external'): array
{
    $node = test()->markAsGateway(Node::query()->create(['name' => 'check-node', 'platform' => 'linux', 'status' => 'active', 'wireguard_ip' => '10.44.0.91', 'public_ssh_host' => '192.0.2.91', 'user' => 'orbit', 'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'AAAAC3NzaC1lZDI1NTE5AAAAINqVuK+N4gL/sDvGNKnGrYhEtEFnZx9hvH+134PsQgND']));
    test()->withServerVariables(['REMOTE_ADDR' => $node->wireguard_ip]);
    app(TaskExtensionState::class)->enable();
    $project = Project::query()->create(['name' => 'Check', 'slug' => 'check', 'repository_url' => 'https://github.com/acme/turn.git']);
    $group = Task::topLevel()->create(['project_id' => $project->id, 'title' => 'Check', 'brief' => 'Orchestrated.', 'status' => 'running', 'orchestration' => $orchestration, 'implementer_agent_driver' => 'pi', 'reviewer_agent_driver' => 'pi', 'implementer_model' => 'gpt-5.6-luna', 'reviewer_model' => 'gpt-5.6-luna']);
    $task = Task::query()->create(['parent_id' => $group->id, 'position' => 1, 'title' => 'One', 'brief' => 'One.', 'status' => 'running', 'deliverables' => [['id' => 'done', 'type' => 'review', 'description' => 'Done.']]]);
    $workspace = Instance::query()->create(['project_id' => $project->id, 'node_id' => $node->id, 'name' => 'task-'.$group->id, 'task_workspace_owner_id' => $group->id, 'checkout_path' => '/srv/check', 'status' => 'source_resolved']);
    $group->taskable()->associate($workspace)->save();
    test_task_vm($group, $node);

    $workspace->update(['branch' => 'task-'.$group->id]);
    Operation::query()->create(['kind' => 'tasks.provision', 'target_type' => 'task_group', 'target_id' => $group->id, 'status' => 'succeeded', 'input' => [], 'result' => ['analysis_base' => ['base' => str_repeat('a', 40), 'instance_id' => $workspace->id, 'repository' => $project->repository_url, 'branch' => $workspace->branch]]]);

    return [$group->refresh(), $task];
}

/** @return array<string, mixed> */
function analysis_operation_input(Task $group): array
{
    // ASCII keys are already in canonical order; this fixture fixes its exact digest.
    $canonical = '{"asserted_by":"orchestrator","complete":true,"id":"feature-2068","paths":[{"change":"add","path":"apps/gateway/new.php"}],"revision":1,"source":{"brief_id":"project:46/brief:2","revision_sha256":"'.str_repeat('d', 64).'"}}';

    return ['subtask' => $group->tasks()->firstOrFail()->id, 'plan' => json_decode($canonical, true, flags: JSON_THROW_ON_ERROR), 'plan_sha256' => hash('sha256', $canonical), 'base' => str_repeat('a', 40), 'head' => str_repeat('a', 40), 'tree' => str_repeat('b', 40)];
}

beforeEach(function (): void {
    config(['swamp.run_token' => 'native-test']);
    $this->run = 'a4944b1e-a6d9-4a8b-8ec0-c065b4a50caa';
    $this->socket = new FakeRoutePublicationSocket;
    $this->socket->reply = fn (array $message): array => [['type' => 'event', 'id' => $message['id'], 'event' => ['kind' => 'started', 'workflowName' => 'operation-task-analyze', 'runId' => $this->run]]];
    app()->instance(WebSocketClient::class, $this->socket);
});

it('admits one native analysis from the public provision pin with no PHP execution state', function (): void {
    [$group] = native_analysis_group();
    $input = analysis_operation_input($group);
    $id = $this->postJson('/api/v1/task-groups/'.$group->id.'/analyze', $input, ['Idempotency-Key' => 'native-analysis'])->assertAccepted()->json('data.id');
    $operation = Operation::findOrFail($id);
    expect($operation->node_id)->toBe($group->taskable->node_id)
        ->and($operation->input['base'])->toBe(str_repeat('a', 40))
        ->and($operation->input['checkout_path'])->toBe('/srv/check')
        ->and($operation->state['swamp_run_id'])->toBe($this->run)
        ->and($this->socket->sent[0]['payload']['inputs']['intent']['ssh_execution']['target']['host'])->toBe('10.44.0.91')
        ->and($this->socket->sent[0]['payload']['inputs']['execution_secrets'])->toEqual((object) [])
        ->and($operation->state)->not->toHaveKey('execution')
        ->and(TaskWorkspaceLeaseRecord::query()->count())->toBe(0)
        ->and(TaskCheck::query()->count())->toBe(0);
    $group->update(['status' => 'cancelled']);
    $this->postJson('/api/v1/task-groups/'.$group->id.'/analyze', $input, ['Idempotency-Key' => 'native-analysis'])->assertAccepted()->assertJsonPath('data.id', $id);
    expect(count($this->socket->sent))->toBe(1);
});

it('retains native success and closed failure evidence without judging it or changing the board', function (bool $failed): void {
    [$group] = native_analysis_group();
    $id = $this->postJson('/api/v1/task-groups/'.$group->id.'/analyze', analysis_operation_input($group), ['Idempotency-Key' => 'native-analysis'])->assertAccepted()->json('data.id');
    $result = $failed ? ['schema' => 1, 'error' => ['code' => 'tasks.analysis_report_too_large', 'required_bytes' => 216325]]
        : ['outcome' => 'ready', 'artifact' => ['content' => 'native evidence', 'bytes' => 15], 'provenance' => ['native' => true]];
    $native = $failed ? [...$result, 'error_code' => $result['error']['code']] : $result;
    $body = ['run_id' => $this->run, 'status' => $failed ? 'failed' : 'succeeded', 'started_at' => '2026-10-31T08:00:00.000Z', 'finished_at' => '2026-10-31T08:01:00.000Z', 'result' => $native, 'error_code' => $failed ? $result['error']['code'] : null];
    config(['swamp.read_token' => 'native-reader', 'swamp.address' => '192.0.2.209', 'swamp.ca_path' => '/dev/null']);
    $this->socket->reply = fn (array $message): array => [['type' => 'workflow.history.get', 'id' => $message['id'], 'payload' => ['data' => [
        'id' => $this->run, 'workflowName' => 'operation-task-analyze', 'status' => $body['status'],
        'inputs' => ['operation_id' => $id, 'node_id' => $group->taskable->node_id, 'kind' => 'tasks.analyze'],
        'startedAt' => $body['started_at'], 'completedAt' => $body['finished_at'],
        'jobs' => [['name' => 'analysis', 'steps' => [['name' => 'result', 'outputs' => ['stdout' => json_encode($native, JSON_THROW_ON_ERROR)]]]]],
    ]]]];
    $projection = app(SwampCommandStep::class);
    $operation = Operation::findOrFail($id);
    $projection->projectTerminal($operation, ['id' => $this->run, 'status' => $body['status']], ['job' => 'analysis', 'step' => 'result']);
    $projection->projectTerminal($operation, ['id' => $this->run, 'status' => $body['status']], ['job' => 'analysis', 'step' => 'result']);
    $this->getJson('/api/v1/operations/'.$id)->assertOk()->assertJsonPath('data.result', $result);
    expect($group->fresh()->status->value)->toBe('running')->and(count($this->socket->sent))->toBe(2);
})->with([false, true]);

it('refuses a missing pin or substituted starting commit before dispatch', function (bool $missing): void {
    [$group] = native_analysis_group();
    $input = analysis_operation_input($group);
    if ($missing) {
        Operation::query()->where('kind', 'tasks.provision')->delete();
    } else {
        $input['base'] = str_repeat('b', 40);
    }
    $this->postJson('/api/v1/task-groups/'.$group->id.'/analyze', $input, ['Idempotency-Key' => 'bad-pin'])
        ->assertStatus($missing ? 422 : 409)->assertJsonPath('error.code', $missing ? 'tasks.analysis_unavailable' : 'tasks.analysis_stale');
    expect($this->socket->sent)->toBe([]);
})->with([false, true]);

it('requires a key, a closed request and the exact canonical plan digest', function (): void {
    [$group] = native_analysis_group();
    $input = analysis_operation_input($group);
    $url = '/api/v1/task-groups/'.$group->id.'/analyze';
    $this->postJson($url, $input)->assertUnprocessable();
    $this->postJson($url, [...$input, 'command' => 'true'], ['Idempotency-Key' => 'bad-field'])->assertUnprocessable();
    $this->postJson($url, [...$input, 'plan_sha256' => str_repeat('e', 64)], ['Idempotency-Key' => 'bad-digest'])->assertUnprocessable();
    expect($this->socket->sent)->toBe([]);
});

it('refuses foreign current child and unowned workspace before dispatch', function (bool $foreign): void {
    [$group, $task] = native_analysis_group();
    $input = analysis_operation_input($group);
    if ($foreign) {
        $input['subtask'] = 99999999;
    } else {
        Instance::query()->whereKey($group->taskable_id)->update(['task_workspace_owner_id' => null]);
    }
    $this->postJson('/api/v1/task-groups/'.$group->id.'/analyze', $input, ['Idempotency-Key' => 'bad-owner'])->assertStatus($foreign ? 404 : 409);
    expect($this->socket->sent)->toBe([]);
})->with([false, true]);
