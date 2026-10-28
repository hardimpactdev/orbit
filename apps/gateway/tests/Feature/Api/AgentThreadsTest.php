<?php

declare(strict_types=1);

use App\Actions\Tasks\ArchiveAgentTranscriptsAction;
use App\Domain\Tasks\AgentDriverRegistry;
use App\Domain\Tasks\AgentObservation;
use App\Domain\Tasks\AgentThreadEvent;
use App\Domain\Tasks\AgentThreadState;
use App\Models\AgentThread;
use App\Models\Node;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FakeAgentDriver;
use Tests\Support\TaskModelRouter;

/** @return array{Task, AgentThread} */
function agent_viewer_fixture(): array
{
    // A read falls back to a saved transcript, so no file another test saved may answer it.
    Storage::fake('local');
    $node = Node::query()->create(['name' => 'agent-gateway', 'status' => 'active', 'platform' => 'linux', 'public_ssh_host' => '10.44.0.80', 'wireguard_ip' => '10.44.0.80']);
    test()->markAsGateway($node);
    test()->withServerVariables(['REMOTE_ADDR' => $node->wireguard_ip]);
    TaskModelRouter::ready();
    test()->postJson('/api/v1/extensions/tasks/enable')->assertOk();
    $project = Project::query()->create(['name' => 'viewer', 'slug' => 'viewer', 'repository_url' => 'git@example.test:viewer.git', 'default_branch' => 'main']);
    $group = Task::topLevel()->create(['project_id' => $project->id, 'title' => 'Viewer', 'brief' => 'Read sessions']);
    $session = AgentThread::query()->create(['task_group_id' => $group->id, 'node_id' => $node->id, 'role' => 'reviewer', 'model' => 'gpt-5.6-luna', 'effort' => 'high', 'external_id' => 'thread-one', 'driver' => 'pi', 'runtime_key' => 'node:'.$node->id]);

    return [$group, $session];
}

/**
 * The data of each `agent` event in a streamed response.
 *
 * @return list<array<string, mixed>>
 */
function agent_stream_events(string $output): array
{
    preg_match_all('/^event: agent\ndata: (.+)$/m', $output, $matches);

    return array_map(static fn (string $json): array => json_decode($json, true, flags: JSON_THROW_ON_ERROR), $matches[1]);
}

