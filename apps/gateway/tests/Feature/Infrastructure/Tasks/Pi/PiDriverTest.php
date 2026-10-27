<?php

declare(strict_types=1);

use App\Actions\Tasks\ArchiveAgentTranscriptsAction;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\AgentDriverException;
use App\Domain\Tasks\AgentDriverRegistry;
use App\Domain\Tasks\AgentInputRequest;
use App\Domain\Tasks\AgentThreadEvent;
use App\Domain\Tasks\AgentThreadObserver;
use App\Domain\Tasks\AgentThreadStart;
use App\Domain\Tasks\AgentThreadState;
use App\Domain\Tasks\LeasedAgentDriver;
use App\Domain\Tasks\TaskCheckRunner;
use App\Domain\Tasks\TaskThreadRole;
use App\Infrastructure\Tasks\Pi\PiClient;
use App\Infrastructure\Tasks\Pi\PiDriver;
use App\Models\AgentThread;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Project;
use App\Models\Task;
use GuzzleHttp\Exception\ResponseException;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request as Psr7Request;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Support\FakeTaskCheckRunner;
use Tests\Support\TaskModelRouter;

const PI_TOKEN = TEST_TASK_VM_PI_TOKEN;
const PI_BASE = 'http://10.44.0.9:3774';

function pi_node(): Node
{
    return Node::query()->create([
        'name' => 'pi-node', 'platform' => 'linux', 'status' => 'active',
        'wireguard_ip' => '10.44.0.9', 'public_ssh_host' => '10.44.0.9',
    ]);
}

/** The workspace of a running group on its ready task VM, whose Node is `$node`. */
function pi_workspace(Node $node, bool $taskVm = true): Instance
{
    $project = Project::query()->create(['name' => 'pi', 'slug' => 'pi', 'repository_url' => 'git@example.test:pi.git', 'default_branch' => 'main']);
    $group = Task::topLevel()->create(['project_id' => $project->id, 'implementer_agent_driver' => 'pi', 'title' => 'Feature', 'brief' => 'Brief', 'status' => 'running']);
    $workspace = Instance::query()->create(['project_id' => $project->id, 'node_id' => $node->id, 'name' => 'task-'.$group->id, 'task_workspace_owner_id' => $group->id, 'checkout_path' => '/srv/task-1', 'status' => 'source_resolved']);
    $group->taskable()->associate($workspace)->save();
    if ($taskVm) {
        test_task_vm($group, $node);
    }

    return $workspace;
}

function pi_thread(Node $node, string $externalId = 'session-1', bool $taskVm = true): AgentThread
{
    $workspace = pi_workspace($node, $taskVm);
    $group = Task::topLevel()->whereMorphedTo('taskable', $workspace)->firstOrFail();

    return AgentThread::query()->create([
        'task_group_id' => $group->id,
        'driver' => 'pi', 'runtime_key' => 'node:'.$node->id, 'external_id' => $externalId, 'node_id' => $node->id,
        'role' => 'implementer', 'model' => 'gpt-5.6-luna', 'effort' => 'low',
    ]);
}

function pi_driver(): PiDriver
{
    return app(PiDriver::class);
}

/**
 * @param  list<array<string, mixed>>  $entries
 * @return array<string, mixed>
 */
function pi_snapshot(string $state, array $entries = [], ?string $error = null, string $id = 'session-1'): array
{
    return [
        'kind' => 'snapshot', 'run' => 'run-1', 'sequence' => 7,
        'session' => ['id' => $id, 'cwd' => '/srv/task-1', 'model' => 'openai-codex/gpt-5.6-luna', 'thinkingLevel' => 'low'],
        'state' => $state, 'error' => $error, 'turnId' => 'turn-key-1', 'entries' => $entries,
        'usage' => ['input' => 100, 'output' => 20, 'cacheRead' => 0, 'cacheWrite' => 0, 'total' => 120],
    ];
}

/** @param list<array<string, mixed>> $lines */
function pi_stream(array $lines): void
{
    Http::fake([PI_BASE.'/sessions/session-1/stream*' => Http::response(implode("\n", array_map(json_encode(...), $lines))."\n")]);
}

/**
 * @param  list<array<string, mixed>>  $lines
 * @return list<AgentThreadEvent>
 */
function pi_events(array $lines, ?string $cursor = null): array
{
    pi_stream($lines);

    return iterator_to_array(pi_driver()->events(pi_thread(pi_node()), $cursor), false);
}

/** @return array<string, mixed> */
function pi_entry_event(int $sequence, array $entry): array
{
    return ['kind' => 'entry', 'run' => 'run-1', 'sequence' => $sequence, 'entry' => $entry];
}

/** @return list<array<string, mixed>> */
function pi_check_transcript(string $checkResult, bool $failed = false): array
{
    return [
        ['id' => 'e1', 'timestamp' => '2026-09-22T10:00:00.000Z', 'message' => ['role' => 'user', 'content' => 'Implement it']],
        ['id' => 'e2', 'timestamp' => '2026-09-22T10:00:01.000Z', 'message' => ['role' => 'assistant', 'stopReason' => 'toolUse', 'content' => [
            ['type' => 'text', 'text' => 'Running the checks.'],
            ['type' => 'toolCall', 'id' => 'call-1', 'name' => 'bash', 'arguments' => ['command' => 'composer check']],
        ]]],
        ['id' => 'e3', 'timestamp' => '2026-09-22T10:00:02.000Z', 'message' => [
            'role' => 'toolResult', 'toolCallId' => 'call-1', 'toolName' => 'bash', 'isError' => $failed,
            'content' => [['type' => 'text', 'text' => $checkResult]],
        ]],
        ['id' => 'e4', 'timestamp' => '2026-09-22T10:00:03.000Z', 'message' => ['role' => 'assistant', 'stopReason' => 'stop', 'content' => [['type' => 'text', 'text' => 'Done.']]]],
    ];
}

