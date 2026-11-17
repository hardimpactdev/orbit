<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Data;

use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/**
 * One row of the Conn Tasks panel ([Tasks panel](/reference/conn#tasks-panel)): a top-level task, its workspace with the Conn server a
 * device opens it on, and what it asks.
 */
#[MapOutputName(SnakeCaseMapper::class)]
final class ConnTaskData extends Data
{
    /**
     * @param  array{kind: string, question: ?string, reason: ?string}|null  $attention
     * @param  array{id: int, slug: string, name: string, code: string}  $project
     * @param  array{instance_id: int, name: string, status: string, power_state: ?string, node: array{id: int, name: string}, branch: ?string, conn_environment_id: ?string}|null  $workspace
     * @param  array{number: ?int, url: string, state: ?string}|null  $pullRequest
     * @param  array{total: int, todo: int, running: int, completed: int, failed: int, cancelled: int}  $subtaskCounts
     */
    public function __construct(
        public int $id,
        public string $title,
        public string $status,
        public ?array $attention,
        public array $project,
        public ?array $workspace,
        public ?array $pullRequest,
        public array $subtaskCounts,
        public ?string $createdAt,
        public ?string $updatedAt,
        public ?string $lastActivityAt,
    ) {}
}
