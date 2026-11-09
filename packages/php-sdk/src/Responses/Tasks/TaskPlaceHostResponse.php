<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Tasks;

/** The task and CI places of one task VM host. */
final readonly class TaskPlaceHostResponse
{
    /** @param list<TaskPlaceHolderResponse> $holders */
    public function __construct(
        public int $nodeId,
        public string $node,
        public int $taskPlaces,
        public int $taskUsed,
        public int $taskFree,
        public int $ciPlaces,
        public int $ciUsed,
        public int $ciFree,
        public array $holders,
    ) {}

    public static function fromGatewayData(mixed $data, string $requestId): self
    {
        $data = TaskPlaceRows::row($data, $requestId);
        $count = static fn (string $key): int => TaskFields::nonNegative($data, $key, 'task place', $requestId);

        return new self(
            nodeId: TaskFields::id($data, 'node_id', 'task place', $requestId),
            node: TaskFields::text($data, 'node', 'task place', $requestId),
            taskPlaces: $count('task_places'),
            taskUsed: $count('task_used'),
            taskFree: $count('task_free'),
            ciPlaces: $count('ci_places'),
            ciUsed: $count('ci_used'),
            ciFree: $count('ci_free'),
            holders: array_map(static fn (mixed $holder): TaskPlaceHolderResponse => TaskPlaceHolderResponse::fromGatewayData($holder, $requestId),
                TaskPlaceRows::list($data['holders'] ?? null, $requestId)),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['node_id' => $this->nodeId, 'node' => $this->node, 'task_places' => $this->taskPlaces, 'task_used' => $this->taskUsed,
            'task_free' => $this->taskFree, 'ci_places' => $this->ciPlaces, 'ci_used' => $this->ciUsed, 'ci_free' => $this->ciFree,
            'holders' => array_map(static fn (TaskPlaceHolderResponse $holder): array => $holder->toArray(), $this->holders)];
    }
}
