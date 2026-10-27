<?php

declare(strict_types=1);

namespace App\Models;

use App\Actions\Tasks\RemoveTaskWorkspaceAction;
use App\Domain\Tasks\AssistanceKind;
use App\Domain\Tasks\TaskableType;
use App\Domain\Tasks\TaskAgentDefaults;
use App\Domain\Tasks\TaskAssistance;
use App\Domain\Tasks\TaskBroadcastObserver;
use App\Domain\Tasks\TaskDeliverable;
use App\Domain\Tasks\TaskFinalReview;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskHierarchyException;
use App\Domain\Tasks\TaskLevelStatusCast;
use App\Domain\Tasks\TaskMergeStatus;
use App\Domain\Tasks\TaskOrchestration;
use App\Domain\Tasks\TaskQuestions;
use App\Domain\Tasks\TaskStatus;
use App\Domain\Tasks\TaskTopology;
use App\Domain\Tasks\TaskType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * @property-read AgentThread|null $implementerThread
 * @property-read Task|null $parent
 * @property TaskType $type
 * @property string|null $completion_summary
 * @property int $id
 * @property int|null $parent_id
 * @property int $position
 * @property int|null $continuation_of_task_id
 * @property int $completion_attempt
 * @property int|null $completion_handoff_comment_id
 * @property int|null $completion_reminder_attempt
 * @property string|null $completion_reminder_input_id
 * @property int|null $completion_handoff_attempt
 * @property string|null $completion_handoff_turn_id
 * @property string|null $handoff_reopen_reason why Orbit reopened the handoff, for the implementer's next reminder
 * @property int $review_attempt
 * @property int|null $review_handled_comment_id
 * @property int|null $review_reminder_attempt
 * @property string|null $review_reminder_input_id
 * @property int|null $review_notified_attempt
 * @property string|null $review_notified_turn_id
 * @property string|null $review_workspace_head
 * @property int|null $review_workspace_lease_id
 * @property string|null $review_workspace_tree
 * @property bool $assistance_requested
 * @property AssistanceKind|null $assistance_kind
 * @property string|null $assistance_question
 * @property string|null $assistance_reason
 * @property int $communication_failures
 * @property int|null $ended_pr_notice_thread_id
 * @property string|null $ended_pr_notice_key
 * @property string|null $ended_pr_notice_state
 * @property array{thread_id: int, source_turn_id: string, key: string, due_at: string, history: list<string>}|null $provider_retry
 * @property int $pi_restart_resumes
 * @property string|null $pi_restart_key
 * @property int|null $pi_restart_thread_id
 * @property string|null $pi_restart_source_turn_id
 * @property string|null $pi_restart_reservation
 * @property string|null $pi_restart_session_revision
 * @property int|null $deliverable_correction_check_id
 * @property array{comment_id: int, thread_id: int|null, key: string, message: string, state: string, caller_node_id?: int|null, caller_ip?: string|null, request_id?: string}|null $deliverable_correction_resume
 * @property string|null $pending_resolution_message
 * @property string|null $pending_resolution_reason
 * @property int|null $pending_resolution_comment_id
 * @property int|null $resolution_delivered_comment_id
 * @property string $title
 * @property string $brief
 * @property TaskOrchestration|null $orchestration
 * @property TaskStatus|TaskGroupStatus $status
 * @property int $project_id
 * @property string|null $taskable_type
 * @property int|null $taskable_id
 * @property string|null $pr_url
 * @property string|null $pr_branch
 * @property TaskMergeStatus|null $merge_status
 * @property string|null $merge_reason
 * @property string|null $merged_sha
 * @property string|null $watched_pr_url
 * @property int|null $watched_pr_number
 * @property string|null $watched_pr_state
 * @property string|null $watched_pr_completion
 * @property bool|null $preview
 * @property string $implementer_model
 * @property string $reviewer_model
 * @property int|null $reviewer_agent_thread_id
 * @property string|null $capacity_wait_reason
 * @property string $implementer_agent_driver
 * @property string $reviewer_agent_driver
 * @property Carbon|null $agent_unavailable_since
 * @property Carbon|null $agent_unavailable_notified_at
 * @property Carbon|null $reserved_at
 * @property int|null $implementer_agent_thread_id
 * @property int|null $tokens
 * @property int|null $lines_added
 * @property int|null $lines_deleted
 * @property int|null $line_diff
 * @property int|null $duration_ms
 * @property int $questions
 * @property int $escalations
 * @property int|null $direction_relay_comment_id
 * @property int|null $consult_comment_id
 * @property string|null $direction_answer_key
 * @property string|null $direction_answer_source_turn_id
 * @property Carbon|null $started_at
 * @property string|null $subtask_start_commit
 * @property string|null $fixup_problem
 * @property string|null $fixup_head_sha
 * @property TaskTopology $topology
 * @property list<array<string, string|bool|list<string>>>|null $deliverables
 * @property Carbon|null $settled_at
 * @property CarbonImmutable|null $ready_at
 * @property CarbonImmutable|null $ended_at
 * @property CarbonImmutable|null $merged_at
 * @property-read Task $parent
 * @property-read Collection<int, Task> $children
 * @property-read Collection<int, Task> $tasks
 * @property-read Project $project
 * @property-read Instance|Model|null $taskable
 * @property-read AgentThread|null $reviewerThread
 */
