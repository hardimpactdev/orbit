<?php

declare(strict_types=1);

use App\Actions\Tasks\ArchiveAgentTranscriptsAction;
use App\Domain\Instances\InstanceSourceLayout;
use App\Domain\Instances\InstanceState;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\OperationStatus;
use App\Domain\Tasks\TaskExtensionState;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskInsights;
use App\Infrastructure\AgentView\CacheAgentStateView;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\AgentView\WebSocketEndpoint;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\AgentThread;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Project;
use App\Models\Task;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

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

/** Shared fake at the native control-plane protocol boundary. */
function recordedTurnSocket(string $root, ?string $child, array $histories): WebSocketClient
{
    return new class($root, $child, $histories) implements WebSocketClient
    {
        public array $artifactRows = [];

        public array $artifactReplies = [];

        public array $queries = [];

        private ?array $frame = null;

        private bool $open = false;

        public function __construct(private string $root, private ?string $child, private array $histories) {}

        public function connect(WebSocketEndpoint $endpoint, float $timeoutSeconds): void
        {
            $this->open = true;
        }

        public function send(array $message): void
        {
            $this->frame = $message;
            if ($message['type'] === 'data.query') {
                $this->queries[] = $message['payload'];
            }
        }

        public function receive(float $timeoutSeconds): array
        {
            $frame = $this->frame;
            $this->frame = null;
            if ($frame === null) {
                return [];
            }
            $data = match ($frame['type']) {
                'workflow.run.search' => ['results' => $this->child === null ? [] : [['runId' => $this->child, 'parentRun' => ['runId' => $this->root]]]],
                'data.query' => ['results' => [], 'projected' => ['shape' => 'map',
                    'columns' => ['id', 'name', 'version', 'content'],
                    'rows' => $this->artifactReplies[$frame['payload']['predicate']] ?? $this->artifactRows]],
                default => $this->histories[$frame['payload']['workflowIdOrName']],
            };

            return [['id' => $frame['id'], 'type' => $frame['type'], 'payload' => ['data' => $data]]];
        }

        public function close(): void
        {
            $this->open = false;
        }

        public function isConnected(): bool
        {
            return $this->open;
        }
    };
}

it('serves distinct totals and recorded line counts for list, board, and detail without remote reads', function (string $path, bool $ended): void {
    [$group, , , $first, $second] = recordedTaskWorld();
    if ($ended) {
        $group->forceFill(['status' => TaskGroupStatus::Completed, 'ended_at' => now()->subMinute()])->save();
    }
    $url = $path === 'detail' ? '/api/v1/tasks/'.$group->id : '/api/v1/tasks'.($path === 'board' ? '?compact=1' : '');
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
    $this->getJson('/api/v1/tasks/'.$group->id.'/agents')->assertOk()->assertJsonPath('data.0.state', 'working');
    Http::assertNothingSent();
});

it('serves the recorded transcript and current activity through JSON and the board stream', function (bool $stream, bool $saved): void {
    [$group, $thread] = recordedTaskWorld();
    if (! $saved) {
        Storage::disk('local')->delete(ArchiveAgentTranscriptsAction::path($thread));
    }
    $response = $this->get('/api/v1/tasks/'.$group->id.'/agents/'.$thread->id.($stream ? '/stream' : '').'?after_sequence=old.4', ['Accept' => 'application/json'])->assertOk();
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
    $this->getJson('/api/v1/tasks/'.$group->id)->assertOk()->assertJsonPath('data.lines_added', 30)->assertJsonPath('data.lines_deleted', 10);
    Http::assertNothingSent();
})->with([false, true]);

it('derives activity from nested thread references of any open operation', function (string $kind): void {
    [$group, $thread, $operation] = recordedTaskWorld();
    $operation->update(['kind' => $kind, 'input' => ['turns' => [['intent' => ['thread_id' => $thread->id]]]]]);
    $this->getJson('/api/v1/tasks/'.$group->id.'/agents')->assertOk()->assertJsonPath('data.0.state', 'working');
    $this->getJson('/api/v1/tasks/'.$group->id.'/agents/'.$thread->id)->assertOk()->assertJsonPath('data.state', 'working');
    Http::assertNothingSent();
})->with(['tasks.copy_turn', 'tasks.check', 'future.operation']);

