<?php

declare(strict_types=1);

namespace App\Domain\Proof;

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Operations\DirectOperationStartRefusal;
use App\Domain\Operations\DirectOperationTimeout;
use App\Domain\Operations\DirectOperationWorkflowInput;
use App\Domain\Operations\OperationStore;
use App\Infrastructure\Swamp\NativeRunResult;
use App\Jobs\Proof\CancelProofGroup;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;

/**
 * Hands the admitted run of one command to its Swamp parent `operation-proof-run-copy` in a copy, and records the run once, from its
 * terminal event: the verdict of each case, and the end of the task group, which is the destroy of its copy. No step calls the Gateway.
 * See docs/cli/proof/run.mmd.
 */
final readonly class ProofCommandProjection implements DirectOperationProjection, DirectOperationStartRefusal, DirectOperationTimeout, DirectOperationWorkflowInput
{
    /** The code of a run whose copy or fleet never got to run its cases. */
    public const string RunFailed = 'proof.run_failed';

    /** The code of a run whose copy's workspace was not at the commit under proof when the check ran, before any case. */
    public const string WorkspaceCommitMismatch = 'proof.workspace_commit_mismatch';

    public function __construct(private ProofRunSettlement $settlement) {}

    /** At the start of a run, each development deploy step's command goes to its vault key. */
    public function workflowInput(Operation $operation): array
    {
        return app(ProofCopies::class)->input($operation, true);
    }

    /** The run's input as the copy workflow takes it, without the vault copies of the deploy steps. */
    public function input(Operation $operation): array
    {
        return app(ProofCopies::class)->input($operation, false);
    }

    /**
     * A run the Gateway timed out keeps the verdicts of the batches that ended before it ({@see ProofBatchProjection::applyChild()});
     * the cases of the others are unrun. Its group is cancelled the normal way, which removes its copy.
     */
    public function timedOut(Operation $operation): void
    {
        $input = ValidatedData::object($operation->input);
        $verdicts = ProofShardVerdicts::rows(ProofBatchPlan::stored($input['assignments'] ?? [['command' => $input['command'], 'file' => $input['file'], 'batch' => '']]), self::recordedReport($operation));
        app(OperationStore::class)->change($operation, ['result' => [...($operation->result ?? []), 'commit' => $input['commit'], 'command' => $input['command'],
            'file' => $input['file'], 'outcome' => 'failed', 'cases' => $verdicts['cases']]]);
        DB::afterCommit(static fn () => CancelProofGroup::dispatch($operation->id));
        $this->settle($operation);
    }

    /** A definite start refusal has no Node effects, but the copy that admission started still needs its removal. */
    public function refused(Operation $operation): void
    {
        DB::afterCommit(static fn () => CancelProofGroup::dispatch($operation->id));
        DB::afterCommit(fn () => $this->settle($operation));
    }

    public function apply(Operation $operation, array $data): Operation
    {
        $projected = DB::transaction(function () use ($operation, $data): Operation {
            $operation = Operation::query()->lockForUpdate()->findOrFail($operation->id);
            if ($operation->status->terminal()) {
                // A repeat of the terminal event finds the run recorded: it answers with what was recorded.
                return app(DirectOperation::class)->project($operation, [...$data, 'status' => $operation->status->value, 'error_code' => $operation->error_code], ValidatedData::object($operation->result ?? []));
            }
            $native = ValidatedData::object($data['result'] ?? []);
            $input = ValidatedData::object($operation->input);
            $status = ValidatedData::string($data['status']);

            $report = is_string($native['report'] ?? null) ? $native['report'] : '';
            if ($status === 'interrupted') {
                $report = self::recordedReport($operation, $report);
            }
            $verdicts = ProofShardVerdicts::rows(ProofBatchPlan::stored($input['assignments'] ?? [['command' => $input['command'], 'file' => $input['file'], 'batch' => '']]), $report);
            if (in_array($status, ['succeeded', 'failed'], true)) {
                $passed = $status === 'succeeded' && ProofVerdicts::passed($verdicts['cases']);
                $data['status'] = $passed ? 'succeeded' : 'failed';
                $data['error_code'] = $passed ? null : ($verdicts['ran'] ? 'proof.cases_failed' : self::RunFailed);
            }
            // The copy's workspace was not at the commit under proof, so no case ran: the code and both commits say what to fix.
            $workspace = $status === 'failed' ? self::workspace($input, $native) : null;
            if ($workspace !== null) {
                $data['error_code'] = self::WorkspaceCommitMismatch;
            }

            // Every end of a run removes its copy: its group's cancel destroys it.
            DB::afterCommit(static fn () => CancelProofGroup::dispatch($operation->id));

            return app(DirectOperation::class)->project($operation, $data, ['commit' => $input['commit'], 'command' => $input['command'], 'file' => $input['file'],
                'outcome' => match ($data['status'] ?? null) {
                    'succeeded' => 'passed', 'interrupted' => 'interrupted', default => 'failed'
                }, 'cases' => $verdicts['cases'], ...($workspace === null ? [] : ['workspace' => $workspace])]);
        });
        // After the commit, so that two runs which end together each see the other, and the last one closes the proof.
        DB::afterCommit(fn () => $this->settle($projected));

        return $projected;
    }

    /** Keep completed batch evidence when execution stops before the final report, without repeating lines it already contains. */
    private static function recordedReport(Operation $operation, string $report = ''): string
    {
        $reported = explode("\n", trim($report));
        $lines = $reported;
        foreach (ValidatedData::object($operation->state[ProofBatchProjection::BatchReports] ?? []) as $key => $batchReport) {
            foreach (explode("\n", trim(ValidatedData::string($batchReport))) as $line) {
                $recorded = $key."\t".trim($line);
                if (trim($line) !== '' && ! in_array($recorded, $reported, true)) {
                    $lines[] = $recorded;
                }
            }
        }

        return implode("\n", $lines);
    }

    /**
     * The commits of a run whose check of the workspace failed, or null when it did not fail. `found` is the commit the copy printed, and
     * an empty string when it printed none that looks like one, so a message never carries text from the copy.
     *
     * @param  array<string,mixed>  $input  the input of the run
     * @param  array<string,mixed>  $native  the native result of the run
     * @return array{expected:string,found:string}|null
     */
    private static function workspace(array $input, array $native): ?array
    {
        if (NativeRunResult::stepStatus($native, 'Check the workspace commit') !== 'failed') {
            return null;
        }
        $steps = ValidatedData::object($native['steps'] ?? []);
        $read = is_array($steps['Read the workspace commit'] ?? null) ? $steps['Read the workspace commit'] : [];
        $found = is_string($read['stdout'] ?? null) ? trim($read['stdout']) : '';

        return ['expected' => ValidatedData::string($input['commit']), 'found' => preg_match('/\A[0-9a-f]{40}(?:[0-9a-f]{24})?\z/D', $found) === 1 ? $found : ''];
    }

    private function settle(Operation $run): void
    {
        $proof = $run->input['proof_operation_id'] ?? null;
        if (is_int($proof)) {
            $this->settlement->settle($proof);
        }
    }
}
