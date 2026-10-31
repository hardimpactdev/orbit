<?php

declare(strict_types=1);

use App\Domain\GitHub\GitHubApi;
use App\Domain\Tasks\TaskExtensionState;
use App\Domain\Tasks\TaskFinalReview;
use App\Domain\Tasks\TaskPublishProjection;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\Swamp\SwampCommandStep;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskCheck;
use App\Models\TaskReviewedCommit;
use App\Models\TaskWorkspaceLeaseRecord;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\GitHub\GitHubTestSupport;
use Tests\Support\FakeRoutePublicationSocket;

/** @return array{Task, Task} */
function native_publish_group(string $orchestration = 'external'): array
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

    return [$group->refresh(), $task];
}

function native_publish_check(Task $task): TaskCheck
{
    $check = $task->checks()->create(['kind' => 'handoff', 'status' => 'passed', 'commit_sha' => str_repeat('a', 40), 'pid' => 0, 'process_started' => '', 'head_before' => str_repeat('b', 40), 'tree_before' => str_repeat('c', 40), 'started_at' => now()]);
    task_checked_operation($check);

    return $check->refresh();
}

beforeEach(function (): void {
    config(['swamp.run_token' => 'native-test', 'swamp.ca_path' => '/public/root.pem', 'swamp.address' => '192.0.2.209']);
    GitHubTestSupport::storeApp();
    $github = Mockery::mock(GitHubApi::class);
    $github->shouldReceive('repositoryInstallation')->andReturn(123);
    $github->shouldReceive('repositoryPullRequestToken')->andReturn('synthetic-publish-write-token');
    $github->shouldNotReceive('repositoryChecksToken');
    app()->instance(GitHubApi::class, $github);
    $this->run = 'a4944b1e-a6d9-4a8b-8ec0-c065b4a50caa';
    $this->socket = new FakeRoutePublicationSocket;
    $this->socket->reply = fn (array $message): array => $message['type'] === 'vault.put' ? [['type' => 'vault.put', 'id' => $message['id'], 'payload' => ['data' => ['vaultName' => $message['payload']['vaultName'], 'secretKey' => $message['payload']['key'], 'vaultType' => 'local_encryption']]]] : [['type' => 'event', 'id' => $message['id'], 'event' => ['kind' => 'started', 'workflowName' => 'operation-task-publish', 'runId' => $this->run]]];
    app()->instance(WebSocketClient::class, $this->socket);
});

it('admits the exact checked candidate and native VM without a push job or lease', function (): void {
    [$group, $task] = native_publish_group();
    $group->project->update(['default_branch' => 'main']);
    $check = native_publish_check($task);
    Queue::fake();
    $input = ['check' => $check->id, 'pull_request' => ['title' => 'Export', 'body' => 'Adds the export.']];
    $id = $this->postJson('/api/v1/task-groups/'.$group->id.'/publish', $input, ['Idempotency-Key' => 'native-publish'])->assertAccepted()->json('data.id');
    $operation = Operation::findOrFail($id);
    expect($operation->input['commit'])->toBe($check->commit_sha)->and($operation->input['tree'])->toBe($check->tree_after)
        ->and($operation->input['repository'])->toBe('acme/turn')->and($operation->input['base'])->toBe('main')
        ->and($operation->input['branch'])->toBe('task-'.$group->id)->and($operation->node_id)->toBe($group->taskable->node_id)
        ->and($operation->state['swamp_run_id'])->toBe($this->run)->and(TaskWorkspaceLeaseRecord::query()->count())->toBe(0);
    $group->update(['status' => 'cancelled']);
    $this->postJson('/api/v1/task-groups/'.$group->id.'/publish', $input, ['Idempotency-Key' => 'native-publish'])->assertAccepted()->assertJsonPath('data.id', $id);
    Queue::assertNothingPushed();
    expect(count($this->socket->sent))->toBe(2);
    $public = app(TaskPublishProjection::class)->input($operation);
    expect($public['intent']['ssh_execution']['target']['host'])->toBe('10.44.0.91')
        ->and($public['execution_secrets']['github_token']['vault'])->toBe('orbit-control-inputs')
        ->and(json_encode($public))->not->toContain('synthetic-publish-write-token')
        ->and($this->socket->sent[1]['payload']['inputs'])->toBe([...$public, 'operation_id' => $id, 'kind' => 'tasks.publish', 'node_id' => $operation->node_id]);
});

