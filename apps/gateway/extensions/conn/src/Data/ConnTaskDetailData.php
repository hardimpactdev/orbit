<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Data;

use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/**
 * One task of the Conn Tasks panel ([Tasks panel](/reference/conn#tasks-panel)): its list row, its brief, its metrics, its subtasks in
 * order, and every started agent thread with the Conn thread it opens.
 */
#[MapOutputName(SnakeCaseMapper::class)]
final class ConnTaskDetailData extends Data
{
    /**
     * @param  array{implementer_mode: string}  $execution
     * @param  array{duration_ms: ?int, tokens: ?int, lines_added: ?int, lines_deleted: ?int}  $metrics
     * @param  list<array<string, mixed>>  $subtasks
     * @param  list<array<string, mixed>>  $agents
     */
    public function __construct(
        public ConnTaskData $row,
        public string $brief,
        public array $execution,
        public array $metrics,
        public array $subtasks,
        public array $agents,
    ) {}

    /** @return array<string, mixed> the row's fields, then the detail's */
    #[\Override]
    public function toArray(): array
    {
        $detail = parent::toArray();
        unset($detail['row']);

        return [...$this->row->toArray(), ...$detail];
    }
}
