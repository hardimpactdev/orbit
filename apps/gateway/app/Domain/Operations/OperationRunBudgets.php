<?php

declare(strict_types=1);

namespace App\Domain\Operations;

use App\Domain\InstanceMigration\DeferredMigrationScheduleRuns;
use App\Domain\Tasks\TaskVmAdmission;
use App\Models\Operation;
use Carbon\CarbonImmutable;
use Closure;

/** Explicit execution budgets, measured history and a separate admission deadline. */
final class OperationRunBudgets
{
    private const int UnmeasuredSeconds = 1800;

    /**
     * Stop-gap floor for a budget derived from history: one quick first success must not cancel a later, larger run (instance.migrate
     * 16674 at 120 s after a 56 s first success). Removed when every kind's budget comes from its workflow's declared step timeouts.
     */
    private const int LearnedFloorSeconds = 900;

    private const int AdmissionSeconds = 600;

    /**
     * Kinds whose admission captures `input.run_budget_seconds` and hands it to their SSH steps as the step time limit. The
     * captured number wins over the kind's history, so the Gateway deadline and the step limits agree.
     *
     * @var list<string>
     */
    private const array CapturedKinds = [OperationKinds::TasksScript, OperationKinds::TasksCheck];

    /** @var array<string,int>|null One observer pass, never a cross-request cache. */
    private static ?array $history = null;

    public static function duringTick(Closure $tick): void
    {
        $previous = self::$history;
        self::$history = [];
        try {
            $tick();
        } finally {
            self::$history = $previous;
        }
    }

    /**
     * What the admission of a `tasks.script` captures in `input.run_budget_seconds`: the budget of the history. The workflow sets
     * it as the time limit of each of its SSH steps, so a step against a VM that is gone ends with the Operation. The Gateway does
     * not re-read the history after admission.
     */
    public static function scriptBudget(): int
    {
        return self::historySeconds(OperationKinds::TasksScript);
    }

    public static function seconds(Operation $operation): int
    {
        if (! isset($operation->state['swamp_run_id']) && $operation->attempts === 0) {
            return self::AdmissionSeconds;
        }
        $captured = $operation->input['run_budget_seconds'] ?? null;
        if (in_array($operation->kind, self::CapturedKinds, true) && is_int($captured) && $captured > 0) {
            return $captured;
        }
        $budget = OperationBudgetTable::SecondsByKind[$operation->kind] ?? null;
        if (is_int($budget)) {
            return $budget;
        }
        if (is_string($budget)) {
            $seconds = $operation->input[$budget] ?? null;

            return is_int($seconds) && $seconds > 0 ? $seconds : (OperationBudgetTable::UncapturedSeconds[$operation->kind] ?? 60);
        }

        return self::historySeconds($operation->kind);
    }

    /** The longest successful run of the kind in the last 14 days x 1.5, rounded up to a minute, at least 15 minutes; 30 minutes without a success. */
    public static function historySeconds(string $kind): int
    {
        if (isset(self::$history[$kind])) {
            return self::$history[$kind];
        }
        $longest = null;
        foreach (Operation::query()->where('kind', $kind)->where('status', OperationStatus::Succeeded)
            ->whereNotNull('started_at')->where('finished_at', '>=', now()->subDays(14))->where('finished_at', '<=', now())
            ->whereNotNull('state->swamp_run_id')->select(['started_at', 'finished_at'])->cursor() as $success) {
            if ($success->started_at === null || $success->finished_at === null) {
                continue;
            }
            $duration = $success->started_at->diffInSeconds($success->finished_at, false);
            if ($duration >= 0) {
                $longest = max($longest ?? 0, $duration);
            }
        }
        $seconds = $longest === null ? self::UnmeasuredSeconds : max(self::LearnedFloorSeconds, (int) ceil($longest * 1.5 / 60) * 60);
        if (self::$history !== null) {
            self::$history[$kind] = $seconds;
        }

        return $seconds;
    }

    /** A held Route write or a deferred Schedule fire starts its budget when admission reopens. */
    public static function deadline(Operation $operation): ?CarbonImmutable
    {
        if (RoutePublicationHolds::waitsForNodes($operation) || DeferredMigrationScheduleRuns::waiting($operation)) {
            return null;
        }
        if ($operation->status === OperationStatus::Waiting && isset($operation->input['capacity_request'])) {
            return $operation->created_at === null ? null : CarbonImmutable::instance($operation->created_at)->addSeconds(TaskVmAdmission::WaitSeconds);
        }
        $start = $operation->started_at ?? $operation->created_at;

        return $start === null ? null : CarbonImmutable::instance($start)->addSeconds(self::seconds($operation));
    }
}
