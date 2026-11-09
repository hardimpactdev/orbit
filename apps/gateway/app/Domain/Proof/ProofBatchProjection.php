<?php

declare(strict_types=1);

namespace App\Domain\Proof;

use App\Domain\Operations\DirectOperationChildProjection;
use App\Models\Operation;
use App\Support\ValidatedData;

/**
 * Inside a `proof.command`, the workflow `proof-fleet-verify` proves one assigned batch. Each one that ends is recorded on that run at
 * once, from the full report it captured, so a run that ends early, at its budget, keeps the verdicts of the batches that finished
 * ({@see ProofCommandProjection::timedOut()}). A standalone `orbit proof` is recorded by {@see ProofVerifyProjection} alone.
 */
final readonly class ProofBatchProjection implements DirectOperationChildProjection
{
    /** The key of the run's state that holds the report of each batch that ended, by its assignment key `command\tfile\tbatch`. */
    public const string BatchReports = 'batch_reports';

    public function acceptsParent(Operation $parent): bool
    {
        return $parent->kind === ProofGroup::OwnerKind;
    }

    /** Records the report of one assigned batch that ended, once; a batch that the run was not assigned is not recorded. */
    public function applyChild(Operation $parent, array $input, array $result): void
    {
        $intent = ValidatedData::object($input['intent'] ?? []);
        $key = implode("\t", [ValidatedData::nullableString($intent['command'] ?? null), ValidatedData::nullableString($intent['file'] ?? null),
            ValidatedData::nullableString($intent['batch'] ?? null)]);
        $assigned = array_map(static fn (array $batch): string => implode("\t", [$batch['command'], $batch['file'], $batch['batch']]),
            ProofBatchPlan::stored(ValidatedData::object($parent->input)['assignments'] ?? []));
        $report = $result['report'] ?? null;
        $recorded = ValidatedData::object($parent->state[self::BatchReports] ?? []);
        if (! is_string($report) || ! in_array($key, $assigned, true) || isset($recorded[$key])) {
            return;
        }
        $parent->update(['state' => [...($parent->state ?? []), self::BatchReports => [...$recorded, $key => $report]]]);
    }
}