describe('registration and placement', function (): void {
    it('registers the lease-protected Pi driver and dispatches through Pi', function (): void {
        Http::fake([
            PI_BASE.'/sessions/session-1?view=state' => Http::response([...pi_snapshot('done'), 'kind' => 'state']),
            PI_BASE.'/sessions/session-1/messages' => Http::response(['duplicate' => false], 202),
        ]);
        app()->instance(TaskCheckRunner::class, new FakeTaskCheckRunner);
        $driver = app(AgentDriverRegistry::class)->get('pi');
        expect($driver)->toBeInstanceOf(LeasedAgentDriver::class)->and($driver->key())->toBe('pi');

        $driver->send(pi_thread(pi_node()), 'Continue through the registered driver.', 'registration-key');

        Http::assertSent(fn (Request $request): bool => $request->url() === PI_BASE.'/sessions/session-1/messages'
            && $request->hasHeader('Authorization', 'Bearer '.PI_TOKEN)
            && $request['text'] === 'Continue through the registered driver.' && $request['key'] === 'registration-key');
    });
});

describe('create and send', function (): void {
    it('creates a session in the workspace and starts the opening turn', function (): void {
        Http::fake([PI_BASE.'/sessions' => Http::response(['id' => 'x'], 201), PI_BASE.'/sessions/*/messages' => Http::response(['duplicate' => false], 202)]);
        $node = pi_node();

        $id = pi_driver()->create(new AgentThreadStart($node, pi_workspace($node), 'Task', 'Do the work', 'gpt-5.6-luna', 'low', TaskThreadRole::Implementer));

        Http::assertSent(fn (Request $request): bool => $request->url() === PI_BASE.'/sessions'
            && $request->hasHeader('Authorization', 'Bearer '.PI_TOKEN)
            && $request['id'] === $id && $request['cwd'] === '/srv/task-1'
            && $request['model'] === 'orbit-task-vm/gpt-5.6-luna' && $request['thinkingLevel'] === 'low');
        Http::assertSent(fn (Request $request): bool => $request->url() === PI_BASE.'/sessions/'.$id.'/messages'
            && $request['text'] === 'Do the work' && is_string($request['key']));
    });

    it('retries a refused send once with the same key', function (): void {
        Http::fake([PI_BASE.'/sessions/*/messages' => Http::sequence()->push(['error' => ['code' => 'server.unhandled', 'message' => 'x', 'details' => (object) []]], 500)->push(['duplicate' => false], 202)]);
        $node = pi_node();

        pi_driver()->send(pi_thread($node), 'Continue');

        $keys = collect(Http::recorded())->map(fn (array $pair): mixed => $pair[0]['key'])->all();
        expect($keys)->toHaveCount(2)->and($keys[0])->toBe($keys[1]);
    });

    it('posts a supplied key on the first send and on the retry', function (): void {
        Http::fake([PI_BASE.'/sessions/*/messages' => Http::sequence()->push(['error' => ['code' => 'server.unhandled', 'message' => 'x', 'details' => (object) []]], 500)->push(['duplicate' => false], 202)]);

        pi_driver()->send(pi_thread(pi_node()), 'Continue', 'resume-key-1');

        $keys = collect(Http::recorded())->map(fn (array $pair): mixed => $pair[0]['key'])->all();
        expect($keys)->toBe(['resume-key-1', 'resume-key-1']);
    });

    it('names the server error when both attempts fail', function (): void {
        Http::fake([PI_BASE.'/sessions/*/messages' => Http::response(['error' => ['code' => 'session.turn_active', 'message' => 'Session is already working on a turn.', 'details' => (object) []]], 409)]);

        expect(fn () => pi_driver()->send(pi_thread(pi_node()), 'Continue'))
            ->toThrow(AgentDriverException::class, 'The Pi server refused the request (session.turn_active): Session is already working on a turn.');
    });

    it('refuses Claude models even through a named provider', function (string $model): void {
        Http::fake();
        $node = pi_node();

        expect(fn () => pi_driver()->create(new AgentThreadStart($node, pi_workspace($node), 'Task', 'Review', $model, 'high', TaskThreadRole::Reviewer)))
            ->toThrow(AgentDriverException::class, 'Claude models are unavailable for task agents.');
        Http::assertNothingSent();
    })->with(['claude-opus-5', 'cliproxyapi/claude-opus-5', 'anthropic/claude-opus-5']);

    it('refuses Claude models before calling the server', function (): void {
        Http::fake();
        $node = pi_node();

        expect(fn () => pi_driver()->create(new AgentThreadStart($node, pi_workspace($node), 'Task', 'Review', 'claude-opus-5', 'high', TaskThreadRole::Reviewer)))
            ->toThrow(AgentDriverException::class, 'Claude models are unavailable for task agents.');
        Http::assertNothingSent();
    });

    it('refuses a thread whose workspace is not on a ready task VM', function (): void {
        Http::fake();

        expect(fn () => pi_driver()->send(pi_thread(pi_node(), taskVm: false), 'Continue'))
            ->toThrow(AgentDriverException::class, 'The original task VM Pi server is unavailable.');
        Http::assertNothingSent();
    });

    it('refuses to create a session on a Node that is no task VM', function (): void {
        Http::fake();
        $node = pi_node();

        expect(fn () => pi_driver()->create(new AgentThreadStart($node, pi_workspace($node, taskVm: false), 'Task', 'Work', 'gpt-5.6-luna', 'low', TaskThreadRole::Implementer)))
            ->toThrow(AgentDriverException::class, 'The original workspace Pi server is unavailable.');
        Http::assertNothingSent();
    });

    it('reports an unreachable server as unavailable', function (): void {
        Http::fake(fn () => throw new ConnectionException('refused'));

        expect(fn () => pi_driver()->interrupt(pi_thread(pi_node())))->toThrow(AgentDriverException::class, 'The Pi server is unavailable.');
    });

    it('does not support input requests', function (): void {
        expect(fn () => pi_driver()->respond(pi_thread(pi_node()), new AgentInputRequest('r', 'question'), []))
            ->toThrow(AgentDriverException::class, 'The agent driver does not support input requests on Pi threads.');
    });
});

