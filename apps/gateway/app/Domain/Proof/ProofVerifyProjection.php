<?php

declare(strict_types=1);

namespace App\Domain\Proof;

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;

/**
 * Hands the admitted proof of the caller's own VM to the Swamp workflow `proof-fleet-verify`, and records it once, from its terminal
 * event: one row for each case. No step calls the Gateway. See docs/cli/proof/proof.mmd.
 */
final readonly class ProofVerifyProjection implements DirectOperationProjection
{
    /** The code of a run whose cases all passed but whose last restore failed, so the VM keeps a dirty fleet. */
    public const string RestoreFailed = 'proof.restore_failed';

    /** The inputs of the workflow: the intent as admitted. */
    public function input(Operation $operation): array
    {
        return ['intent' => array_diff_key(ValidatedData::object($operation->input), ['execution_secrets' => true]), 'execution_secrets' => []];
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
