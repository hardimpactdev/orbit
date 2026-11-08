<?php

declare(strict_types=1);

namespace App\Domain\Proof;

use App\Actions\Operations\CancelOperationAction;
use App\Domain\Operations\OperationKinds;
use App\Domain\Operations\OperationStatus;
use App\Jobs\Proof\CancelProofGroup;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The stop of the running report-only proofs (`proof:run --report-only`, the job of every pull request) for a proof that cannot be placed.
 * A non-CI report-only proof yields capacity, so a normal proof that is refused for room takes it back: each non-CI report-only proof that has runs is
 * cancelled the way a person cancels it (`operation:cancel`), which cancels its runs and the group and VM of each, found by `proof_operation_id`.
 * See docs/cli/proof/run.mmd.
 */
final readonly class ProofReportOnlyProofs
{
    /** A report-only proof that ended is looked at only this long: its VMs are on their way out, or they are a case for the watchdog. */
    public const int DrainMinutes = 10;

    public function __construct(private CancelOperationAction $cancel, private ProofGroup $groups) {}

    /**
     * Cancels every open report-only proof that has runs, other than `$except`, and counts the ones whose VMs are on their way out. A proof with
     * no runs yet holds no capacity and is left to its admission. The cancel of a group that is still there is asked for again on each try; it is
     * keyed, so it never admits a second one. The caller holds no lock.
     *
     * @return int how many report-only proofs have a group that still holds its VM, or could not be cancelled now and are tried again
     */
    public function preempt(int $except): int
    {
        $leaving = 0;
        $ids = Operation::query()->where('kind', OperationKinds::ProofRun)->where('input->report_only', true)->where('id', '!=', $except)
            ->where(static fn ($query) => $query->whereNull('input->ci_reserve')->orWhere('input->ci_reserve', false))
            ->where(static fn ($query) => $query->whereIn('status', [OperationStatus::Queued->value, OperationStatus::Running->value, OperationStatus::Waiting->value])
                ->orWhere('finished_at', '>', now()->subMinutes(self::DrainMinutes)))
            ->orderBy('id')->pluck('id');
        foreach ($ids as $id) {
            try {
                $leaving += $this->stop(ValidatedData::integer($id), $except) ? 1 : 0;
            } catch (Throwable $exception) {
                // One proof that cannot be cancelled must not hold the others. It still holds its VM, so the next try asks again.
                report($exception);
                $leaving++;
            }
        }

        return $leaving;
    }

    private function stop(int $id, int $by): bool
    {
        $proof = Operation::query()->find($id);
        if (! $proof instanceof Operation || ($proof->input['ci_reserve'] ?? false) === true) {
            return false;
        }
        $runs = ProofRunSettlement::children($id);
        if ($runs->isEmpty()) {
            return false;
        }
        $cancelled = false;
        if (! $proof->status->terminal()) {
            Log::warning('A report-only proof is cancelled for a proof that cannot be placed.', ['operation_id' => $id, 'for_proof_operation_id' => $by]);
            $this->cancel->execute($proof);
            $cancelled = true;
        }

        // A run that kept its VM (`--keep-failed`) is the business of the kept-VM release, which counts it.
        $draining = $runs->filter(fn (Operation $run): bool => ! ProofRunWatch::keeps($run) && $this->groups->needsCancel($run));
        if (! $cancelled) {
            // The cancel job gives up after its tries; asking again is safe, because the cancel of a group is keyed.
            foreach ($draining as $run) {
                CancelProofGroup::dispatch($run->id);
            }
        }

        return $draining->isNotEmpty();
    }
}