describe('transcript and observation', function (): void {
    it('maps a completed turn to done with entries, tokens, and turn identity', function (): void {
        Http::fake([PI_BASE.'/sessions/session-1*' => Http::response(pi_snapshot('done', pi_check_transcript("All checks passed\n")))]);

        $observation = pi_driver()->read(pi_thread(pi_node()), null);

        expect($observation->state)->toBe(AgentThreadState::Done)
            ->and($observation->tokens)->toBe(120)
            ->and($observation->turnId)->toBe('turn-key-1')
            ->and($observation->cursor)->toBe('run-1.7')
            ->and(array_column($observation->entries, 'kind'))->toBe(['message', 'message', 'activity', 'message'])
            ->and($observation->entries[2])->toMatchArray(['label' => 'bash', 'text' => "All checks passed\n\n$ composer check\nexit code 0"]);
    });

    it('shows a successful bash result as one activity that ends with the command and its exit code', function (): void {
        Http::fake([PI_BASE.'/sessions/session-1*' => Http::response(pi_snapshot('done', pi_check_transcript('ok')))]);

        $observation = pi_driver()->read(pi_thread(pi_node()), null);

        expect($observation->entries[2]['text'])->toEndWith("$ composer check\nexit code 0");
    });

    it('shows the exit code of a failed bash result', function (): void {
        Http::fake([PI_BASE.'/sessions/session-1*' => Http::response(pi_snapshot('done', pi_check_transcript("PHPStan found 2 errors\n\nCommand exited with code 2", failed: true)))]);

        $observation = pi_driver()->read(pi_thread(pi_node()), null);

        expect($observation->entries[2]['text'])->toEndWith("$ composer check\nexit code 2");
    });

    it('shows a later edit by its tool name and target', function (): void {
        $entries = [...pi_check_transcript('ok'),
            ['id' => 'e5', 'timestamp' => '2026-09-22T10:00:04.000Z', 'message' => ['role' => 'assistant', 'stopReason' => 'toolUse', 'content' => [
                ['type' => 'toolCall', 'id' => 'call-2', 'name' => 'edit', 'arguments' => ['path' => 'app/Service.php']],
            ]]],
            ['id' => 'e6', 'timestamp' => '2026-09-22T10:00:05.000Z', 'message' => ['role' => 'toolResult', 'toolCallId' => 'call-2', 'toolName' => 'edit', 'isError' => false, 'content' => [['type' => 'text', 'text' => 'Replaced 1 block.']]]],
        ];
        Http::fake([PI_BASE.'/sessions/session-1*' => Http::response(pi_snapshot('done', $entries))]);

        $observation = pi_driver()->read(pi_thread(pi_node()), null);

        expect(array_last($observation->entries)['text'])->toBe('Editing Service.php');
    });

    it('keeps the leading spaces of bash output, such as git status lines', function (): void {
        Http::fake([PI_BASE.'/sessions/session-1*' => Http::response(pi_snapshot('done', [
            ['id' => 'e1', 'timestamp' => '2026-09-22T10:00:00.000Z', 'message' => ['role' => 'assistant', 'stopReason' => 'toolUse', 'content' => [
                ['type' => 'toolCall', 'id' => 'call-1', 'name' => 'bash', 'arguments' => ['command' => 'git status --short']],
            ]]],
            ['id' => 'e2', 'timestamp' => '2026-09-22T10:00:01.000Z', 'message' => ['role' => 'toolResult', 'toolCallId' => 'call-1', 'toolName' => 'bash', 'isError' => false, 'content' => [['type' => 'text', 'text' => " M routes/web.php\n?? boost.json"]]]],
        ]))]);

        expect(pi_driver()->read(pi_thread(pi_node()), null)->entries[0]['text'])->toBe(" M routes/web.php\n?? boost.json\n$ git status --short\nexit code 0");
    });

    it('keeps file contents out of non-bash activity', function (): void {
        $entries = [
            ['id' => 'e1', 'timestamp' => '2026-09-22T10:00:00.000Z', 'message' => ['role' => 'assistant', 'stopReason' => 'toolUse', 'content' => [
                ['type' => 'toolCall', 'id' => 'call-1', 'name' => 'read', 'arguments' => ['path' => 'README.md']],
            ]]],
            ['id' => 'e2', 'timestamp' => '2026-09-22T10:00:01.000Z', 'message' => ['role' => 'toolResult', 'toolCallId' => 'call-1', 'toolName' => 'read', 'isError' => false, 'content' => [['type' => 'text', 'text' => 'Always write tests.']]]],
        ];
        Http::fake([PI_BASE.'/sessions/session-1*' => Http::response(pi_snapshot('idle', $entries))]);

        expect(pi_driver()->read(pi_thread(pi_node()), null)->entries)->toBe([
            ['id' => 'e1:0', 'kind' => 'activity', 'label' => 'read', 'text' => 'Reading README.md', 'at' => '2026-09-22T10:00:01.000Z'],
        ]);
    });

    it('shows a docs search by its queries', function (): void {
        $entries = [
            ['id' => 'e1', 'timestamp' => '2026-09-22T10:00:00.000Z', 'message' => ['role' => 'assistant', 'stopReason' => 'toolUse', 'content' => [
                ['type' => 'toolCall', 'id' => 'call-9', 'name' => 'search_docs', 'arguments' => ['queries' => ['queue rate limiting', 'throttle']]],
            ]]],
            ['id' => 'e2', 'timestamp' => '2026-09-22T10:00:01.000Z', 'message' => ['role' => 'toolResult', 'toolCallId' => 'call-9', 'toolName' => 'search_docs', 'isError' => false, 'content' => [['type' => 'text', 'text' => 'Rate Limiting ...']]]],
        ];
        Http::fake([PI_BASE.'/sessions/session-1*' => Http::response(pi_snapshot('done', $entries))]);

        expect(array_last(pi_driver()->read(pi_thread(pi_node()), null)->entries)['text'])->toBe('Searching docs for "queue rate limiting", "throttle"');
    });

    it('carries the reported failure', function (): void {
        Http::fake([PI_BASE.'/sessions/session-1*' => Http::response(pi_snapshot('failed', error: 'quota exceeded'))]);

        $observation = pi_driver()->read(pi_thread(pi_node()), null);

        expect($observation->state)->toBe(AgentThreadState::Failed)->and($observation->error)->toBe('quota exceeded');
    });

    it('shows a Durable provider retry wait on a working thread', function (): void {
        $notice = 'Waiting for provider retry 2/4 (overloaded) at 2026-10-22T15:00:00Z.';
        Http::fake([PI_BASE.'/sessions/session-1*' => Http::response([
            ...pi_snapshot('working', error: $notice), 'providerError' => 'overloaded',
        ])]);

        $observation = pi_driver()->read(pi_thread(pi_node()), null);

        expect($observation->state)->toBe(AgentThreadState::Working)
            ->and($observation->toArray()['error'])->toBe($notice)
            ->and($observation->providerError)->toBe('overloaded');
    });

    it('carries only recognized provider classifications on failed and retrying turns', function (string $state, ?string $category, ?string $expected): void {
        Http::fake([PI_BASE.'/sessions/session-1*' => Http::response([
            ...pi_snapshot($state, error: 'Provider failure'), 'providerError' => $category,
        ])]);

        $observation = pi_driver()->read(pi_thread(pi_node()), null);

        expect($observation->providerError)->toBe($expected);
    })->with([
        ['working', 'overloaded', 'overloaded'],
        ['failed', 'overloaded', 'overloaded'],
        ['failed', 'rate_limited', 'rate_limited'],
        ['failed', 'server_error', 'server_error'],
        ['failed', 'tool_error', null],
        ['failed', null, null],
        ['done', 'overloaded', null],
    ]);

    it('rejects a snapshot for another session', function (): void {
        Http::fake([PI_BASE.'/sessions/session-1*' => Http::response(pi_snapshot('done', id: 'other'))]);

        expect(fn () => pi_driver()->read(pi_thread(pi_node()), null))->toThrow(AgentDriverException::class, 'Agent observation identity does not match.');
    });

    it('reads the token breakdown from Pi usage', function (): void {
        $node = pi_node();
        $thread = pi_thread($node);
        $body = '';
        Http::fake(function () use (&$body) {
            return Http::response($body);
        });

        $snapshot = pi_snapshot('done');
        $snapshot['usage'] = ['input' => 30, 'output' => 20, 'cacheRead' => 70, 'cacheWrite' => 10, 'total' => 130, 'calls' => 2, 'peakContext' => 80, 'cost' => 0.0125];
        $body = json_encode($snapshot, JSON_THROW_ON_ERROR);
        $observation = pi_driver()->read($thread, null);
        expect($observation->costUsd)->toBe(0.0125);

        expect($observation->tokens)->toBe(130)
            ->and($observation->inputTokens)->toBe(40)
            ->and($observation->cachedInputTokens)->toBe(70)
            ->and($observation->outputTokens)->toBe(20)
            ->and($observation->modelCalls)->toBe(2)
            ->and($observation->peakContextTokens)->toBe(80);

        $snapshot['usage'] = ['input' => 0, 'output' => 0, 'cacheRead' => 0, 'cacheWrite' => 0, 'total' => 0, 'calls' => 0, 'peakContext' => 0];
        $body = json_encode($snapshot, JSON_THROW_ON_ERROR);
        $zeros = pi_driver()->read($thread, null);
        expect($zeros->inputTokens)->toBe(0)
            ->and($zeros->cachedInputTokens)->toBe(0)
            ->and($zeros->outputTokens)->toBe(0)
            ->and($zeros->modelCalls)->toBe(0)
            ->and($zeros->peakContextTokens)->toBe(0);

        $snapshot['usage'] = ['input' => 30, 'output' => 20, 'cacheRead' => 70, 'total' => 120, 'calls' => '2', 'peakContext' => null];
        $body = json_encode($snapshot, JSON_THROW_ON_ERROR);
        $partial = pi_driver()->read($thread, null);
        expect($partial->costUsd)->toBeNull();
        expect($partial->tokens)->toBe(120)
            ->and($partial->inputTokens)->toBeNull()
            ->and($partial->cachedInputTokens)->toBe(70)
            ->and($partial->outputTokens)->toBe(20)
            ->and($partial->modelCalls)->toBeNull()
            ->and($partial->peakContextTokens)->toBeNull();

        $started = pi_snapshot('working');
        $started['usage'] = ['input' => 1, 'output' => 0, 'cacheRead' => 0, 'cacheWrite' => 0, 'total' => 1];
        $body = implode("\n", [
            json_encode($started, JSON_THROW_ON_ERROR),
            json_encode(['kind' => 'state', 'run' => 'run-1', 'sequence' => 8, 'state' => 'done', 'error' => null, 'turnId' => 'turn-key-1', 'usage' => ['input' => 30, 'output' => 20, 'cacheRead' => 70, 'cacheWrite' => 10, 'total' => 130, 'calls' => 2, 'peakContext' => 80]], JSON_THROW_ON_ERROR),
        ])."\n";
        $events = iterator_to_array(pi_driver()->events($thread, null), false);

        expect($events[1]->data)->toMatchArray([
            'tokens' => 130, 'input_tokens' => 40, 'cached_input_tokens' => 70, 'output_tokens' => 20, 'model_calls' => 2, 'peak_context_tokens' => 80,
        ]);
    });

    it('redacts the Node token from transcript text', function (): void {
        Http::fake([PI_BASE.'/sessions/session-1*' => Http::response(pi_snapshot('done', [
            ['id' => 'e1', 'timestamp' => '2026-09-22T10:00:00.000Z', 'message' => ['role' => 'assistant', 'stopReason' => 'stop', 'content' => [['type' => 'text', 'text' => 'token '.PI_TOKEN]]]],
        ]))]);

        expect(pi_driver()->read(pi_thread(pi_node()), null)->entries[0]['text'])->toBe('token [REDACTED]');
    });
});

