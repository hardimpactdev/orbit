<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Tasks;

/** One admission waiting for a task place, front first. */
final readonly class TaskPlaceQueueResponse
{
    public function __construct(
        public int $position,
        public int $operationId,
        public string $kind,
        public int $groupId,
        public ?string $title,
        public ?string $waitingSince,
    ) {}

    public static function fromGatewayData(mixed $data, string $requestId): self
    {
        $data = TaskPlaceRows::row($data, $requestId);

        return new self(
            position: TaskFields::id($data, 'position', 'task queue', $requestId),
            operationId: TaskFields::id($data, 'operation_id', 'task queue', $requestId),
            kind: TaskFields::text($data, 'kind', 'task queue', $requestId),
            groupId: TaskFields::id($data, 'group_id', 'task queue', $requestId),
            title: TaskFields::nullableText($data, 'title'),
            waitingSince: TaskFields::nullableText($data, 'waiting_since'),
        );
    }

    /** @return array{position: int, operation_id: int, kind: string, group_id: int, title: string|null, waiting_since: string|null} */
    public function toArray(): array
    {
        return ['position' => $this->position, 'operation_id' => $this->operationId, 'kind' => $this->kind, 'group_id' => $this->groupId,
            'title' => $this->title, 'waiting_since' => $this->waitingSince];
    }
}
