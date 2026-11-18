<?php

declare(strict_types=1);

namespace App\Data\Tasks;

use App\Domain\Compute\VmPower;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\AssistanceKind;
use App\Domain\Tasks\TaskAgent;
use App\Domain\Tasks\TaskGroupActivity;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskOrchestration;
use App\Domain\Tasks\TaskPrMode;
use App\Domain\Tasks\TaskPrModeChoice;
use App\Domain\Tasks\TaskReviewMode;
use App\Domain\Tasks\TaskThreadRole;
use App\Models\Instance;
use App\Models\Route;
use App\Models\Task;
use Illuminate\Database\Eloquent\Collection;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Lazy;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

#[MapOutputName(SnakeCaseMapper::class)]
final class TaskGroupData extends Data
{
    /** The columns a list row reads (docs/reference/tasks.md#list-tasks). */
    public const array ListColumns = [
        'id', 'parent_id', 'project_id', 'taskable_type', 'taskable_id', 'title', 'brief', 'status', 'orchestration', 'review_mode', 'preview',
        'current_recipe', 'reviewer_agent_thread_id', 'pr_url', 'watched_pr_url', 'watched_pr_number', 'watched_pr_state', 'pr_branch', 'merge_status',
        'merge_reason', 'merged_sha', 'assistance_requested', 'assistance_kind', 'assistance_question', 'assistance_reason', 'capacity_wait_reason',
        'implementer_agent_provider', 'implementer_agent_model', 'implementer_agent_effort', 'reviewer_agent_provider', 'reviewer_agent_model',
        'reviewer_agent_effort', 'pr_mode', 'issue_number', 'tokens', 'line_diff', 'lines_added', 'lines_deleted', 'duration_ms', 'questions', 'escalations',
        'started_at', 'settled_at', 'ended_at', 'created_at', 'updated_at',
    ];

    /**
     * @param  list<TaskData>  $subtasks
     * @param  array<string, array<string, array{value: string, source: string}>>  $resolvedAgents  each role's copy turn agent and where each value comes from (TaskAgent)
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
        /** Always null: no task VM exists. The field stays for older clients (docs/reference/compute-drivers.md#vm-power). */
        public ?VmPower $vmPower = null,
        public bool $preview = false,
        public bool $currentRecipe = false,
        public TaskReviewMode $review = TaskReviewMode::Manual,
        public ?TaskReviewAndMergeData $reviewAndMerge = null,
        public TaskOrchestration $orchestration = TaskOrchestration::External,
        public ?string $lastActivityAt = null,
        public ?string $previewUrl = null,
        public ?string $implementerProvider = null,
        public ?string $implementerEffort = null,
        public ?string $reviewerProvider = null,
        public ?string $reviewerEffort = null,
        public array $resolvedAgents = [],
        public ?TaskPrMode $prMode = null,
        public ?string $prModeSource = null,
        /** The GitHub issue the group's pull request closes (docs/reference/tasks.md#closes-the-issue). */
        public ?int $issue = null,
        /** Show only: a list row leaves it out, so its reads never run for a list (docs/reference/tasks.md#publication-state). */
        public Lazy|TaskPublicationData|null $publication = null,
    ) {}

    /** @return array<string, mixed> */
    public function toListArray(bool $compact = false): array
    {
        $this->exclude('publication');
        if ($compact) {
            return $this->toCompactArray();
        }
        $data = $this->toArray();
        $data['subtasks'] = array_map(static fn (TaskData $task): array => $task->toListArray(), $this->subtasks);

        return $data;
    }

    /** @return array<string, mixed> */
    public function toCompactArray(): array
    {
        $data = $this->toArray();
        unset($data['brief'], $data['assistance_question'], $data['assistance_reason']);
        $data['subtasks'] = array_map(static fn (TaskData $task): array => $task->toCompactArray(), $this->subtasks);

        return $data;
    }

    /** What a group's data reads beyond its own columns. A list loads these first, with the columns a row shows. */
    public const array Relations = ['project', 'tasks.latestCheck', 'reviewedCommits', 'taskable'];

    public static function fromModel(Task $group): self
    {
        return self::fromModels(new Collection([$group]))[0];
    }

    /**
     * The data of many groups, with every read they share done once for all of them: show reads one group, a list a page.
     *
     * @param  Collection<int, Task>  $groups
     * @return list<self>
     */
    public static function fromModels(Collection $groups): array
    {
        $groups->loadMissing(self::Relations);
        $activity = TaskGroupActivity::of(array_values($groups->modelKeys()));

        return array_values($groups->map(static fn (Task $group): self => self::fromLoaded($group, $activity[$group->id]))->all());
    }

    /**
     * @param  string|null  $lastActivityAt  when an Operation on the group was last created, started or ended
     */
    private static function fromLoaded(Task $group, ?string $lastActivityAt): self
    {
        $taskable = $group->taskable;
        $status = $group->groupStatus();
        // Every group shows its mode from creation on: the mode needs no copy (docs/reference/tasks.md#where-the-mode-comes-from).
        try {
            $mode = TaskPrModeChoice::of($group);
        } catch (ResourceOperationException) {
            $mode = null;
        }

        return new self(
            lastActivityAt: $lastActivityAt,
            previewUrl: self::previewUrl($group, $taskable),
            orchestration: $group->groupOrchestration(),
            capacityWaitReason: $group->capacity_wait_reason,
            preview: $group->preview ?? false,
            currentRecipe: $group->current_recipe ?? false,
            review: $group->review_mode,
            reviewAndMerge: TaskReviewAndMergeData::fromModel($group),
            prMode: $mode?->mode,
            prModeSource: $mode?->source,
            issue: $group->issue_number,
            publication: Lazy::create(static fn (): ?TaskPublicationData => TaskPublicationData::fromModel($group))->defaultIncluded(),

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
            // The copy turn agent values the group names; the task VM columns implementer_model and reviewer_model are not shown.
            implementerModel: $group->implementer_agent_model,
            reviewerModel: $group->reviewer_agent_model,
            implementerProvider: $group->implementer_agent_provider,
            implementerEffort: $group->implementer_agent_effort,
            reviewerProvider: $group->reviewer_agent_provider,
            reviewerEffort: $group->reviewer_agent_effort,
            resolvedAgents: [
                TaskThreadRole::Implementer->value => TaskAgent::resolve($group, TaskThreadRole::Implementer),
                TaskThreadRole::Reviewer->value => TaskAgent::resolve($group, TaskThreadRole::Reviewer),
            ],
            tokens: $group->tokens,
            lineDiff: $group->line_diff,
            linesAdded: $group->lines_added,
            linesDeleted: $group->lines_deleted,
            durationMs: $group->duration_ms,
            questions: (int) ($group->questions ?? 0),
            escalations: (int) ($group->escalations ?? 0),
            subtasks: TaskData::fromModels($group->tasks),
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
}