describe('events', function (): void {
    it('refuses an oversized stream line before decoding, with or without a newline', function (string $ending): void {
        Http::fake([PI_BASE.'/sessions/session-1/stream*' => Http::response(str_pad('{"kind":"heartbeat"}', PiClient::SESSION_MAX_BYTES + 1).$ending)]);
        $thread = pi_thread(pi_node());

        expect(fn () => iterator_to_array(pi_driver()->events($thread, null), false))
            ->toThrow(AgentDriverException::class, 'The Pi server session is larger than 16 MiB.');
    })->with(['unterminated' => '', 'terminated' => "\n"]);

    it('streams a snapshot, then normalized entries and states, ignoring events before the snapshot', function (): void {
        $events = pi_events([
            pi_entry_event(1, ['id' => 'early', 'timestamp' => 't', 'message' => ['role' => 'user', 'content' => 'too early']]),
            pi_snapshot('idle'),
            ['kind' => 'state', 'run' => 'run-1', 'sequence' => 8, 'state' => 'working', 'error' => null, 'turnId' => 'turn-key-2', 'usage' => ['total' => 120]],
            pi_entry_event(9, ['id' => 'e1', 'timestamp' => '2026-09-22T10:00:00.000Z', 'message' => ['role' => 'user', 'content' => 'Go']]),
            ['kind' => 'heartbeat'],
            ['kind' => 'state', 'run' => 'run-1', 'sequence' => 10, 'state' => 'done', 'error' => null, 'turnId' => 'turn-key-2', 'usage' => ['total' => 300]],
        ]);

        expect(array_map(fn ($event): string => $event->kind, $events))->toBe(['snapshot', 'state', 'entry', 'heartbeat', 'state'])
            ->and($events[0]->cursor)->toBe('run-1.7')
            ->and($events[1]->data)->toMatchArray(['state' => 'working'])
            ->and($events[2]->data['entry'])->toMatchArray(['id' => 'e1', 'kind' => 'message', 'label' => 'user', 'text' => 'Go'])
            ->and($events[4]->data)->toMatchArray(['state' => 'done', 'tokens' => 300])
            ->and($events[4]->cursor)->toBe('run-1.10');
        Http::assertSent(fn (Request $request): bool => $request->url() === PI_BASE.'/sessions/session-1/stream?max_bytes=16777216');
    });

    it('streams the same snapshot and cursor that read returns', function (): void {
        $snapshot = pi_snapshot('working', array_slice(pi_check_transcript('token '.PI_TOKEN), 0, 3));
        Http::fake([
            PI_BASE.'/sessions/session-1/stream*' => Http::response(json_encode($snapshot)."\n"),
            PI_BASE.'/sessions/session-1*' => Http::response($snapshot),
        ]);
        $thread = pi_thread(pi_node());

        $observation = pi_driver()->read($thread, null);
        $events = iterator_to_array(pi_driver()->events($thread, null), false);

        expect($events[0]->kind)->toBe('snapshot')
            ->and($events[0]->data)->toBe($observation->toArray())
            ->and($events[0]->cursor)->toBe($observation->cursor)->toBe('run-1.7')
            ->and(json_encode($events[0]->data))->toContain('token [REDACTED]')->not->toContain(PI_TOKEN);
    });

    it('resumes after a cursor without a snapshot and relays only the later events', function (): void {
        $events = pi_events([
            ['kind' => 'resumed', 'run' => 'run-1', 'sequence' => 7, 'session' => ['id' => 'session-1'], 'context' => []],
            pi_entry_event(8, ['id' => 'e5', 'timestamp' => '2026-09-22T10:00:05.000Z', 'message' => ['role' => 'assistant', 'stopReason' => 'stop', 'content' => [['type' => 'text', 'text' => 'Later.']]]]),
            ['kind' => 'state', 'run' => 'run-1', 'sequence' => 9, 'state' => 'done', 'error' => null, 'turnId' => 'turn-key-1', 'usage' => ['total' => 400]],
        ], 'run-1.7');

        expect(array_map(fn ($event): string => $event->kind, $events))->toBe(['resumed', 'entry', 'state'])
            ->and($events[0]->cursor)->toBeNull()
            ->and($events[1]->data['entry'])->toMatchArray(['id' => 'e5', 'text' => 'Later.'])
            ->and($events[1]->cursor)->toBe('run-1.8')
            ->and($events[2]->cursor)->toBe('run-1.9');
        Http::assertSent(fn (Request $request): bool => $request->url() === PI_BASE.'/sessions/session-1/stream?max_bytes=16777216&run=run-1&after=7');
    });

    it('asks for a snapshot when the cursor is not a Pi cursor', function (string $cursor): void {
        $events = pi_events([pi_snapshot('idle')], $cursor);

        expect($events[0]->kind)->toBe('snapshot');
        Http::assertSent(fn (Request $request): bool => $request->url() === PI_BASE.'/sessions/session-1/stream?max_bytes=16777216');
    })->with(['a sequence from an older Gateway' => '42', 'a foreign cursor' => 'abc', 'a run without a sequence' => 'run-1.']);

    it('ignores a resume for another session', function (): void {
        $events = pi_events([
            ['kind' => 'resumed', 'run' => 'run-1', 'sequence' => 7, 'session' => ['id' => 'other'], 'context' => []],
            pi_entry_event(8, ['id' => 'e5', 'timestamp' => 't', 'message' => ['role' => 'user', 'content' => 'foreign']]),
        ], 'run-1.7');

        expect($events)->toBe([]);
    });

    it('shows a started tool call as running until its result replaces it', function (): void {
        [, $call] = pi_check_transcript('All checks passed');
        [, , $result] = pi_check_transcript('All checks passed');

        $events = pi_events([pi_snapshot('working'), pi_entry_event(8, $call), pi_entry_event(9, $result)]);

        expect(array_map(fn ($event): array => $event->data['entry'] ?? [], array_slice($events, 1)))->toBe([
            ['id' => 'e2', 'kind' => 'message', 'label' => 'assistant', 'text' => 'Running the checks.', 'at' => '2026-09-22T10:00:01.000Z'],
            ['id' => 'e2:0', 'kind' => 'activity', 'label' => 'Running', 'text' => 'Running: $ composer check', 'at' => '2026-09-22T10:00:01.000Z'],
            ['id' => 'e2:0', 'kind' => 'activity', 'label' => 'bash', 'text' => "All checks passed\n$ composer check\nexit code 0", 'at' => '2026-09-22T10:00:02.000Z'],
        ])->and(array_map(fn ($event): ?string => $event->cursor, array_slice($events, 1)))->toBe([null, 'run-1.8', 'run-1.9']);
    });

    it('names a result whose call came before the cursor', function (): void {
        [, $call, $result] = pi_check_transcript('ok');

        $events = pi_events([
            ['kind' => 'resumed', 'run' => 'run-1', 'sequence' => 8, 'session' => ['id' => 'session-1'], 'context' => [$call]],
            pi_entry_event(9, $result),
        ], 'run-1.8');

        expect($events[1]->data['entry'])->toMatchArray(['id' => 'e2:0', 'label' => 'bash', 'text' => "ok\n$ composer check\nexit code 0"]);
    });

    it('stops a running call when the turn settles without its result', function (): void {
        [, $call] = pi_check_transcript('ok');

        $events = pi_events([
            pi_snapshot('working'),
            pi_entry_event(8, $call),
            ['kind' => 'state', 'run' => 'run-1', 'sequence' => 9, 'state' => 'failed', 'error' => 'The turn was interrupted.', 'turnId' => 'turn-key-1', 'usage' => ['total' => 120]],
        ]);

        expect($events[3]->data['entry'])->toMatchArray(['id' => 'e2:0', 'label' => 'bash', 'text' => '$ composer check (stopped without a result)'])
            ->and($events[3]->cursor)->toBeNull()
            ->and($events[4]->kind)->toBe('state');
    });

    it('shows an unfinished call as running in a snapshot of a working turn', function (): void {
        [$user, $call] = pi_check_transcript('ok');

        $events = pi_events([pi_snapshot('working', [$user, $call])]);

        expect(array_column($events[0]->data['entries'], 'label'))->toBe(['user', 'assistant', 'Running'])
            ->and($events[0]->data['entries'][2])->toMatchArray(['id' => 'e2:0', 'text' => 'Running: $ composer check']);
    });

    it('redacts the Node token from a running command', function (): void {
        $call = ['id' => 'e1', 'timestamp' => 't', 'message' => ['role' => 'assistant', 'stopReason' => 'toolUse', 'content' => [
            ['type' => 'toolCall', 'id' => 'call-1', 'name' => 'bash', 'arguments' => ['command' => 'curl -H "Authorization: Bearer '.PI_TOKEN.'" pi']],
        ]]];

        $events = pi_events([pi_snapshot('working'), pi_entry_event(8, $call)]);

        expect($events[1]->data['entry']['text'])->not->toContain(PI_TOKEN)->toContain('[REDACTED]');
    });
});

