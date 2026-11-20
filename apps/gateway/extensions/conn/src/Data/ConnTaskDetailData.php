<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Data;

use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;
use Spatie\LaravelData\Optional;

/**
 * One task of the Conn Tasks panel ([Tasks panel](/reference/conn#tasks-panel)): its list row, its brief, its metrics, its subtasks in
 * order or the work of a task with no subtask, and every started agent thread with the Conn thread it opens.
 */
#[MapOutputName(SnakeCaseMapper::class)]
final class ConnTaskDetailData extends Data
{
    /**
     * @param  array{implementer_mode: string}  $execution
     * @param  array{duration_ms: ?int, tokens: ?int, lines_added: ?int, lines_deleted: ?int}  $metrics
     * @param  list<array<string, mixed>>  $subtasks
     * @param  list<array<string, mixed>>  $agents
     * @param  array<string, mixed>|Optional  $work  only for a task with no subtask
     */
    public function __construct(
        public ConnTaskData $row,
        public string $brief,
        public array $execution,
        public array $metrics,
        public array $subtasks,
        public array $agents,
        public array|Optional $work = new Optional,
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
