<?php

declare(strict_types=1);

namespace App\Domain\Proof;

use App\Actions\Operations\CancelOperationAction;
use App\Domain\Operations\OperationKinds;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The stop of the running report-only proofs (`proof:run --report-only`, the job of every pull request) for a proof that cannot be placed.
 * A report-only proof yields capacity, so a normal proof that is refused for room takes it back: each report-only proof that has runs is
 * cancelled the way a person cancels it (`operation:cancel`), which cancels its runs and the group and VM of each, found by `proof_operation_id`.
 * See docs/cli/proof/run.mmd.
 */
final readonly class ProofReportOnlyProofs
{
    /** Report-only proofs older than this are not looked at: their VMs are long gone or belong to the watchdog. */
    public const int LookbackHours = 24;

    public function __construct(private CancelOperationAction $cancel, private ProofGroup $groups) {}

    /**
     * Cancels every open report-only proof that has runs, other than `$keep`, and counts the ones whose VMs are on their way out. A proof with
     * no runs yet holds no capacity and is left to its admission. The caller holds no lock.
     *
     * @return int how many report-only proofs have a group that still holds its VM: cancelled now, or cancelled lately and not yet gone
     */
    public function preempt(int $keep): int
    {
        $leaving = 0;
        $ids = Operation::query()->where('kind', OperationKinds::ProofRun)->where('input->report_only', true)->where('id', '!=', $keep)
            ->where('created_at', '>', now()->subHours(self::LookbackHours))->orderBy('id')->pluck('id');
        foreach ($ids as $id) {
            try {
                $leaving += $this->stop(ValidatedData::integer($id)) ? 1 : 0;
            } catch (Throwable $exception) {
                // One proof that cannot be cancelled must not hold the others.
                report($exception);
            }
        }

        return $leaving;
    }

    private function stop(int $id): bool
    {
        $proof = Operation::query()->find($id);
        if (! $proof instanceof Operation) {
            return false;
        }
        $runs = ProofRunSettlement::children($id);
        if ($runs->isEmpty()) {
            return false;
        }
        if (! $proof->status->terminal()) {
            Log::warning('A report-only proof is cancelled for a proof that cannot be placed.', ['operation_id' => $id]);
            $this->cancel->execute($proof);
        }

        // A run that kept its VM (`--keep-failed`) is the business of the kept-VM release, which counts it.
        return $runs->contains(fn (Operation $run): bool => ! ProofRunWatch::keeps($run) && $this->groups->needsCancel($run));
    }
}