/**
 * A Pi `delta` from the cursor `run-1.7`: the open call `call-1` from before the cursor, its result, a new call that never
 * gets a result, and the settled state.
 *
 * @return array{array<string, mixed>, list<array<string, mixed>>}
 */
function pi_delta_and_stream(): array
{
    [, $call, $result] = pi_check_transcript('token '.PI_TOKEN);
    $open = ['id' => 'e5', 'timestamp' => '2026-09-22T10:00:04.000Z', 'message' => ['role' => 'assistant', 'stopReason' => 'toolUse', 'content' => [
        ['type' => 'toolCall', 'id' => 'call-2', 'name' => 'bash', 'arguments' => ['command' => 'ls']],
    ]]];
    $state = ['kind' => 'state', 'run' => 'run-1', 'sequence' => 10, 'state' => 'done', 'error' => null, 'turnId' => 'turn-key-1', 'usage' => ['total' => 300]];
    $events = [pi_entry_event(8, $result), pi_entry_event(9, $open), $state];
    $delta = [
        'kind' => 'delta', 'run' => 'run-1', 'sequence' => 10, 'session' => ['id' => 'session-1'],
        'state' => 'done', 'error' => null, 'turnId' => 'turn-key-1', 'usage' => ['input' => 200, 'output' => 100, 'cacheRead' => 0, 'cacheWrite' => 0, 'total' => 300],
        'context' => [$call], 'events' => $events,
    ];

    return [$delta, [['kind' => 'resumed', 'run' => 'run-1', 'sequence' => 7, 'session' => ['id' => 'session-1'], 'context' => [$call]], ...$events]];
}

