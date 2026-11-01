<?php

declare(strict_types=1);

namespace App\Domain\Operations;

use App\Models\Operation;
use Carbon\CarbonImmutable;
use Closure;

/** Explicit execution budgets, measured history and a separate admission deadline. */
final class OperationRunBudgets
{
    private const int UnmeasuredSeconds = 1800;

    private const int AdmissionSeconds = 600;

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
     * A number is a fixed budget; a field name selects a server-captured admission budget. Fixed budgets are the
     * kind owner's measured 14-day p95 of successful runs, rounded up to the next five minutes, unless a row states another basis.
     */
    private const array SecondsByKind = [
        'instance.create' => 'run_budget_seconds',
        // No successful run yet. Basis: the observed work of op 6592 (root 42a546fd), 2,417 s (prepare 1m57s, forward
        // 22m26s, restoration 15m45s) x 1.5 = 3,626 s, rounded up to five minutes. Replace with the 14-day p95 after the
        // first success.
        'cluster.router_set' => 3900,
        'cluster.router_unset' => 3900,
        'node.converge' => 3900,
        'node.network_converge' => 1500,
        'node.prepare_php_packages' => 3600,
        'node.remove' => 2100,
        'node.role_add' => 2700,
        'task_vm.destroy' => 2700,
        'task_vm.image_build' => 2400,
        'task_vm.sweep_host' => 600,
        'task_vm.upcloud_image_build' => 2100,
        'tasks.provision' => 3900,
        // Both ending kinds run the task-vm-destroy child after Route withdrawal and Project teardown. Their history
        // came from cancels with no VM, so the derived budget timed out real cleanups at 300 s (ops 6725, 6727, 6728).
        // Basis: task_vm.destroy 2,700 s + teardown cap 540 s (LifecycleStep::MaxTotalTimeoutSeconds) + 660 s for the
        // workspace Route withdrawal, whose only fixed wait is the 31 s DNS grace; the rest is margin. Replace with the 14-day p95 once
        // both kinds have successful runs with a VM.
        'tasks.cancel' => 3900,
        'tasks.maintenance_finish' => 3900,
        // Provisional, ADR 0242. A Tool write runs its bounded phases in turn: signed repository prepare 180 s, candidate
        // query 120 s, mutation 900 s (brew installs measured 471-578 s), plus the unchanged manager reads. 1,200 s of
        // phases plus margin. Replace with the 14-day p95 once repository installs have native history.
        'tool.install' => 1500,
        'tool.update' => 1500,
    ];

    public static function seconds(Operation $operation): int
    {
        if (! isset($operation->state['swamp_run_id']) && $operation->attempts === 0) {
            return self::AdmissionSeconds;
        }
        $budget = self::SecondsByKind[$operation->kind] ?? null;
        if (is_int($budget)) {
            return $budget;
        }
        if (is_string($budget)) {
            $seconds = $operation->input[$budget] ?? null;

            return is_int($seconds) && $seconds > 0 ? $seconds : 60;
        }
        if (isset(self::$history[$operation->kind])) {
            return self::$history[$operation->kind];
        }
        $longest = null;
        foreach (Operation::query()->where('kind', $operation->kind)->where('status', OperationStatus::Succeeded)
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
            self::$history[$operation->kind] = $seconds;
        }

        return $seconds;
    }

    public static function deadline(Operation $operation): ?CarbonImmutable
    {
        $timeout = $operation->state['native_timeout'] ?? null;
        $recorded = is_array($timeout) ? ($timeout['deadline'] ?? null) : null;
        if (is_string($recorded)) {
            return CarbonImmutable::parse($recorded);
        }
        $start = $operation->started_at ?? $operation->created_at;

        return $start === null ? null : CarbonImmutable::instance($start)->addSeconds(self::seconds($operation));
    }
}
