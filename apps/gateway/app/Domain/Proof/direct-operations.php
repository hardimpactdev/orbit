<?php

declare(strict_types=1);

use App\Domain\Proof\ProofBatchProjection;
use App\Domain\Proof\ProofCommandProjection;
use App\Domain\Proof\ProofVerifyProjection;
use App\Domain\Tasks\TaskGitHubTokens;

$provision = (require __DIR__.'/../Tasks/direct-operations.php')['tasks.provision'];

return [
    // The Swamp workflow `proof-fleet-verify` proves one command on the task VM that calls, with the fleet it already has. Its `report` step
    // joins the verdict lines; the Gateway reads it once, when the run ends.
    'proof.verify' => ['workflow' => 'proof-fleet-verify', 'projection' => ProofVerifyProjection::class, 'child_projection' => ProofBatchProjection::class, 'workflow_inputs' => true,
        'terminal_result' => ['job' => 'report', 'step' => 'report', 'native' => true, 'captures' => ['report' => ['job' => 'report', 'step' => 'report', 'field' => 'stdout', 'artifact' => 'result']]]],
    // The Swamp workflow `operation-proof-run` proves one command on a slot of its own: the claim, the cases through `proof-fleet-verify`
    // and the release of the slot, always. Its `Report` step joins the verdict lines; the Gateway reads it once, when the run ends. Each
    // `proof-fleet-verify` child that ends records its batch on the run as it ends (ProofBatchProjection::applyChild), for a run that reaches its budget.
    // A run in a copy runs `operation-proof-run-copy` instead: it brings the commit into the copy admission started, runs the Project's
    // development deploy steps from their vault copies, and leaves the copy's removal to the Gateway (ProofCommandProjection).
    'proof.command' => ['workflow' => 'operation-proof-run', 'alternate_workflows' => ['operation-proof-run-copy'], 'projection' => ProofCommandProjection::class, 'workflow_inputs' => true, 'github_token_issuer' => TaskGitHubTokens::class,
        'terminal_result' => ['job' => 'prove', 'step' => 'Report', 'native' => true, 'captures' => ['provision' => ['job' => 'prove', 'step' => 'Claim a place'], 'report' => ['job' => 'prove', 'step' => 'Report', 'field' => 'stdout', 'artifact' => 'result']]],
        'execution_copies' => [...$provision['execution_copies'],
            ['vault' => 'orbit-control-inputs', 'key_pattern' => '^development-deploy-operation-[1-9][0-9]*-step-(?:[0-9]|[12][0-9]|3[01])$']]],
];