#[ObservedBy([TaskBroadcastObserver::class])]
final class Task extends Model
{
    /** @var list<string> */
    private const array TOP_LEVEL_COLUMNS = [
        'orchestration',
        'watched_pr_completion',
        'watched_pr_url',
        'watched_pr_number',
        'watched_pr_state',
        'project_id',
        'taskable_type',
        'taskable_id',
        'pr_url',
        'preview',
        'implementer_model',
        'reviewer_model',
        'reviewer_agent_thread_id',
        'implementer_agent_driver',
        'reviewer_agent_driver',
        'agent_unavailable_since',
        'agent_unavailable_notified_at',
        'reserved_at',
        'capacity_wait_reason',
        'pr_branch',
        'merge_status',
        'merge_reason',
        'merged_sha',
        'ready_at',
        'ended_at',
        'merged_at',
    ];

    /** @var list<string> */
    private const array SUBTASK_COLUMNS = [
        'ended_pr_notice_thread_id',
        'ended_pr_notice_key',
        'ended_pr_notice_state',
        'position',
        'implementer_agent_thread_id',
        'type',
        'completion_summary',
        'subtask_start_commit',
        'completion_attempt',
        'completion_handoff_comment_id',
        'completion_reminder_attempt',
        'completion_reminder_input_id',
        'completion_handoff_attempt',
        'completion_handoff_turn_id',
        'handoff_reopen_reason',
        'review_attempt',
        'review_handled_comment_id',
        'review_reminder_attempt',
        'review_reminder_input_id',
        'review_notified_attempt',
        'review_notified_turn_id',
        'review_workspace_head',
        'review_workspace_tree',
        'review_workspace_lease_id',
        'deliverables',
        'topology',
        'deliverable_correction_check_id',
        'deliverable_correction_resume',
        'fixup_problem',
        'fixup_head_sha',
        'communication_failures',
        'pending_resolution_message', 'pending_resolution_reason',
        'pending_resolution_comment_id',
        'resolution_delivered_comment_id',
        'direction_relay_comment_id',
        'consult_comment_id',
        'direction_answer_key',
        'direction_answer_source_turn_id',
        'provider_retry',
        'pi_restart_resumes',
        'pi_restart_key',
        'pi_restart_thread_id',
        'pi_restart_source_turn_id',
        'pi_restart_reservation',
        'pi_restart_session_revision',
        'continuation_of_task_id',
    ];

