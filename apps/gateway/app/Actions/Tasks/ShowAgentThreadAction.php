<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\AgentDriverException;
use App\Domain\Tasks\AgentDriverRegistry;
use App\Domain\Tasks\AgentThreadEvent;
use App\Infrastructure\Activity\CommandActivityInputSanitizer;
use App\Models\AgentThread;
use App\Models\Task;

/**
 * Reads one agent thread as a single snapshot, the same redacted content the agent stream sends first.
 * The stream uses the same checks, saved transcript, and sanitizer, so both return the same content.
 */
final readonly class ShowAgentThreadAction
{
    public function __construct(
        private RequireTasksExtensionAction $requireExtension,
        private AgentDriverRegistry $drivers,
        private CommandActivityInputSanitizer $sanitizer,
        private ArchiveAgentTranscriptsAction $archive,
    ) {}

    /** @return array<string, mixed> */
    public function execute(Task $group, AgentThread $session): array
    {
        $archived = $this->archived($group, $session);
        if ($archived !== null) {
            return [...$archived, 'archived' => true];
        }
        try {
            $observation = $this->drivers->get($session->driver)->observe($session);
        } catch (AgentDriverException $exception) {
            // The response names no driver detail; the report keeps it.
            throw new ResourceOperationException('tasks.agent_read_failed', 'Orbit could not read the agent thread from its Node.', 502, $exception);
        }

        return [...$this->event(new AgentThreadEvent($session->id, 'snapshot', $observation->toArray(), $observation->cursor)), 'archived' => false];
    }

    /**
     * Checks a transcript request. Returns the transcript Orbit saved before the task VM was deleted, as one
     * sanitized snapshot, when the thread's Node is gone. Returns null when the thread's Node can serve it.
     *
     * @return array<string, mixed>|null
     */
    public function archived(Task $group, AgentThread $session): ?array
    {
        $this->requireExtension->execute();
        abort_unless($session->task_group_id === $group->id, 404);
        if ($session->driver === 't3') {
            throw new ResourceOperationException('tasks.agent_transcript_unavailable', 'Transcripts for retired T3 task threads are unavailable.', 409);
        }
        $node = $session->node;
        if ($node !== null && $node->status === LifecycleStatus::Active) {
            return null;
        }
        $observation = $this->archive->read($session);
        if ($observation === null) {
            throw new ResourceOperationException('tasks.agent_unavailable', 'The original agent Node is unavailable.', 409);
        }

        return $this->event(new AgentThreadEvent($session->id, 'snapshot', $observation));
    }

    /** @return array<string, mixed> the event as the agent viewer receives it */
    public function event(AgentThreadEvent $event): array
    {
        return $this->sanitizer->sanitizeProperties($event->toArray());
    }
}
