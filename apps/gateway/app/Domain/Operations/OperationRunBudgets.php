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
     * kind owner's measured 14-day p95 of successful runs, rounded up to the next five minutes.
     */
    private const array SecondsByKind = [
        'instance.create' => 'run_budget_seconds',
        // No successful run yet. Basis: the observed work of op 6592 (root 42a546fd), 40m17s (prepare 1m57s, forward
        // 22m26s, restoration 15m45s) x 1.5, rounded up. Replace with the 14-day p95 after the first success.
        'cluster.router_set' => 3600,
        'cluster.router_unset' => 3600,
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
        $start = $operation->started_at ?? $operation->created_at;

        return $start === null ? null : CarbonImmutable::instance($start)->addSeconds(self::seconds($operation));
    }
}