    /** @var list<string> */
    #[\Override]
    protected $fillable = [
        'orchestration',
        'watched_pr_completion',
        'watched_pr_url',
        'watched_pr_number',
        'watched_pr_state',
        'type', 'completion_summary',
        'parent_id',
        'continuation_of_task_id',
        'position',
        'title',
        'brief',
        'status',
        'implementer_agent_thread_id',
        'tokens',
        'questions',
        'escalations',
        'line_diff',
        'lines_added',
        'lines_deleted',
        'duration_ms',
        'started_at',
        'subtask_start_commit',
        'fixup_problem',
        'fixup_head_sha',
        'deliverables',
        'topology',
        'deliverable_correction_check_id',
        'deliverable_correction_resume',
        'settled_at',
        'completion_attempt',
        'completion_handoff_comment_id',
        'completion_reminder_attempt',
        'completion_reminder_input_id',
        'completion_handoff_attempt',
        'completion_handoff_turn_id',
        'handoff_reopen_reason',
        'review_attempt',
        'review_handled_comment_id',
        'review_reminder_attempt',
        'review_reminder_input_id',
        'review_notified_attempt',
        'review_notified_turn_id',
        'review_workspace_head',
        'review_workspace_tree',
        'review_workspace_lease_id',
        'assistance_requested', 'assistance_kind', 'assistance_question', 'assistance_reason', 'communication_failures', 'pending_resolution_message', 'pending_resolution_reason', 'pending_resolution_comment_id', 'resolution_delivered_comment_id', 'direction_relay_comment_id', 'consult_comment_id', 'direction_answer_key', 'direction_answer_source_turn_id',
        'ended_pr_notice_thread_id', 'ended_pr_notice_key', 'ended_pr_notice_state',
        'provider_retry', 'pi_restart_resumes', 'pi_restart_key', 'pi_restart_thread_id', 'pi_restart_source_turn_id', 'pi_restart_reservation', 'pi_restart_session_revision',
        'project_id',
        'taskable_type',
        'taskable_id',
        'pr_url',
        'pr_branch',
        'merge_status',
        'merge_reason',
        'merged_sha',
        'merged_at',
        'preview',
        'implementer_model',
        'reviewer_model',
        'reviewer_agent_thread_id',
        'implementer_agent_driver',
        'reviewer_agent_driver',
        'agent_unavailable_since',
        'agent_unavailable_notified_at',
        'reserved_at',
        'capacity_wait_reason',
    ];

    #[\Override]
    protected static function booted(): void
    {
        self::addGlobalScope('subtask', static function (Builder $query): void {
            $query->whereNotNull('parent_id');
        });

        self::saving(static function (Task $task): void {
            $task->ensureParentId();
            $task->applyLevelDefaults();
            $task->guardHierarchy();
            $task->guardWorkspace();
            $task->guardStatus();
            $task->stampLifecycle();
        });

        self::saved(static function (Task $task): void {
            $task->clearAssistanceWhenEnded();
            $task->settleQuestionsWhenEnded();
        });
    }

    /**
     * A top-level task has a loaded null parent. A subtask has a loaded parent id.
     * The status cast and the broadcast observer both use this.
     */
    public function isTopLevel(): bool
    {
        return $this->resolvedParentId() === null;
    }

    public function subtaskStatus(): TaskStatus
    {
        $status = $this->status;

        if (! $status instanceof TaskStatus) {
            throw new LogicException('A subtask status must use the subtask status names.');
        }

        return $status;
    }

    public function groupStatus(): TaskGroupStatus
    {
        $status = $this->status;

        if (! $status instanceof TaskGroupStatus) {
            throw new LogicException('A top-level task status must use the top-level status names.');
        }

        return $status;
    }

    public function groupOrchestration(): TaskOrchestration
    {
        $mode = array_key_exists('orchestration', $this->attributes)
            ? $this->orchestration
            : self::topLevel()->whereKey($this->getKey())->value('orchestration');

        return $mode instanceof TaskOrchestration ? $mode : throw new LogicException('A task group must have an orchestration mode.');
    }

    public function requireGroupId(): int
    {
        $groupId = $this->resolvedParentId();

        if (! is_int($groupId)) {
            throw new LogicException('A subtask is missing its task.');
        }

        return $groupId;
    }

