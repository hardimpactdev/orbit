<?php

declare(strict_types=1);

namespace App\Domain\Proof;

use App\Domain\Operations\OperationStore;
use App\Jobs\Proof\CancelProofGroup;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The cancel of the VMs that failed proof runs kept (`proof:run --keep-failed`): at the end of their 60 minutes, and earlier when a proof
 * cannot be placed. Only a `proof.command` that recorded the VM it kept is asked, and the cancel is the one every run gets, with the trigger
 * `proof-run`. See docs/cli/proof/run.mmd.
 */
final readonly class ProofKeptVms
{
    /** A cancel that has not ended the group is asked for again after this many minutes. */
    public const int RetryMinutes = 5;

    public function __construct(private OperationStore $store, private ProofGroup $groups) {}

    /**
     * Asks for the cancel of the group of a kept run, unless it was asked for lately or the group and its VM are gone. The caller holds no lock.
     *
     * @return bool whether the VM is on its way out: asked now or lately, and not yet gone
     */
    public function release(int $id): bool
    {
        return DB::transaction(function () use ($id): bool {
            $run = Operation::query()->lockForUpdate()->find($id);
            $kept = $run instanceof Operation && is_array($run->result['kept'] ?? null) ? $run->result['kept'] : null;
            if (! $run instanceof Operation || $kept === null || ! $this->groups->needsCancel($run)) {
                return false;
            }
            $asked = $kept['cancel_requested_at'] ?? null;
            if (is_string($asked)) {
                if ($asked > now()->utc()->subMinutes(self::RetryMinutes)->format(ProofRunWatch::Time)) {
                    return true;
                }
                Log::warning('A kept proof VM is still there after its cancel was asked for.', ['operation_id' => $run->id, 'group_id' => $kept['group_id'] ?? null, 'asked_at' => $asked]);
            }
            $this->store->change($run, ['result' => [...ValidatedData::object($run->result), 'kept' => [...$kept, 'cancel_requested_at' => now()->utc()->format(ProofRunWatch::Time)]]]);
            DB::afterCommit(static fn () => CancelProofGroup::dispatch($run->id));

            return true;
        });
    }

    /**
     * Releases every kept VM that is still there, for a proof that cannot be placed.
     *
     * @return int how many kept VMs are on their way out
     */
    public function releaseAll(): int
    {
        $leaving = 0;
        foreach (Operation::query()->where('kind', ProofGroup::OwnerKind)->whereNotNull('result->kept')->orderBy('id')->pluck('id') as $id) {
            $leaving += $this->release(ValidatedData::integer($id)) ? 1 : 0;
        }

        return $leaving;
    }
}
