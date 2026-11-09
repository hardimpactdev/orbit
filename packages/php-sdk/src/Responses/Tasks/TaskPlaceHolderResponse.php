<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Tasks;

/** One live task VM that holds a place, and the group it serves. */
final readonly class TaskPlaceHolderResponse
{
    public function __construct(
        public string $vm,
        public string $pool,
        public ?int $groupId,
        public ?string $title,
        public ?string $status,
        public ?string $lastActivityAt,
        public ?int $activeOperationId,
    ) {}

    public static function fromGatewayData(mixed $data, string $requestId): self
    {
        $data = TaskPlaceRows::row($data, $requestId);

        return new self(
            vm: TaskFields::text($data, 'vm', 'task place', $requestId),
            pool: TaskFields::text($data, 'pool', 'task place', $requestId),
            groupId: TaskFields::nullableInt($data, 'group_id'),
            title: TaskFields::nullableText($data, 'title'),
            status: TaskFields::nullableText($data, 'status'),
            lastActivityAt: TaskFields::nullableText($data, 'last_activity_at'),
            activeOperationId: TaskFields::nullableInt($data, 'active_operation_id'),
        );
    }

    /** @return array{vm: string, pool: string, group_id: int|null, title: string|null, status: string|null, last_activity_at: string|null, active_operation_id: int|null} */
    public function toArray(): array
    {
        return ['vm' => $this->vm, 'pool' => $this->pool, 'group_id' => $this->groupId, 'title' => $this->title, 'status' => $this->status,
            'last_activity_at' => $this->lastActivityAt, 'active_operation_id' => $this->activeOperationId];
    }
}
