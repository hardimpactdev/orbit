<?php

declare(strict_types=1);

namespace App\Domain\Proof;

use App\Actions\Tasks\StartTaskScriptAction;
use App\Data\Tasks\StartTaskScriptData;
use App\Domain\Operations\OperationIdempotency;
use App\Domain\Operations\OperationKinds;
use App\Domain\Operations\OperationStatus;
use App\Domain\Operations\OperationStore;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\TaskCancelIntent;
use App\Jobs\Proof\AdvanceProofRun;
use App\Models\Operation;
use App\Models\Task;
use App\Support\ValidatedData;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * Drives one proof through the normal task operations, as TaskMaintenanceContinuation does for maintenance: the group is
 * provisioned at the commit, `bin/proof-fleet verify` runs in its VM as tasks.script, then the group is cancelled. The
 * terminal result of each step admits the next, and the last closes the proof.run Operation. See docs/cli/proof/run.mmd.
 */
final readonly class ProofRunContinuation
{
    private const array Verdicts = ['p' => 'passed', 'f' => 'failed', 'u' => 'unrun'];

    public function __construct(private OperationStore $store) {}

    /** Admits one task operation under its own key, because the request key of proof:run belongs to the proof.run Operation. */
    public function admit(Task $group, string $kind, Closure $start): Operation
    {
        $identity = app(OperationIdempotency::class);
        $prior = [$identity->kind, $identity->key, $identity->fingerprint];
        $key = 'proof:'.$group->id.':'.$kind;
        [$identity->kind, $identity->key, $identity->fingerprint] = [$kind, $key, hash('sha256', $key)];
        try {
            return $start();
        } finally {
            [$identity->kind, $identity->key, $identity->fingerprint] = $prior;
        }
    }

    /** Called when a task operation of any group ends. A group of a running proof gets one job that admits the next step, away from the locks of this projection. */
    public function apply(Operation $operation): void
    {
        if ($operation->status->terminal() && $this->proof($operation) !== null) {
            DB::afterCommit(static fn () => AdvanceProofRun::dispatch($operation->id));
        }
    }

    /** @throws ResourceOperationException when the next admission is refused, such as while the group is still recording a result; the job tries again */
    public function advance(Operation $operation): void
    {
        $proof = $this->proof($operation);
        if ($proof === null || ! $operation->status->terminal() || ($operation->state['proof_continued'] ?? false)) {
            return;
        }
        $group = Task::topLevel()->findOrFail($operation->target_id);
        if ($operation->kind === 'tasks.cancel') {
            $this->close($proof);
        } elseif ($operation->kind === 'tasks.provision' && $operation->status === OperationStatus::Succeeded && $proof->cancel_requested_at === null) {
            $subtask = $group->tasks()->orderBy('position')->firstOrFail();
            $this->admit($group, 'tasks.script', fn () => app(StartTaskScriptAction::class)->execute($group,
                new StartTaskScriptData($subtask->id, 'bin/proof-fleet', ['verify', (string) $group->id, ...ValidatedData::stringList(array_column($this->cases($proof), 'program'))]), 'proof-run', null));
        } else {
            if ($operation->kind === 'tasks.script') {
                $this->store->change($proof, ['state' => [...($proof->state ?? []), 'verdict' => $this->verdict($this->lastLine($operation), $proof)]]);
            }
            $this->admit($group, 'tasks.cancel', fn () => app(TaskCancelIntent::class)->start($group, 'proof-run', null));
        }
        $operation->update(['state' => [...($operation->state ?? []), 'proof_continued' => true]]);
    }

    /** The job gave up: cancel the group once more and close the proof with what it has. */
    public function abandon(Operation $operation): void
    {
        $proof = $this->proof($operation);
        if ($proof === null) {
            return;
        }
        $group = Task::topLevel()->findOrFail($operation->target_id);
        try {
            $this->admit($group, 'tasks.cancel', fn () => app(TaskCancelIntent::class)->start($group, 'proof-run', null));
        } catch (ResourceOperationException) {
            // The group stays for `tasks:cancel`; the proof still closes.
        }
        $this->close($proof->refresh());
    }

    /** The running proof of the group that the task operation belongs to. */
    private function proof(Operation $operation): ?Operation
    {
        return Operation::query()->where('kind', OperationKinds::ProofRun)->where('target_type', 'proof')->where('target_id', $operation->target_id)
            ->whereNotIn('status', OperationStatus::terminalValues())->latest('id')->first();
    }

    /** @return array<string,mixed> the one JSON line that `bin/proof-fleet verify` prints last, or nothing when the run printed none */
    private function lastLine(Operation $script): array
    {
        $lines = array_values(array_filter(array_map('trim', explode("\n", ValidatedData::string($script->result['output'] ?? '')))));
        $line = json_decode($lines === [] ? '' : $lines[count($lines) - 1], true);

        return is_array($line) ? ValidatedData::object($line) : [];
    }

    /**
     * Each admitted case with its verdict from the summary of that line. A case with no usable row is unrun.
     *
     * @param  array<string,mixed>  $line
     * @return array{ran: bool, evidence: string|null, cases: list<array<string,mixed>>}
     */
    private function verdict(array $line, Operation $proof): array
    {
        $cases = $this->cases($proof);
        $summary = is_array($line['summary'] ?? null) ? array_values($line['summary']) : [];
        $usable = count($summary) === count($cases);
        $rows = [];
        foreach ($cases as $index => $case) {
            $row = $usable ? $summary[$index] : null;
            $letter = is_array($row) ? ($row[0] ?? null) : null;
            $seconds = is_array($row) ? ($row[1] ?? null) : null;
            $known = is_string($letter) && isset(self::Verdicts[$letter]) && (is_int($seconds) || is_float($seconds));
            $rows[] = [...$case, 'verdict' => $known ? self::Verdicts[$letter] : 'unrun', 'seconds' => $known ? $seconds : 0];
        }

        return ['ran' => $usable && ($line['cases_ran'] ?? false) === true, 'evidence' => is_string($line['evidence'] ?? null) ? $line['evidence'] : null, 'cases' => $rows];
    }

    /** @return list<array<string,mixed>> */
    private function cases(Operation $proof): array
    {
        return array_values(array_map(ValidatedData::object(...), (array) $proof->input['cases']));
    }

    private function close(Operation $proof): void
    {
        $verdict = ValidatedData::object($proof->state['verdict'] ?? $this->verdict([], $proof));
        $cases = array_values(array_map(ValidatedData::object(...), (array) $verdict['cases']));
        $passed = array_all($cases, static fn (array $case): bool => $case['verdict'] === 'passed');
        $error = $passed ? null : (($verdict['ran'] ?? false) === true ? 'proof.cases_failed' : 'proof.run_failed');
        $this->store->change($proof, ['status' => $passed ? OperationStatus::Succeeded : OperationStatus::Failed, 'finished_at' => now(), 'error_code' => $error,
            'result' => ['commit' => $proof->input['commit'], 'outcome' => $passed ? 'passed' : 'failed', 'cases' => $cases, 'uncovered' => $proof->input['uncovered'],
                'evidence' => $verdict['evidence'] ?? null, 'receipt_url' => null]]);
    }
}