describe('task agent viewer', function (): void {
    beforeEach(function (): void {
        // Transcript files outlive database rollbacks and can match reused group/thread IDs.
        Storage::fake('local');
    });

    it('lists persisted links after the workspace is removed', function (): void {
        [$group, $session] = agent_viewer_fixture();
        AgentThread::query()->create([
            'task_group_id' => $group->id, 'node_id' => $session->node_id, 'role' => 'reviewer',
            'model' => 'gpt-5.6-luna', 'effort' => 'high', 'external_id' => AgentThread::PendingPrefix.'not-started',
            'driver' => 'pi', 'runtime_key' => 'node:'.$session->node_id,
        ]);

        $this->getJson("/api/v1/task-groups/{$group->id}/agents")
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.external_id', 'thread-one')
            ->assertJsonPath('data.0.task_group_id', $group->id)->assertJsonPath('data.0.node_id', $session->node_id)
            ->assertJsonPath('data.0.model', 'gpt-5.6-luna')->assertJsonPath('data.0.effort', 'high');
        $this->postJson('/api/v1/extensions/tasks/disable')->assertOk()->assertJsonPath('data.enabled', false);
        $this->getJson("/api/v1/task-groups/{$group->id}/agents")->assertStatus(409)->assertJsonPath('error.code', 'extension.disabled');
    });

    it('denies a peer without Gateway access and an unknown peer', function (): void {
        [$group, $session] = agent_viewer_fixture();
        $peer = Node::query()->create(['name' => 'other-peer', 'status' => 'active', 'platform' => 'linux', 'public_ssh_host' => '10.44.0.81', 'wireguard_ip' => '10.44.0.81']);
        $this->withServerVariables(['REMOTE_ADDR' => $peer->wireguard_ip])->getJson("/api/v1/task-groups/{$group->id}/agents")->assertForbidden();
        $this->getJson("/api/v1/task-groups/{$group->id}/agents/{$session->id}/stream")->assertForbidden();
        $this->withServerVariables(['REMOTE_ADDR' => '10.44.0.99'])->getJson("/api/v1/task-groups/{$group->id}/agents")->assertForbidden();
    });

    it('refuses a session from another group, invalid cursors, and missing Nodes', function (): void {
        [$group, $session] = agent_viewer_fixture();
        $other = Task::topLevel()->create(['project_id' => $group->project_id, 'title' => 'Other', 'brief' => 'Other']);
        $this->getJson("/api/v1/task-groups/{$other->id}/agents/{$session->id}/stream")->assertNotFound();
        $this->withHeader('Last-Event-ID', 'bad cursor')->getJson("/api/v1/task-groups/{$group->id}/agents/{$session->id}/stream")->assertUnprocessable();
        $this->flushHeaders();
        $session->update(['node_id' => null]);
        $this->getJson("/api/v1/task-groups/{$group->id}/agents/{$session->id}/stream")->assertStatus(409)->assertJsonPath('error.code', 'tasks.agent_unavailable');
    });

    it('replays the transcript Orbit archived once the task VM Node is gone', function (): void {
        [$group, $session] = agent_viewer_fixture();
        $session->update(['node_id' => null]);
        Storage::disk('local')->put(ArchiveAgentTranscriptsAction::path($session), json_encode([
            'state' => 'done', 'entries' => [['id' => 'm1', 'kind' => 'message', 'label' => 'assistant', 'text' => 'Approved TOKEN=hidden', 'at' => '2026-10-14T18:00:00Z']],
        ], JSON_THROW_ON_ERROR));

        $response = $this->get("/api/v1/task-groups/{$group->id}/agents/{$session->id}/stream");

        $response->assertOk()->assertHeader('Content-Type', 'text/event-stream; charset=UTF-8');
        expect($response->streamedContent())->toContain('event: agent', '"kind":"snapshot"', '"state":"done"', 'Approved', '[REDACTED]')
            ->not->toContain('hidden');
    });

    it('reads the same redacted snapshot and cursor that the stream sends first', function (): void {
        [$group, $session] = agent_viewer_fixture();
        $driver = new class extends FakeAgentDriver
        {
            public function events(AgentThread $thread, ?string $cursor, ?float $timeoutSeconds = null): iterable
            {
                $observation = $this->observe($thread);
                yield new AgentThreadEvent($thread->id, 'snapshot', $observation->toArray(), $observation->cursor);
            }
        };
        $driver->observation = new AgentObservation(
            state: AgentThreadState::Working,
            entries: [['id' => 'm1', 'kind' => 'message', 'label' => 'assistant', 'text' => 'Running checks TOKEN=hidden', 'at' => '2026-10-14T18:00:00Z']],
            tokens: 120,
            cursor: 'run.8',
        );
        app()->instance(AgentDriverRegistry::class, new AgentDriverRegistry([$driver]));
        $session->update(['driver' => 'example']);
        $stored = $session->refresh()->getAttributes();

        $read = $this->getJson("/api/v1/task-groups/{$group->id}/agents/{$session->id}")->assertOk()
            ->assertJsonPath('data.thread_id', $session->id)->assertJsonPath('data.kind', 'snapshot')
            ->assertJsonPath('data.state', 'working')->assertJsonPath('data.cursor', 'run.8')
            ->assertJsonPath('data.archived', false)->assertJsonPath('data.entries.0.text', 'Running checks TOKEN=[REDACTED]')
            ->json('data');
        $streamed = agent_stream_events($this->get("/api/v1/task-groups/{$group->id}/agents/{$session->id}/stream")->streamedContent());

        unset($read['archived']);
        expect($streamed)->toHaveCount(1)->and($read)->toBe($streamed[0])
            ->and(json_encode($read, JSON_THROW_ON_ERROR))->not->toContain('hidden')
            ->and($session->fresh()->getAttributes())->toBe($stored);
    });

    it('reads the archived transcript once the task VM Node is gone', function (): void {
        [$group, $session] = agent_viewer_fixture();
        Storage::fake('local');
        $session->update(['node_id' => null]);
        Storage::disk('local')->put(ArchiveAgentTranscriptsAction::path($session), json_encode([
            'state' => 'done', 'entries' => [['id' => 'm1', 'kind' => 'message', 'label' => 'assistant', 'text' => 'Approved TOKEN=hidden', 'at' => '2026-10-14T18:00:00Z']],
        ], JSON_THROW_ON_ERROR));

        $read = $this->getJson("/api/v1/task-groups/{$group->id}/agents/{$session->id}")->assertOk()
            ->assertJsonPath('data.kind', 'snapshot')->assertJsonPath('data.state', 'done')
            ->assertJsonPath('data.cursor', null)->assertJsonPath('data.archived', true)
            ->assertJsonPath('data.entries.0.text', 'Approved TOKEN=[REDACTED]')
            ->json('data');
        $streamed = agent_stream_events($this->get("/api/v1/task-groups/{$group->id}/agents/{$session->id}/stream")->streamedContent());

        unset($read['archived']);
        expect($read)->toBe($streamed[0]);
    });

    it('passes the cursor to the driver and answers only the later entries as a delta', function (): void {
        [$group, $session] = agent_viewer_fixture();
        $driver = new FakeAgentDriver;
        $driver->observation = new AgentObservation(
            state: AgentThreadState::Done,
            entries: [['id' => 'm2', 'kind' => 'message', 'label' => 'assistant', 'text' => 'Done TOKEN=hidden', 'at' => '2026-10-14T18:01:00Z']],
            tokens: 160,
            cursor: 'run.12',
            delta: true,
        );
        app()->instance(AgentDriverRegistry::class, new AgentDriverRegistry([$driver]));
        $session->update(['driver' => 'example']);
        $read = "/api/v1/task-groups/{$group->id}/agents/{$session->id}";

        $this->getJson($read.'?after_sequence=run.8')->assertOk()
            ->assertJsonPath('data.kind', 'delta')->assertJsonPath('data.cursor', 'run.12')->assertJsonPath('data.state', 'done')
            ->assertJsonPath('data.tokens', 160)->assertJsonPath('data.archived', false)
            ->assertJsonCount(1, 'data.entries')->assertJsonPath('data.entries.0.text', 'Done TOKEN=[REDACTED]');
        $this->getJson($read)->assertOk();
        $this->getJson($read.'?after_sequence='.rawurlencode('bad cursor'))->assertUnprocessable();

        expect($driver->readCursors)->toBe(['run.8', null]);
    });

    it('answers a thread that has not started as an empty snapshot, without calling the driver or reporting', function (): void {
        [$group, $session] = agent_viewer_fixture();
        Exceptions::fake();
        $driver = new FakeAgentDriver;
        app()->instance(AgentDriverRegistry::class, new AgentDriverRegistry([$driver]));
        $session->update(['driver' => 'example', 'external_id' => AgentThread::PendingPrefix.'not-started']);

        $this->getJson("/api/v1/task-groups/{$group->id}/agents/{$session->id}?after_sequence=run.8")->assertOk()
            ->assertJsonPath('data.thread_id', $session->id)->assertJsonPath('data.kind', 'snapshot')
            ->assertJsonPath('data.state', null)->assertJsonPath('data.entries', [])->assertJsonPath('data.input_requests', [])
            ->assertJsonPath('data.cursor', null)->assertJsonPath('data.archived', false);

        expect($driver->readCursors)->toBe([]);
        Exceptions::assertNothingReported();
    });

    it('falls back to the saved transcript when the driver cannot read a thread whose Node is still active', function (): void {
        [$group, $session] = agent_viewer_fixture();
        Storage::fake('local');
        Exceptions::fake();
        app()->instance(AgentDriverRegistry::class, new AgentDriverRegistry([new FakeAgentDriver]));
        $session->update(['driver' => 'example']);
        Storage::disk('local')->put(ArchiveAgentTranscriptsAction::path($session), json_encode([
            'state' => 'done', 'entries' => [['id' => 'm1', 'kind' => 'message', 'label' => 'assistant', 'text' => 'Approved.', 'at' => '2026-10-14T18:00:00Z']],
        ], JSON_THROW_ON_ERROR));

        $this->getJson("/api/v1/task-groups/{$group->id}/agents/{$session->id}?after_sequence=run.8")->assertOk()
            ->assertJsonPath('data.kind', 'snapshot')->assertJsonPath('data.archived', true)
            ->assertJsonPath('data.cursor', null)->assertJsonPath('data.entries.0.text', 'Approved.');

        Exceptions::assertNothingReported();
    });

    it('refuses a read like the stream, and hides a failed read', function (): void {
        [$group, $session] = agent_viewer_fixture();
        $other = Task::topLevel()->create(['project_id' => $group->project_id, 'title' => 'Other', 'brief' => 'Other']);
        $peer = Node::query()->create(['name' => 'other-peer', 'status' => 'active', 'platform' => 'linux', 'public_ssh_host' => '10.44.0.81', 'wireguard_ip' => '10.44.0.81']);
        $driver = new FakeAgentDriver;
        app()->instance(AgentDriverRegistry::class, new AgentDriverRegistry([$driver]));
        $session->update(['driver' => 'example']);
        $read = "/api/v1/task-groups/{$group->id}/agents/{$session->id}";

        $this->withServerVariables(['REMOTE_ADDR' => $peer->wireguard_ip])->getJson($read)->assertForbidden();
        $this->withServerVariables(['REMOTE_ADDR' => '10.44.0.99'])->getJson($read)->assertForbidden();
        $this->withServerVariables(['REMOTE_ADDR' => '10.44.0.80']);
        $this->getJson("/api/v1/task-groups/{$other->id}/agents/{$session->id}")->assertNotFound();
        $this->getJson($read)->assertStatus(502)->assertJsonPath('error.code', 'tasks.agent_read_failed')->assertDontSee('Unavailable');
        $session->update(['node_id' => null]);
        $this->getJson($read)->assertStatus(409)->assertJsonPath('error.code', 'tasks.agent_unavailable');
        $this->postJson('/api/v1/extensions/tasks/disable')->assertOk();
        $this->getJson($read)->assertStatus(409)->assertJsonPath('error.code', 'extension.disabled');
    });

    it('relays driver events, resumes the cursor, filters other threads, and hides errors', function (): void {
        [$group, $session] = agent_viewer_fixture();
        $session->update(['state' => 'done', 'tokens' => 500, 'observation_version' => 7]);
        $stored = $session->refresh()->getAttributes();
        $driver = new class extends FakeAgentDriver
        {
            public ?string $cursor = null;

            public function events(AgentThread $thread, ?string $cursor, ?float $timeoutSeconds = null): iterable
            {
                $this->cursor = $cursor;
                yield new AgentThreadEvent($thread->id, 'snapshot', ['state' => 'working', 'entries' => []], 'run.8');
                yield new AgentThreadEvent($thread->id + 1, 'entry', ['entry' => ['text' => 'must-not-leak']], 'run.9');
                yield new AgentThreadEvent($thread->id, 'entry', ['entry' => ['text' => 'Visible progress TOKEN=hidden']], 'run.10');
                yield new AgentThreadEvent($thread->id, 'heartbeat');
                yield new AgentThreadEvent($thread->id, 'state', ['state' => 'done'], 'run.11');
                throw new RuntimeException('secret error');
            }
        };
        app()->instance(AgentDriverRegistry::class, new AgentDriverRegistry([$driver]));
        $session->update(['driver' => 'example']);
        $stored = $session->refresh()->getAttributes();

        $response = $this->withHeader('Last-Event-ID', 'run.7')->get("/api/v1/task-groups/{$group->id}/agents/{$session->id}/stream");
        $response->assertOk()->assertHeader('Content-Type', 'text/event-stream; charset=UTF-8');
        $output = $response->streamedContent();
        expect($output)->toContain('id: run.8', 'id: run.10', 'id: run.11', 'Visible progress', '[REDACTED]', ': heartbeat', 'event: unavailable')
            ->not->toContain('hidden', 'must-not-leak', 'secret error')
            ->and($driver->cursor)->toBe('run.7')
            ->and($session->fresh()->getAttributes())->toBe($stored);
    });

    it('shows per-thread token metrics', function (): void {
        [$group, $session] = agent_viewer_fixture();
        $session->update([
            'tokens' => 130, 'input_tokens' => 40, 'cached_input_tokens' => 70, 'output_tokens' => 20,
            'model_calls' => 2, 'peak_context_tokens' => 80,
        ]);
        AgentThread::query()->create([
            'task_group_id' => $group->id, 'node_id' => $session->node_id, 'role' => 'implementer',
            'model' => 'gpt-5.6-luna', 'effort' => 'low', 'external_id' => 'thread-two', 'driver' => 'pi',
            'runtime_key' => 'node:'.$session->node_id,
        ]);

        $response = $this->getJson("/api/v1/task-groups/{$group->id}/agents")->assertOk();
        $reported = $response->json('data.0');
        $unknown = $response->json('data.1');

        expect($reported)->toMatchArray([
            'tokens' => 130, 'input_tokens' => 40, 'cached_input_tokens' => 70, 'output_tokens' => 20, 'model_calls' => 2, 'peak_context_tokens' => 80,
        ])->and($unknown)->toHaveKeys(['input_tokens', 'cached_input_tokens', 'output_tokens', 'model_calls', 'peak_context_tokens'])
            ->and($unknown['tokens'])->toBeNull()->and($unknown['input_tokens'])->toBeNull()
            ->and($unknown['cached_input_tokens'])->toBeNull()->and($unknown['output_tokens'])->toBeNull()
            ->and($unknown['model_calls'])->toBeNull()->and($unknown['peak_context_tokens'])->toBeNull();
    });
});
