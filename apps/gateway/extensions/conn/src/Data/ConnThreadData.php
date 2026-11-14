<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Data;

use Orbit\Extensions\Conn\Models\ConnThread;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/** One row of the thread index ([Thread index](/reference/conn#thread-index)). `state` is T3's thread status, null until a read. */
#[MapOutputName(SnakeCaseMapper::class)]
final class ConnThreadData extends Data
{
    public function __construct(
        public string $environmentId,
        public int $instanceId,
        public int $projectId,
        public string $threadId,
        public string $title,
        public ?string $state,
        public bool $needsInput,
        public ?string $lastUserMessageAt,
        public ?string $lastRunCompletedAt,
        public string $seenAt,
    ) {}

    public static function fromModel(ConnThread $thread): self
    {
        $thread->loadMissing(['environment', 'instance']);

        return new self(
            environmentId: $thread->environment->environment_id,
            instanceId: $thread->instance_id,
            projectId: $thread->instance->project_id,
            threadId: $thread->thread_id,
            title: $thread->title,
            state: $thread->state,
            needsInput: $thread->needs_input,
            lastUserMessageAt: $thread->last_user_message_at?->toIso8601String(),
            lastRunCompletedAt: $thread->last_run_completed_at?->toIso8601String(),
            seenAt: $thread->seen_at->toIso8601String(),
        );
    }

    /**
     * The same row, built as an array straight from the table, for the list: a list of thousands of rows would spend most of its time
     * casting and serializing models. The stored times are UTC in `Y-m-d H:i:s`, so each becomes ISO 8601 by its separator and offset.
     *
     * @return array<string, mixed>
     */
    public static function fromRow(\stdClass $row, string $environmentId, int $projectId): array
    {
        $time = static fn (mixed $value): ?string => is_string($value) ? str_replace(' ', 'T', $value).'+00:00' : null;

        return [
            'environment_id' => $environmentId,
            'instance_id' => (int) $row->instance_id,
            'project_id' => $projectId,
            'thread_id' => (string) $row->thread_id,
            'title' => (string) $row->title,
            'state' => is_string($row->state) ? $row->state : null,
            'needs_input' => (bool) $row->needs_input,
            'last_user_message_at' => $time($row->last_user_message_at),
            'last_run_completed_at' => $time($row->last_run_completed_at),
            'seen_at' => (string) $time($row->seen_at),
        ];
    }
}