    /**
     * ADR 0203: subtasks that are not final reviews. A legacy row may have no type.
     *
     * @param  Builder<Task>  $query
     */
    public function scopeWithoutFinalReviews(Builder $query): void
    {
        $query->where(static fn (Builder $type) => $type->whereNull('type')->orWhere('type', '!=', TaskType::FinalReview->value));
    }

    /** Whether the task's pull request merged, by Orbit or by a person ([Task insights](/reference/tasks#task-insights)). */
    public function pullRequestMerged(): bool
    {
        return $this->merged_at !== null;
    }

    /** @param  Builder<Task>  $query */
    public function scopeTopLevel(Builder $query): void
    {
        $query->withoutGlobalScope('subtask')->whereNull('parent_id');
    }

    /** @param  Builder<Task>  $query */
    public function scopeRemovalFailure(Builder $query): void
    {
        $query->where($query->qualifyColumn('assistance_requested'), true)
            ->where($query->qualifyColumn('assistance_kind'), AssistanceKind::RemovalFailure);
    }

    /** A claim no longer owns provisioning once its workspace is attached or its reservation expires.
     *
     * @param  Builder<Task>  $query
     */
    public function scopeWorkspaceClaimReleased(Builder $query): void
    {
        $query->where(static fn (Builder $claim) => $claim
            ->whereNull($query->qualifyColumn('reserved_at'))
            ->orWhere($query->qualifyColumn('reserved_at'), '<=', RemoveTaskWorkspaceAction::reservationCutoff())
            ->orWhereHas('workspace', static fn (Builder $workspace) => $workspace->whereColumn('instances.id', 'tasks.taskable_id')));
    }

    /** @param  Builder<Task>  $query */
    public function scopeWorkspaceRemovalRetry(Builder $query): void
    {
        $query->workspaceClaimReleased()->whereIn('status', TaskGroupStatus::workspaceRemovalStatuses())
            ->where(static fn (Builder $eligible) => $eligible
                ->whereIn('status', TaskGroupStatus::endedStatuses())
                ->orWhere(static fn (Builder $published) => $published
                    ->where('orchestration', TaskOrchestration::Tick)
                    ->whereNotNull('pr_url')->where('pr_url', '!=', '')->removalFailure()
                    ->whereDoesntHave('tasks', static fn (Builder $subtasks) => $subtasks->whereIn('status', [TaskStatus::Todo, TaskStatus::Running, TaskStatus::Reviewing]))));
    }

    /** @param Builder<Task> $query */
    public function scopeWorkspaceDestructionRetry(Builder $query): void
    {
        $query->workspaceRemovalRetry()->where(static fn (Builder $eligible) => $eligible
            ->removalFailure()->orWhere('orchestration', TaskOrchestration::External));
    }

