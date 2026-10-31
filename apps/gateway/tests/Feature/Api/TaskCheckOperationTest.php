<?php

declare(strict_types=1);

use App\Domain\GitHub\GitHubApi;
use App\Domain\Tasks\TaskCheckedCandidate;
use App\Domain\Tasks\TaskExtensionState;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Models\AgentThread;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskCheck;
use App\Models\TaskWorkspaceLeaseRecord;
use Tests\Feature\GitHub\GitHubTestSupport;
use Tests\Support\FakeRoutePublicationSocket;

/** @return array{Task, Task} */
function native_check_group(string $orchestration = 'external'): array
{
    $node = test()->markAsGateway(Node::query()->create(['name' => 'check-node', 'platform' => 'linux', 'status' => 'active', 'wireguard_ip' => '10.44.0.91', 'public_ssh_host' => '192.0.2.91']));
    test()->withServerVariables(['REMOTE_ADDR' => $node->wireguard_ip]);
    app(TaskExtensionState::class)->enable();
    $project = Project::query()->create(['name' => 'Check', 'slug' => 'check', 'repository_url' => 'https://github.com/acme/turn.git']);
    $group = Task::topLevel()->create(['project_id' => $project->id, 'title' => 'Check', 'brief' => 'Orchestrated.', 'status' => 'running', 'orchestration' => $orchestration, 'implementer_agent_driver' => 'pi', 'reviewer_agent_driver' => 'pi', 'implementer_model' => 'gpt-5.6-luna', 'reviewer_model' => 'gpt-5.6-luna']);
    $task = Task::query()->create(['parent_id' => $group->id, 'position' => 1, 'title' => 'One', 'brief' => 'One.', 'status' => 'running', 'deliverables' => [['id' => 'done', 'type' => 'review', 'description' => 'Done.']]]);
    $workspace = Instance::query()->create(['project_id' => $project->id, 'node_id' => $node->id, 'name' => 'task-'.$group->id, 'task_workspace_owner_id' => $group->id, 'checkout_path' => '/srv/check', 'status' => 'source_resolved']);
    $group->taskable()->associate($workspace)->save();
    test_task_vm($group, $node);

    return [$group->refresh(), $task];
}

beforeEach(function (): void {
    config(['swamp.run_token' => 'native-test']);
    $this->run = 'a4944b1e-a6d9-4a8b-8ec0-c065b4a50caa';
    $this->socket = new FakeRoutePublicationSocket;
    $this->socket->reply = fn (array $message): array => [['type' => 'event', 'id' => $message['id'], 'event' => ['kind' => 'started', 'workflowName' => 'operation-task-check', 'runId' => $this->run]]];
    app()->instance(WebSocketClient::class, $this->socket);
});

function native_check_start(Task $group, Task $task, string $phase = 'baseline'): Operation
{
    $id = test()->postJson('/api/v1/task-groups/'.$group->id.'/check', ['subtask' => $task->id, 'phase' => $phase], ['Idempotency-Key' => 'native-check'])->assertAccepted()->json('data.id');

    return Operation::findOrFail($id);
}

function native_check_finish(Operation $operation, array $result, string $status = 'succeeded'): array
{
    $body = ['run_id' => test()->run, 'status' => $status, 'started_at' => '2026-10-31T08:00:00.000Z', 'finished_at' => '2026-10-31T08:01:00.000Z', 'result' => $result, 'error_code' => null];
    test()->postJson('/api/v1/direct-operations/'.$operation->id.'/result', $body)->assertOk()->assertJsonPath('data.result', $result);

    return $body;
}

it('admits one native check row and exact VM without a workspace lease', function (): void {
    [$group, $task] = native_check_group();
    $operation = native_check_start($group, $task);

    expect($operation->node_id)->toBe($group->taskable->node_id)
        ->and($operation->input['checkout_path'])->toBe('/srv/check')
        ->and($operation->input['check_id'])->toBe(TaskCheck::query()->sole()->id)
        ->and($operation->state['swamp_run_id'])->toBe($this->run)
        ->and(TaskWorkspaceLeaseRecord::query()->count())->toBe(0);
    $group->update(['status' => 'completed']);
    $this->postJson('/api/v1/task-groups/'.$group->id.'/check', ['subtask' => $task->id, 'phase' => 'baseline'], ['Idempotency-Key' => 'native-check'])->assertAccepted()->assertJsonPath('data.id', $operation->id);
    expect(TaskCheck::query()->count())->toBe(1)->and(count($this->socket->sent))->toBe(1);
});

