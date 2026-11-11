<?php

declare(strict_types=1);

namespace App\Domain\Operations;

/**
 * The fixed and captured run budget of each Operation kind: one entry per kind, plus the longest fixed one that the Swamp serve
 * unit takes as its run cap. It is data. The rules that read it are in {@see OperationRunBudgets}.
 */
final class OperationBudgetTable
{
    /**
     * A number is a fixed budget; a field name selects a server-captured admission budget. Fixed budgets are the
     * kind owner's measured 14-day p95 of successful runs, rounded up to the next five minutes, unless a row states another basis.
     */
    public const array SecondsByKind = [
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
        // No run yet. Basis: a pass over the 12 managed Linux Nodes, each with a 900 s drain budget at most, a reboot of about 2 minutes
        // and a check; most Nodes drain at once. The window is three hours, and the pass never starts a Node it cannot finish before 06:00,
        // so the budget is the window. Replace with the 14-day p95 after the first nights.
        'fleet.reboot' => 10800,
        // No native run yet. Basis: the legacy runner's last 47 verified releases (p95 117 s, longest 125 s) plus the
        // Horizon drain, which waits for running jobs up to the longest supervisor timeout + 30 s (1,730 s); x 1.5 = 2,783 s,
        // rounded up to five minutes. An expiry pauses releases. Replace with the 14-day p95 after the first success.
        // The pre-load busy guard adds up to 600 s to the existing 3,000 s release allowance.
        'gateway.release' => 3600,
        // The proof the CLI follows closes when its last run ends: the two-hour admission wait, the longest run of a shard
        // (ProofBatchPlan::MaxShardSeconds) and five minutes.
        'proof.run' => 14700,
        // The fixed work of the shard and of each of its batches and the measured case times, x 1.5, captured at admission
        // (ProofBatchPlan::budget). Basis of the fixed parts: shards of proof 14337 took 996 to 1,909 s for 3 to 8 batches.
        'proof.command' => 'run_budget_seconds',
        // No run yet. Basis: the cases of firewall:allow on the author's own VM, with no provision and no destruction: 14 cases x 120 s,
        // the restores before the first batch, between batches and after the last (about 16 s each), and a re-mint with its readiness check and
        // the new `base` (about 150 s); about 1,900 s x 1.5, rounded up to five minutes. Replace with the 14-day p95 after the first success.
        'proof.verify' => 3000,
        'node.converge' => 3900,
        // No native run yet. Basis: the legacy rollback deadline of 900 s (docs/reference/deployments.md#deploy) and the chart's
        // budget. Replace with the 14-day p95 after the first success.
        'instance.rollback' => 900,
        'node.prepare_php_packages' => 3600,
        'node.remove' => 2100,
        'node.role_add' => 2700,
        // Provisional. Basis: the first refresh of a new host, the longest: the first boot of its base VM (600 s at its bound), the
        // converge from stock with the build of the nested fleet (5,400 s at its bound; the standalone fleet build, since removed, took 1,840 s as op 11816), the
        // snapshot and the task start that proves it (tasks.provision, 3,900 s at its bound), plus margin. A refresh of a built base takes
        // about two minutes (ops 13052, 13053). Replace with the 14-day p95 after the first successes.
        'task_vm.image_refresh' => 10800,
        // No run yet. Basis: the VM step of tasks.provision (about 160 s, op 11752), "Converge once" (about 60 s at the pins, 3,300 s at
        // its bound) and the stop (130 s at its bound), rounded up to one hour. Replace with the 14-day p95 after the first successes.
        'tasks.provision' => 3900,
        // No run yet. One package manager run, bounded to 900 s by its step, plus the write and the removal of the configuration.
        // Replace with the 14-day p95 after the first success.
        'tasks.package' => 1500,
        // No run yet. The publish step mints its own token, so the one-hour life of a token no longer bounds the run:
        // provision (about 300 s), a draft turn, three reviews and two fixes of about 10 min each. Prep runs of 43 to 90 min
        // are expected (Nick, 2026-11-06), so 105 min. Replace with the 14-day p95 after the first success.
        'tasks.prep' => 6300,
        // No run yet. Basis: the task VM resume (about 90 s), one author turn of about 10 minutes, two review rounds of about 4
        // minutes with the runner plans, and one fix of 4 minutes, so about 25 minutes, x 1.5, rounded to 45 minutes. The push token
        // is minted when the push step starts, so the hour of an installation token does not limit this run.
        // Replace with the 14-day p95 after the first success.
        'tasks.prep_fix' => 2700,
        // No run yet. Basis: the task VM resume (about 90 s), a reviewer turn (p95 933 s), the comment (about 30 s), and in rounds 1 and 2
        // an author turn bounded by the 3,600 s of `tasks.turn`, the local check (520 s, one run) and the push (about 60 s), so about 5,200 s,
        // rounded to 6,300 s (105 min) as for a prep run. The comment and the push mint their tokens when they start.
        // Replace with the 14-day p95 after the first success.
        'tasks.review_round' => 6300,
        // The history of the kind held short reviewer and consult turns: its longest run of 12 minutes x 1.5 gave 18 minutes, and the first
        // implementation turn of group 3517 (operation 11737, firewall:allow) ended at that bound while the author was still working.
        // An author turn writes code, runs the Pest suite of its deliverables and fixes what the Project check finds. One hour; the
        // `observe` step of `operation-task-turn` ends 5 minutes earlier (3,300 seconds) so the snapshots and the receipt still run.
        // Replace with the longest run x 1.5 once the history holds author turns.
        'tasks.turn' => 3600,
        // No run yet. Basis: the task VM resume (about 90 s), the sync of the refs (about 2 minutes) and the first implementer turn, which
        // the one-hour bound of `tasks.turn` limits, so 1 hour and 5 minutes, rounded to 75 minutes. Replace with the 14-day p95 after the
        // first success.
        'tasks.build' => 4500,
        // No run yet. Basis: a bare clone of one branch and a fetch of another (about 60 s for this repository), one bundle, one copy
        // and one fetch over WireGuard (about 30 s), so about 2 minutes, x 3 for a slow GitHub, rounded to 6 minutes. Replace with the
        // 14-day p95 after the first success.
        'tasks.sync' => 360,
        // No run yet. Basis: the bounds of its own steps: the Ansible phase (240 s), the import (60 s), the push (60 s) and 60 s for the
        // intent, the mint and the cleanup, so 7 minutes. Replace with the 14-day p95 after the first success.
        'tasks.idle_save' => 420,
        // Both ending kinds run the release of the task VM (task_vm.slot_release) after Route withdrawal and Project teardown. Their history
        // came from cancels with no VM, so the derived budget timed out real cleanups at 300 s (ops 6725, 6727, 6728).
        // Basis: the former VM destroy bound of 2,700 s + teardown cap 540 s (LifecycleStep::MaxTotalTimeoutSeconds) + 660 s for the
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
     * The budget of a run of a captured kind that was admitted before its kind captured one: the fixed budget it had then. A run
     * that older code admits, also while a release switches, never gets less than it was admitted with.
     *
     * @var array<string,int>
     */
    public const array UncapturedSeconds = ['proof.command' => 3600];

    /** The longest fixed budget. Serve cuts a run at this length, so it must not be shorter than a fixed budget below. */
    public static function longestFixedSeconds(): int
    {
        return max(array_filter(self::SecondsByKind, is_int(...)));
    }
}
