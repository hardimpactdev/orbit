<?php

declare(strict_types=1);

namespace App\Data\Tasks;

use App\Domain\Tasks\AssistanceKind;
use App\Domain\Tasks\TaskDeliverable;
use App\Domain\Tasks\TaskStatus;
use App\Domain\Tasks\TaskType;
use App\Models\Task;
use App\Models\TaskCheck;
use Illuminate\Database\Eloquent\Collection;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

#[MapOutputName(SnakeCaseMapper::class)]
final class TaskData extends Data
{
    /** The columns a list row reads; no list reads the unbounded completion summary or fixup problem (docs/reference/tasks.md#list-tasks). */
    public const array ListColumns = [
        'id', 'parent_id', 'position', 'title', 'brief', 'deliverables', 'status', 'type', 'implementer_agent_thread_id', 'tokens',
        'line_diff', 'lines_added', 'lines_deleted', 'duration_ms', 'questions', 'escalations', 'assistance_requested', 'assistance_kind',
        'assistance_question', 'assistance_reason', 'started_at', 'settled_at',
    ];

    public function __construct(
        public int $id,
        public int $parentId,
        public int $position,
        public string $title,
        public string $brief,
        /** @var list<array<string, string|bool|list<string>>> */
        public array $deliverables,
        public TaskStatus $status,
        public ?int $implementerAgentThreadId,
        public ?int $tokens,
        public ?int $lineDiff,
        public ?int $linesAdded,
        public ?int $linesDeleted,
        public ?int $durationMs,
        public int $questions,
        public int $escalations,
        public TaskType $type,
        public ?string $completionSummary,
        public ?TaskCheckData $check,
        public bool $assistanceRequested,
        public ?AssistanceKind $assistanceKind,
        public ?string $assistanceQuestion,
        public ?string $assistanceReason,
        public ?string $fixupProblem,
    ) {}

    /** @return array<string, mixed> */
    public function toListArray(bool $compact = false): array
    {
        if ($compact) {
            return $this->toCompactArray();
        }
        $data = $this->toArray();
        unset($data['completion_summary'], $data['fixup_problem']);
        if (is_array($data['check'])) {
            unset($data['check']['output']);
        }

        return $data;
    }

    /** @return array<string, mixed> */
    public function toCompactArray(): array
    {
        $data = $this->toListArray();
        unset($data['brief'], $data['deliverables'], $data['assistance_question'], $data['assistance_reason']);

        return $data;
    }

    public static function fromModel(Task $task): self
    {
        return self::fromModels(new Collection([$task]))[0];
    }

    /**
     * The data of many subtasks, with their latest checks read once for all of them.
     *
     * @param  Collection<int, Task>  $tasks
     * @return list<self>
     */
    public static function fromModels(Collection $tasks): array
    {
        $tasks->loadMissing('latestCheck');

        return array_values($tasks->map(self::fromLoaded(...))->all());
    }

    private static function fromLoaded(Task $task): self
    {
        $check = $task->latestCheck;

        return new self(
            type: $task->type,
            completionSummary: $task->completion_summary,
            check: $check instanceof TaskCheck ? TaskCheckData::fromModel($check) : null,
            assistanceRequested: $task->assistance_requested,
            assistanceKind: $task->assistance_kind,
            assistanceQuestion: $task->assistance_question,
            assistanceReason: $task->assistance_reason,
            fixupProblem: $task->fixup_problem,

            id: $task->id,
            parentId: $task->requireGroupId(),
            position: $task->position,
            title: $task->title,
            brief: $task->brief,
            deliverables: array_map(static fn (TaskDeliverable $deliverable): array => $deliverable->toArray(), $task->deliverableList()),
            status: $task->subtaskStatus(),
            implementerAgentThreadId: $task->implementer_agent_thread_id,
            tokens: $task->tokens,
            lineDiff: $task->line_diff,
            linesAdded: $task->lines_added,
            linesDeleted: $task->lines_deleted,
            durationMs: $task->duration_ms,
            questions: (int) ($task->questions ?? 0),
            escalations: (int) ($task->escalations ?? 0),
        );
    }
}