it('projects failed and changed native checks without starting another action', function (string $outcome): void {
    [$group, $task] = native_check_group();
    $operation = native_check_start($group, $task);
    $result = ['outcome' => $outcome, 'check_id' => $operation->input['check_id'], 'commit' => null, 'ci' => null,
        'head' => str_repeat('a', 40), 'tree' => str_repeat('b', 40), 'head_before' => str_repeat('a', 40), 'tree_before' => str_repeat('c', 40),
        'exit_code' => 23, 'output' => 'Check output', 'evidence' => null, 'changed_paths' => ['code'], 'deliverables' => []];
    $body = native_check_finish($operation, $result);
    $this->postJson('/api/v1/direct-operations/'.$operation->id.'/result', $body)->assertOk();
    expect(TaskCheck::findOrFail($operation->input['check_id'])->status->value)->toBe($outcome)
        ->and($group->fresh()->status->value)->toBe('running')->and(count($this->socket->sent))->toBe(1);
})->with(['failed', 'changed']);

it('uses the native exact-head CI result for reviewer eligibility', function (string $state): void {
    [$group, $task] = native_check_group();
    $group->project->update(['review_and_merge' => true, 'merge_check' => 'Required checks']);
    $operation = native_check_start($group, $task, 'handoff');
    $sha = str_repeat('a', 40);
    $result = ['outcome' => 'passed', 'check_id' => $operation->input['check_id'], 'commit' => $sha, 'head' => str_repeat('b', 40), 'tree' => str_repeat('c', 40),
        'ci' => ['state' => $state, 'fault' => null, 'branch' => 'task-'.$group->id.'-handoff', 'commit' => $sha, 'check_name' => 'Required checks'], 'deliverables' => []];
    native_check_finish($operation, $result);
    expect(app(TaskCheckedCandidate::class)->eligible($group->refresh(), TaskCheck::findOrFail($operation->input['check_id'])))->toBe($state === 'passed');
})->with(['passed', 'pending']);

it('records cancellation intent and leaves stopping to the native method', function (): void {
    [$group, $task] = native_check_group();
    $operation = native_check_start($group, $task);
    $this->postJson('/api/v1/task-groups/'.$group->id.'/tasks/'.$task->id.'/check/cancel')->assertOk();
    expect($operation->refresh()->cancel_requested_at)->not->toBeNull()
        ->and(TaskCheck::findOrFail($operation->input['check_id'])->status->value)->toBe('running');
    native_check_finish($operation, [], 'cancelled');
    expect(TaskCheck::findOrFail($operation->input['check_id'])->status->value)->toBe('cancelled');
});

it('rejects an unowned workspace before recording a check or native run', function (): void {
    [$group, $task] = native_check_group();
    Instance::query()->whereKey($group->taskable_id)->update(['task_workspace_owner_id' => null]);
    $this->postJson('/api/v1/task-groups/'.$group->id.'/check', ['subtask' => $task->id, 'phase' => 'baseline'], ['Idempotency-Key' => 'unowned'])->assertConflict()->assertJsonPath('error.code', 'tasks.no_workspace');
    expect(TaskCheck::query()->count())->toBe(0)->and($this->socket->sent)->toBe([]);
});

it('refuses a baseline after the implementer started', function (): void {
    [$group, $task] = native_check_group();
    AgentThread::query()->create(['task_group_id' => $group->id, 'task_id' => $task->id, 'node_id' => $group->taskable->node_id,
        'driver' => 'pi', 'runtime_key' => 'node:'.$group->taskable->node_id, 'external_id' => 'started-session', 'role' => 'implementer', 'model' => 'orbit-task-vm/gpt-6.1-sol']);
    $this->postJson('/api/v1/task-groups/'.$group->id.'/check', ['subtask' => $task->id, 'phase' => 'baseline'], ['Idempotency-Key' => 'late'])->assertConflict()->assertJsonPath('error.code', 'tasks.baseline_done');
    expect(TaskCheck::query()->count())->toBe(0)->and($this->socket->sent)->toBe([]);
});

it('keeps repository token transport private and scoped to the admitted Project', function (): void {
    [$group, $task] = native_check_group();
    $operation = native_check_start($group, $task, 'handoff');
    $this->getJson('/api/v1/direct-operations/'.$operation->id.'?run_id='.$this->run)->assertOk()->assertJsonMissingPath('data.input.credentials');
    GitHubTestSupport::storeApp();
    $github = Mockery::mock(GitHubApi::class);
    $github->shouldReceive('repositoryInstallation')->andReturn(123);
    $github->shouldReceive('repositoryPullRequestToken')->once()->andReturn('fixture-write');
    $github->shouldReceive('repositoryChecksToken')->once()->andReturn('fixture-checks');
    $github->shouldReceive('repositoryReadToken')->once()->andReturn('fixture-read');
    app()->instance(GitHubApi::class, $github);
    $this->getJson('/api/v1/direct-operations/'.$operation->id.'/private-material?run_id='.$this->run)->assertOk()->assertJsonPath('data.credentials.repository', 'acme/turn');
    $group->project->update(['repository_url' => 'https://github.com/acme/other.git']);
    $this->getJson('/api/v1/direct-operations/'.$operation->id.'/private-material?run_id='.$this->run)->assertForbidden()->assertJsonPath('error.code', 'tasks.repository_token_forbidden');
});