describe('read', function (): void {
    it('folds a delta into the entries the stream sends after the same cursor', function (): void {
        [$delta, $stream] = pi_delta_and_stream();
        Http::fake([
            PI_BASE.'/sessions/session-1/stream*' => Http::response(implode("\n", array_map(json_encode(...), $stream))."\n"),
            PI_BASE.'/sessions/session-1?*' => Http::response($delta),
        ]);
        $thread = pi_thread(pi_node());

        $observation = pi_driver()->read($thread, 'run-1.7');
        $streamed = [];
        foreach (pi_driver()->events($thread, 'run-1.7') as $event) {
            if ($event->kind === 'entry') {
                $streamed[$event->data['entry']['id'].'|'.$event->data['entry']['kind']] = $event->data['entry'];
            }
        }

        expect($observation->delta)->toBeTrue()
            ->and($observation->cursor)->toBe('run-1.10')
            ->and($observation->state)->toBe(AgentThreadState::Done)
            ->and($observation->tokens)->toBe(300)
            ->and($observation->entries)->toBe(array_values($streamed))
            ->and(array_column($observation->entries, 'text'))->toBe(["token [REDACTED]\n$ composer check\nexit code 0", '$ ls (stopped without a result)'])
            ->and(json_encode($observation->toArray()))->not->toContain(PI_TOKEN);
        Http::assertSent(fn (Request $request): bool => $request->url() === PI_BASE.'/sessions/session-1?max_bytes=16777216&run=run-1&after=7');
    });

    it('reads a snapshot when the server cannot resume the cursor or predates deltas', function (): void {
        Http::fake([PI_BASE.'/sessions/session-1*' => Http::response(pi_snapshot('done', pi_check_transcript("All checks passed\n")))]);
        $thread = pi_thread(pi_node());

        $resumable = pi_driver()->read($thread, 'run-0.3');
        $opaque = pi_driver()->read($thread, 'not a pi cursor');

        expect($resumable->delta)->toBeFalse()->and($resumable->cursor)->toBe('run-1.7')
            ->and(array_column($resumable->entries, 'kind'))->toBe(['message', 'message', 'activity', 'message'])
            ->and($opaque->delta)->toBeFalse();
        Http::assertSent(fn (Request $request): bool => $request->url() === PI_BASE.'/sessions/session-1?max_bytes=16777216&run=run-0&after=3');
        Http::assertSent(fn (Request $request): bool => $request->url() === PI_BASE.'/sessions/session-1?max_bytes=16777216');
    });

    it('refuses a session body over the limit before decoding it', function (): void {
        Http::fake([PI_BASE.'/sessions/session-1*' => Http::response(str_repeat(' ', PiClient::SESSION_MAX_BYTES + 1))]);
        $thread = pi_thread(pi_node());

        expect(fn () => pi_driver()->read($thread, null))->toThrow(AgentDriverException::class, 'The Pi server session is larger than 16 MiB.');
    });

    it('stops a transfer whose announced or received size passes the limit, within its time budget', function (): void {
        $seen = [];
        Http::fake(function (Request $request, array $options) use (&$seen): PromiseInterface {
            $progress = $options['progress'];
            expect(fn () => $progress(PiClient::SESSION_MAX_BYTES + 1, 0))->toThrow(AgentDriverException::class, 'The Pi server session is larger than 16 MiB.')
                ->and(fn () => $progress(0, PiClient::SESSION_MAX_BYTES + 1))->toThrow(AgentDriverException::class, 'The Pi server session is larger than 16 MiB.');
            $progress(PiClient::SESSION_MAX_BYTES, PiClient::SESSION_MAX_BYTES);
            $seen[] = [$options['timeout'], $options['decode_content']];

            return Http::response([...pi_snapshot('done'), 'kind' => ($request->data()['view'] ?? null) === 'state' ? 'state' : 'snapshot']);
        });
        $thread = pi_thread(pi_node());

        pi_driver()->read($thread, null);
        pi_driver()->observe($thread);

        expect($seen)->toBe([[PiClient::READ_TIMEOUT, false], [15.0, false]]);
    });

    it('names the limit when the transfer stops at it', function (): void {
        $request = new Psr7Request('GET', PI_BASE.'/sessions/session-1');
        $stopped = new ResponseException('An error was encountered during the progress event', $request, new Psr7Response(200), new AgentDriverException('The Pi server session is larger than 16 MiB.'));
        Http::fake(fn () => throw new ConnectionException($stopped->getMessage(), 0, $stopped));

        expect(fn () => pi_driver()->read(pi_thread(pi_node()), null))->toThrow(AgentDriverException::class, 'The Pi server session is larger than 16 MiB.');
    });

    it('answers an error status whose transfer stopped as a failed server', function (): void {
        Http::fake(fn () => throw new RequestException(new Response(new Psr7Response(500))));

        expect(fn () => pi_driver()->read(pi_thread(pi_node()), null))->toThrow(AgentDriverException::class, 'The Pi server failed with HTTP 500.');
    });

    it('names an invalid state response in observation errors', function (array|string $payload): void {
        Http::fake([PI_BASE.'/sessions/session-1*' => Http::response($payload)]);

        expect(fn () => pi_driver()->observe(pi_thread(pi_node())))
            ->toThrow(AgentDriverException::class, 'The Pi server returned an invalid state.');
    })->with(['wrong kind' => [['kind' => 'resumed']], 'malformed JSON' => 'not json']);

    it('refuses an answer that is neither a snapshot nor a delta', function (): void {
        Http::fake([PI_BASE.'/sessions/session-1*' => Http::response(['kind' => 'resumed', 'session' => ['id' => 'session-1']])]);

        expect(fn () => pi_driver()->read(pi_thread(pi_node()), 'run-1.7'))->toThrow(AgentDriverException::class, 'The Pi server returned an invalid snapshot.');
    });
});

