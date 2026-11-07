<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Tasks;

/** The outside hosts of one task group, by destination, port and protocol. */
final readonly class TaskEgressResponse
{
    /** @param list<TaskEgressRowResponse> $rows */
    public function __construct(
        public array $rows,
        public string $requestId,
    ) {}

    /** @return array{hosts: list<array<string, mixed>>, request_id: string} */
    public function toArray(): array
    {
        return [
            'hosts' => array_map(static function (TaskEgressRowResponse $row): array {
                $data = $row->toArray();
                unset($data['request_id']);

                return $data;
            }, $this->rows),
            'request_id' => $this->requestId,
        ];
    }
}
