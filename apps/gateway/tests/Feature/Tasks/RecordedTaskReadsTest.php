<?php

declare(strict_types=1);

use App\Actions\Tasks\ArchiveAgentTranscriptsAction;
use App\Domain\Instances\InstanceSourceLayout;
use App\Domain\Instances\InstanceState;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\OperationStatus;
use App\Domain\Tasks\TaskExtensionState;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskTurnProjection;
use App\Infrastructure\AgentView\CacheAgentStateView;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\AgentThread;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskVm;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

uses(LazilyRefreshDatabase::class);

/** Real Gateway records, including a reachable-looking VM and workspace that no read may contact. */
function recordedTaskWorld(): array
{
    test()->travelTo(now()->startOfSecond());
    Http::fake(fn () => throw new RuntimeException('A task read contacted HTTP.'));
    test()->mock(SshExecutor::class)->shouldNotReceive('execute');
    test()->mock(SshKeyProvider::class)->shouldReceive('privateKeyPath')->andReturn('/unused-test-key');
    test()->mock(KnownHostsStore::class)->shouldReceive('path')->andReturn('/unused-test-hosts');
    Storage::fake('local');
    app(TaskExtensionState::class)->enable();
    $gateway = Node::withoutEvents(fn () => Node::query()->create(['name' => 'gateway', 'status' => 'active', 'platform' => 'linux', 'wireguard_ip' => '10.44.0.2', 'public_ssh_host' => '10.44.0.2', 'user' => 'orbit']));
    $gateway->roles()->create(['role' => RoleName::Gateway, 'status' => 'active']);
    $node = Node::withoutEvents(fn () => Node::query()->create(['name' => 'tvm-598', 'status' => 'active', 'platform' => 'linux', 'wireguard_ip' => '10.44.0.137', 'public_ssh_host' => '10.44.0.137', 'ssh_host_fingerprint' => 'SHA256:fixture', 'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'fixture-public-key', 'user' => 'orbit']));
    $project = Project::query()->create(['name' => 'orbit', 'slug' => 'orbit', 'repository_url' => 'https://github.com/hardimpactdev/orbit.git', 'default_branch' => 'main', 'root' => 'public']);
    $group = Task::topLevel()->create(['project_id' => $project->id, 'title' => 'Group', 'brief' => 'Brief', 'status' => TaskGroupStatus::Running, 'implementer_model' => 'gpt-6.1-sol', 'reviewer_model' => 'gpt-6-astra', 'started_at' => now()->subMinutes(5), 'lines_added' => 30, 'lines_deleted' => 10, 'line_diff' => 40]);
    TaskVm::query()->create(['host_node_id' => $gateway->id, 'node_id' => $node->id, 'group_id' => $group->id, 'provider' => 'incus', 'name' => 'tvm-598', 'state' => 'ready', 'size' => 'normal', 'wireguard_ip' => '10.44.0.137', 'pi_token' => str_repeat('p', 40)]);
    $workspace = Instance::query()->create(['project_id' => $project->id, 'node_id' => $node->id, 'name' => 'workspace', 'source_layout' => InstanceSourceLayout::Checkout, 'checkout_path' => '/home/orbit/apps/orbit/workspace', 'task_workspace_owner_id' => $group->id, 'status' => InstanceState::Reserved]);
    $group->update(['taskable_type' => $workspace->getMorphClass(), 'taskable_id' => $workspace->id]);
    $first = $group->tasks()->create(['title' => 'First', 'brief' => 'Brief', 'position' => 1, 'tokens' => 60, 'started_at' => now()->subMinutes(5), 'settled_at' => now()->subMinutes(3)]);
    $second = $group->tasks()->create(['title' => 'Second', 'brief' => 'Brief', 'position' => 2, 'tokens' => 40]);
    $thread = AgentThread::query()->create(['task_group_id' => $group->id, 'task_id' => $first->id, 'node_id' => $node->id, 'driver' => 'pi', 'runtime_key' => 'node:'.$node->id, 'external_id' => 'session-598', 'role' => 'implementer', 'state' => 'done', 'tokens' => 100]);
    $first->update(['implementer_agent_thread_id' => $thread->id]);
    $second->update(['implementer_agent_thread_id' => $thread->id]);
    AgentThread::query()->create(['task_group_id' => $group->id, 'node_id' => $node->id, 'driver' => 'pi', 'runtime_key' => 'node:'.$node->id, 'external_id' => 'review-598', 'role' => 'reviewer', 'tokens' => 20, 'cost_usd' => 0.2]);
    $operation = Operation::query()->create(['kind' => 'tasks.turn', 'target_type' => 'task_group', 'target_id' => $group->id, 'node_id' => $node->id, 'status' => OperationStatus::Running, 'input' => ['thread_id' => $thread->id, 'subtask' => $second->id], 'state' => ['swamp_run_id' => 'recorded-turn']]);
    Storage::disk('local')->put(ArchiveAgentTranscriptsAction::path($thread), json_encode(['state' => 'done', 'entries' => [['id' => 'saved', 'kind' => 'message', 'label' => 'assistant', 'text' => 'Recorded answer', 'at' => '']]], JSON_THROW_ON_ERROR));
    test()->withServerVariables(['REMOTE_ADDR' => '10.44.0.2']);

    return [$group, $thread, $operation, $first, $second];
}

it('serves distinct totals and recorded line counts for list, board, and detail without remote reads', function (string $path, bool $ended): void {
    [$group, , , $first, $second] = recordedTaskWorld();
    if ($ended) {
        $group->forceFill(['status' => TaskGroupStatus::Completed, 'ended_at' => now()->subMinute()])->save();
    }
    $url = $path === 'detail' ? '/api/v1/task-groups/'.$group->id : '/api/v1/task-groups'.($path === 'board' ? '?compact=1' : '');
    $response = $this->getJson($url)->assertOk();
    $data = $path === 'detail' ? 'data' : 'data.0';
    $response->assertJsonPath($data.'.tokens', 120)->assertJsonPath($data.'.lines_added', 30)->assertJsonPath($data.'.line_diff', 40)
        ->assertJsonPath($data.'.duration_ms', $ended ? 240000 : 300000);
    expect($first->refresh()->tokens)->toBe(60);
    expect($second->refresh()->tokens)->toBe(40);
    expect($group->refresh()->tokens)->toBeNull();
    Http::assertNothingSent();
})->with(['list', 'board', 'detail'])->with([false, true]);

it('serves recorded working state through the agents list without a remote read', function (): void {
    [$group] = recordedTaskWorld();
    $this->getJson('/api/v1/task-groups/'.$group->id.'/agents')->assertOk()->assertJsonPath('data.0.state', 'working');
    Http::assertNothingSent();
});

it('serves the recorded transcript and current activity through JSON and the board stream', function (bool $stream, bool $saved): void {
    [$group, $thread] = recordedTaskWorld();
    if (! $saved) {
        Storage::disk('local')->delete(ArchiveAgentTranscriptsAction::path($thread));
    }
    $response = $this->get('/api/v1/task-groups/'.$group->id.'/agents/'.$thread->id.($stream ? '/stream' : '').'?after_sequence=old.4', ['Accept' => 'application/json'])->assertOk();
    $data = $stream
        ? json_decode(explode('data: ', $response->streamedContent())[1], true, flags: JSON_THROW_ON_ERROR)
        : $response->json('data');
    expect($data)->toHaveKeys(['state', 'kind', 'cursor', 'entries']);
    expect($data['state'])->toBe('working')->and($data['kind'])->toBe('snapshot')->and($data['cursor'])->toBeNull()
        ->and($data['entries'])->toHaveCount($saved ? 1 : 0);
    if ($saved) {
        expect($data['entries'][0]['text'])->toBe('Recorded answer');
    }
    Http::assertNothingSent();
})->with([false, true])->with([false, true]);

it('records terminal cost, transcript, subtask deltas and clears observation errors only once', function (): void {
    [$group, $thread, $operation, $first, $second] = recordedTaskWorld();
    $thread->update(['observation_error' => 'Old failure']);
    $result = ['state' => 'done', 'subtask' => $second->id, 'metrics' => ['tokens' => 140, 'input_tokens' => 100, 'cached_input_tokens' => 10, 'output_tokens' => 30, 'model_calls' => 2, 'peak_context_tokens' => 80, 'cost_usd' => 0.75], 'transcript' => [['id' => 'new', 'timestamp' => '', 'message' => ['role' => 'assistant', 'content' => [['type' => 'text', 'text' => 'Turn result']]]]]];
    $data = ['run_id' => 'recorded-turn', 'status' => 'succeeded', 'started_at' => now()->subMinute()->toIso8601String(), 'finished_at' => now()->toIso8601String(), 'result' => ['turn' => json_encode($result, JSON_THROW_ON_ERROR)]];
    $projection = app(TaskTurnProjection::class);
    $projection->apply($operation, $data);
    $projection->apply($operation, $data);
    expect($thread->refresh()->cost_usd)->toBe(0.75)->and($thread->observation_error)->toBeNull()->and($thread->tokens)->toBe(140);
    expect($first->refresh()->tokens)->toBe(60);
    expect($second->refresh()->tokens)->toBe(80);
    $this->getJson('/api/v1/task-groups/'.$group->id.'/agents/'.$thread->id)->assertOk()->assertJsonPath('data.entries.0.text', 'Turn result')->assertJsonPath('data.state', 'done');
    Http::assertNothingSent();
});

it('preserves usage after a failed continuation without a terminal usage report and exposes recorded cost in insights', function (): void {
    [$group, $thread, $operation, , $second] = recordedTaskWorld();
    TaskTurnProjection::recordThread($group->id, $thread->id, ['state' => 'done', 'subtask' => $second->id, 'metrics' => ['tokens' => 140, 'input_tokens' => 100, 'cost_usd' => 0.75]]);
    $operation->update(['status' => OperationStatus::Succeeded]);
    TaskTurnProjection::recordThread($group->id, $thread->id, ['state' => 'failed']);
    expect($thread->refresh()->tokens)->toBe(140)->and($thread->input_tokens)->toBe(100)->and($thread->cost_usd)->toBe(0.75);
    $group->update(['status' => TaskGroupStatus::Failed, 'ended_at' => now()]);
    $this->getJson('/api/v1/task-insights')->assertOk()->assertJsonPath('data.current.tokens', 160)->assertJsonPath('data.current.cost_usd', 0.95);
    Http::assertNothingSent();
});

it('keeps recorded line counts when the workspace observation is stale or truncated', function (bool $stale): void {
    [$group] = recordedTaskWorld();
    AgentThread::query()->where('task_group_id', $group->id)->get()->each(fn (AgentThread $thread) => $thread->update(['external_id' => 'pending:'.$thread->id]));
    $instance = $group->taskable;
    app(CacheAgentStateView::class)->putNode($instance->node_id, [], null, 1,
        $stale ? 1.0 : microtime(true), null, [[
            'instance_id' => $instance->id, 'base' => 'main', 'start' => null, 'branch' => 'task-'.$group->id,
            'head' => str_repeat('a', 40), 'dirty' => false, 'commits' => null,
            'diff' => ['files' => 501, 'added' => 900, 'removed' => 800, 'truncated' => ! $stale],
        ]]);
    $this->getJson('/api/v1/task-groups/'.$group->id)->assertOk()->assertJsonPath('data.lines_added', 30)->assertJsonPath('data.lines_deleted', 10);
    Http::assertNothingSent();
})->with([false, true]);

it('replaces a known cost with null when a terminal report contains unpriced usage', function (): void {
    [$group, $thread, , , $second] = recordedTaskWorld();
    $thread->update(['cost_usd' => 0.75]);
    TaskTurnProjection::recordThread($group->id, $thread->id, ['state' => 'done', 'subtask' => $second->id, 'metrics' => ['tokens' => 150, 'cost_usd' => null]]);
    expect($thread->refresh()->cost_usd)->toBeNull();
    expect($second->refresh()->tokens)->toBe(90);
    Http::assertNothingSent();
});
