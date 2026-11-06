<?php

declare(strict_types=1);

namespace App\Domain\Proof;

use App\Domain\Operations\OperationKinds;
use App\Domain\Operations\OperationStatus;
use App\Domain\Operations\OperationStore;
use App\Domain\TaskVms\ModelKeyIssuer;
use App\Jobs\Proof\CancelProofGroup;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The watchdog of `proof.run`, which has no Swamp run and so no other deadline. It ends a proof only when the request that admitted
 * it is gone, never because a run is slow: a proof either shows its runs, or ends with `proof.admission_interrupted`, and never sits at
 * `runs: []`. It also closes a proof whose runs have all ended, and frees the admission lock that the dead request held.
 * A run that waits for VM capacity does not exist: capacity is decided at admission (docs/cli/proof/run.mmd).
 */
final readonly class ProofRunWatch
{
    /** Without a run: the request waits 60 s at most for the admission lock and then admits in about a minute. Later, it is gone. */
    public const int AdmissionSeconds = 150;

    /**
     * With runs: the request starts them one after another, and each start is answered within 120 s. A proof whose runs have all been
     * quiet for longer has no request that will start the rest.
     */
    public const int DispatchSilenceSeconds = 300;

    public function __construct(private OperationStore $store, private ProofRunSettlement $settlement) {}

    /** The owner of the admission lock while this proof is admitted, so the lock ends with the proof. */
    public static function lockOwner(int $proofId): string
    {
        return 'proof.run:'.$proofId;
    }

    /** @return int how many proofs it ended or settled */
    public function sweep(): int
    {
        $ended = 0;
        $open = Operation::query()->where('kind', OperationKinds::ProofRun)->whereIn('status', [OperationStatus::Queued, OperationStatus::Running])
            ->where('created_at', '<', now()->subSeconds(self::AdmissionSeconds))->pluck('id');
        foreach ($open as $id) {
            // Reads first: a healthy proof takes the write lock for nothing on every pass.
            if ($this->needsAction(ValidatedData::integer($id))) {
                $ended += DB::transaction(fn (): int => $this->watch(ValidatedData::integer($id)));
            }
        }

        return $ended;
    }

    private function needsAction(int $id): bool
    {
        $children = ProofRunSettlement::children($id);

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
                'error_code' => ProofRunSettlement::AdmissionInterrupted,
                'result' => ['commit' => $input['commit'] ?? null, 'outcome' => 'failed', 'cases' => [], 'uncovered' => $input['uncovered'] ?? [], 'receipt_url' => null]]);
            $this->freeLock($proof->id);

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
            $this->freeLock($proof->id);
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
            && $child->error_code !== 'swamp.start_uncertain' && ! isset($child->state['swamp_start']);
    }

    /** Releases the admission lock only if this proof's request holds it; a lock another admission or a teardown holds stays. */
    private function freeLock(int $proofId): void
    {
        Cache::restoreLock(ModelKeyIssuer::Lock, self::lockOwner($proofId))->release();
    }
}
