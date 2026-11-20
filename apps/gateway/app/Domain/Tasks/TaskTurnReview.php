<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Domain\Operations\OperationKinds;
use App\Domain\Operations\OperationStatus;
use App\Models\Operation;
use App\Models\Task;
use App\Models\TaskCheck;
use App\Models\TaskHandoff;
use Illuminate\Database\Eloquent\Builder;

/**
 * What a reviewer turn's prompt names beyond the briefs (docs/reference/tasks.md#run-an-external-turn): the check it reviews, that
 * check's commit, the base it compared against and each deliverable's outcome, the review round with the commit the last finished
 * review judged, and the implementer's handoff note.
 */
final readonly class TaskTurnReview
{
    /**
     * @param  string  $start  the subtask's start that the check was admitted with
     * @param  string|null  $merged  the default head that the check merged into the start before it compared: the default branch's own
     *                               changes are never the subtask's (docs/reference/tasks.md#the-handoff-check-merges-main-first)
     * @param  array<string, string>  $results  each deliverable's outcome in the check, by its id
     * @param  string|null  $previous  the commit of the latest finished reviewer turn on the subtask; null on the first review
     */
    public function __construct(
        public int $check,
        public string $sha,
        public string $start,
        public ?string $merged,
        public int $round,
        public ?string $previous,
        public array $results,
        public ?string $note,
    ) {}

    /**
     * A finished turn ended its Operation as succeeded and did not end in a question; a turn that failed, was cancelled or asked counts
     * for no round and gives no handoff note. In a gated group a reviewer turn whose review row was rejected counts for no round either.
     *
     * @param  Operation  $owner  the handoff check Operation that recorded CHECK (TaskCheckedCandidate::owner)
     */
    public static function of(Task $group, Task $subtask, TaskCheck $check, Operation $owner): self
    {
        $reviews = self::finished($group, $subtask, TaskThreadRole::Reviewer)
            // In a gated group a review counts only once its gate accepted it (docs/reference/tasks.md#the-review-gate).
            ->when($group->handoffs_gated, static fn (Builder $turns): Builder => $turns->whereIn('id', TaskHandoff::query()
                ->where('task_id', $subtask->id)->where('type', TaskHandoffType::Review->value)->where('rejected', false)->select('operation_id')))
            ->get();
        $handoff = self::finished($group, $subtask, TaskThreadRole::Implementer)->first();
        $note = $handoff?->result['reply'] ?? null;
        $results = [];
        foreach (is_array($owner->result['deliverables'] ?? null) ? $owner->result['deliverables'] : [] as $deliverable) {
            if (is_array($deliverable) && is_string($deliverable['id'] ?? null) && is_string($deliverable['outcome'] ?? null)) {
                $results[$deliverable['id']] = $deliverable['outcome'];
            }
        }
        $previous = $reviews->first()?->input['candidate'] ?? null;
        $start = is_string($owner->input['base'] ?? null) ? $owner->input['base'] : '';
        // The check merges the default head that its base phase brought in into a start it has; without either it compares with the start.
        $merged = $start !== '' && is_string($owner->result['default_commit'] ?? null) && $owner->result['default_commit'] !== ''
            ? $owner->result['default_commit'] : null;

        return new self($check->id, (string) $check->commit_sha, $start, $merged, $reviews->count() + 1, is_string($previous) ? $previous : null,
            $results, is_string($note) && $note !== '' ? $note : null);
    }

    /** The base as the prompt names it: the start, or the tree of the start with the default head merged in, as the check built it. */
    public function base(): string
    {
        return $this->merged === null ? $this->start : "the tree of `git merge-tree --write-tree {$this->start} {$this->merged}`";
    }

    /** @return Builder<Operation> the role's finished copy turns on the subtask, newest first */
    private static function finished(Task $group, Task $subtask, TaskThreadRole $role): Builder
    {
        return Operation::query()->where('target_type', OperationKinds::TaskGroupTarget)->where('target_id', $group->id)
            ->where('kind', OperationKinds::TasksCopyTurn)->where('status', OperationStatus::Succeeded->value)
            ->where('input->subtask', $subtask->id)->where('input->role', $role->value)
            ->where('result->state', AgentThreadState::Done->value)->orderByDesc('id');
    }
}
