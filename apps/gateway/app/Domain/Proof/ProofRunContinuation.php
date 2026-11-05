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
use App\Domain\Tasks\TaskGroupStatus;
use App\Jobs\Proof\AdvanceProofRun;
use App\Jobs\Proof\CancelProofGroup;
use App\Models\Operation;
use App\Models\Task;
use App\Support\ValidatedData;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * Drives one proof through the normal task operations, as TaskMaintenanceContinuation does for maintenance: the group is
 * provisioned at the commit, `bin/proof-fleet reset` builds the nested fleet in its VM as tasks.script, the Swamp workflow
 * `proof-fleet-verify` (kind proof.verify) runs the cases of one command over SSH, then the group is cancelled. A second
 * command resets the fleet again and verifies. The terminal result of each step admits the next, and the last closes the
 * proof.run Operation. See docs/cli/proof/run.mmd.
 */
final readonly class ProofRunContinuation
{
    public function __construct(private OperationStore $store) {}

    /** Admits one task operation under its own key, because the request key of proof:run belongs to the proof.run Operation. A later command of the same proof has its own `stage`. */
    public function admit(Task $group, string $kind, Closure $start, int $stage = 0): Operation
    {
        $identity = app(OperationIdempotency::class);
        $prior = [$identity->kind, $identity->key, $identity->fingerprint];
        $key = 'proof:'.$group->id.':'.$kind.($stage > 0 ? ':'.$stage : '');
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
        $stage = ValidatedData::integer($proof->state['stage'] ?? 0);
        // A script that exits nonzero is a succeeded operation with outcome failed: a reset that failed leaves no fleet to verify.
        $go = $operation->status === OperationStatus::Succeeded && $proof->cancel_requested_at === null
            && ($operation->kind !== 'tasks.script' || ($operation->result['outcome'] ?? null) === 'passed');
        if ($operation->kind === 'tasks.cancel') {
            $this->close($proof);
        } elseif ($go && $operation->kind === 'tasks.provision') {
            $this->reset($group, $stage);
        } elseif ($go && $operation->kind === 'tasks.script') {
            $command = $this->commands($proof)[$stage];
            $this->admit($group, 'proof.verify', fn () => app(ProofVerifyIntent::class)->start($group, $command['command'], $command['file'], 'proof-run', null), $stage);
        } else {
            if ($operation->kind === 'proof.verify') {
                $next = $stage + 1;
                $this->store->change($proof, ['state' => [...($proof->state ?? []), 'stage' => $next, 'rows' => [...array_values((array) ($proof->state['rows'] ?? [])), ...$this->rows($operation)],
                    'evidence' => ValidatedData::string($operation->input['checkout_path']).'/.orbit-artifacts/'.$group->id.'/verify']]);
                if ($next < count($this->commands($proof)) && $proof->cancel_requested_at === null) {
                    $this->reset($group, $next);
                    $operation->update(['state' => [...($operation->state ?? []), 'proof_continued' => true]]);

                    return;
                }
            }
            $this->admit($group, 'tasks.cancel', fn () => app(TaskCancelIntent::class)->start($group, 'proof-run', null));
        }
        $operation->update(['state' => [...($operation->state ?? []), 'proof_continued' => true]]);
    }

    /** The nested fleet of the VM, from the ready copies and with its `base` snapshot, for the cases of one command. */
    private function reset(Task $group, int $stage): void
    {
        $subtask = $group->tasks()->orderBy('position')->firstOrFail();
        $this->admit($group, 'tasks.script', fn () => app(StartTaskScriptAction::class)->execute($group,
            new StartTaskScriptData($subtask->id, 'bin/proof-fleet', ['reset', (string) $group->id, '--from-ready']), 'proof-run', null), $stage);
    }

    /** The proof operation was cancelled: its group must go too, or the VM keeps running. One job admits the cancel, away from the locks of the cancel request. */
    public function cancelled(Operation $proof): void
    {
        DB::afterCommit(static fn () => CancelProofGroup::dispatch($proof->id));
    }

    /**
     * Cancels the group of a cancelled proof through the same keyed admission as the end of a run, so a group that the run already cancelled keeps its one cancel.
     *
     * @throws ResourceOperationException when the group is busy for a moment, such as while a step still holds it; the job tries again
     */
    public function cancelGroup(Operation $proof): void
    {
        $group = Task::topLevel()->find($proof->target_id);
        if ($group === null || $group->status === TaskGroupStatus::Cancelled) {
            return;
        }
        try {
            $this->admit($group, 'tasks.cancel', fn () => app(TaskCancelIntent::class)->start($group, 'proof-run', null));
        } catch (ResourceOperationException $exception) {
            if (! in_array($exception->errorCode, ['tasks.not_cancellable', 'tasks.group_closed'], true)) {
                throw $exception;
            }
        }
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

    /**
     * Each command of the selection once, with the cases file that the runner reads. The selection names a command by its case programs.
     *
     * @return list<array{command:string,file:string}>
     */
    private function commands(Operation $proof): array
    {
        $commands = [];
        foreach ($this->cases($proof) as $case) {
            $command = ValidatedData::string($case['command']);
            [$family, $name] = explode(':', $command, 2) + [1 => ''];
            $commands[$command] ??= ['command' => $command, 'file' => 'swamp/proof/'.$family.'/'.str_replace(':', '-', $name).'.cases.yaml'];
        }

        return array_values($commands);
    }

    /**
     * The verdict row of each case in the verdict lines of a finished proof.verify.
     *
     * @return list<array<string,mixed>>
     */
    private function rows(Operation $verify): array
    {
        $file = ValidatedData::string($verify->input['file']);
        $rows = [];
        foreach ((array) ($verify->result['lines'] ?? []) as $line) {
            $line = ValidatedData::object($line);
            $rows[] = ['program' => $file.'#'.ValidatedData::string($line['id']), 'command' => $verify->input['command'], 'case' => $line['id'], ...array_diff_key($line, ['id' => 0])];
        }

        return $rows;
    }

    /**
     * Every command of the selection with the rows of its cases. A command that has no rows, because the fleet or the run failed before it, is one unrun row.
     *
     * @return array{ran: bool, evidence: string|null, cases: list<array<string,mixed>>}
     */
    private function verdict(Operation $proof): array
    {
        $recorded = array_map(ValidatedData::object(...), (array) ($proof->state['rows'] ?? []));
        $cases = [];
        foreach ($this->commands($proof) as $command) {
            $rows = array_values(array_filter($recorded, static fn (array $row): bool => $row['command'] === $command['command']));
            $cases = [...$cases, ...($rows !== [] ? $rows : [['program' => $command['file'], 'command' => $command['command'], 'verdict' => 'unrun', 'seconds' => 0]])];
        }

        return ['ran' => array_any($recorded, static fn (array $row): bool => $row['verdict'] !== 'unrun'), 'evidence' => is_string($proof->state['evidence'] ?? null) ? $proof->state['evidence'] : null, 'cases' => $cases];
    }

    /** @return list<array<string,mixed>> */
    private function cases(Operation $proof): array
    {
        return array_values(array_map(ValidatedData::object(...), (array) $proof->input['cases']));
    }

    private function close(Operation $proof): void
    {
        $verdict = $this->verdict($proof);
        $cases = $verdict['cases'];
        $passed = array_all($cases, static fn (array $case): bool => $case['verdict'] === 'passed');
        $error = $passed ? null : ($verdict['ran'] ? 'proof.cases_failed' : 'proof.run_failed');
        $this->store->change($proof, ['status' => $passed ? OperationStatus::Succeeded : OperationStatus::Failed, 'finished_at' => now(), 'error_code' => $error,
            'result' => ['commit' => $proof->input['commit'], 'outcome' => $passed ? 'passed' : 'failed', 'cases' => $cases, 'uncovered' => $proof->input['uncovered'],
                'evidence' => $verdict['evidence'], 'receipt_url' => null]]);
    }
}
