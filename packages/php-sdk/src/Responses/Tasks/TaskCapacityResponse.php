<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Tasks;

/** The task places of every task VM host and the task queue, as `task:status` reports them. */
final readonly class TaskCapacityResponse
{
    /**
     * @param  list<TaskPlaceHostResponse>  $hosts
     * @param  list<TaskPlaceQueueResponse>  $queue
     */
    public function __construct(
        public array $hosts,
        public array $queue,
    ) {}

    public static function fromGatewayData(mixed $data, string $requestId): self
    {
        $data = TaskPlaceRows::row($data, $requestId);

        return new self(
            hosts: array_map(static fn (mixed $host): TaskPlaceHostResponse => TaskPlaceHostResponse::fromGatewayData($host, $requestId),
                TaskPlaceRows::list($data['hosts'] ?? null, $requestId)),
            queue: array_map(static fn (mixed $entry): TaskPlaceQueueResponse => TaskPlaceQueueResponse::fromGatewayData($entry, $requestId),
                TaskPlaceRows::list($data['queue'] ?? null, $requestId)),
        );
    }

    /** @return array{hosts: list<array<string, mixed>>, queue: list<array<string, mixed>>} */
    public function toArray(): array
    {
        return [
            'hosts' => array_map(static fn (TaskPlaceHostResponse $host): array => $host->toArray(), $this->hosts),
            'queue' => array_map(static fn (TaskPlaceQueueResponse $entry): array => $entry->toArray(), $this->queue),
        ];
    }
}
