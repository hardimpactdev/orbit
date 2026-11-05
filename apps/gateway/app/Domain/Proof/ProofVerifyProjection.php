<?php

declare(strict_types=1);

namespace App\Domain\Proof;

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Operations\DirectOperationTimeout;
use App\Models\Operation;
use App\Support\ValidatedData;

/** Records the verdict lines of the report step and hands the proof its next step. It runs no case and reads no VM. */
final readonly class ProofVerifyProjection implements DirectOperationProjection, DirectOperationTimeout
{
    public function input(Operation $operation): array
    {
        return ['intent' => $operation->input, 'execution_secrets' => []];
    }

    /** A verify the Gateway timed out has no report: the proof goes on and the cases of its command are unrun. */
    public function timedOut(Operation $operation): void
    {
        app(ProofRunContinuation::class)->apply($operation);
    }

    public function apply(Operation $operation, array $data): Operation
    {
        $native = ValidatedData::object($data['result'] ?? []);
        $report = is_string($native['report'] ?? null) ? $native['report'] : '';

        $projected = app(DirectOperation::class)->project($operation, $data, ['lines' => ProofVerdictLines::parse($report)]);
        app(ProofRunContinuation::class)->apply($projected);

        return $projected;
    }
}
