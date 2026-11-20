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

/**
 * The handoff gates of a group that left backlog after they shipped (docs/reference/tasks.md#handoffs). The handoff check is the
 * delivery gate: when it ends it writes one delivery row for the implementer turn that handed off, accepted or rejected with one reason.
 */
final readonly class TaskHandoffs
{
    /** The rejection reasons of a delivery, in the order the gate tries them. */
    public const string NoReceipt = 'no_receipt';

    public const string OtherCommit = 'other_commit';

    public const string FailedPart = 'failed_part';

    public const string ClaimDisproved = 'claim_disproved';

    public const string CheckFailed = 'check_failed';

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
