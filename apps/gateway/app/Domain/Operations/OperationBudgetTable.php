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
        // Bounded shared path and one-line phase (90 s), plus input, result and cleanup. No live sample yet.
        'instance.mail_update' => 120,
        // The 30 s marker phase and the 90 s freeze phase (a 30 s clean stop when swap is full), plus input, result and cleanup.
        // Measured by hand on a fixture clone: the pause and the reclaim of a frozen container take under 0.5 s.
        'instance.sleep' => 150,
        // The 120 s start phase and the 120 s T3 and marker phase (31 T3 tries of up to 3 s), plus input, result and cleanup.
        // Measured on a fixture clone: T3 answered 2.6 to 3.4 s after the start. No live run of the kind yet.
        'instance.wake' => 300,
        // A nested-fleet clone's start: the 240 s start phase with the Club key, the 300 s fleet check and the 600 s fleet start with
        // its Doctor wait, plus input, result and cleanup. Expected about 110 s: a clone's fleet start took 91 s (Operation 20180).
        'instance.start' => 1200,
        // One 150 s network, file and restart phase, plus input, result and cleanup. No live sample yet.
        'instance.connection_environment' => 180,
        // The fixed phases and one copy step per target, in series, captured at admission (ProofSeeds::budgetSeconds).
        'instance.seed_copy' => 'run_budget_seconds',
        // The 240 s restart phase (a 100 s try-restart and about 84 s of label reads), a 30 s queue wait, input, result and cleanup,
        // rounded up to five minutes. No live sample yet.
        'conn.environment_restart' => 300,
        // No run yet. Basis: the import step's own bound, DatabaseImport::TimeoutSeconds (3,600 s: a 2 GB dump at 1 MB/s), plus
        // 300 s for the vault copy, the run start and the result. Replace with the 14-day p95 after the first successes.
        'database.import' => 3900,
        // One 90 s scoped network/proxy phase, plus input/result/cleanup.
        'instance.node_service_detach' => 120,
        'instance.create' => 'run_budget_seconds',
        // No run yet. Basis: the database fill's 600 s and the nested fleet start's 900 s, with margin for the stage, services, check
        // and the Route beside them (docs/reference/development-containers.md#complete-a-clone). Replace with the 14-day p95.
        'instance.complete' => 1800,
        // The removal steps, teardown timeouts, withdrawn Routes and owned databases, captured at admission.
        'instance.destroy' => 'run_budget_seconds',
        'instance.unregister' => 'run_budget_seconds',
        // Two database batches and one Route create; 900 s, captured at admission.
        'instance.restore' => 'run_budget_seconds',
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
        // The migrate path declares 4,830 s, including the 150 s pre-cutover Node preparation phase.
        // Round up to 5,100, plus 600 s for admission and settlement. This lower bound includes restore/cleanup,
        // but not undeclared work or dynamic repetitions.
        'instance.migrate' => 5700,
        // No native run yet. Basis: the legacy rollback deadline of 900 s (docs/reference/deployments.md#deploy) and the chart's
        // budget. Replace with the 14-day p95 after the first success.
        'instance.rollback' => 'run_budget_seconds',
        'node.prepare_php_packages' => 3600,
        'node.remove' => 2100,
        'node.role_add' => 2700,
        // No run yet. Basis: the VM step of tasks.provision (about 160 s, op 11752), "Converge once" (about 60 s at the pins, 3,300 s at
        // its bound) and the stop (130 s at its bound), rounded up to one hour. Replace with the 14-day p95 after the first successes.
        'tasks.provision' => 3900,
        // No run yet. Basis: the agent's turn in a task copy, which its own step bounds at 3,600 s, and
        // the Ansible phase around it, which `timeout 4500` bounds: the kill grace (30 s), eight T3 or helper calls of up to 60 s (the
        // settle's list and its stops of up to two earlier runs, the list of a continued turn, the question's read and stop or a cut run's
        // list and stop, the list and show after the turn: 8 minutes), sixteen Git commands of up to 20 s (the settle's restore, a
        // reviewer's set-up and restore: 320 s) and the SSH set-up, rounded to 78 minutes. Replace with the longest run x 1.5 once the
        // history holds turns.
        'tasks.copy_turn' => 4680,
        // No run yet. Basis: a bare clone of one branch and a fetch of another (about 60 s for this repository), one bundle, one copy
        // and one fetch over WireGuard (about 30 s), so about 2 minutes, x 3 for a slow GitHub, rounded to 6 minutes. Replace with the
        // 14-day p95 after the first success.
        'tasks.sync' => 360,
        // Both ending kinds ran the release of the task VM (task_vm.slot_release, removed) after Route withdrawal and Project teardown. Their history
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
    public const array UncapturedSeconds = ['proof.command' => 3600, 'instance.seed_copy' => 7800, 'instance.rollback' => 900];

    /** The longest fixed budget. Serve cuts a run at this length, so it must not be shorter than a fixed budget below. */
    public static function longestFixedSeconds(): int
    {
        return max(array_filter(self::SecondsByKind, is_int(...)));
    }
}