    /** @return BelongsTo<Task, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id')->withoutGlobalScope('subtask');
    }

    /** @return HasMany<Task, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('position')->orderBy('id');
    }

    /**
     * Subtasks of a top-level task, in position order. Same rows as children().
     *
     * @return HasMany<Task, $this>
     */
    public function tasks(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('position')->orderBy('id');
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    /** @return MorphTo<Model, $this> */
    public function taskable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * The workspace this group owns: the Instance that provisioning created for it. It is the only record of task
     * workspace ownership. An Instance name or branch never makes an Instance a group's workspace.
     *
     * @return HasOne<Instance, $this>
     */
    public function workspace(): HasOne
    {
        return $this->hasOne(Instance::class, 'task_workspace_owner_id');
    }

    /** @return BelongsTo<AgentThread, $this> */
    public function reviewerThread(): BelongsTo
    {
        return $this->belongsTo(AgentThread::class, 'reviewer_agent_thread_id');
    }

    /** ADR 0203: whether this top-level task holds every push until a final review approves it, and merges reviewed heads. */
    public function reviewsBeforePush(): bool
    {
        // An incoming pull request keeps these rules until it ends, so its branch never gets an unreviewed push.
        return $this->parent_id === null
            && ($this->project->reviewsAndMerges() || $this->reviewsIncomingPullRequest());
    }

    /** ADR 0203: an incoming pull request that Orbit reviews, not a pull request Orbit opened. */
    public function reviewsIncomingPullRequest(): bool
    {
        return $this->parent_id === null && is_string($this->pr_branch) && $this->pr_branch !== '';
    }

    public function isFinalReview(): bool
    {
        return $this->type === TaskType::FinalReview;
    }

    /** A subtask an operator added: not a Gateway fixup and not a final review. Its completion opens a new fixup window. */
    public function isOperatorWork(): bool
    {
        return $this->fixup_problem === null && ! $this->isFinalReview();
    }

    /** A fixup the settling watcher appended for a conflict, a failed check, or trusted feedback. */
    public function isSettlingFixup(): bool
    {
        return is_string($this->fixup_problem) && $this->fixup_problem !== '' && $this->fixup_problem !== TaskFinalReview::FixupProblem;
    }

    /** @return HasMany<TaskReviewedCommit, $this> */
    public function reviewedCommits(): HasMany
    {
        return $this->hasMany(TaskReviewedCommit::class, 'task_id')->orderBy('id');
    }

    /** @return BelongsTo<AgentThread, $this> */
    public function implementerThread(): BelongsTo
    {
        return $this->belongsTo(AgentThread::class, 'implementer_agent_thread_id');
    }

    /** @return HasMany<TaskCheck, $this> */
    public function checks(): HasMany
    {
        return $this->hasMany(TaskCheck::class);
    }

    /** @return HasMany<TaskComment, $this> */
    public function comments(): HasMany
    {
        return $this->hasMany(TaskComment::class);
    }

    /**
     * Whether no other subtask of the group is still to be done.
     */
    public function isLastSubtask(): bool
    {
        return self::query()
            ->where('parent_id', $this->requireGroupId())
            ->whereKeyNot($this->id)
            ->whereIn('status', [TaskStatus::Todo, TaskStatus::Reserved, TaskStatus::Running])
            ->doesntExist();
    }

    /**
     * Whether this approval opens the group's pull request.
     * After a pull request URL is stored, the approval pushes to that pull request and does not require pull request fields (ADR 0164).
     */
    public function opensPullRequest(): bool
    {
        // ADR 0203: in a review-and-merge task only the final review opens the pull request.
        if ($this->type === TaskType::FinalReview) {
            $url = $this->parent()->value('pr_url');

            return ! is_string($url) || $url === '';
        }
        if ($this->parent->reviewsBeforePush()) {
            return false;
        }
        if (! $this->isLastSubtask()) {
            return false;
        }

        $url = $this->parent()->value('pr_url');

        return ! is_string($url) || $url === '';
    }

    /**
     * ADR 0133: the typed items this subtask must deliver. Empty for subtasks created before deliverables existed.
     *
     * @return list<TaskDeliverable>
     */
    public function deliverableList(): array
    {
        return TaskDeliverable::listFrom($this->deliverables);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'orchestration' => TaskOrchestration::class,
            'type' => TaskType::class,
            'position' => 'integer',
            'status' => TaskLevelStatusCast::class,
            'merge_status' => TaskMergeStatus::class,
            'preview' => 'boolean',
            'watched_pr_number' => 'integer',
            'agent_unavailable_since' => 'datetime',
            'agent_unavailable_notified_at' => 'datetime',
            'reserved_at' => 'datetime',
            'tokens' => 'integer',
            'line_diff' => 'integer',
            'lines_added' => 'integer',
            'lines_deleted' => 'integer',
            'duration_ms' => 'integer',
            'questions' => 'integer',
            'escalations' => 'integer',
            'direction_relay_comment_id' => 'integer',
            'consult_comment_id' => 'integer',
            'started_at' => 'immutable_datetime',
            'settled_at' => 'immutable_datetime',
            'ready_at' => 'immutable_datetime',
            'ended_at' => 'immutable_datetime',
            'merged_at' => 'immutable_datetime',
            'deliverables' => 'array',
            'topology' => TaskTopology::class,
            'completion_attempt' => 'integer',
            'completion_handoff_attempt' => 'integer',
            'completion_handoff_comment_id' => 'integer',
            'completion_reminder_attempt' => 'integer',
            'review_attempt' => 'integer',
            'review_workspace_lease_id' => 'integer',
            'review_handled_comment_id' => 'integer',
            'review_reminder_attempt' => 'integer',
            'review_notified_attempt' => 'integer',
            'assistance_requested' => 'boolean',
            'assistance_kind' => AssistanceKind::class,
            'communication_failures' => 'integer',
            'provider_retry' => 'array',
            'pi_restart_resumes' => 'integer',
            'pi_restart_thread_id' => 'integer',
            'ended_pr_notice_thread_id' => 'integer',
            'deliverable_correction_check_id' => 'integer',
            'deliverable_correction_resume' => 'array',
            'pending_resolution_comment_id' => 'integer',
            'resolution_delivered_comment_id' => 'integer',
        ];
    }

