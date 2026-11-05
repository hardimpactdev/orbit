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

    /**
     * The proof reset `bin/proof-fleet reset GROUP --from-ready`: the history of short scripts gave 120 s, which timed out the
     * first real reset (op 10861). Basis: the measured parts on a fresh VM, the restore from the ready copies, the rsync sync,
     * `composer install` where lock files differ from the seed (31 to 35 s for five) and the memory snapshot `base` (about 19 s),
     * plus margin; 360 s. Replace with the 14-day p95 after the first passing runs. Other scripts keep the history-derived budget.
     */
    private const int ProofResetSeconds = 360;

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
        // The removal steps, teardown timeouts, withdrawn Routes and owned databases, captured at admission.
        'instance.destroy' => 'run_budget_seconds',
        // The deploy step timeouts plus 900 s, captured at admission (docs/reference/deployments.md#deploy).
        'instance.deploy' => 'run_budget_seconds',
        // The Schedule's own timeout plus ScheduleFires::MarginSeconds, recorded at admission or at the fire's start.
        'schedule.run' => 'run_budget_seconds',
        // No successful run yet. Basis: the observed work of op 6592 (root 42a546fd), 2,417 s (prepare 1m57s, forward
        // 22m26s, restoration 15m45s) x 1.5 = 3,626 s, rounded up to five minutes. Replace with the 14-day p95 after the
        // first success.
        'cluster.router_set' => 3900,
        'cluster.router_unset' => 3900,
        // Its first successes deferred every Node outside the window in about 35 s, so the derived budget timed out
        // the first real visit at 3 min (op 8821). Basis: op 8837 visited 7 Nodes one after another in 187 s, 4 of them
        // converged; a full fleet of 7 converging at the same pace takes about 330 s, x 1.5, rounded up to five minutes.
        // Replace with the 14-day p95 once windowed visits have history.
        'fleet.rollout' => 600,
        // No native run yet. Basis: the legacy runner's last 47 verified releases (p95 117 s, longest 125 s) plus the
        // Horizon drain, which waits for running jobs up to the longest supervisor timeout + 30 s (1,730 s); x 1.5 = 2,783 s,
        // rounded up to five minutes. An expiry pauses releases. Replace with the 14-day p95 after the first success.
        'gateway.release' => 3000,
        // No run yet. Basis: the case budget of 1,800 s that docs/cli/proof/run.mdx sets, the test fleet reset (about 60 s,
        // 300 s allowed), the fleet release (600 s) and a margin, rounded up to five minutes. Replace with the 14-day p95
        // after the first success.
        'proof.run' => 3000,
        // No run yet. Basis: the runner cases of firewall:allow, 16 cases, each about 85 s with its restore (65 s of it the wait for Doctor in
        // the main case only) and at most 180 s; 14 cases x 120 s plus 2 batch restores is about 1,800 s, x 1.5, rounded up to five minutes.
        // Replace with the 14-day p95 after the first success.
        'proof.verify' => 2700,
        'node.converge' => 3900,
        // No native run yet. Basis: the legacy rollback deadline of 900 s (docs/reference/deployments.md#deploy) and the chart's
        // budget. Replace with the 14-day p95 after the first success.
        'instance.rollback' => 900,
        'node.prepare_php_packages' => 3600,
        'node.remove' => 2100,
        'node.role_add' => 2700,
        'task_vm.destroy' => 2700,
        // No native run yet. Basis: the legacy fill's one overall 1,700 s budget, plus margin for the run start and the
        // native assert. Each batch is bounded on the host by `timeout 1530`. Replace with the 14-day p95 after the first success.
        'task_vm.fill_package_cache' => 2100,
        'task_vm.image_build' => 2400,
        // No successful run yet. Basis: two live runs on beast with the host at load 5 to 9, 7 min 31 s and 10 min 2 s. The host
        // steps are bounded by 340 s (cleanup), 640 s (clone), 310 s (start), 310 s (fetch), 910 s (dependencies), 1,710 s
        // (the proof reset), 610 s (copies), 910 s (check), 210 s (stop) and 410 s (promote). A loaded host stretches the reset
        // and the boot checks, so the budget is 1 h, not 2 x the longer run. Replace with the 14-day p95 after the first successes.
        'task_vm.seed_refresh' => 3600,
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

    /**
     * The budget that the admission of a `tasks.script` captures in `input.run_budget_seconds`, or null for a script with no explicit budget.
     *
     * @param  list<string>  $args
     */
    public static function scriptSeconds(string $script, array $args): ?int
    {
        return $script === 'bin/proof-fleet' && ($args[0] ?? null) === 'reset' ? self::ProofResetSeconds : null;
    }

    public static function seconds(Operation $operation): int
    {
        if (! isset($operation->state['swamp_run_id']) && $operation->attempts === 0) {
            return self::AdmissionSeconds;
        }
        $captured = $operation->input['run_budget_seconds'] ?? null;
        if ($operation->kind === OperationKinds::TasksScript && is_int($captured) && $captured > 0) {
            return $captured;
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
