<?php

declare(strict_types=1);

namespace App\Domain\Proof;

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationChildProjection;
use App\Domain\Operations\DirectOperationProjection;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;

/**
 * Hands the admitted proof of the caller's own VM to the Swamp workflow `proof-fleet-verify`, and records it once, from its terminal
 * event: one row for each case. No step calls the Gateway. See docs/cli/proof/proof.mmd.
 *
 * Inside a `proof.command`, the same workflow proves one assigned batch. Each one that ends is recorded on that run at once, so a run
 * that ends early, at its budget, keeps the verdicts of the batches that finished ({@see ProofCommandProjection::timedOut()}).
 */
final readonly class ProofVerifyProjection implements DirectOperationChildProjection, DirectOperationProjection
{
    /** The key of the run's state that holds the report of each batch that ended, by its assignment key `command\tfile\tbatch`. */
    public const string BatchReports = 'batch_reports';

    /** The code of a run whose cases all passed but whose last restore failed, so the VM keeps a dirty fleet. */
    public const string RestoreFailed = 'proof.restore_failed';

    /** The inputs of the workflow: the intent as admitted. */
    public function input(Operation $operation): array
    {
        return ['intent' => array_diff_key(ValidatedData::object($operation->input), ['execution_secrets' => true]), 'execution_secrets' => []];
    }

    public function acceptsParent(Operation $parent): bool
    {
        return $parent->kind === ProofGroup::OwnerKind;
    }

    /** Records the report of one assigned batch that ended, once; a batch that the run was not assigned is not recorded. */
    public function applyChild(Operation $parent, array $input, array $result): void
    {
        $intent = ValidatedData::object($input['intent'] ?? []);
        $key = implode("\t", [ValidatedData::nullableString($intent['command'] ?? null), ValidatedData::nullableString($intent['file'] ?? null),
            ValidatedData::nullableString($intent['batch'] ?? null)]);
        $parentInput = ValidatedData::object($parent->input);
        $assigned = array_map(static fn (array $batch): string => implode("\t", [$batch['command'], $batch['file'], $batch['batch']]),
            ProofBatchPlan::stored($parentInput['assignments'] ?? []));
        $steps = $result['steps'] ?? null;
        $report = is_array($steps) && is_array($steps['report'] ?? null) && is_string($steps['report']['stdout'] ?? null) ? $steps['report']['stdout'] : null;
        $recorded = ValidatedData::object($parent->state[self::BatchReports] ?? []);
        if ($report === null || ! in_array($key, $assigned, true) || isset($recorded[$key])) {
            return;
        }
        $parent->update(['state' => [...($parent->state ?? []), self::BatchReports => [...$recorded, $key => $report]]]);
    }

    public function apply(Operation $operation, array $data): Operation
    {
        return DB::transaction(function () use ($operation, $data): Operation {
            $operation = Operation::query()->lockForUpdate()->findOrFail($operation->id);
            if ($operation->status->terminal()) {
                // A repeat of the terminal event finds the run recorded: it answers with what was recorded.
                return app(DirectOperation::class)->project($operation, [...$data, 'status' => $operation->status->value, 'error_code' => $operation->error_code], ValidatedData::object($operation->result ?? []));
            }
            $native = ValidatedData::object($data['result'] ?? []);
            $input = ValidatedData::object($operation->input);
            $status = ValidatedData::string($data['status']);

            $steps = $native['steps'] ?? null;
            $report = is_array($steps) && is_array($steps['report'] ?? null) && is_string($steps['report']['stdout'] ?? null) ? $steps['report']['stdout'] : '';
            $verdicts = ProofVerdicts::rows(ValidatedData::string($input['command']), ValidatedData::string($input['file']), $report);
            if (in_array($status, ['succeeded', 'failed'], true)) {
                $allPassed = ProofVerdicts::passed($verdicts['cases']);
                $passed = $status === 'succeeded' && $allPassed;
                $data['status'] = $passed ? 'succeeded' : 'failed';
                $data['error_code'] = $passed ? null : match (true) {
                    ! $verdicts['ran'] => ProofCommandProjection::RunFailed,
                    $allPassed => self::RestoreFailed,
                    default => 'proof.cases_failed',
                };
            }

            return app(DirectOperation::class)->project($operation, $data, ['command' => $input['command'], 'file' => $input['file'],
                'outcome' => ($data['status'] ?? null) === 'succeeded' ? 'passed' : 'failed', 'cases' => $verdicts['cases']]);
        });
    }
}
