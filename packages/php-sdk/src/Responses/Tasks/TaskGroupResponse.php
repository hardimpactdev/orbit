<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Tasks;

final readonly class TaskGroupResponse implements TaskRecordResponse
{
    /** A task group holds at most this many subtasks in one response. */
    private const int MAX_SUBTASKS = 1_000;

    /** @param list<SubtaskResponse> $subtasks */
    public function __construct(
        public int $id,
        public int $projectId,
        public ?string $project,
        public ?string $projectCode,
        public string $title,
        public string $brief,
        public string $status,
        public ?string $taskableType,
        public ?int $taskableId,
        public ?int $reviewerAgentThreadId,
        public ?string $prUrl,
        public ?string $watchedPrUrl,
        public ?int $watchedPrNumber,
        public ?string $watchedPrState,
        public bool $assistanceRequested,
        public ?string $assistanceKind,
        public ?string $assistanceQuestion,
        public ?string $assistanceReason,
        public ?string $implementerModel,
        public ?string $reviewerModel,
        public ?int $tokens,
        public ?int $lineDiff,
        public ?int $linesAdded,
        public ?int $linesDeleted,
        public ?int $durationMs,
        public int $questions,
        public int $escalations,
        public array $subtasks,
        public string $requestId,
        public ?string $capacityWaitReason = null,
        public ?string $vmPower = null,
        public bool $preview = false,
        public ?TaskReviewAndMergeResponse $reviewAndMerge = null,
        public string $orchestration = 'tick',
        public bool $currentRecipe = false,
        public string $review = 'manual',
        public ?string $lastActivityAt = null,
        public ?string $previewUrl = null,
        public ?TaskPlaceWaitResponse $capacityWait = null,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromGatewayData(array $data, string $requestId): self
    {
        return new self(
            id: TaskFields::id($data, 'id', 'task group', $requestId),
            projectId: TaskFields::id($data, 'project_id', 'task group', $requestId),
            project: TaskFields::nullableText($data, 'project'),
            projectCode: TaskFields::nullableText($data, 'project_code'),
            title: TaskFields::text($data, 'title', 'task group', $requestId),
            brief: TaskFields::text($data, 'brief', 'task group', $requestId),
            status: TaskFields::text($data, 'status', 'task group', $requestId),
            orchestration: TaskFields::text($data, 'orchestration', 'task group', $requestId),
            taskableType: TaskFields::nullableText($data, 'taskable_type'),
            taskableId: TaskFields::nullableInt($data, 'taskable_id'),
            reviewerAgentThreadId: TaskFields::nullableInt($data, 'reviewer_agent_thread_id'),
            prUrl: TaskFields::nullableText($data, 'pr_url'),
            watchedPrUrl: TaskFields::nullableText($data, 'watched_pr_url'),
            watchedPrNumber: TaskFields::nullableInt($data, 'watched_pr_number'),
            watchedPrState: TaskFields::nullableText($data, 'watched_pr_state'),
            assistanceRequested: ($data['assistance_requested'] ?? false) === true,
            assistanceKind: TaskFields::nullableText($data, 'assistance_kind'),
            assistanceQuestion: TaskFields::nullableText($data, 'assistance_question'),
            assistanceReason: TaskFields::nullableText($data, 'assistance_reason'),
            implementerModel: TaskFields::nullableText($data, 'implementer_model'),
            reviewerModel: TaskFields::nullableText($data, 'reviewer_model'),
            tokens: TaskFields::nullableInt($data, 'tokens'),
            lineDiff: TaskFields::nullableInt($data, 'line_diff'),
            linesAdded: TaskFields::nullableInt($data, 'lines_added'),
            linesDeleted: TaskFields::nullableInt($data, 'lines_deleted'),
            durationMs: TaskFields::nullableInt($data, 'duration_ms'),
            questions: TaskFields::count($data, 'questions', 'task group', $requestId),
            escalations: TaskFields::count($data, 'escalations', 'task group', $requestId),
            subtasks: self::subtasks($data['subtasks'] ?? [], $requestId),
            requestId: $requestId,
            capacityWaitReason: TaskFields::nullableText($data, 'capacity_wait_reason'),
            preview: ($data['preview'] ?? false) === true,
            currentRecipe: ($data['current_recipe'] ?? false) === true,
            review: ($data['review'] ?? null) === 'automatic' ? 'automatic' : 'manual',
            vmPower: in_array($data['vm_power'] ?? null, ['running', 'stopped', 'destroyed'], true) ? $data['vm_power'] : null,
            reviewAndMerge: TaskReviewAndMergeResponse::fromGatewayData($data['review_and_merge'] ?? null, $requestId),
            lastActivityAt: TaskFields::nullableText($data, 'last_activity_at'),
            previewUrl: TaskFields::nullableText($data, 'preview_url'),
            capacityWait: TaskPlaceWaitResponse::fromGatewayData($data['capacity_wait'] ?? null, $requestId),
        );
    }

    /**
     * The group's human reference, such as ORB-13, or #13 when the Project has no code.
     */
    public function reference(): string
    {
        return $this->projectCode === null || $this->projectCode === ''
            ? "#{$this->id}"
            : "{$this->projectCode}-{$this->id}";
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'parent_id' => null,
            'project_id' => $this->projectId,
            'project' => $this->project,
            'project_code' => $this->projectCode,
            'title' => $this->title,
            'brief' => $this->brief,
            'status' => $this->status,
            'orchestration' => $this->orchestration,
            'taskable_type' => $this->taskableType,
            'taskable_id' => $this->taskableId,
            'reviewer_agent_thread_id' => $this->reviewerAgentThreadId,
            'pr_url' => $this->prUrl,
            'watched_pr_url' => $this->watchedPrUrl,
            'watched_pr_number' => $this->watchedPrNumber,
            'watched_pr_state' => $this->watchedPrState,
            'assistance_requested' => $this->assistanceRequested,
            'assistance_kind' => $this->assistanceKind,
            'assistance_question' => $this->assistanceQuestion,
            'assistance_reason' => $this->assistanceReason,
            'implementer_model' => $this->implementerModel,
            'reviewer_model' => $this->reviewerModel,
            'tokens' => $this->tokens,
            'line_diff' => $this->lineDiff,
            'lines_added' => $this->linesAdded,
            'lines_deleted' => $this->linesDeleted,
            'duration_ms' => $this->durationMs,
            'questions' => $this->questions,
            'escalations' => $this->escalations,
            'subtasks' => array_map(static function (SubtaskResponse $task): array {
                $data = $task->toArray();
                unset($data['request_id']);

                return $data;
            }, $this->subtasks),
            ...($this->capacityWaitReason === null ? [] : ['capacity_wait_reason' => $this->capacityWaitReason]),
            ...($this->vmPower === null ? [] : ['vm_power' => $this->vmPower]),
            'preview' => $this->preview,
            'current_recipe' => $this->currentRecipe,
            'review' => $this->review,
            'last_activity_at' => $this->lastActivityAt,
            'preview_url' => $this->previewUrl,
            'capacity_wait' => $this->capacityWait?->toArray(),
            ...($this->reviewAndMerge === null ? [] : ['review_and_merge' => $this->reviewAndMerge->toArray()]),
            'request_id' => $this->requestId,
        ];
    }

    /** @return list<SubtaskResponse> */
    private static function subtasks(mixed $value, string $requestId): array
    {
        if (! is_array($value) || ! array_is_list($value) || count($value) > self::MAX_SUBTASKS) {
            throw TaskFields::invalid('task group', $requestId);
        }

        $subtasks = [];

        foreach ($value as $task) {
            if (! is_array($task)) {
                throw TaskFields::invalid('task group', $requestId);
            }

            $data = [];
            foreach ($task as $key => $item) {
                if (! is_string($key)) {
                    throw TaskFields::invalid('task group', $requestId);
                }
                $data[$key] = $item;
            }

            $subtasks[] = SubtaskResponse::fromGatewayData($data, $requestId);
        }

        return $subtasks;
    }
}
