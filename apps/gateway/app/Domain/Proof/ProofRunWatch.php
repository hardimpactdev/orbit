<?php

declare(strict_types=1);

namespace App\Domain\Proof;

use App\Domain\Operations\OperationKinds;
use App\Domain\Operations\OperationStatus;
use App\Domain\Operations\OperationStore;
use App\Jobs\Proof\CancelProofGroup;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The watchdog of `proof.run`, which has no Swamp run and so no other deadline. It ends a proof only when the request that admitted
 * it is gone, never because a run is slow: a proof either shows its runs, or ends with `proof.admission_interrupted`, and never sits at
 * `runs: []`. It also closes a proof whose runs have all ended.
 * It also ends the keep of a failed run (`proof:run --keep-failed`) when its 60 minutes are up, by cancelling the group of that run.
 * Operator proof children may wait in task admission; that wait is governed by its own capacity budget.
 */
final readonly class ProofRunWatch
{
    /** Without a run: the job may wait in the queue, then admits in about a minute. Later, it is lost. */
    public const int AdmissionSeconds = 7500;

    /** What the Operation says when the admission was lost. */
    public const string Lost = 'The Gateway stopped before it handed the runs to Swamp. No case ran; run the proof again.';

    /**
     * With runs: the job starts them one after another, and each start is answered within 120 s. A proof whose runs have all been
     * quiet for longer has no job that will start the rest.
     */
    public const int DispatchSilenceSeconds = 300;

    /** The format of the times in the record of a kept VM; it sorts as text. */
    public const string Time = 'Y-m-d\TH:i:s\Z';

    /** A keep that is still open this many hours after its time is no longer asked to cancel. */
    public const int KeptGiveUpHours = 24;

    public function __construct(private OperationStore $store, private ProofRunSettlement $settlement, private ProofKeptVms $kept) {}

    /** @return int how many proofs it ended or settled */
    public function sweep(): int
    {
        $ended = 0;
        // The kept VMs expire on every pass, whatever the watch of the proofs does.
        try {
            $open = Operation::query()->where('kind', OperationKinds::ProofRun)->whereIn('status', [OperationStatus::Queued, OperationStatus::Running])
                ->where(fn ($query) => $query->where('created_at', '<', now()->subSeconds(self::AdmissionSeconds))
                    ->orWhereIn('id', ProofChildStarts::heldProofs()))->get(['id', 'created_at']);
            foreach ($open as $proof) {
                $children = ProofRunSettlement::children($proof->id);
                if (ProofChildStarts::needsResume($children)) {
                    app(ProofChildStarts::class)->resume($proof->id);
                    $children = ProofRunSettlement::children($proof->id);
                }
                // Reads first: a healthy proof takes the write lock for nothing on every pass.
                if ($proof->created_at?->lt(now()->subSeconds(self::AdmissionSeconds)) && $this->needsAction($children)) {
                    $ended += DB::transaction(fn (): int => $this->watch($proof->id));
                }
            }
        } finally {
            $this->expireKept();
        }

        return $ended;
    }

    /** Whether the run kept its VM and the 60 minutes are not up: its group stays open. */
    public static function keeps(Operation $run): bool
    {
        $until = is_array($run->result['kept'] ?? null) ? ($run->result['kept']['keep_until'] ?? null) : null;

        return is_string($until) && $until > now()->utc()->format(self::Time);
    }

    /**
     * Cancels the group of each kept VM (`proof:run --keep-failed`) when its time is up. The cancel is the one every run gets, with the trigger
     * `proof-run`; it is asked for again every few minutes until the group and its VM are gone, so nobody has to come back. A keep that is
     * still there a day after its time is left alone: something other than the time holds it, and the warning names the run.
     */
    private function expireKept(): void
    {
        $due = Operation::query()->where('kind', ProofGroup::OwnerKind)->whereNotNull('result->kept')
            ->whereBetween('result->kept->keep_until', [now()->utc()->subHours(self::KeptGiveUpHours)->format(self::Time), now()->utc()->format(self::Time)])->pluck('id');
        foreach ($due as $id) {
            try {
                $this->kept->release(ValidatedData::integer($id));
            } catch (Throwable $exception) {
                // One run that cannot be cancelled must not hold the others.
                report($exception);
            }
        }
    }

    /** @param Collection<int, Operation> $children */
    private function needsAction(Collection $children): bool
    {
        return $children->isEmpty() || $children->every(static fn (Operation $child): bool => $child->status->terminal())
            || ($children->contains(static fn (Operation $child): bool => self::neverHandedOver($child)) && self::quiet($children));
    }

    /**
     * Whether no run of the proof moved for the dispatch silence.
     *
     * @param  Collection<int, Operation>  $children
     */
    private static function quiet(Collection $children): bool
    {
        return $children->every(static fn (Operation $child): bool => $child->updated_at !== null && $child->updated_at->lt(now()->subSeconds(self::DispatchSilenceSeconds)));
    }

    private function watch(int $id): int
    {
        $proof = Operation::query()->lockForUpdate()->find($id);
        if (! $proof instanceof Operation || $proof->status->terminal()) {
            return 0;
        }
        $children = ProofRunSettlement::children($proof->id);
        if ($children->isEmpty()) {
            $input = ValidatedData::object($proof->input);
            $this->store->change($proof, ['status' => OperationStatus::Failed, 'finished_at' => now(), 'current_step' => null, 'failed_step' => null,
                'error_code' => ProofRunSettlement::AdmissionInterrupted, 'state' => [...($proof->state ?? []), 'native_failure' => ['failed_step' => null, 'detail' => self::Lost, 'stderr_recorded' => false]],
                'result' => ['commit' => $input['commit'] ?? null, 'outcome' => 'failed', 'cases' => [], 'uncovered' => $input['uncovered'] ?? [], 'receipt_url' => null]]);

            return 1;
        }
        $unstarted = $children->filter(static fn (Operation $child): bool => self::neverHandedOver($child));
        if ($unstarted->isNotEmpty() && self::quiet($children)) {
            // No request is left to hand these runs to Swamp. End them the way an expired run ends, so the group and its VM go too.
            foreach ($unstarted as $child) {
                $this->store->change($child, ['status' => OperationStatus::Failed, 'finished_at' => now(), 'error_code' => ProofRunSettlement::AdmissionInterrupted,
                    'current_step' => null, 'lock_key' => null, 'result' => ['error_code' => ProofRunSettlement::AdmissionInterrupted]]);
                DB::afterCommit(static fn () => CancelProofGroup::dispatch($child->id));
            }
        }
        $this->settlement->settle($proof->id);

        return $proof->refresh()->status->terminal() ? 1 : 0;
    }

    /**
     * Whether Swamp never got the run: it is open, bound to no Swamp run, and not in `swamp.start_uncertain`, where Swamp may have
     * accepted the run and the reconcile pass finds it by its tag (`recoverUnbound`), which the 600 s budget covers.
     */
    private static function neverHandedOver(Operation $child): bool
    {
        return ! $child->status->terminal() && ! isset($child->state['swamp_run_id']) && $child->attempts === 0
            && $child->error_code !== 'swamp.start_uncertain' && (! isset($child->state['swamp_start']) || ProofChildStarts::held($child)) && ! isset($child->input['capacity_request']);
    }
}
