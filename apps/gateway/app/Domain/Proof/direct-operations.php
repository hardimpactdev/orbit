<?php

declare(strict_types=1);

use App\Domain\Proof\ProofBatchProjection;
use App\Domain\Proof\ProofCommandProjection;
use App\Domain\Proof\ProofVerifyProjection;

return [
    // The Swamp workflow `proof-fleet-verify` proves one command in the task copy of a group, with the fleet it already has. Its `report` step
    // joins the verdict lines; the Gateway reads it once, when the run ends.
    'proof.verify' => ['workflow' => 'proof-fleet-verify', 'projection' => ProofVerifyProjection::class, 'child_projection' => ProofBatchProjection::class, 'workflow_inputs' => true,
        'terminal_result' => ['job' => 'report', 'step' => 'report', 'native' => true, 'captures' => ['report' => ['job' => 'report', 'step' => 'report', 'field' => 'stdout', 'artifact' => 'result']]]],
    // The Swamp workflow `operation-proof-run-copy` proves one command in the copy that admission started: it brings the commit into the
    // copy, runs the Project's development deploy steps from their vault copies, proves the assigned batches through `proof-fleet-verify` and
    // leaves the copy's removal to the Gateway (ProofCommandProjection). Its `Report` step joins the verdict lines; the Gateway reads
    // it once, when the run ends. Each child that ends records its batch on the run as it ends (ProofBatchProjection::applyChild), for a
    // run that reaches its budget.
    'proof.command' => ['child_versions' => ['proof.verify' => 1], 'workflow' => 'operation-proof-run-copy', 'projection' => ProofCommandProjection::class, 'workflow_inputs' => true,
        'terminal_result' => ['job' => 'prove', 'step' => 'Report', 'native' => true, 'captures' => ['report' => ['job' => 'prove', 'step' => 'Report', 'field' => 'stdout', 'artifact' => 'result']]],
        'execution_copies' => [['vault' => 'orbit-control-inputs', 'key_pattern' => '^development-deploy-operation-[1-9][0-9]*-step-(?:[0-9]|[12][0-9]|3[01])$']]],
];