it('refuses a foreign check or an ineligible required-CI result before starting Swamp', function (bool $foreign): void {
    [$group, $task] = native_publish_group();
    $group->project->update(['default_branch' => 'main']);
    $check = native_publish_check($task);
    if ($foreign) {
        $check->delete();
    } else {
        $group->project->update(['review_and_merge' => true, 'merge_check' => 'Required checks']);
    }
    $this->postJson('/api/v1/task-groups/'.$group->id.'/publish', ['check' => $check->id], ['Idempotency-Key' => 'bad-check'])->assertUnprocessable()->assertJsonPath('error.code', 'tasks.commit_not_checked');
    expect($this->socket->sent)->toBe([]);
})->with([false, true]);

it('projects the native PR metadata once without changing group status', function (bool $withPr): void {
    [$group, $task] = native_publish_group();
    $group->project->update(['default_branch' => 'main']);
    $check = native_publish_check($task);
    $id = $this->postJson('/api/v1/task-groups/'.$group->id.'/publish', ['check' => $check->id], ['Idempotency-Key' => 'native-publish'])->assertAccepted()->json('data.id');
    $url = $withPr ? 'https://github.com/acme/turn/pull/12' : null;
    $result = ['branch' => 'task-'.$group->id, 'commit' => $check->commit_sha, 'pull_request_number' => $withPr ? 12 : null, 'pull_request_url' => $url];
    $body = ['run_id' => $this->run, 'status' => 'succeeded', 'started_at' => '2026-10-31T08:00:00.000Z', 'finished_at' => '2026-10-31T08:01:00.000Z', 'result' => $result, 'error_code' => null];
    config(['swamp.read_token' => 'native-reader']);
    $this->socket->reply = fn (array $message): array => [['type' => 'workflow.history.get', 'id' => $message['id'], 'payload' => ['data' => [
        'id' => $this->run, 'workflowName' => 'operation-task-publish', 'status' => 'succeeded',
        'inputs' => ['operation_id' => $id, 'node_id' => $group->taskable->node_id, 'kind' => 'tasks.publish'],
        'startedAt' => $body['started_at'], 'completedAt' => $body['finished_at'],
        'jobs' => [['name' => 'publish', 'steps' => [['name' => 'result', 'outputs' => ['stdout' => json_encode($result, JSON_THROW_ON_ERROR)]]]]],
    ]]]];
    $projector = app(SwampCommandStep::class);
    $operation = Operation::findOrFail($id);
    $projector->projectTerminal($operation, ['id' => $this->run, 'status' => 'succeeded'], ['job' => 'publish', 'step' => 'result']);
    $projector->projectTerminal($operation, ['id' => $this->run, 'status' => 'succeeded'], ['job' => 'publish', 'step' => 'result']);
    expect($operation->fresh()->result)->toBe($result)
        ->and(array_column($this->socket->sent, 'type'))->toBe(['vault.put', 'workflow.run', 'workflow.history.get']);
    expect($group->fresh()->pr_url)->toBe($url)->and($group->fresh()->watched_pr_url)->toBe($url)->and($group->fresh()->status->value)->toBe('running');
})->with([false, true]);

it('retires publish private-material reads after the input switch', function (): void {
    [$group, $task] = native_publish_group();
    $group->project->update(['default_branch' => 'main']);
    $check = native_publish_check($task);
    $id = $this->postJson('/api/v1/task-groups/'.$group->id.'/publish', ['check' => $check->id])->assertAccepted()->json('data.id');
    $this->getJson('/api/v1/direct-operations/'.$id.'/private-material?run_id='.$this->run)->assertUnprocessable();
});

it('recognizes native publication evidence when preserving approved work for cleanup', function (): void {
    [$group] = native_publish_group();
    $group->project->update(['review_and_merge' => true, 'merge_check' => 'Required checks', 'source_access' => 'github_app']);
    $sha = str_repeat('a', 40);
    $reviewed = TaskReviewedCommit::query()->create(['task_id' => $group->id, 'sha' => $sha, 'source' => 'orbit_push']);
    Operation::query()->create(['kind' => 'tasks.publish', 'target_type' => 'task_group', 'target_id' => $group->id, 'status' => 'succeeded', 'input' => [], 'result' => ['commit' => $sha, 'branch' => 'task-'.$group->id]]);
    expect(TaskFinalReview::reconcileRemovalPush($group->refresh()))->toBeNull()
        ->and($reviewed->refresh()->pushed_at)->not->toBeNull();
});
