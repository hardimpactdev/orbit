<?php

declare(strict_types=1);

namespace App\Infrastructure\Tasks\Pi;

use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tasks\AgentDriver;
use App\Domain\Tasks\AgentDriverException;
use App\Domain\Tasks\AgentInputRequest;
use App\Domain\Tasks\AgentThreadStart;
use App\Domain\Tasks\TaskOrchestration;
use App\Domain\TaskVms\TaskVmException;
use App\Domain\TaskVms\TaskVmWorkspaceGuard;
use App\Infrastructure\TaskVms\TaskVmRuntime;
use App\Models\AgentThread;
use App\Models\Instance;
use App\Models\Node;
use App\Models\TaskVm;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Runs agent threads on the Node's Pi server (ADR 0116). Orbit chooses the session ID, so the
 * external ID is known before the server answers. Each send carries a key; a retry reuses it,
 * so an ambiguous failure never starts a second turn. A caller may supply that key, and this driver
 * posts it unchanged (ADR 0167).
 */
final readonly class PiDriver implements AgentDriver
{
    public function __construct(
        private PiClient $client,
    ) {}

    public function key(): string
    {
        return 'pi';
    }

    public function create(AgentThreadStart $intent): string
    {
        $cwd = $intent->workspace->checkout_path;
        if ($cwd === '') {
            throw new AgentDriverException('The workspace has no checkout path.');
        }
        $id = $intent->externalId ?? (string) Str::uuid();
        $node = $this->workspaceNode($intent->workspace, $intent->node);
        if (! TaskVm::forNode($node) instanceof TaskVm) {
            throw new AgentDriverException('Pi threads run only on a task VM.');
        }
        $this->client->create($node, [
            'id' => $id,
            'cwd' => $cwd,
            'model' => PiModel::onlyProvider($intent->model, TaskVmRuntime::PiProvider),
            'thinkingLevel' => $intent->effort,
            'appendSystemPrompt' => null,
        ]);
        if ($intent->deferOpeningTurn) {
            return $id;
        }
        try {
            $this->deliver($node, $id, $intent->prompt, $intent->openingKey);
        } catch (AgentDriverException $exception) {
            // The session exists. A lost response is still that session, and a new id would not
            // see the key Pi already accepted.
            $exception->createdThreadId = $id;

            throw $exception;
        }

        return $id;
    }

    public function send(AgentThread $thread, string $message, ?string $key = null): void
    {
        if ($thread->parent?->groupOrchestration() === TaskOrchestration::External) {
            $this->client->send($this->node($thread), $thread->external_id, $key ?? (string) Str::uuid(), $message);

            return;
        }
        $this->deliver($this->node($thread), $thread->external_id, $message, $key);
    }

    public function respond(AgentThread $thread, AgentInputRequest $request, array $answers): void
    {
        throw AgentDriverException::unsupported('input requests on Pi threads');
    }

    public function interrupt(AgentThread $thread): void
    {
        $this->client->interrupt($this->node($thread), $thread->external_id);
    }

    public function archive(AgentThread $thread, string $commandId): void
    {
        // Pi sessions are files on the Node and are outside thread archive cleanup.
    }

    private function deliver(Node $node, string $sessionId, string $message, ?string $key = null): void
    {
        $key ??= (string) Str::uuid();
        try {
            $this->client->send($node, $sessionId, $key, $message);
        } catch (AgentDriverException) {
            try {
                $this->client->send($node, $sessionId, $key, $message);
            } catch (AgentDriverException $exception) {
                Log::error('Pi server refused a turn after one retry.', ['session_id' => $sessionId, 'exception' => $exception->getMessage()]);

                throw $exception;
            }
        }
    }

    private function node(AgentThread $thread): Node
    {
        $node = $thread->node;
        if ($node === null || $node->status !== LifecycleStatus::Active) {
            throw new AgentDriverException('The original agent Node is unavailable.');
        }
        $group = $thread->parent()->with('taskable')->first();
        $workspace = $group?->taskable;
        // A task VM is a normal Node: the thread stays on the Node of the group's ready task VM.
        if ($group === null || $thread->runtime_key !== 'node:'.$node->id || ! $workspace instanceof Instance
            || ! TaskVmWorkspaceGuard::allowsWorkspace($group, $workspace) || $workspace->node_id !== $node->id) {
            throw new AgentDriverException('The original task VM Pi server is unavailable.');
        }

        return $node;
    }

    /** The workspace Node runs the Pi server; a task VM workspace must still be owned by its group. */
    private function workspaceNode(Instance $workspace, Node $node): Node
    {
        if ($workspace->node_id !== $node->id) {
            throw new AgentDriverException('The original workspace Pi server is unavailable.');
        }
        try {
            TaskVmWorkspaceGuard::assertOwnedWorkspace($workspace);
        } catch (TaskVmException) {
            throw new AgentDriverException('The original workspace Pi server is unavailable.');
        }

        return $node;
    }
}
