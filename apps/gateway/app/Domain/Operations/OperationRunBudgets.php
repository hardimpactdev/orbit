<?php

declare(strict_types=1);

namespace App\Domain\Operations;

use App\Domain\Tasks\TaskVmAdmission;
use App\Models\Operation;
use Carbon\CarbonImmutable;
use Closure;

/** Soft run budgets, hard idle limits and separate admission deadlines. */
final class OperationRunBudgets
{
    private const int UnmeasuredSeconds = 1800;

    private const int AdmissionSeconds = 600;

    /**
     * Task admissions capture the soft limit separately from the native SSH timeout. An already admitted task without the
     * separate soft limit keeps its captured run budget.
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
     * Native task SSH timeouts retain their existing floor independently of the learned soft limit.
     * Both values are frozen at admission; workflows keep reading only run_budget_seconds.
     *
     * @return array{soft_limit_seconds:int,run_budget_seconds:int}
     */
    public static function taskBudgets(string $kind): array
    {
        $soft = self::historySeconds($kind);

        return ['soft_limit_seconds' => $soft, 'run_budget_seconds' => max(900, $soft)];
    }

    public static function seconds(Operation $operation): int
    {
        if (self::admitting($operation)) {
            return self::AdmissionSeconds;
        }
        $captured = $operation->input['soft_limit_seconds'] ?? $operation->input['run_budget_seconds'] ?? null;
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

    /** The longest successful run of the kind in the last 14 days x 1.5, rounded up to a minute, at least one minute; 30 minutes without a success. */
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
        $seconds = $longest === null ? self::UnmeasuredSeconds : max(60, (int) ceil($longest * 1.5 / 60) * 60);
        if (self::$history !== null) {
            self::$history[$kind] = $seconds;
        }

        return $seconds;
    }

    /** The soft limit counts from the run's start, never from a later step. Admission waits have their own deadline. */
    public static function softDeadline(Operation $operation): ?CarbonImmutable
    {
        if (RoutePublicationHolds::waitsForNodes($operation) || self::admitting($operation)
            || isset($operation->input['capacity_request']) || $operation->started_at === null) {
            return null;
        }

        return CarbonImmutable::instance($operation->started_at)->addSeconds(self::seconds($operation));
    }

    public static function hardSeconds(Operation $operation): int
    {
        $soft = self::seconds($operation);

        return self::admitting($operation) ? $soft : max(3 * $soft, $soft + 1800);
    }

    private static function admitting(Operation $operation): bool
    {
        return ! isset($operation->state['swamp_run_id']) && $operation->attempts === 0;
    }

    /** The hard idle deadline. Route holds have none; admission and capacity waits retain their separate deadlines. */
    public static function deadline(Operation $operation): ?CarbonImmutable
    {
        if (RoutePublicationHolds::waitsForNodes($operation)) {
            return null;
        }
        if ($operation->status === OperationStatus::Waiting && isset($operation->input['capacity_request'])) {
            return $operation->created_at === null ? null : CarbonImmutable::instance($operation->created_at)->addSeconds(TaskVmAdmission::WaitSeconds);
        }
        $timeout = $operation->state['native_timeout'] ?? null;
        $expired = is_array($timeout) ? ($timeout['deadline'] ?? null) : null;
        if ($operation->error_code === 'operation.timed_out' && is_string($expired)) {
            return CarbonImmutable::parse($expired);
        }
        $start = $operation->started_at ?? $operation->created_at;
        $progress = $operation->state['native_progress'] ?? null;
        $transition = is_array($progress) ? ($progress['last_transition_at'] ?? null) : null;
        if (! self::admitting($operation) && is_string($transition)) {
            $start = CarbonImmutable::parse($transition);
        }

        return $start === null ? null : CarbonImmutable::instance($start)->addSeconds(self::hardSeconds($operation));
    }
}