it('clears legacy copied subtask totals', function (): void {
    [$group, , , $first, $second] = recordedTaskWorld();
    $group->update(['tokens' => 120]);
    $first->update(['tokens' => 100]);
    $second->update(['tokens' => 100]);
    $migration = require database_path('migrations/2027_01_31_000000_clear_copied_subtask_token_totals.php');
    $migration->up();
    expect($first->refresh()->tokens)->toBeNull()->and($second->refresh()->tokens)->toBeNull()->and($group->refresh()->tokens)->toBe(120);
});

it('breaks insights down by the model of each task\'s first implementer thread, and counts a task without one as Unknown', function (): void {
    $project = Project::query()->create(['name' => 'app', 'slug' => 'app', 'repository_url' => 'https://github.com/example/app.git', 'default_branch' => 'main', 'root' => 'public']);
    $ended = static fn (string $title): Task => Task::topLevel()->create(['project_id' => $project->id, 'title' => $title, 'brief' => 'Brief', 'status' => TaskGroupStatus::Failed,
        'ended_at' => now(), 'implementer_model' => 'task-vm-model', 'reviewer_model' => 'task-vm-model']);
    $thread = static fn (Task $group, string $role, string $model): AgentThread => AgentThread::query()->create(['task_group_id' => $group->id, 'driver' => 't3',
        'runtime_key' => 'instance:1', 'external_id' => (string) Str::uuid(), 'role' => $role, 'model' => $model, 'effort' => 'high']);
    $replaced = $ended('Replaced implementer');
    $thread($replaced, 'reviewer', 'claude-haiku-5-5');
    $thread($replaced, 'implementer', 'claude-sonnet-5-5');
    $thread($replaced, 'implementer', 'claude-opus-5-5');
    $thread($ended('One implementer'), 'implementer', 'claude-sonnet-5-5');
    $ended('No thread');

    $model = app(TaskInsights::class)->report('7d', $project->id, CarbonImmutable::now())->breakdowns->model;

    // The task VM column never counts; a reviewer thread does not either.
    expect(array_map(static fn ($row): array => [$row->key, $row->label, $row->tasks], $model))
        ->toBe([['', 'Unknown', 1], ['claude-sonnet-5-5', 'claude-sonnet-5-5', 2]]);
});

it('takes the median merge wait from PR opened over the merged tasks that know both times, and null without one', function (): void {
    $project = Project::query()->create(['name' => 'app', 'slug' => 'app', 'repository_url' => 'https://github.com/example/app.git', 'default_branch' => 'main', 'root' => 'public']);
    $ended = static fn (?string $opened, ?string $merged): Task => Task::topLevel()->create(['project_id' => $project->id, 'title' => 'Ended', 'brief' => 'Brief',
        'status' => TaskGroupStatus::Completed, 'ended_at' => now(), 'settled_at' => now()->subHour(), 'pr_opened_at' => $opened, 'merged_at' => $merged]);
    $insights = static fn (): array => app(TaskInsights::class)->report('7d', $project->id, CarbonImmutable::now())->toArray()['current'];
    $ended(null, now()->subMinutes(30)->toIso8601String());
    $ended(now()->subHours(9)->toIso8601String(), null);

    expect($insights()['pr_merge_wait_ms'])->toBeNull();

    $ended(now()->subHours(3)->toIso8601String(), now()->subHour()->toIso8601String());
    $ended(now()->subHours(5)->toIso8601String(), now()->subHour()->toIso8601String());
    $ended(now()->subHours(7)->toIso8601String(), now()->subHour()->toIso8601String());

    // Only merged tasks with an opened time count.
    expect($insights())->toMatchArray(['merged' => 4, 'pr_merge_wait_ms' => 4 * 3_600_000]);
});
