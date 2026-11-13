<?php

declare(strict_types=1);

namespace App\Domain\Proof;

use App\Actions\Operations\CancelOperationAction;
use App\Domain\Operations\OperationKinds;
use App\Domain\Operations\OperationStatus;
use App\Domain\Operations\OperationStore;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Swamp\StartSwampRun;
use App\Jobs\Operations\StartAdmittedSwampRun;
use App\Jobs\Proof\CancelProofGroup;
use App\Models\Operation;
use App\Support\ValidatedData;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Serializes the starts of one proof, leaving queue time between native answers. */
final readonly class ProofChildStarts
{
    public const int SpacingSeconds = 20;

    public function __construct(private OperationStore $store) {}

    /**
     * Called only after execution copies succeeded. Record every hold before publishing a start.
     *
     * @param  array<int, Operation>  $operations
     * @return array<int, Operation> Operations outside proof.run, for ordinary dispatch.
     */
    public function queue(array $operations): array
    {
        $ordinary = [];
        $proofs = [];
        foreach ($operations as $operation) {
            $id = $operation->kind === ProofGroup::OwnerKind ? ($operation->input['proof_operation_id'] ?? null) : null;
            if (! is_int($id) || ! Operation::query()->whereKey($id)->where('kind', OperationKinds::ProofRun)->exists()) {
                $ordinary[] = $operation;

                continue;
            }
            $proofs[$id][] = $operation->id;
        }
        foreach ($proofs as $id => $children) {
            DB::transaction(function () use ($id, $children): void {
                $proof = Operation::query()->lockForUpdate()->findOrFail($id);
                foreach (Operation::query()->whereKey($children)->lockForUpdate()->get() as $child) {
                    if ($child->status === OperationStatus::Queued && ! isset($child->state['proof_child_start'])) {
                        $this->mark($child, ['not_before' => null, 'released_at' => null, 'advanced_at' => null]);
                    }
                }
                if ($this->ended($proof)) {
                    $this->cancelHeld($proof);

                    return;
                }
                $this->scheduleNext($proof);
            });
        }

        return $ordinary;
    }

    /** Held includes a queued successor whose delayed job has not run yet. */
    public static function held(Operation $operation): bool
    {
        return isset($operation->state['proof_child_start']) && self::releasedAt($operation) === null;
    }

    public static function releasedAt(Operation $operation): ?CarbonImmutable
    {
        $time = self::record($operation)['released_at'] ?? null;

        return is_string($time) ? CarbonImmutable::parse($time) : null;
    }

    /**
     * Check the parent before submission and open the admission clock.
     *
     * @return bool|CarbonImmutable True when eligible, false when ineligible, or the stored due time when early.
     */
    public function release(Operation $operation): bool|CarbonImmutable
    {
        if (! isset($operation->state['proof_child_start'])) {
            return true;
        }

        return DB::transaction(function () use ($operation): bool|CarbonImmutable {
            $proof = Operation::query()->lockForUpdate()->findOrFail(ValidatedData::integer($operation->input['proof_operation_id']));
            if ($this->ended($proof)) {
                $this->cancelHeld($proof);

                return false;
            }
            $operation->setRawAttributes(Operation::query()->lockForUpdate()->findOrFail($operation->id)->getAttributes(), true);
            $notBefore = self::record($operation)['not_before'] ?? null;
            if ($operation->status !== OperationStatus::Queued || ! is_string($notBefore)) {
                return false;
            }
            $due = CarbonImmutable::parse($notBefore);
            if ($due->isFuture()) {
                return $due;
            }
            if (self::held($operation)) {
                $this->mark($operation, ['released_at' => now()->toISOString()]);
            }

            return true;
        });
    }

    /** A missed callback can be recovered entirely from the child's stored result. */
    private static function answered(Operation $child): bool
    {
        return isset(self::record($child)['advanced_at']) || $child->status->terminal() || isset($child->state['swamp_run_id']);
    }

    /** @return Builder<Operation> The parent IDs, used as a subquery without reading every open proof. */
    public static function heldProofs(): Builder
    {
        return Operation::query()->select('input->proof_operation_id')->where('kind', ProofGroup::OwnerKind)
            ->whereNotIn('status', OperationStatus::terminalValues())->whereNotNull('state->proof_child_start')->whereNull('state->proof_child_start->released_at');
    }

    /** @param Collection<int, Operation> $children */
    public static function needsResume(Collection $children): bool
    {
        return $children->contains(static fn (Operation $child): bool => ! $child->status->terminal() && self::held($child))
            && ! $children->contains(static fn (Operation $child): bool => isset(self::record($child)['not_before']) && ! self::answered($child));
    }

    public function advance(Operation $operation): void
    {
        if (isset($operation->state['proof_child_start'])) {
            $this->resume(ValidatedData::integer($operation->input['proof_operation_id']));
        }
    }

    /** The one retry entry point, shared by start answers and the watchdog. */
    public function resume(int $proofId): void
    {
        DB::transaction(function () use ($proofId): void {
            $proof = Operation::query()->lockForUpdate()->findOrFail($proofId);
            if ($this->ended($proof)) {
                $this->cancelHeld($proof);

                return;
            }
            $this->scheduleNext($proof);
        });
    }

    /** The parent lock serializes selection even when jobs run on different workers. */
    private function scheduleNext(Operation $proof): void
    {
        $notBefore = now()->toImmutable();
        foreach (ProofRunSettlement::children($proof->id) as $child) {
            $start = $child->state['proof_child_start'] ?? null;
            if (! is_array($start)) {
                continue;
            }
            if (self::answered($child)) {
                if (! isset($start['advanced_at'])) {
                    $start['advanced_at'] = now()->toISOString();
                    $this->mark($child, ['advanced_at' => $start['advanced_at']]);
                }
                $notBefore = max($notBefore, CarbonImmutable::parse(ValidatedData::string($start['advanced_at']))->addSeconds(self::SpacingSeconds));

                continue;
            }
            if (isset($start['not_before'])) {
                return;
            }
            // Queue availability is an integer timestamp; round up so delivery cannot precede the stored due time.
            $notBefore = $notBefore->ceilSecond();
            $this->mark($child, ['not_before' => $notBefore->toISOString()]);
            DB::afterCommit(fn () => $this->enqueue($child, $notBefore));

            return;
        }
    }

    private function enqueue(Operation $child, CarbonImmutable $notBefore): void
    {
        try {
            $job = new StartAdmittedSwampRun($child->id);
            if ($notBefore->isFuture()) {
                $job->delay($notBefore);
            }
            Bus::dispatch($job);
        } catch (Throwable $exception) {
            Log::warning('Swamp start could not be queued', ['operation_id' => $child->id, 'exception_type' => $exception::class]);
            app(StartSwampRun::class)->refuse($child, new ResourceOperationException(
                'swamp.start_enqueue_failed', 'The native workflow start could not be queued.', 503));
        }
    }

    private function ended(Operation $proof): bool
    {
        return $proof->status->terminal() || $proof->cancel_requested_at !== null;
    }

    /** Use the same cancellation and keyed group cleanup as an unstarted cancelled child. */
    private function cancelHeld(Operation $proof): void
    {
        foreach (ProofRunSettlement::children($proof->id) as $child) {
            if (! $child->status->terminal() && self::held($child)) {
                app(CancelOperationAction::class)->execute($child);
                DB::afterCommit(static fn () => CancelProofGroup::dispatch($child->id));
            }
        }
    }

    /** @return array<string, mixed> */
    private static function record(Operation $operation): array
    {
        return ValidatedData::object($operation->state['proof_child_start'] ?? []);
    }

    /** @param array<string, string|null> $fields */
    private function mark(Operation $child, array $fields): void
    {
        $this->store->change($child, ['state' => [...($child->state ?? []),
            'proof_child_start' => [...self::record($child), ...$fields]]]);
    }
}