describe('large sessions', function (): void {
    /** A session over the Gateway cap; Pi's state and bounded transcript views remain small. */
    function pi_large_session(?float $expectedTimeout = null): void
    {
        $full = pi_snapshot('working', [
            ['id' => 'old', 'timestamp' => '', 'message' => ['role' => 'user', 'content' => str_repeat('x', PiClient::SESSION_MAX_BYTES + 1)]],
            ['id' => 'latest', 'timestamp' => '', 'message' => ['role' => 'assistant', 'content' => 'Still working.']],
        ]);
        Http::fake(function (Request $request, array $options) use ($full, $expectedTimeout): PromiseInterface {
            if ($expectedTimeout !== null) {
                expect($options['timeout'])->toBe($expectedTimeout);
            }
            if ($request->url() === PI_BASE.'/sessions/session-1?view=state') {
                $state = [...$full, 'kind' => 'state'];
                unset($state['entries']);

                return Http::response($state);
            }
            if (($request->data()['max_bytes'] ?? null) === PiClient::SESSION_MAX_BYTES) {
                return Http::response([...$full, 'entries' => [
                    ['id' => 'orbit-transcript-truncated', 'timestamp' => '', 'message' => ['role' => 'system', 'content' => '[Transcript truncated: older entries omitted to fit the Gateway response budget.]']],
                    $full['entries'][1],
                ]]);
            }

            return Http::response($full);
        });
    }

    it('records state and tokens without fetching an oversized transcript', function (): void {
        pi_large_session();
        $thread = pi_thread(pi_node());
        $thread->update(['observation_error' => 'Agent observation unavailable.']);

        $observation = app(AgentThreadObserver::class)->observe($thread);

        expect($observation?->entries)->toBe([]);
        $this->assertDatabaseHas('agent_threads', [
            'id' => $thread->id, 'state' => 'working', 'tokens' => 120,
            'input_tokens' => 100, 'output_tokens' => 20, 'observation_error' => null,
        ]);
        expect($thread->fresh()->observed_at)->not->toBeNull();
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => $request->url() === PI_BASE.'/sessions/session-1?view=state');
    });

    it('observes metadata from an older Pi snapshot without using its transcript or retrying', function (): void {
        Http::fake([PI_BASE.'/sessions/session-1?view=state' => Http::response(pi_snapshot('working', pi_check_transcript('unused transcript')))]);
        $thread = pi_thread(pi_node());

        $observed = app(AgentThreadObserver::class)->observe($thread);

        expect($observed?->state)->toBe(AgentThreadState::Working)
            ->and($observed?->tokens)->toBe(120)
            ->and($observed?->entries)->toBe([]);
        Http::assertSentCount(1);
    });

    it('answers agent show with the current state and a visible truncation marker', function (): void {
        pi_large_session();

        pi_read_as_gateway(pi_thread(pi_node()))->assertOk()
            ->assertJsonPath('data.kind', 'snapshot')
            ->assertJsonPath('data.state', 'working')
            ->assertJsonPath('data.tokens', 120)
            ->assertJsonPath('data.cursor', 'run-1.7')
            ->assertJsonPath('data.entries.0.label', 'system')
            ->assertJsonPath('data.entries.0.text', '[Transcript truncated: older entries omitted to fit the Gateway response budget.]')
            ->assertJsonPath('data.entries.1.text', 'Still working.');
    });

    it('archives the bounded transcript rather than a state-only observation', function (): void {
        Storage::fake('local');
        pi_large_session(expectedTimeout: 15.0);
        $thread = pi_thread(pi_node());

        app(ArchiveAgentTranscriptsAction::class)->execute($thread->task_group_id, $thread->node_id);

        $saved = app(ArchiveAgentTranscriptsAction::class)->read($thread);
        expect($saved)->toBeArray();
        expect($saved['state'])->toBe('working')
            ->and($saved['tokens'])->toBe(120)
            ->and($saved['entries'][0]['label'])->toBe('system')
            ->and($saved['entries'][1]['text'])->toBe('Still working.');
    });
});

