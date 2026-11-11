<?php

declare(strict_types=1);

namespace App\Domain\Operations;

use App\Domain\Tasks\TaskVmAdmission;
use App\Models\Operation;
use Carbon\CarbonImmutable;
use Closure;
use LogicException;
use App\Support\ValidatedData;
use Symfony\Component\Yaml\Yaml;

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
        if ($operation->kind === 'instance.migrate') {
            return self::workflowSeconds('operation-instance-migrate');
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

    /**
     * Sum the workflow's declared execution bounds, including both forward and restore children and cleanup. The extra 600 s
     * covers admission, metadata steps and settlement. Reading the shipped declarations keeps their timeouts the only rule;
     * no second table can silently fall behind a longer phase. Only migrate selects this rule until its other owners adopt it.
     *
     * @param  ?string  $directory  A workflow fixture directory; production reads the release's shipped workflow definitions.
     */
    public static function workflowSeconds(string $workflow, ?string $directory = null): int
    {
        $directory ??= dirname(base_path(), 2).'/swamp/workflows';
        /** @var Closure(string, list<string>): int $sum */
        $sum = static function (string $name, array $parents = []) use ($directory, &$sum): int {
            if (preg_match('/\A[a-z0-9-]+\z/D', $name) !== 1 || in_array($name, $parents, true)) {
                throw new LogicException('A run budget requires an acyclic, literal workflow declaration.');
            }
            /** @var array{jobs:list<array{steps:list<array{task:array{type:string,workflowIdOrName?:string,inputs?:array<string,mixed>}}}>}>} $definition */
            $definition = Yaml::parseFile($directory.'/workflow-'.$name.'.yaml');
            $seconds = 0;
            foreach ($definition['jobs'] as $job) {
                foreach ($job['steps'] as $step) {
                    $task = $step['task'];
                    if ($task['type'] === 'workflow') {
                        $seconds += $sum(ValidatedData::string($task['workflowIdOrName'] ?? null), [...$parents, $name]);

                        continue;
                    }
                    $inputs = $task['inputs'] ?? [];
                    $bound = $inputs['timeoutSec'] ?? 0;
                    if (! is_int($bound)) {
                        throw new LogicException('A run budget requires a declared numeric step timeout.');
                    }
                    if (preg_match('/\Atimeout\s+([1-9][0-9]*)\s/', ValidatedData::string($inputs['run'] ?? ''), $match) === 1) {
                        $bound = max($bound, (int) $match[1]);
                    }
                    $seconds += $bound;
                }
            }

            return $seconds;
        };

        return 600 + $sum($workflow);
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

    /** A Route write that waits for a held Node has no deadline; its budget counts from its start (docs/reference/routes.md#coordinate-publication). */
    public static function deadline(Operation $operation): ?CarbonImmutable
    {
        if (RoutePublicationHolds::waitsForNodes($operation)) {
            return null;
        }
        if ($operation->status === OperationStatus::Waiting && isset($operation->input['capacity_request'])) {
            return $operation->created_at === null ? null : CarbonImmutable::instance($operation->created_at)->addSeconds(TaskVmAdmission::WaitSeconds);
        }
        $start = $operation->started_at ?? $operation->created_at;

        return $start === null ? null : CarbonImmutable::instance($start)->addSeconds(self::seconds($operation));
    }
}
