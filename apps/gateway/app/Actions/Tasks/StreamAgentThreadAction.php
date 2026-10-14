<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\AgentDriverRegistry;
use App\Domain\Tasks\AgentThreadEvent;
use App\Infrastructure\Activity\CommandActivityInputSanitizer;
use App\Models\AgentThread;
use App\Models\Task;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

final readonly class StreamAgentThreadAction
{
    private const array Headers = ['Content-Type' => 'text/event-stream', 'Cache-Control' => 'no-cache, no-store', 'X-Accel-Buffering' => 'no'];

    public function __construct(
        private RequireTasksExtensionAction $requireExtension,
        private AgentDriverRegistry $drivers,
        private CommandActivityInputSanitizer $sanitizer,
        private ArchiveAgentTranscriptsAction $archive,
    ) {}

    public function execute(Task $group, AgentThread $session, ?string $afterSequence): StreamedResponse
    {
        $this->requireExtension->execute();
        abort_unless($session->task_group_id === $group->id, 404);
        if ($session->driver === 't3') {
            throw new ResourceOperationException('tasks.agent_transcript_unavailable', 'Transcripts for retired T3 task threads are unavailable.', 409);
        }
        $node = $session->node;
        if ($node === null || $node->status !== LifecycleStatus::Active) {
            $archived = $this->archive->read($session);
            if ($archived === null) {
                throw new ResourceOperationException('tasks.agent_unavailable', 'The original agent Node is unavailable.', 409);
            }

            return $this->archived($session, $archived);
        }

        return response()->stream(function () use ($session, $afterSequence): \Generator {
            yield "retry: 3000\n\n";
            try {
                foreach ($this->drivers->get($session->driver)->events($session, $afterSequence) as $event) {
                    if (connection_aborted()) {
                        break;
                    }
                    if ($event->threadId !== $session->id) {
                        continue;
                    }
                    if ($event->kind === 'heartbeat') {
                        yield ": heartbeat\n\n";

                        continue;
                    }
                    $data = $this->sanitizer->sanitizeProperties($event->toArray());
                    $json = json_encode($data, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
                    $cursor = $event->cursor;
                    if ($cursor !== null && preg_match('/[\r\n\x00]/', $cursor) === 1) {
                        throw new \RuntimeException('Invalid agent event cursor.');
                    }
                    yield ($cursor !== null ? 'id: '.$cursor."\n" : '')."event: agent\ndata: ".$json."\n\n";
                }
            } catch (Throwable) {
                yield "event: unavailable\ndata: {\"message\":\"Agent stream unavailable. Reconnecting…\"}\n\n";
            }
        }, 200, self::Headers);
    }

    /**
     * Replays the transcript Orbit saved before the task VM was deleted, as one snapshot.
     *
     * @param  array<string, mixed>  $observation
     */
    private function archived(AgentThread $session, array $observation): StreamedResponse
    {
        $data = $this->sanitizer->sanitizeProperties(new AgentThreadEvent($session->id, 'snapshot', $observation)->toArray());

        return response()->stream(static function () use ($data): \Generator {
            yield "retry: 3000\n\n";
            yield "event: agent\ndata: ".json_encode($data, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE)."\n\n";
        }, 200, self::Headers);
    }
}
