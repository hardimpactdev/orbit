<?php

declare(strict_types=1);

namespace App\Domain\Proof;

use App\Domain\Operations\OperationKinds;
use App\Domain\Operations\OperationStatus;
use App\Domain\Operations\OperationStore;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;

/**
 * The watchdog of `proof.run`, which has no Swamp run and so no other deadline. A proof either shows its runs, or ends with
 * `proof.admission_interrupted`; it never sits at `runs: []`. It also closes a proof whose runs have all ended, which the last run's
 * own projection normally does. See docs/cli/proof/run.mmd.
 */
final readonly class ProofRunWatch
{
    /** Admission takes a minute at most. A proof with no run after this long lost the request that admitted it. */
    public const int AdmissionSeconds = 180;

    public function __construct(private OperationStore $store, private ProofRunSettlement $settlement) {}

    /** @return int how many proofs it ended or settled */
    public function sweep(): int
    {
        $ended = 0;
        $open = Operation::query()->where('kind', OperationKinds::ProofRun)->whereIn('status', [OperationStatus::Queued, OperationStatus::Running])
            ->where('created_at', '<', now()->subSeconds(self::AdmissionSeconds))->pluck('id');
        foreach ($open as $id) {
            $ended += DB::transaction(function () use ($id): int {
                $proof = Operation::query()->lockForUpdate()->find($id);
                if (! $proof instanceof Operation || $proof->status->terminal()) {
                    return 0;
                }
                if (ProofRunSettlement::children($proof->id)->isEmpty()) {
                    $input = ValidatedData::object($proof->input);
                    $this->store->change($proof, ['status' => OperationStatus::Failed, 'finished_at' => now(), 'current_step' => null, 'failed_step' => null,
                        'error_code' => ProofRunSettlement::AdmissionInterrupted,
                        'result' => ['commit' => $input['commit'] ?? null, 'outcome' => 'failed', 'cases' => [], 'uncovered' => $input['uncovered'] ?? [], 'receipt_url' => null]]);

                    return 1;
                }
                $this->settlement->settle($proof->id);

                return $proof->refresh()->status->terminal() ? 1 : 0;
            });
        }

        return $ended;
    }
}
