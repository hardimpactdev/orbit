<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Domain\Operations\OperationKinds;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;
use App\Models\Task;
use App\Models\TaskCheck;
use App\Models\TaskHandoff;
use App\Support\ValidatedData;
use JsonException;

/**
 * The handoff gates of a group that left backlog after they shipped (docs/reference/tasks.md#handoffs). The handoff check is the
 * delivery gate: when it ends it writes one delivery row for the implementer turn that handed off, accepted or rejected with one reason.
 * The end of a copy turn is the gate of a review and of a question. The admissions of the next steps read the rows.
 */
final readonly class TaskHandoffs
{
    /** The rejection reasons of a delivery, in the order the gate tries them. */
    public const string NoReceipt = 'no_receipt';

    public const string OtherCommit = 'other_commit';

    public const string FailedPart = 'failed_part';

    public const string ClaimDisproved = 'claim_disproved';

    public const string CheckFailed = 'check_failed';

    /** The rejection reasons of a review, in the order the gate tries them; a review without a receipt is NoReceipt. */
    public const string InvalidReceipt = 'invalid_receipt';

    public const string ChangesWithoutFinding = 'changes_without_finding';

    public const string ApproveWithFinding = 'approve_with_finding';

    /** Where the reviewer writes its review receipt, under the copy's Git directory: `git rev-parse --git-path` names it. */
    public const string ReviewPath = 'orbit-handoff/review.json';

    /** The results `bin/task-check` gives a part. */
    public const array PartResults = ['passed', 'FAILED', 'skipped', 'not selected'];

    /**
     * Writes the delivery row of a handoff check that ended. `$result` is the check's result; `$passed` whether the check's Operation
     * succeeded with the outcome `passed`. A row belongs to one delivery: the subtask's latest implementer turn before the check, when it
     * ended done and has no delivery row yet. A group without the gates, a check that is not a handoff check, a check with no such turn,
     * such as a check before the first turn, a retry after a newer question or failed turn, or a second check of the same turn, writes none.
     *
     * @param  array<string, mixed>  $result
     */
    public static function delivery(Operation $check, array $result, bool $passed): void
    {
        if (($check->input['phase'] ?? null) !== TaskCheckKind::Handoff->value
            || ! (Task::topLevel()->whereKey($check->target_id)->value('handoffs_gated') ?? false)) {
            return;
        }
        $subtask = ValidatedData::integer($check->input['subtask']);
        $turn = Operation::query()->where('target_type', OperationKinds::TaskGroupTarget)->where('target_id', $check->target_id)
            ->where('kind', OperationKinds::TasksCopyTurn)->where('input->subtask', $subtask)
            ->where('input->role', TaskThreadRole::Implementer->value)->where('id', '<', $check->id)->orderByDesc('id')->first();
        if (! $turn instanceof Operation || $turn->status !== OperationStatus::Succeeded || ($turn->result['state'] ?? null) !== AgentThreadState::Done->value
            || TaskHandoff::query()->where('operation_id', $turn->id)->where('type', TaskHandoffType::Delivery->value)->exists()) {
            return;
        }
        $receipts = $turn->result['receipts'] ?? null;
        $receipt = self::receipt(is_array($receipts) ? $receipts['delivery'] ?? null : null);
        TaskHandoff::query()->create(['task_id' => $subtask, 'operation_id' => $turn->id, 'type' => TaskHandoffType::Delivery,
            'receipt' => $receipt, 'task_check_id' => ValidatedData::integer($check->input['check_id']),
            ...self::verdict($receipt, $result, $passed)]);
    }

    /**
     * Refuses a reviewer turn on CHECK of a gated group unless the subtask's latest delivery row is accepted for the commit under review:
     * the commit of the check that row's gate ran.
     */
    public static function requireDelivery(Task $group, Task $subtask, TaskCheck $check): void
    {
        if (! $group->handoffs_gated) {
            return;
        }
        $latest = TaskHandoff::query()->where('task_id', $subtask->id)->where('type', TaskHandoffType::Delivery->value)->orderByDesc('id')->first();
        $gated = $latest?->task_check_id === null ? null : TaskCheck::query()->whereKey($latest->task_check_id)->value('commit_sha');
        if (! $latest instanceof TaskHandoff || $latest->rejected || $check->commit_sha === null || $gated !== $check->commit_sha) {
            throw new ResourceOperationException('tasks.handoff_required',
                'Review requires an accepted delivery for the commit under review: the implementer hands off again and the handoff check runs.', 409);
        }
    }

    /**
     * Writes the handoff row of a copy turn of a gated group that ended: a turn that asked leaves an accepted question row whose
     * receipt holds its questions; a reviewer turn that ended done leaves a review row, accepted or rejected with one reason. A failed
     * turn hands nothing off and writes none; an implementer turn that ended done waits for the delivery gate.
     *
     * @param  array<string, mixed>  $result  the turn's result, its state already one of done, asking_for_input or failed
     */
    public static function turn(Operation $turn, array $result): void
    {
        if ($turn->status !== OperationStatus::Succeeded || ! (Task::topLevel()->whereKey($turn->target_id)->value('handoffs_gated') ?? false)) {
            return;
        }
        $row = ['task_id' => ValidatedData::integer($turn->input['subtask']), 'operation_id' => $turn->id];
        $questions = is_array($result['questions'] ?? null) ? array_values($result['questions']) : [];
        if ($result['state'] === AgentThreadState::AskingForInput->value && $questions !== []) {
            TaskHandoff::query()->create([...$row, 'type' => TaskHandoffType::Question, 'receipt' => ['questions' => $questions]]);
        } elseif ($result['state'] === AgentThreadState::Done->value && ($turn->input['role'] ?? null) === TaskThreadRole::Reviewer->value) {
            $receipts = $result['receipts'] ?? null;
            // The turn's projection already validated the raw receipt and kept the verdict and the number of findings as JSON, or the
            // reason alone (stored()): the row takes its decision.
            $stored = is_array($receipts) ? $receipts['review'] ?? null : null;
            $kept = is_string($stored) ? json_decode($stored, true) : null;
            [$receipt, $reason] = match (true) {
                ! is_string($stored) => [null, self::NoReceipt],
                is_array($kept) => [$kept, ''],
                default => [null, $stored],
            };
            TaskHandoff::query()->create([...$row, 'type' => TaskHandoffType::Review, 'receipt' => $receipt,
                'rejected' => $reason !== '', 'rejection_reason' => $reason]);
        }
    }

    /**
     * Refuses an implementer turn of a gated group after an accepted delivery until an accepted review of it exists: the reviewer
     * judges each delivery before the work goes on. A newer handoff check of the subtask that ended with `tasks.check_conflict` lets the
     * merge turn run: its work is a new delivery. No other check outcome does.
     */
    public static function requireReview(Task $group, Task $subtask): void
    {
        if (! $group->handoffs_gated) {
            return;
        }
        $delivery = TaskHandoff::query()->where('task_id', $subtask->id)->where('type', TaskHandoffType::Delivery->value)
            ->where('rejected', false)->orderByDesc('id')->first();
        if (! $delivery instanceof TaskHandoff
            || TaskHandoff::query()->where('task_id', $subtask->id)->where('type', TaskHandoffType::Review->value)->where('rejected', false)->where('id', '>', $delivery->id)->exists()
            || Operation::query()->where('target_type', OperationKinds::TaskGroupTarget)->where('target_id', $group->id)
                ->where('kind', OperationKinds::TasksCheck)->where('input->subtask', $subtask->id)->where('input->phase', TaskCheckKind::Handoff->value)
                ->where('input->check_id', '>', (int) $delivery->task_check_id)->where('error_code', 'tasks.check_conflict')->exists()) {
            return;
        }

        throw new ResourceOperationException('tasks.handoff_required',
            'The delivery is accepted and waits for its review: a reviewer turn on its check comes before the next implementer turn.', 409);
    }

    /**
     * Refuses to complete a subtask of a gated group, or to publish a check of it, unless the subtask's latest review row is an accepted
     * `approve` that came after its latest delivery row. A publish also needs that review to have judged the commit it publishes: another
     * check of the same commit counts. A gated group without its copy yet has no review, so its subtask does not complete.
     */
    public static function requireApproval(Task $group, Task $subtask, string $step, ?string $commit = null): void
    {
        if (! $group->handoffs_gated) {
            return;
        }
        $review = TaskHandoff::query()->where('task_id', $subtask->id)->where('type', TaskHandoffType::Review->value)->orderByDesc('id')->first();
        $reviewed = $review?->operation_id === null ? null : Operation::query()->find($review->operation_id)?->input['candidate'] ?? null;
        if ($review instanceof TaskHandoff && ! $review->rejected && ($review->receipt['verdict'] ?? null) === 'approve'
            && ($commit === null || $reviewed === $commit)
            && ! TaskHandoff::query()->where('task_id', $subtask->id)->where('type', TaskHandoffType::Delivery->value)->where('id', '>', $review->id)->exists()) {
            return;
        }

        throw new ResourceOperationException('tasks.handoff_required',
            "{$step} requires an accepted approving review of the subtask's latest delivery: a reviewer turn on its check comes first.", 409);
    }

    /**
     * The part table of a receipt or a check: a non-empty list of parts, each with a name, one of PartResults and finite, non-negative
     * seconds. Anything else is none.
     *
     * @return list<array{name: string, result: string, seconds: int|float}>|null
     */
    public static function parts(mixed $parts): ?array
    {
        if (! is_array($parts) || $parts === [] || ! array_is_list($parts)) {
            return null;
        }
        $table = [];
        foreach ($parts as $part) {
            $seconds = is_array($part) ? $part['seconds'] ?? null : null;
            if (! is_array($part) || ! is_string($part['name'] ?? null) || $part['name'] === '' || mb_strlen($part['name']) > 200
                || ! in_array($part['result'] ?? null, self::PartResults, true)
                || ! (is_int($seconds) || (is_float($seconds) && is_finite($seconds))) || $seconds < 0) {
                return null;
            }
            $table[] = ['name' => $part['name'], 'result' => (string) $part['result'], 'seconds' => $seconds];
        }

        return $table;
    }

    /**
     * The delivery receipt as the turn returned it: the commit and the tree `bin/task-check` checked, and its part table. Anything
     * else is none.
     *
     * @return array{commit: string, tree: string, parts: list<array{name: string, result: string, seconds: int|float}>}|null
     */
    private static function receipt(mixed $receipt): ?array
    {
        if (! is_array($receipt)) {
            return null;
        }
        $parts = self::parts($receipt['parts'] ?? null);
        $commit = $receipt['commit'] ?? null;
        $tree = $receipt['tree'] ?? null;
        $sha = '/^[0-9a-f]{40}([0-9a-f]{24})?$/';
        if ($parts === null || ! is_string($commit) || preg_match($sha, $commit) !== 1 || ! is_string($tree) || preg_match($sha, $tree) !== 1) {
            return null;
        }

        return ['commit' => $commit, 'tree' => $tree, 'parts' => $parts];
    }

    /**
     * What a turn's result keeps of its review receipt: the valid receipt as its verdict and its number of
     * findings, as JSON, or the reason alone that its gate rejects it for. The raw text is never kept.
     */
    public static function stored(string $text): string
    {
        [$receipt, $reason] = self::review($text);

        return $reason === '' ? json_encode($receipt, JSON_THROW_ON_ERROR) : $reason;
    }

    /**
     * The one validator of a review receipt: its raw text as the turn returned it, and the gate's reason. It is valid when it is a JSON
     * object of exactly `verdict` (the raw value `approve` or `changes`) and `findings`, a JSON list of objects of exactly a `summary`
     * string that holds a visible character once control characters and terminal formatting are removed, with a finding only for
     * `changes`. JSON objects decode as objects, so an object never passes for a list; text that is not valid UTF-8 JSON, such as half
     * a surrogate pair or Swamp's `too large` and `not utf-8`, is invalid. A valid receipt keeps only its verdict and its number of
     * findings, never a summary; a rejected one keeps nothing.
     *
     * @return array{0: array{verdict: string, findings: int}|null, 1: string}
     */
    private static function review(mixed $text): array
    {
        if (! is_string($text)) {
            return [null, self::NoReceipt];
        }
        try {
            $receipt = json_decode($text, false, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $receipt = null;
        }
        $findings = $receipt instanceof \stdClass ? $receipt->findings ?? null : null;
        $keys = $receipt instanceof \stdClass ? array_keys(get_object_vars($receipt)) : [];
        sort($keys);
        $visible = array_filter(is_array($findings) ? $findings : [], static fn (mixed $finding): bool => $finding instanceof \stdClass
            && array_keys(get_object_vars($finding)) === ['summary'] && is_string($finding->summary)
            && preg_match('/[\p{L}\p{N}\p{P}\p{S}]/u', (string) preg_replace('/\e\[[0-?]*[ -\/]*[@-~]/', '', $finding->summary)) === 1);
        if (! $receipt instanceof \stdClass || $keys !== ['findings', 'verdict'] || ! in_array($receipt->verdict, ['approve', 'changes'], true)
            || ! is_array($findings) || count($visible) !== count($findings)) {
            return [null, self::InvalidReceipt];
        }
        $reason = match (true) {
            $receipt->verdict === 'changes' && $findings === [] => self::ChangesWithoutFinding,
            $receipt->verdict === 'approve' && $findings !== [] => self::ApproveWithFinding,
            default => '',
        };

        return [$reason === '' ? ['verdict' => $receipt->verdict, 'findings' => count($findings)] : null, $reason];
    }

    /**
     * @param  array{commit: string, tree: string, parts: list<array{name: string, result: string, seconds: int|float}>}|null  $receipt
     * @param  array<string, mixed>  $result
     * @return array{rejected: bool, rejection_reason: string}
     */
    private static function verdict(?array $receipt, array $result, bool $passed): array
    {
        // The work the implementer handed off, as the check observed it before it merged the default head in or ran any command: HEAD
        // and the tree of the working tree, uncommitted work included. A check that observed neither cannot establish the receipt's
        // candidate, so it rejects it as a failed check.
        $head = is_string($result['handed_off_head'] ?? null) ? $result['handed_off_head'] : null;
        $tree = is_string($result['handed_off_tree'] ?? null) ? $result['handed_off_tree'] : null;
        $failed = static fn (array $parts): array => array_column(array_filter($parts, static fn (array $part): bool => $part['result'] === 'FAILED'), 'name');
        $passedParts = $receipt === null ? [] : array_column(array_filter($receipt['parts'], static fn (array $part): bool => $part['result'] === 'passed'), 'name');
        $reason = match (true) {
            $receipt === null => self::NoReceipt,
            $head === null || $tree === null => self::CheckFailed,
            $receipt['commit'] !== $head || $receipt['tree'] !== $tree => self::OtherCommit,
            $failed($receipt['parts']) !== [] => self::FailedPart,
            array_intersect($passedParts, $failed(self::parts($result['parts'] ?? null) ?? [])) !== [] => self::ClaimDisproved,
            ! $passed => self::CheckFailed,
            default => '',
        };

        return ['rejected' => $reason !== '', 'rejection_reason' => $reason];
    }
}
