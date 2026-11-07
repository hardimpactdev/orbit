<?php

declare(strict_types=1);

namespace App\Domain\Proof;

use App\Domain\Operations\OperationKinds;
use App\Domain\Operations\OperationStatus;
use App\Domain\Operations\OperationStore;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Closes the `proof.run` of the CLI once, when the last of its runs has ended. The proof passes only when every run passes. This
 * reads the records the runs projected and runs nothing; a repeat finds the proof closed and returns.
 */
final readonly class ProofRunSettlement
{
    /** The code of a proof whose runs never started because the request that admitted them stopped. */
    public const string AdmissionInterrupted = 'proof.admission_interrupted';

    public function __construct(private OperationStore $store) {}

    /**
     * The runs of a proof: the `proof.command` Operations whose input names it. This is the one key of ownership. It does not depend on
     * the run list in the proof, which a stopped request may never have written, and it never matches by commit, title or kind alone.
     *
     * @return Collection<int, Operation>
     */
    public static function children(int $proofId): Collection
    {
        return Operation::query()->where('kind', ProofGroup::OwnerKind)->where('input->proof_operation_id', $proofId)->orderBy('id')->get();
    }

    public function settle(int $proofId): void
    {
        DB::transaction(function () use ($proofId): void {
            $proof = Operation::query()->lockForUpdate()->find($proofId);
            if (! $proof instanceof Operation || $proof->kind !== OperationKinds::ProofRun || $proof->status->terminal()) {
                return;
            }
            $input = ValidatedData::object($proof->input);
            $children = self::children($proofId);
            if ($children->isEmpty() || $children->contains(static fn (Operation $child): bool => ! $child->status->terminal())) {
                return;
            }

            $cases = [];
            $ran = false;
            $passed = true;
            $interrupted = false;
            $kept = [];
            foreach ($children as $child) {
                $rows = self::cases($child);
                $passed = $passed && $child->status === OperationStatus::Succeeded && ProofVerdicts::passed($rows);
                $ran = $ran || array_any($rows, static fn (array $row): bool => ($row['verdict'] ?? 'unrun') !== 'unrun');
                // A run that no Swamp run ever started, because the request that admitted it stopped, timed out unstarted.
                $interrupted = $interrupted || $child->error_code === self::AdmissionInterrupted || ($child->error_code === 'operation.timed_out' && ! isset($child->state['swamp_run_id']));
                $childInput = ValidatedData::object($child->input);
                if (is_array($child->result['kept'] ?? null)) {
                    $kept[] = ['command' => $childInput['command'], ...array_intersect_key(ValidatedData::object($child->result['kept']), ['vm' => 0, 'group_id' => 0, 'keep_until' => 0])];
                }
                // A run that ended with no record of its cases, such as one the Gateway timed out, is one unrun row.
                $cases = [...$cases, ...($rows !== [] ? $rows : [['program' => $childInput['file'], 'command' => $childInput['command'], 'verdict' => 'unrun', 'seconds' => 0]])];
            }

            $this->store->change($proof, [
                'status' => $passed ? OperationStatus::Succeeded : OperationStatus::Failed, 'finished_at' => now(), 'current_step' => null, 'failed_step' => null,
                'error_code' => $passed ? null : ($ran ? 'proof.cases_failed' : ($interrupted ? self::AdmissionInterrupted : 'proof.run_failed')),
                'result' => ['commit' => $input['commit'], 'outcome' => $passed ? 'passed' : 'failed', 'cases' => $cases, 'uncovered' => $input['uncovered'], 'receipt_url' => null, ...($kept === [] ? [] : ['kept' => $kept])],
            ]);
        });
    }

    /** @return list<array<string,mixed>> the rows a run recorded for its cases */
    private static function cases(?Operation $run): array
    {
        $cases = $run instanceof Operation ? ($run->result['cases'] ?? null) : null;

        return is_array($cases) ? array_values(array_map(ValidatedData::object(...), array_filter($cases, is_array(...)))) : [];
    }
}
