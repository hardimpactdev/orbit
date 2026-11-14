<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\AgentObservation;
use App\Domain\Tasks\AgentThreadEvent;
use App\Infrastructure\Activity\CommandActivityInputSanitizer;
use App\Models\AgentThread;
use App\Models\Task;

/** JSON and SSE readers share one recorded, sanitized snapshot. */
final readonly class ReadAgentThreadAction
{
    public function __construct(
        private RequireTasksExtensionAction $requireExtension,
        private CommandActivityInputSanitizer $sanitizer,
        private ArchiveAgentTranscriptsAction $archive,
    ) {}

    /** @return array<string, mixed> */
    public function execute(Task $group, AgentThread $session, ?string $afterSequence): array
    {
        $this->requireExtension->execute();
        abort_unless($session->task_group_id === $group->id, 404);
        $saved = $this->archive->read($session);
        // A T3 thread is readable only from its archive: a task copy's turn records one, a retired host T3 thread has none.
        if ($session->driver === 't3' && $saved === null) {
            throw new ResourceOperationException('tasks.agent_transcript_unavailable', 'This T3 thread has no recorded transcript.', 409);
        }
        $observation = [
            ...($saved ?? new AgentObservation(null)->toArray()),
            'state' => $session->activityState()?->value,
            'error' => $session->error,
            'tokens' => $session->tokens,
            'input_tokens' => $session->input_tokens,
            'cached_input_tokens' => $session->cached_input_tokens,
            'output_tokens' => $session->output_tokens,
            'model_calls' => $session->model_calls,
            'peak_context_tokens' => $session->peak_context_tokens,
            'cost_usd' => $session->cost_usd,
        ];

        return [...$this->sanitizer->sanitizeProperties(new AgentThreadEvent($session->id, 'snapshot', $observation)->toArray()), 'archived' => $saved !== null];
    }
}
