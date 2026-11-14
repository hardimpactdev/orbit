<?php

declare(strict_types=1);

namespace App\Data\Tasks;

use App\Domain\Compute\VmPower;
use App\Domain\Tasks\AssistanceKind;
use App\Domain\Tasks\TaskCopyAgent;
use App\Domain\Tasks\TaskGroupActivity;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskOrchestration;
use App\Domain\Tasks\TaskPlaces;
use App\Domain\Tasks\TaskReviewMode;
use App\Domain\Tasks\TaskThreadRole;
use App\Domain\TaskVms\TaskVmState;
use App\Models\Instance;
use App\Models\Route;
use App\Models\Task;
use App\Models\TaskVm;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

#[MapOutputName(SnakeCaseMapper::class)]
final class TaskGroupData extends Data
{
    /**
     * @param  list<TaskData>  $subtasks
     * @param  array<string, array<string, array{value: string, source: string}>>  $resolvedAgents  each role's copy turn agent and where each value comes from (TaskCopyAgent)
     */
    public function __construct(
        public int $id,
        public ?int $parentId,
        public int $projectId,
        public string $project,
        public string $projectCode,
        public ?string $taskableType,
        public ?int $taskableId,
        public string $title,
        public string $brief,
        public TaskGroupStatus $status,
        public ?int $reviewerAgentThreadId,
        public ?string $prUrl,
        public ?string $watchedPrUrl,
        public ?int $watchedPrNumber,
        public ?string $watchedPrState,
        public bool $assistanceRequested,
        public ?AssistanceKind $assistanceKind,
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
        public ?string $capacityWaitReason = null,
        public ?VmPower $vmPower = null,
        public bool $preview = false,
        public bool $currentRecipe = false,
        public TaskReviewMode $review = TaskReviewMode::Manual,
        public ?TaskReviewAndMergeData $reviewAndMerge = null,
        public TaskOrchestration $orchestration = TaskOrchestration::External,
        public ?string $lastActivityAt = null,
        public ?string $previewUrl = null,
        public ?TaskPlaceWaitData $capacityWait = null,
        public ?string $implementerProvider = null,
        public ?string $implementerEffort = null,
        public ?string $reviewerProvider = null,
        public ?string $reviewerEffort = null,
        public array $resolvedAgents = [],
    ) {}

    /** @return array<string, mixed> */
    public function toCompactArray(): array
    {
        $data = $this->toArray();
        unset($data['brief'], $data['assistance_question'], $data['assistance_reason']);
        $data['subtasks'] = array_map(static fn (TaskData $task): array => $task->toCompactArray(), $this->subtasks);

        return $data;
    }

    /** @param  array{last_activity_at: string|null, active_operation_id: int|null}|null  $activity  read for many groups at once by a list */
    public static function fromModel(Task $group, ?array $activity = null): self
    {
        $group->loadMissing(['project', 'tasks']);
        $taskable = $group->taskable;
        $status = $group->groupStatus();

        return new self(
            lastActivityAt: ($activity ?? TaskGroupActivity::ofGroup($group->id))['last_activity_at'],
            previewUrl: self::previewUrl($group, $taskable),
            capacityWait: $group->capacity_wait_reason === null ? null : app(TaskPlaces::class)->waitOfGroup($group),
            orchestration: $group->groupOrchestration(),
            capacityWaitReason: $group->capacity_wait_reason,
            vmPower: self::vmPower($group),
            preview: $group->preview ?? false,
            currentRecipe: $group->current_recipe ?? false,
            review: $group->review_mode,
            reviewAndMerge: TaskReviewAndMergeData::fromModel($group),

            id: $group->id,
            parentId: null,
            projectId: $group->project_id,
            project: $group->project->slug,
            projectCode: $group->project->code,
            taskableType: $taskable instanceof Instance ? 'instance' : $group->taskable_type,
            taskableId: $group->taskable_id,
            title: $group->title,
            brief: $group->brief,
            status: $status,
            reviewerAgentThreadId: $group->reviewer_agent_thread_id,
            prUrl: $group->pr_url,
            watchedPrUrl: $group->watched_pr_url,
            watchedPrNumber: $group->watched_pr_number,
            watchedPrState: $group->watched_pr_state,
            assistanceRequested: $group->assistance_requested,
            assistanceKind: $group->assistance_kind,
            assistanceQuestion: $group->assistance_question,
            assistanceReason: $group->assistance_reason,
            implementerModel: $group->implementer_model,
            reviewerModel: $group->reviewer_model,
            implementerProvider: $group->implementer_provider,
            implementerEffort: $group->implementer_effort,
            reviewerProvider: $group->reviewer_provider,
            reviewerEffort: $group->reviewer_effort,
            resolvedAgents: [
                TaskThreadRole::Implementer->value => TaskCopyAgent::resolve($group, TaskThreadRole::Implementer),
                TaskThreadRole::Reviewer->value => TaskCopyAgent::resolve($group, TaskThreadRole::Reviewer),
            ],
            tokens: $group->tokens,
            lineDiff: $group->line_diff,
            linesAdded: $group->lines_added,
            linesDeleted: $group->lines_deleted,
            durationMs: $group->duration_ms,
            questions: (int) ($group->questions ?? 0),
            escalations: (int) ($group->escalations ?? 0),
            subtasks: array_values($group->tasks
                ->map(static fn (Task $task): TaskData => TaskData::fromModel($task))
                ->all()),
        );
    }

    /** The workspace's own active Route, as the URL a preview opens; null until the workspace has one. */
    private static function previewUrl(Task $group, mixed $taskable): ?string
    {
        if (! $taskable instanceof Instance || $taskable->task_workspace_owner_id !== $group->id) {
            return null;
        }
        $route = $taskable->authoritativeRoute();

        return $route instanceof Route ? "https://{$route->domain}" : null;
    }

    private static function vmPower(Task $group): ?VmPower
    {
        if ($group->parent_id !== null) {
            return null;
        }
        // A task VM reports running while ready and destroyed once gone. The capacity wait reason names other states.
        $vm = TaskVm::query()->where('group_id', $group->id)->orderByDesc('id')->first();
        if ($vm instanceof TaskVm) {
            return match ($vm->state) {
                TaskVmState::Ready => VmPower::Running,
                TaskVmState::Parked => $vm->stop_confirmed_at === null ? null : VmPower::Stopped,
                TaskVmState::Destroyed => VmPower::Destroyed,
                default => null,
            };
        }

        return null;
    }
}