    /** Keep lifecycle changes and their assistance Activity in one transaction. */
    #[\Override]
    public function save(array $options = []): bool
    {
        if ($this->exists && $this->isDirty('orchestration')) {
            throw new LogicException('A task group cannot change its orchestration.');
        }
        if ($this->isDirty(TaskAssistance::Fields)) {
            throw new LogicException('Assistance can only be changed through TaskAssistance::apply.');
        }

        return $this->getConnection()->transaction(fn (): bool => parent::save($options));
    }

    private function applyLevelDefaults(): void
    {
        if ($this->parentKey() === null) {
            if (! $this->exists) {
                $this->fillIfMissing(['orchestration' => TaskOrchestration::Tick->value]);
            }
            $this->fillIfMissing([
                'status' => TaskGroupStatus::Backlog->value,
                'implementer_agent_driver' => 'pi',
                'reviewer_agent_driver' => 'pi',
                'preview' => false,
                'assistance_requested' => false,
                'implementer_model' => TaskAgentDefaults::ImplementerModel,
                'reviewer_model' => TaskAgentDefaults::ReviewerModel,
            ]);

            return;
        }

        $this->fillIfMissing([
            'status' => TaskStatus::Todo->value,
            'type' => TaskType::Implementation->value,
            'assistance_requested' => false,
            'pi_restart_resumes' => 0,
            'completion_attempt' => 1,
            'review_attempt' => 1,
            'communication_failures' => 0,
        ]);
    }

    /** @param  array<string, mixed>  $defaults */
    private function fillIfMissing(array $defaults): void
    {
        foreach ($defaults as $column => $value) {
            if (! array_key_exists($column, $this->attributes) || $this->attributes[$column] === null) {
                $this->setAttribute($column, $value);
            }
        }
    }

    /** A task holds only the workspace it owns: the Instance that provisioning created with this task as its owner. */
    private function guardWorkspace(): void
    {
        if ($this->parentKey() !== null || $this->getAttribute('taskable_id') === null || ! $this->isDirty(['taskable_type', 'taskable_id'])) {
            return;
        }

        $owned = $this->exists
            && $this->getAttribute('taskable_type') === TaskableType::Instance
            && Instance::query()->whereKey($this->getAttribute('taskable_id'))->where('task_workspace_owner_id', $this->getKey())->exists();

        if (! $owned) {
            throw new LogicException('A task holds only the workspace it owns.');
        }
    }

    private function guardHierarchy(): void
    {
        $parentId = $this->parentKey();

        if ($parentId === null) {
            $this->rejectColumns(self::SUBTASK_COLUMNS, 'top-level task');

            return;
        }

        $this->rejectColumns(self::TOP_LEVEL_COLUMNS, 'subtask');

        $key = self::integerOrNull($this->getKey());

        if ($this->exists && $key === $parentId) {
            throw TaskHierarchyException::nested();
        }

        $parent = DB::table('tasks')->where('id', $parentId)->first(['id', 'parent_id']);

        if ($parent === null || $parent->parent_id !== null) {
            throw TaskHierarchyException::nested();
        }

        if ($this->exists && $this->children()->exists()) {
            throw TaskHierarchyException::nested();
        }
    }

