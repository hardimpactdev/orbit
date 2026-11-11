<?php

declare(strict_types=1);

use App\Actions\Tasks\ArchiveAgentTranscriptsAction;
use App\Domain\Instances\InstanceSourceLayout;
use App\Domain\Instances\InstanceState;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\TaskCheckStatus;
use App\Domain\Tasks\TaskExtensionState;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskReviewRoundVerdict;
use App\Domain\Tasks\TaskTurnProjection;
use App\Infrastructure\AgentView\CacheAgentStateView;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\AgentView\WebSocketEndpoint;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Infrastructure\Swamp\SwampCommandStep;
use App\Infrastructure\Swamp\SwampConnection;
use App\Infrastructure\Swamp\SwampTerminalReadUnavailable;
use App\Models\AgentThread;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskCheck;
use App\Models\TaskReviewRound;
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

it('records terminal cost, transcript, subtask deltas and clears observation errors only once', function (): void {
    [$group, $thread, $operation, $first, $second] = recordedTaskWorld();
    $thread->update(['observation_error' => 'Old failure']);
    $result = ['state' => 'done', 'subtask' => $second->id, 'metrics' => ['tokens' => 140, 'input_tokens' => 100, 'cached_input_tokens' => 10, 'output_tokens' => 30, 'model_calls' => 2, 'peak_context_tokens' => 80, 'cost_usd' => 0.75], 'transcript' => [['id' => 'new', 'kind' => 'message', 'label' => 'assistant', 'text' => 'Turn result', 'at' => '']]];
    $data = ['run_id' => 'recorded-turn', 'status' => 'succeeded', 'started_at' => now()->subMinute()->toIso8601String(), 'finished_at' => now()->toIso8601String(), 'result' => ['turn' => json_encode($result, JSON_THROW_ON_ERROR)]];
    $projection = app(TaskTurnProjection::class);
    $projection->apply($operation, $data);
    $projection->apply($operation, $data);
    expect($thread->refresh()->cost_usd)->toBe(0.75)->and($thread->observation_error)->toBeNull()->and($thread->tokens)->toBe(140);
    expect($first->refresh()->tokens)->toBe(60);
    expect($second->refresh()->tokens)->toBe(80);
    $this->getJson('/api/v1/tasks/'.$group->id.'/agents/'.$thread->id)->assertOk()->assertJsonPath('data.entries.0.text', 'Turn result')->assertJsonPath('data.state', 'done');
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
    $this->getJson('/api/v1/tasks/'.$group->id)->assertOk()->assertJsonPath('data.lines_added', 30)->assertJsonPath('data.lines_deleted', 10);
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

it('derives activity from nested thread references of any open operation', function (string $kind): void {
    [$group, $thread, $operation] = recordedTaskWorld();
    $operation->update(['kind' => $kind, 'input' => ['turns' => [['intent' => ['thread_id' => $thread->id]]]]]);
    $this->getJson('/api/v1/tasks/'.$group->id.'/agents')->assertOk()->assertJsonPath('data.0.state', 'working');
    $this->getJson('/api/v1/tasks/'.$group->id.'/agents/'.$thread->id)->assertOk()->assertJsonPath('data.state', 'working');
    Http::assertNothingSent();
})->with(['tasks.prep', 'tasks.prep_fix', 'tasks.build', 'tasks.review_round', 'future.operation']);

it('clears legacy copied subtask totals and records only later deltas', function (): void {
    [$group, $thread, , $first, $second] = recordedTaskWorld();
    $group->update(['tokens' => 120]);
    $first->update(['tokens' => 100]);
    $second->update(['tokens' => 100]);
    $migration = require database_path('migrations/2027_01_31_000000_clear_copied_subtask_token_totals.php');
    $migration->up();
    expect($first->refresh()->tokens)->toBeNull()->and($second->refresh()->tokens)->toBeNull()->and($group->refresh()->tokens)->toBe(120);
    TaskTurnProjection::recordThread($group->id, $thread->id, ['state' => 'done', 'subtask' => $second->id, 'metrics' => ['tokens' => 140]]);
    expect($first->refresh()->tokens)->toBeNull()->and($second->refresh()->tokens)->toBe(40);
});

it('records a completed child while its parent is running and deduplicates parent replay', function (string $kind, bool $artifact): void {
    [$group, $thread, $operation, , $second] = recordedTaskWorld();
    $root = '986bc349-54dc-4dea-9f22-76e3353d1cb0';
    $child = '986bc349-54dc-4dea-9f22-76e3353d1cb1';
    $intent = ['group_id' => $group->id, 'thread_id' => $thread->id];
    $operation->update(['kind' => $kind, 'input' => ['turn' => $intent], 'state' => ['swamp_run_id' => $root]]);
    $result = ['state' => 'done', 'subtask' => $second->id, 'metrics' => ['tokens' => 140, 'cost_usd' => 0.75],
        'transcript' => [['id' => 'child', 'kind' => 'message', 'label' => 'assistant', 'text' => 'Child ended before parent', 'at' => '']]];
    $workflow = app(DirectOperation::class)->definition($kind)['workflow'];
    $histories = [
        $root => ['id' => $root, 'workflowName' => $workflow, 'status' => 'running', 'startedAt' => now()->subMinute()->toISOString(),
            'inputs' => ['operation_id' => $operation->id, 'node_id' => $operation->node_id, 'kind' => $kind]],
        $child => ['id' => $child, 'workflowName' => 'operation-task-turn', 'status' => 'succeeded', 'completedAt' => now()->toISOString(),
            'inputs' => ['intent' => $intent], 'jobs' => [['name' => 'turn', 'steps' => [
                ['name' => 'shape_result', 'status' => 'succeeded', 'outputs' => ['result_json' => json_encode($result, JSON_THROW_ON_ERROR)]],
                ['name' => 'outcome', 'status' => 'succeeded'],
            ]]]],
    ];
    $dataId = '9bbf19aa-d4a5-405e-9a43-901fb585a812';
    if ($artifact) {
        unset($histories[$child]['jobs'][0]['steps'][0]['outputs']);
        $histories[$child]['jobs'][0]['steps'][0]['dataArtifacts'] = [['dataId' => $dataId, 'name' => 'result', 'version' => 22]];
    }
    $socket = recordedTurnSocket($root, $child, $histories);
    app()->instance(WebSocketClient::class, $socket);
    app(SwampConnection::class)->release();
    config(['swamp.address' => 'swamp.test', 'swamp.ca_path' => '/tmp/unused-test-ca', 'swamp.read_token' => 'fixture-read-token']);
    try {
        $reader = app(SwampCommandStep::class);
        $selector = app(DirectOperation::class)->definition($kind)['terminal_result'];
        if ($artifact) {
            expect(fn () => $reader->projectTerminal($operation, ['id' => $root], $selector))->toThrow(ResourceOperationException::class);
            expect($operation->refresh()->state['native_children'] ?? [])->toBe([]);
            expect($thread->refresh()->tokens)->toBe(100);
            $socket->artifactRows = [['id' => $dataId, 'name' => 'result', 'version' => 23, 'content' => ['result_json' => json_encode($result, JSON_THROW_ON_ERROR)]]];
            expect(fn () => $reader->projectTerminal($operation, ['id' => $root], $selector))->toThrow(ResourceOperationException::class);
            expect($operation->refresh()->state['native_children'] ?? [])->toBe([]);
            $socket->artifactRows[0]['version'] = 22;
        }
        expect(fn () => $reader->projectTerminal($operation, ['id' => $root], $selector))->toThrow(SwampTerminalReadUnavailable::class);
        expect($operation->refresh()->state['native_children'][$child])->toBeTrue();
        if ($artifact) {
            expect($socket->queries)->toHaveCount(3);
            expect($socket->queries[0]['predicate'])->toBe('id == "'.$dataId.'" && version == 22');
        }
        expect($operation->refresh()->status)->toBe(OperationStatus::Running);
        expect($thread->refresh()->tokens)->toBe(140);
        expect($second->refresh()->tokens)->toBe(80);
        $this->getJson('/api/v1/tasks/'.$group->id.'/agents/'.$thread->id)->assertOk()->assertJsonPath('data.entries.0.text', 'Child ended before parent');
        TaskTurnProjection::recordTurn($operation, $thread->id, [...$result, 'metrics' => ['tokens' => 180]]);
        TaskTurnProjection::recordTurn($operation, $thread->id, $result);
        expect($thread->refresh()->tokens)->toBe(180)->and($second->refresh()->tokens)->toBe(120);
        Http::assertNothingSent();
    } finally {
        app(SwampConnection::class)->release();
    }
})->with(['tasks.prep', 'tasks.prep_fix', 'tasks.build', 'tasks.review_round'])->with([false, true]);

it('keeps the transcript and distinguishes deadline cancellation from user cancellation', function (bool $expired, bool $userCancel): void {
    [$group, $thread, $operation] = recordedTaskWorld();
    $root = '986bc349-54dc-4dea-9f22-76e3353d1cc0';
    $operation->update(['state' => ['swamp_run_id' => $root, ...($expired ? ['native_timeout' => [], 'native_timeout_restore' => ['cancel_requested' => $userCancel]] : [])],
        'input' => ['group_id' => $group->id, 'thread_id' => $thread->id],
        'started_at' => $expired ? now()->subHours(2) : now()->subMinute(), 'dispatched_at' => $expired ? now()->subHours(2) : now()->subMinute(),
        'status' => $expired ? OperationStatus::Failed : OperationStatus::Running,
        'error_code' => $expired ? 'operation.timed_out' : null, 'cancel_requested_at' => $userCancel ? now()->subMinutes(2) : null,
        'lock_key' => 'task-group:'.$group->id]);
    $result = ['state' => 'failed', 'transcript' => [['id' => 'partial', 'kind' => 'message', 'label' => 'assistant', 'text' => 'Saved before disconnect', 'at' => '']]];
    $history = ['id' => $root, 'workflowName' => 'operation-task-turn', 'status' => 'cancelled',
        'startedAt' => now()->subHours(2)->toISOString(), 'completedAt' => now()->toISOString(),
        'inputs' => ['operation_id' => $operation->id, 'node_id' => $operation->node_id, 'kind' => 'tasks.turn', 'intent' => $operation->input],
        'jobs' => [['name' => 'turn', 'steps' => [['name' => 'shape_result', 'status' => 'succeeded', 'outputs' => ['result_json' => json_encode($result, JSON_THROW_ON_ERROR)]]]]]];
    $socket = new class($history) implements WebSocketClient
    {
        private ?array $frame = null;

        private bool $open = false;

        public function __construct(private array $history) {}

        public function connect(WebSocketEndpoint $endpoint, float $timeoutSeconds): void
        {
            $this->open = true;
        }

        public function send(array $message): void
        {
            $this->frame = $message;
        }

        public function receive(float $timeoutSeconds): array
        {
            $frame = $this->frame;
            $this->frame = null;
            if ($frame === null) {
                return [];
            }

            return [['id' => $frame['id'], 'type' => $frame['type'], 'payload' => ['data' => $frame['type'] === 'workflow.run.search' ? ['results' => []] : $this->history]]];
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
    app()->instance(WebSocketClient::class, $socket);
    app(SwampConnection::class)->release();
    config(['swamp.address' => 'swamp.test', 'swamp.ca_path' => __FILE__, 'swamp.read_token' => 'fixture-read-token']);
    try {
        $reader = app(SwampCommandStep::class);
        $selector = app(DirectOperation::class)->definition('tasks.turn')['terminal_result'];
        $reader->projectTerminal($operation, ['id' => $root], $selector);
        expect($thread->refresh()->tokens)->toBe(100);
        expect($operation->refresh()->status)->toBe($expired && ! $userCancel ? OperationStatus::Failed : OperationStatus::Cancelled)
            ->and($operation->error_code)->toBe($expired && ! $userCancel ? 'operation.timed_out' : null);
        if ($expired) {
            expect($operation->state['native_timeout']['native_terminal_status'])->toBe('cancelled');
        }
        expect($operation->lock_key)->toBeNull();
        $this->getJson('/api/v1/tasks/'.$group->id.'/agents/'.$thread->id)->assertOk()->assertJsonPath('data.entries.0.text', 'Recorded answer')->assertJsonPath('data.state', 'done');
        Http::assertNothingSent();
    } finally {
        app(SwampConnection::class)->release();
    }
})->with(['user cancellation' => [false, true], 'deadline cancellation' => [true, false], 'user request predates expiry' => [true, true]]);

it('loads a root turn capture from its recorded result artifact before projecting', function (): void {
    [$group, $thread, $operation] = recordedTaskWorld();
    $root = '986bc349-54dc-4dea-9f22-76e3353d1cd0';
    $dataId = '9bbf19aa-d4a5-405e-9a43-901fb585a813';
    $operation->update(['state' => ['swamp_run_id' => $root], 'input' => ['group_id' => $group->id, 'thread_id' => $thread->id]]);
    $result = ['state' => 'done', 'metrics' => ['tokens' => 140], 'transcript' => [['id' => 'root', 'kind' => 'message', 'label' => 'assistant', 'text' => 'Large recorded result', 'at' => '']]];
    $history = ['id' => $root, 'workflowName' => 'operation-task-turn', 'status' => 'succeeded',
        'startedAt' => now()->subMinute()->toISOString(), 'completedAt' => now()->toISOString(),
        'inputs' => ['operation_id' => $operation->id, 'node_id' => $operation->node_id, 'kind' => 'tasks.turn'],
        'jobs' => [['name' => 'turn', 'steps' => [
            ['name' => 'shape_result', 'status' => 'succeeded', 'dataArtifacts' => [['dataId' => $dataId, 'name' => 'result', 'version' => 22]]],
            ['name' => 'outcome', 'status' => 'succeeded'],
        ]]]];
    $socket = recordedTurnSocket($root, null, [$root => $history]);
    $socket->artifactRows = [['id' => $dataId, 'name' => 'result', 'version' => 22, 'content' => json_encode(['result_json' => json_encode($result, JSON_THROW_ON_ERROR)], JSON_THROW_ON_ERROR)]];
    app()->instance(WebSocketClient::class, $socket);
    app(SwampConnection::class)->release();
    config(['swamp.address' => 'swamp.test', 'swamp.ca_path' => __FILE__, 'swamp.read_token' => 'fixture-read-token']);
    try {
        app(SwampCommandStep::class)->projectTerminal($operation, ['id' => $root], app(DirectOperation::class)->definition('tasks.turn')['terminal_result']);
        expect($operation->refresh()->status)->toBe(OperationStatus::Succeeded);
        expect($thread->refresh()->tokens)->toBe(140);
        expect($socket->queries[0]['predicate'])->toBe('id == "'.$dataId.'" && version == 22');
        $this->getJson('/api/v1/tasks/'.$group->id.'/agents/'.$thread->id)->assertOk()->assertJsonPath('data.entries.0.text', 'Large recorded result');
        Http::assertNothingSent();
    } finally {
        app(SwampConnection::class)->release();
    }
});

it('projects a terminal prep parent with large state settlement evidence', function (string $kind): void {
    [$group, $thread, $operation] = recordedTaskWorld();
    $root = '986bc349-54dc-4dea-9f22-76e3353d1cd1';
    $dataId = '9bbf19aa-d4a5-405e-9a43-901fb585a814';
    $intent = ['draft' => ['thread_id' => $thread->id], 'author' => ['thread_id' => $thread->id],
        'reviews' => array_fill(0, 3, ['thread_id' => $thread->id]), 'execution_secrets' => [],
        'provision' => ['task_vm_id' => TaskVm::query()->where('group_id', $group->id)->sole()->id,
            'instance_id' => $group->taskable_id, 'workspace' => ['branch' => 'task-fixture', 'start_commit' => str_repeat('1', 40)]]];
    $operation->update(['kind' => $kind, 'input' => $intent, 'state' => ['swamp_run_id' => $root]]);
    $selector = app(DirectOperation::class)->definition($kind)['terminal_result'];
    $findings = str_repeat('Recorded settlement findings. ', 3000);
    $settlement = ['approved' => false, 'head' => str_repeat('2', 40), 'tree' => str_repeat('3', 40), 'findings' => $findings];
    $history = ['id' => $root, 'workflowName' => app(DirectOperation::class)->definition($kind)['workflow'], 'status' => 'failed',
        'startedAt' => now()->subMinute()->toISOString(), 'completedAt' => now()->toISOString(),
        'inputs' => ['operation_id' => $operation->id, 'node_id' => $operation->node_id, 'kind' => $kind],
        'jobs' => [['name' => $selector['job'], 'steps' => [
            ['name' => 'Claim a task place', 'status' => 'succeeded', 'outputs' => ['ready' => true]],
            ['name' => $selector['captures']['findings']['step'], 'status' => 'succeeded',
                'dataArtifacts' => [['dataId' => $dataId, 'name' => 'state', 'version' => 22]]],
            ['name' => $selector['step'], 'status' => 'failed'],
        ]]]];
    $socket = recordedTurnSocket($root, null, [$root => $history]);
    $socket->artifactRows = [['id' => $dataId, 'name' => 'state', 'version' => 23, 'content' => $settlement]];
    app()->instance(WebSocketClient::class, $socket);
    app(SwampConnection::class)->release();
    config(['swamp.address' => 'swamp.test', 'swamp.ca_path' => __FILE__, 'swamp.read_token' => 'fixture-read-token']);
    try {
        $reader = app(SwampCommandStep::class);
        expect(fn () => $reader->projectTerminal($operation, ['id' => $root], $selector))->toThrow(ResourceOperationException::class);
        expect($operation->refresh()->status)->toBe(OperationStatus::Running);
        $socket->artifactRows[0]['version'] = 22;
        $socket->artifactRows[0]['name'] = 'result';
        expect(fn () => $reader->projectTerminal($operation, ['id' => $root], $selector))->toThrow(ResourceOperationException::class);
        expect($operation->refresh()->status)->toBe(OperationStatus::Running);
        $socket->artifactRows[0]['name'] = 'state';
        $reader->projectTerminal($operation, ['id' => $root], $selector);
        expect($operation->refresh()->status)->toBe(OperationStatus::Failed);
        expect($operation->error_code)->toBe('tasks.prep_review_unresolved');
        expect($operation->result['findings'])->toBe(substr($findings, 0, 4000));
        expect($socket->queries[0]['predicate'])->toBe('id == "'.$dataId.'" && version == 22');
        Http::assertNothingSent();
    } finally {
        app(SwampConnection::class)->release();
    }
})->with(['tasks.prep', 'tasks.prep_fix']);

it('projects a terminal review parent with large verdict and checked fix artifacts', function (): void {
    [$group, , $operation, , $subtask] = recordedTaskWorld();
    $root = '986bc349-54dc-4dea-9f22-76e3353d1cd2';
    $verdictId = '9bbf19aa-d4a5-405e-9a43-901fb585a815';
    $fixId = '9bbf19aa-d4a5-405e-9a43-901fb585a816';
    $commit = str_repeat('4', 40);
    $output = str_repeat('Recorded check output. ', 3000);
    $checked = ['outcome' => 'passed', 'exit_code' => 0, 'commit' => $commit, 'output' => $output];
    $operation->update(['kind' => 'tasks.review_round', 'input' => [], 'state' => ['swamp_run_id' => $root]]);
    $check = TaskCheck::query()->create(['task_id' => $subtask->id, 'kind' => 'handoff', 'status' => 'running',
        'pid' => 0, 'process_started' => '', 'head_before' => '', 'tree_before' => '', 'started_at' => now()]);
    $round = TaskReviewRound::query()->create(['task_group_id' => $group->id, 'head_sha' => str_repeat('1', 40),
        'round' => 1, 'operation_id' => $operation->id, 'check_id' => $check->id]);
    $history = ['id' => $root, 'workflowName' => 'operation-task-review-round', 'status' => 'succeeded',
        'startedAt' => now()->subMinute()->toISOString(), 'completedAt' => now()->toISOString(),
        'inputs' => ['operation_id' => $operation->id, 'node_id' => $operation->node_id, 'kind' => 'tasks.review_round'],
        'jobs' => [['name' => 'round', 'steps' => [
            ['name' => 'Settle the review', 'status' => 'succeeded', 'dataArtifacts' => [['dataId' => $verdictId, 'name' => 'verdict', 'version' => 22]]],
            ['name' => 'Settle the check', 'status' => 'succeeded', 'dataArtifacts' => [['dataId' => $fixId, 'name' => 'fix', 'version' => 24]]],
            ['name' => 'Push the fix', 'status' => 'succeeded'], ['name' => 'Confirm the round', 'status' => 'succeeded'],
        ]]]];
    $socket = recordedTurnSocket($root, null, [$root => $history]);
    $socket->artifactReplies = [
        'id == "'.$verdictId.'" && version == 22' => [['id' => $verdictId, 'name' => 'verdict', 'version' => 22,
            'content' => ['verdict' => 'findings', 'findings' => str_repeat('Review findings. ', 5000), 'body' => str_repeat('Review body. ', 5000)]]],
        'id == "'.$fixId.'" && version == 24' => [['id' => $fixId, 'name' => 'fix', 'version' => 24,
            'content' => json_encode(['result_json' => json_encode($checked, JSON_THROW_ON_ERROR), 'commit' => $commit], JSON_THROW_ON_ERROR)]],
    ];
    app()->instance(WebSocketClient::class, $socket);
    app(SwampConnection::class)->release();
    config(['swamp.address' => 'swamp.test', 'swamp.ca_path' => __FILE__, 'swamp.read_token' => 'fixture-read-token']);
    try {
        app(SwampCommandStep::class)->projectTerminal($operation, ['id' => $root], app(DirectOperation::class)->definition('tasks.review_round')['terminal_result']);
        expect($operation->refresh()->status)->toBe(OperationStatus::Succeeded);
        expect($round->refresh()->verdict)->toBe(TaskReviewRoundVerdict::Findings);
        expect($round->pushed_commit)->toBe($commit);
        expect($check->refresh()->status)->toBe(TaskCheckStatus::Passed);
        expect($check->output)->toBe($output);
        expect(array_values(array_unique(array_column($socket->queries, 'predicate'))))->toBe(array_keys($socket->artifactReplies));
        Http::assertNothingSent();
    } finally {
        app(SwampConnection::class)->release();
    }
});