describe('agent thread read route', function (): void {
    /** Calls the read as the Gateway peer with the tasks extension on. */
    function pi_read_as_gateway(AgentThread $thread): TestResponse
    {
        // A read falls back to a saved transcript, so no file another test saved may answer it.
        Storage::fake('local');
        $gateway = Node::query()->create(['name' => 'pi-gateway', 'status' => 'active', 'platform' => 'linux', 'public_ssh_host' => '10.44.0.1', 'wireguard_ip' => '10.44.0.1']);
        test()->markAsGateway($gateway);
        test()->withServerVariables(['REMOTE_ADDR' => $gateway->wireguard_ip]);
        TaskModelRouter::ready();
        test()->postJson('/api/v1/extensions/tasks/enable')->assertOk();

        return test()->getJson("/api/v1/task-groups/{$thread->task_group_id}/agents/{$thread->id}?after_sequence=run-1.7");
    }

    it('answers a Pi server error as a 502 without its detail and reports the detail', function (): void {
        Exceptions::fake();
        Http::fake([PI_BASE.'/sessions/session-1*' => Http::response(['error' => ['code' => 'server.unhandled', 'message' => 'secret detail', 'details' => []]], 500)]);

        $response = pi_read_as_gateway(pi_thread(pi_node()));

        $response->assertStatus(502)->assertJsonPath('error.code', 'tasks.agent_read_failed')
            ->assertJsonPath('error.message', 'Orbit could not read the agent thread from its Node.');
        expect($response->getContent())->not->toContain('secret detail')->not->toContain('server.unhandled');
        Exceptions::assertReported(fn (ResourceOperationException $exception): bool => $exception->getPrevious() instanceof AgentDriverException
            && str_contains($exception->getPrevious()->getMessage(), 'server.unhandled'));
    });

    it('answers an unreachable Pi server as a 502 without the connection error', function (): void {
        Http::fake(fn () => throw new ConnectionException('cURL error 7: Failed to connect to 10.44.0.9 port 3774'));

        $response = pi_read_as_gateway(pi_thread(pi_node()));

        $response->assertStatus(502)->assertJsonPath('error.code', 'tasks.agent_read_failed');
        expect($response->getContent())->not->toContain('cURL')->not->toContain('3774');
    });

    it('returns the delta to the client', function (): void {
        [$delta] = pi_delta_and_stream();
        Http::fake([PI_BASE.'/sessions/session-1*' => Http::response($delta)]);

        pi_read_as_gateway(pi_thread(pi_node()))->assertOk()
            ->assertJsonPath('data.kind', 'delta')->assertJsonPath('data.cursor', 'run-1.10')->assertJsonPath('data.state', 'done')
            ->assertJsonPath('data.archived', false)->assertJsonCount(2, 'data.entries');
    });
});

it('sends an external turn once and preserves the Pi refusal code', function (): void {
    $thread = pi_thread(pi_node());
    Task::topLevel()->whereKey($thread->task_group_id)->update(['orchestration' => 'external']);
    Http::fake([PI_BASE.'/sessions/session-1/messages' => Http::response(['error' => ['code' => 'model.unavailable', 'message' => 'Unknown model.']], 422)]);

    try {
        pi_driver()->send($thread, 'One external turn.', 'external.c1');
        test()->fail('The refused send must fail.');
    } catch (AgentDriverException $exception) {
        expect($exception->errorCode)->toBe('model.unavailable');
    }
    Http::assertSentCount(1);
});