    /** @param  list<string>  $columns */
    private function rejectColumns(array $columns, string $level): void
    {
        foreach ($columns as $column) {
            if (array_key_exists($column, $this->attributes) && $this->attributes[$column] !== null) {
                throw TaskHierarchyException::column($column, $level);
            }
        }
    }

    /**
     * The parent id, loaded from the row when a partial select omitted it.
     * A model that has neither the attribute nor a row fails instead of guessing a level.
     */
    private function resolvedParentId(): ?int
    {
        if (! array_key_exists('parent_id', $this->attributes)) {
            $this->loadParentId();
        }

        return self::integerOrNull($this->attributes['parent_id']);
    }

    /**
     * A new row that omits parent_id stores null. Record that before the level is read.
     * A saved row loads the stored value instead of treating the missing attribute as either level.
     */
    private function ensureParentId(): void
    {
        if (array_key_exists('parent_id', $this->attributes)) {
            return;
        }

        if ($this->exists) {
            $this->loadParentId();

            return;
        }

        $this->attributes['parent_id'] = null;
    }

    private function loadParentId(): void
    {
        if (! $this->exists) {
            throw new LogicException('A task needs parent_id before its level can be decided.');
        }

        $key = $this->getKey();

        if (! is_int($key) && ! is_string($key)) {
            throw new LogicException('A task needs its id before its level can be decided.');
        }

        $row = DB::table($this->getTable())->where('id', $key)->first(['id', 'parent_id']);

        if ($row === null) {
            throw new LogicException("Task [{$key}] has no row, so its level cannot be decided.");
        }

        $stored = (array) $row;

        if (! array_key_exists('parent_id', $stored)) {
            throw new LogicException("Task [{$key}] has no row, so its level cannot be decided.");
        }

        $this->attributes['parent_id'] = $stored['parent_id'];
        $this->syncOriginalAttribute('parent_id');
    }

    /** The writer owns the assistance rule for every status transition. */
    private function clearAssistanceWhenEnded(): void
    {
        if ($this->wasChanged('status')) {
            TaskAssistance::apply($this, reconcileStatus: true);
        }
    }

    /**
     * An ended group or a completed or cancelled subtask leaves no question open or escalated. They become `superseded`.
     * A query-builder update of subtask statuses skips this hook, so it relies on the task's own update.
     */
    private function settleQuestionsWhenEnded(): void
    {
        if (! $this->wasChanged('status')) {
            return;
        }

        $status = $this->status;
        $reason = $status instanceof TaskGroupStatus && in_array($status, TaskGroupStatus::endedStatuses(), true)
            ? 'Task '.$status->value.'.'
            : match ($status) {
                TaskStatus::Completed => 'Subtask completed.',
                TaskStatus::Cancelled => 'Subtask cancelled.',
                default => null,
            };

        if ($reason !== null) {
            TaskQuestions::settle($this, $reason);
        }
    }

    private function guardStatus(): void
    {
        $status = $this->attributes['status'] ?? null;

        if (! is_string($status) || $status === '') {
            return;
        }

        if ($this->resolvedParentId() === null) {
            TaskGroupStatus::from($status);

            return;
        }

        TaskStatus::from($status);
    }

    /** Records once when a task first becomes ready and when it ends ([Lifecycle times](/reference/tasks#lifecycle-times)). */
    private function stampLifecycle(): void
    {
        if ($this->resolvedParentId() !== null || ! $this->isDirty('status')) {
            return;
        }
        $value = $this->attributes['status'] ?? null;
        $status = is_string($value) ? TaskGroupStatus::tryFrom($value) : null;
        if ($status === TaskGroupStatus::Todo) {
            $this->ready_at ??= now()->toImmutable();
        }
        if (in_array($status, TaskGroupStatus::endedStatuses(), true)) {
            $this->ended_at ??= now()->toImmutable();
        }
    }

    private function parentKey(): ?int
    {
        return $this->resolvedParentId();
    }

    private static function integerOrNull(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && is_numeric($value)) {
            return (int) $value;
        }

        return null;
    }
}
