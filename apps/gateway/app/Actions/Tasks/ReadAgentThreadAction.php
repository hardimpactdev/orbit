<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\AgentDriverException;
use App\Domain\Tasks\AgentDriverRegistry;
use App\Domain\Tasks\AgentObservation;
use App\Domain\Tasks\AgentThreadEvent;
use App\Infrastructure\Activity\CommandActivityInputSanitizer;
use App\Models\AgentThread;
use App\Models\Task;

/**
 * Reads one agent thread as JSON: a snapshot, as the agent stream sends first, or with a cursor only what changed after
 * it. The stream uses the same checks, saved transcript, and sanitizer, so both return the same redacted content.
 */
final readonly class ReadAgentThreadAction
{
    public function __construct(
        private RequireTasksExtensionAction $requireExtension,
        private AgentDriverRegistry $drivers,
        private CommandActivityInputSanitizer $sanitizer,
        private ArchiveAgentTranscriptsAction $archive,
    ) {}

    /** @return array<string, mixed> */
    public function execute(Task $group, AgentThread $session, ?string $afterSequence): array
    {
        $this->assertReadable($group, $session);
        if ($session->isPending()) {
            // The spawner reserved the thread, and its conversation has not started: an empty snapshot, not a failure.
            return [...$this->event(new AgentThreadEvent($session->id, 'snapshot', new AgentObservation(null)->toArray())), 'archived' => false];
        }
        if ($this->nodeGone($session)) {
            return [...$this->savedTranscript($session), 'archived' => true];
        }
        try {
            $observation = $this->drivers->get($session->driver)->read($session, $afterSequence);
        } catch (AgentDriverException $exception) {
            // A finished thread whose task VM is gone keeps an active Node row until the Node is removed.
            $saved = $this->saved($session);
            if ($saved !== null) {
                return [...$saved, 'archived' => true];
            }

            // The response names no driver detail; the report keeps it.
            throw new ResourceOperationException('tasks.agent_read_failed', 'Orbit could not read the agent thread from its Node.', 502, $exception);
        }
        $kind = $observation->delta ? 'delta' : 'snapshot';

        return [...$this->event(new AgentThreadEvent($session->id, $kind, $observation->toArray(), $observation->cursor)), 'archived' => false];
    }

    /** Refuses a thread the caller cannot read: the extension is off, the thread is another task's, or it is a stored `t3` thread. */
    public function assertReadable(Task $group, AgentThread $session): void
    {
        $this->requireExtension->execute();
        abort_unless($session->task_group_id === $group->id, 404);
        if ($session->driver === 't3') {
            throw new ResourceOperationException('tasks.agent_transcript_unavailable', 'Transcripts for retired T3 task threads are unavailable.', 409);
        }
    }

    /** The thread's Node is removed or not active, so only the saved transcript can serve it. */
    public function nodeGone(AgentThread $session): bool
    {
        $node = $session->node;

        return $node === null || $node->status !== LifecycleStatus::Active;
    }

    /**
     * The transcript Orbit saved before the task VM was deleted, as one sanitized snapshot. Without one, the thread is
     * unavailable.
     *
     * @return array<string, mixed>
     */
    public function savedTranscript(AgentThread $session): array
    {
        return $this->saved($session) ?? throw new ResourceOperationException('tasks.agent_unavailable', 'The original agent Node is unavailable.', 409);
    }

    /** @return array<string, mixed> the event as the agent viewer receives it */
    public function event(AgentThreadEvent $event): array
    {
        return $this->sanitizer->sanitizeProperties($event->toArray());
    }

    /** @return array<string, mixed>|null */
    private function saved(AgentThread $session): ?array
    {
        $observation = $this->archive->read($session);

        return $observation === null ? null : $this->event(new AgentThreadEvent($session->id, 'snapshot', $observation));
    }
}
