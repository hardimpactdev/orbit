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
 * Closes the `proof.run` of the CLI once, when the last of its runs has ended. The proof passes only when every run passes. This
 * reads the records the runs projected and runs nothing; a repeat finds the proof closed and returns.
 */
final readonly class ProofRunSettlement
{
    public function __construct(private OperationStore $store) {}

    /** @return list<mixed> the runs the proof admitted: command, cases file, group and Operation of each */
    public static function runs(Operation $proof): array
    {
        $runs = ValidatedData::object($proof->input)['runs'] ?? [];

        return is_array($runs) ? array_values($runs) : [];
    }

    public function settle(int $proofId): void
    {
        DB::transaction(function () use ($proofId): void {
            $proof = Operation::query()->lockForUpdate()->find($proofId);
            if (! $proof instanceof Operation || $proof->kind !== OperationKinds::ProofRun || $proof->status->terminal()) {
                return;
            }
            $input = ValidatedData::object($proof->input);
            $runs = array_map(ValidatedData::object(...), self::runs($proof));
            if ($runs === []) {
                return;
            }
            $ids = array_map(static fn (array $run): int => ValidatedData::integer($run['operation_id']), $runs);
            $children = Operation::query()->whereIn('id', $ids)->get()->keyBy('id');
            if ($children->count() !== count($ids) || $children->contains(static fn (Operation $child): bool => ! $child->status->terminal())) {
                return;
            }

            $cases = [];
            $ran = false;
            $passed = true;
            foreach ($runs as $index => $run) {
                $child = $children->get($ids[$index]);
                $rows = self::cases($child);
                $passed = $passed && $child instanceof Operation && $child->status === OperationStatus::Succeeded && ProofVerdicts::passed($rows);
                $ran = $ran || array_any($rows, static fn (array $row): bool => ($row['verdict'] ?? 'unrun') !== 'unrun');
                // A run that ended with no record of its cases, such as one the Gateway timed out, is one unrun row.
                $cases = [...$cases, ...($rows !== [] ? $rows : [['program' => $run['file'], 'command' => $run['command'], 'verdict' => 'unrun', 'seconds' => 0]])];
            }

            $this->store->change($proof, [
                'status' => $passed ? OperationStatus::Succeeded : OperationStatus::Failed, 'finished_at' => now(), 'current_step' => null, 'failed_step' => null,
                'error_code' => $passed ? null : ($ran ? 'proof.cases_failed' : 'proof.run_failed'),
                'result' => ['commit' => $input['commit'], 'outcome' => $passed ? 'passed' : 'failed', 'cases' => $cases, 'uncovered' => $input['uncovered'], 'receipt_url' => null],
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
