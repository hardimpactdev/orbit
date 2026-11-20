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
 * delivery gate: when it ends it writes one delivery row for the implementer turn before it, accepted or rejected with one reason.
 */
final readonly class TaskHandoffs
{
    /** The rejection reasons of a delivery, in the order the gate tries them. */
    public const string NoReceipt = 'no_receipt';

    public const string OtherCommit = 'other_commit';

    public const string FailedPart = 'failed_part';

    public const string ClaimDisproved = 'claim_disproved';

    public const string CheckFailed = 'check_failed';

    /**
     * Writes the delivery row of a handoff check that ended. `$result` is the check's result; `$passed` whether the check's Operation
     * succeeded with the outcome `passed`. A group without the gates, or a check that is not a handoff check, writes none.
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
            ->where('kind', OperationKinds::TasksCopyTurn)->where('status', OperationStatus::Succeeded->value)
            ->where('input->subtask', $subtask)->where('input->role', TaskThreadRole::Implementer->value)
            ->where('result->state', AgentThreadState::Done->value)->where('id', '<', $check->id)->orderByDesc('id')->first();
        $receipts = $turn?->result['receipts'] ?? null;
        $receipt = self::receipt(is_array($receipts) ? $receipts['delivery'] ?? null : null);
        TaskHandoff::query()->create(['task_id' => $subtask, 'operation_id' => $turn?->id, 'type' => TaskHandoffType::Delivery,
            'receipt' => $receipt, 'task_check_id' => ValidatedData::integer($check->input['check_id']),
            ...self::verdict($receipt, $result, $passed)]);
    }

    /**
     * Refuses a reviewer turn on CHECK unless the subtask's latest delivery row is accepted for that check.
     */
    public static function requireDelivery(Task $group, Task $subtask, TaskCheck $check): void
    {
        if (! $group->handoffs_gated) {
            return;
        }
        $latest = TaskHandoff::query()->where('task_id', $subtask->id)->where('type', TaskHandoffType::Delivery->value)->orderByDesc('id')->first();
        if (! $latest instanceof TaskHandoff || $latest->rejected || $latest->task_check_id !== $check->id) {
            throw new ResourceOperationException('tasks.handoff_required',
                'Review requires an accepted delivery for the commit under review: the implementer hands off again and the handoff check runs.', 409);
        }
    }

    /**
     * The receipt as `bin/task-check` writes it: a commit and a list of parts with a name, a result and seconds. Anything else is none.
     *
     * @return array{commit: string, parts: list<array{name: string, result: string, seconds: int|float}>}|null
     */
    private static function receipt(mixed $raw): ?array
    {
        $receipt = is_string($raw) ? json_decode($raw, true) : null;
        if (! is_array($receipt) || ! is_string($receipt['commit'] ?? null) || ! is_array($receipt['parts'] ?? null) || ! array_is_list($receipt['parts'])) {
            return null;
        }
        foreach ($receipt['parts'] as $part) {
            if (! is_array($part) || ! is_string($part['name'] ?? null) || ! is_string($part['result'] ?? null)
                || ! (is_int($part['seconds'] ?? null) || is_float($part['seconds'] ?? null))) {
                return null;
            }
        }

        /** @var array{commit: string, parts: list<array{name: string, result: string, seconds: int|float}>} */
        return ['commit' => $receipt['commit'], 'parts' => $receipt['parts']];
    }

    /**
     * @param  array{commit: string, parts: list<array{name: string, result: string, seconds: int|float}>}|null  $receipt
     * @param  array<string, mixed>  $result
     * @return array{rejected: bool, rejection_reason: string}
     */
    private static function verdict(?array $receipt, array $result, bool $passed): array
    {
        // The commit the implementer handed off: HEAD before the check merged the default head in, else HEAD when the check started.
        $handed = $result['handed_off'] ?? $result['head_before'] ?? null;
        $failed = static fn (array $parts): array => array_values(array_map(static fn (array $part): string => (string) $part['name'],
            array_filter($parts, static fn (mixed $part): bool => is_array($part) && ($part['result'] ?? null) === 'FAILED')));
        $reason = match (true) {
            $receipt === null => self::NoReceipt,
            $receipt['commit'] !== $handed => self::OtherCommit,
            $failed($receipt['parts']) !== [] => self::FailedPart,
            array_intersect(array_column(array_filter($receipt['parts'], static fn (array $part): bool => $part['result'] === 'passed'), 'name'),
                $failed(is_array($result['parts'] ?? null) ? $result['parts'] : [])) !== [] => self::ClaimDisproved,
            ! $passed => self::CheckFailed,
            default => '',
        };

        return ['rejected' => $reason !== '', 'rejection_reason' => $reason];
    }
}
