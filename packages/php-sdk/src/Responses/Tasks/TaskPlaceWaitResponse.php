<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Tasks;

/** Where a waiting admission stands in the task queue, and who holds the task places. */
final readonly class TaskPlaceWaitResponse
{
    /** @param list<TaskPlaceHolderResponse> $holders */
    public function __construct(
        public int $operationId,
        public int $position,
        public array $holders,
    ) {}

    /** Null when the record waits for no task place. */
    public static function fromGatewayData(mixed $data, string $requestId): ?self
    {
        if ($data === null) {
            return null;
        }
        $data = TaskPlaceRows::row($data, $requestId);

        return new self(
            operationId: TaskFields::id($data, 'operation_id', 'task place wait', $requestId),
            position: TaskFields::id($data, 'position', 'task place wait', $requestId),
            holders: array_map(static fn (mixed $holder): TaskPlaceHolderResponse => TaskPlaceHolderResponse::fromGatewayData($holder, $requestId),
                TaskPlaceRows::list($data['holders'] ?? null, $requestId)),
        );
    }

    /** @return array{operation_id: int, position: int, holders: list<array<string, mixed>>} */
    public function toArray(): array
    {
        return ['operation_id' => $this->operationId, 'position' => $this->position,
            'holders' => array_map(static fn (TaskPlaceHolderResponse $holder): array => $holder->toArray(), $this->holders)];
    }
}
